<?php

declare(strict_types=1);

namespace Four\RateLimit\Algorithm;

use Four\RateLimit\RateLimiterInterface;
use Four\RateLimit\RateLimitConfiguration;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Leaky Bucket Rate Limiter
 *
 * Implements leaky bucket algorithm for very smooth rate limiting.
 * Requests "leak" out at a steady rate. Ideal for conservative APIs like Bandcamp.
 * Provides the smoothest traffic shaping.
 */
class LeakyBucketRateLimiter implements RateLimiterInterface
{
    private array $buckets = [];
    private array $dynamicLimits = [];
    
    public function __construct(
        private readonly RateLimitConfiguration $config,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
        if ($this->config->shouldPersistState()) {
            $this->loadState();
        }
    }

    public function isAllowed(string $key, int $tokens = 1): bool
    {
        $this->initializeBucket($key);
        $this->processLeakage($key);
        
        $bucket = &$this->buckets[$key];
        $capacity = $this->getBucketCapacity($key);
        
        if ($bucket['level'] + $tokens <= $capacity) {
            $bucket['level'] += $tokens;
            $bucket['last_request'] = microtime(true);
            
            $this->saveState();
            
            $this->logger->debug("Leaky bucket rate limit allowed", [
                'key' => $key,
                'tokens_requested' => $tokens,
                'bucket_level' => $bucket['level'],
                'capacity' => $capacity,
                'leak_rate' => $this->getEffectiveRate($key)
            ]);
            
            return true;
        }
        
        $this->logger->debug("Leaky bucket rate limit exceeded", [
            'key' => $key,
            'tokens_requested' => $tokens,
            'bucket_level' => $bucket['level'],
            'capacity' => $capacity,
            'wait_time_ms' => $this->getWaitTime($key)
        ]);
        
        return false;
    }

    public function waitForAllowed(string $key, int $tokens = 1, int $maxWaitMs = 30000): bool
    {
        $startTime = microtime(true);
        $maxWaitSec = $maxWaitMs / 1000.0;
        
        while ((microtime(true) - $startTime) < $maxWaitSec) {
            if ($this->isAllowed($key, $tokens)) {
                return true;
            }
            
            $waitTimeMs = min($this->getWaitTime($key), 1000); // Max 1 second wait between checks
            if ($waitTimeMs > 0) {
                usleep($waitTimeMs * 1000);
            }
        }
        
        $this->logger->warning("Leaky bucket rate limit wait timeout", [
            'key' => $key,
            'tokens' => $tokens,
            'max_wait_ms' => $maxWaitMs
        ]);
        
        return false;
    }

    public function getWaitTime(string $key): int
    {
        $this->initializeBucket($key);
        $this->processLeakage($key);
        
        $bucket = $this->buckets[$key];
        $capacity = $this->getBucketCapacity($key);
        $tokensNeeded = 1;
        
        $availableSpace = $capacity - $bucket['level'];
        if ($availableSpace >= $tokensNeeded) {
            return 0;
        }
        
        $tokensToLeak = $tokensNeeded - $availableSpace;
        $leakRate = $this->getEffectiveRate($key);
        
        if ($leakRate <= 0) {
            return 30000; // 30 second fallback
        }
        
        $waitTimeSeconds = $tokensToLeak / $leakRate;
        return (int)ceil($waitTimeSeconds * 1000);
    }

    public function reset(string $key): void
    {
        $this->initializeBucket($key);
        $bucket = &$this->buckets[$key];
        $bucket['level'] = 0;
        $bucket['last_leak'] = microtime(true);
        
        $this->saveState();
        
        $this->logger->info("Leaky bucket rate limiter reset", ['key' => $key]);
    }

    public function getStatus(string $key): array
    {
        $this->initializeBucket($key);
        $this->processLeakage($key);
        
        $bucket = $this->buckets[$key];
        $capacity = $this->getBucketCapacity($key);
        
        return [
            'algorithm' => 'leaky_bucket',
            'key' => $key,
            'bucket_level' => round($bucket['level'], 2),
            'capacity' => $capacity,
            'available_space' => round($capacity - $bucket['level'], 2),
            'leak_rate_per_second' => $this->getEffectiveRate($key),
            'wait_time_ms' => $this->getWaitTime($key),
            'last_leak' => date('c', (int)$bucket['last_leak']),
            'last_request' => $bucket['last_request'] ? date('c', (int)$bucket['last_request']) : null,
            'is_rate_limited' => ($bucket['level'] >= $capacity)
        ];
    }

    public function updateFromHeaders(string $key, array $headers): void
    {
        $headerMappings = $this->config->getHeaderMappings();
        
        // Update dynamic limit from headers
        if (!empty($headerMappings['limit']) && isset($headers[$headerMappings['limit']])) {
            $headerLimit = (float)$headers[$headerMappings['limit']];
            if ($headerLimit > 0) {
                $newLimit = $this->config->applySafetyBuffer($headerLimit);
                $this->dynamicLimits[$key] = $newLimit;
                
                $this->logger->info("Updated leaky bucket rate from header", [
                    'key' => $key,
                    'header_limit' => $headerLimit,
                    'applied_rate' => $newLimit,
                    'safety_buffer' => $this->config->getSafetyBuffer()
                ]);
            }
        }
        
        // For leaky bucket, "remaining" doesn't directly map, but we can use it to infer current usage
        if (!empty($headerMappings['remaining']) && isset($headers[$headerMappings['remaining']])) {
            $remaining = (int)$headers[$headerMappings['remaining']];
            $this->initializeBucket($key);
            
            // If API indicates we have fewer requests remaining than we think,
            // we can increase our bucket level to be more conservative
            $capacity = $this->getBucketCapacity($key);
            $currentLevel = $this->buckets[$key]['level'];
            
            // This is a rough heuristic - adjust bucket level based on remaining ratio
            if ($remaining < $capacity * 0.9) { // If less than 90% remaining
                $suggestedLevel = $capacity * (1 - $remaining / $capacity);
                if ($suggestedLevel > $currentLevel) {
                    $this->buckets[$key]['level'] = min($capacity, $suggestedLevel);
                    
                    $this->logger->debug("Adjusted leaky bucket level from remaining header", [
                        'key' => $key,
                        'api_remaining' => $remaining,
                        'old_level' => $currentLevel,
                        'new_level' => $this->buckets[$key]['level']
                    ]);
                }
            }
        }
        
        $this->saveState();
    }

    /**
     * Initialize bucket for a key if not exists
     */
    private function initializeBucket(string $key): void
    {
        if (!isset($this->buckets[$key])) {
            $this->buckets[$key] = [
                'level' => 0.0,
                'last_leak' => microtime(true),
                'last_request' => null
            ];
            
            $this->logger->debug("Initialized leaky bucket", [
                'key' => $key,
                'capacity' => $this->getBucketCapacity($key),
                'leak_rate' => $this->getEffectiveRate($key)
            ]);
        }
    }

    /**
     * Process leakage from the bucket
     */
    private function processLeakage(string $key): void
    {
        $bucket = &$this->buckets[$key];
        $now = microtime(true);
        $elapsed = $now - $bucket['last_leak'];
        
        if ($elapsed > 0 && $bucket['level'] > 0) {
            $leakRate = $this->getEffectiveRate($key);
            $leakage = $elapsed * $leakRate;
            
            $oldLevel = $bucket['level'];
            $bucket['level'] = max(0, $bucket['level'] - $leakage);
            $bucket['last_leak'] = $now;
            
            if ($oldLevel !== $bucket['level']) {
                $this->logger->debug("Leaky bucket processed leakage", [
                    'key' => $key,
                    'elapsed_sec' => round($elapsed, 3),
                    'leak_rate' => $leakRate,
                    'leakage' => round($leakage, 3),
                    'old_level' => round($oldLevel, 2),
                    'new_level' => round($bucket['level'], 2)
                ]);
            }
        }
    }

    /**
     * Get effective leak rate for a key
     */
    private function getEffectiveRate(string $key): float
    {
        // Check for dynamic limit first
        if (isset($this->dynamicLimits[$key])) {
            return $this->dynamicLimits[$key];
        }
        
        // Check for endpoint-specific limit
        $endpointLimit = $this->config->getEndpointLimit($key);
        if ($endpointLimit !== null) {
            return $this->config->applySafetyBuffer($endpointLimit);
        }
        
        // Fall back to default rate
        return $this->config->applySafetyBuffer($this->config->getRatePerSecond());
    }

    /**
     * Get bucket capacity for a key
     */
    private function getBucketCapacity(string $key): int
    {
        // For leaky bucket, capacity is usually the burst capacity
        return max(1, $this->config->getBurstCapacity());
    }

    /**
     * Load state from persistent storage
     */
    private function loadState(): void
    {
        $stateFile = $this->getStateFilePath();
        
        if ($stateFile && file_exists($stateFile)) {
            try {
                $data = json_decode(file_get_contents($stateFile), true);
                if (is_array($data)) {
                    $this->buckets = $data['buckets'] ?? [];
                    $this->dynamicLimits = $data['dynamic_limits'] ?? [];
                    
                    // Process leakage for all buckets to account for downtime
                    foreach (array_keys($this->buckets) as $key) {
                        $this->processLeakage($key);
                    }
                    
                    // Clean old entries
                    $this->cleanOldBuckets();
                    
                    $this->logger->debug("Loaded leaky bucket state", [
                        'buckets_count' => count($this->buckets),
                        'dynamic_limits_count' => count($this->dynamicLimits)
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->warning("Failed to load leaky bucket state", [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Save state to persistent storage
     */
    private function saveState(): void
    {
        if (!$this->config->shouldPersistState()) {
            return;
        }
        
        $stateFile = $this->getStateFilePath();
        if (!$stateFile) {
            return;
        }
        
        try {
            $dir = dirname($stateFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $data = [
                'buckets' => $this->buckets,
                'dynamic_limits' => $this->dynamicLimits,
                'timestamp' => microtime(true)
            ];
            
            file_put_contents($stateFile, json_encode($data, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            $this->logger->warning("Failed to save leaky bucket state", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get full path to state file
     */
    private function getStateFilePath(): ?string
    {
        $configFile = $this->config->getStateFile();
        if (!$configFile) {
            return null;
        }
        
        if ($configFile[0] !== '/') {
            return getcwd() . '/' . ltrim($configFile, '/');
        }
        
        return $configFile;
    }

    /**
     * Clean old buckets to prevent memory leaks
     */
    private function cleanOldBuckets(): void
    {
        $cutoff = microtime(true) - 3600; // 1 hour
        
        foreach ($this->buckets as $key => $bucket) {
            $lastActivity = max($bucket['last_leak'], $bucket['last_request'] ?? 0);
            
            // Only remove if bucket is empty and hasn't been used recently
            if ($bucket['level'] <= 0 && $lastActivity < $cutoff) {
                unset($this->buckets[$key]);
            }
        }
    }
}