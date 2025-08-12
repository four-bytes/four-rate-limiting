<?php

declare(strict_types=1);

namespace Four\RateLimit\Algorithm;

use Four\RateLimit\RateLimiterInterface;
use Four\RateLimit\RateLimitConfiguration;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Token Bucket Rate Limiter
 *
 * Implements token bucket algorithm for rate limiting.
 * Allows bursts up to bucket capacity, then limits to refill rate.
 * Ideal for APIs that allow initial bursts then steady rate.
 */
class TokenBucketRateLimiter implements RateLimiterInterface
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
        $this->refillBucket($key);
        
        $bucket = &$this->buckets[$key];
        
        if ($bucket['tokens'] >= $tokens) {
            $bucket['tokens'] -= $tokens;
            $bucket['last_request'] = microtime(true);
            
            $this->saveState();
            
            $this->logger->debug("Rate limit allowed", [
                'key' => $key,
                'tokens_requested' => $tokens,
                'tokens_remaining' => $bucket['tokens'],
                'capacity' => $bucket['capacity']
            ]);
            
            return true;
        }
        
        $this->logger->debug("Rate limit exceeded", [
            'key' => $key,
            'tokens_requested' => $tokens,
            'tokens_available' => $bucket['tokens'],
            'capacity' => $bucket['capacity'],
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
            
            $waitTimeMs = min($this->getWaitTime($key), 1000); // Max 1 second wait
            if ($waitTimeMs > 0) {
                usleep($waitTimeMs * 1000); // Convert to microseconds
            }
        }
        
        $this->logger->warning("Rate limit wait timeout", [
            'key' => $key,
            'tokens' => $tokens,
            'max_wait_ms' => $maxWaitMs,
            'actual_wait_ms' => (microtime(true) - $startTime) * 1000
        ]);
        
        return false;
    }

    public function getWaitTime(string $key): int
    {
        $this->initializeBucket($key);
        $this->refillBucket($key);
        
        $bucket = $this->buckets[$key];
        
        if ($bucket['tokens'] >= 1) {
            return 0;
        }
        
        $tokensNeeded = 1 - $bucket['tokens'];
        $refillRate = $this->getEffectiveRate($key);
        
        if ($refillRate <= 0) {
            return 30000; // 30 second fallback
        }
        
        $waitTimeSeconds = $tokensNeeded / $refillRate;
        return (int)ceil($waitTimeSeconds * 1000);
    }

    public function reset(string $key): void
    {
        $this->initializeBucket($key);
        $bucket = &$this->buckets[$key];
        $bucket['tokens'] = $bucket['capacity'];
        $bucket['last_refill'] = microtime(true);
        
        $this->saveState();
        
        $this->logger->info("Rate limiter reset", ['key' => $key]);
    }

    public function getStatus(string $key): array
    {
        $this->initializeBucket($key);
        $this->refillBucket($key);
        
        $bucket = $this->buckets[$key];
        
        return [
            'algorithm' => 'token_bucket',
            'key' => $key,
            'tokens_available' => round($bucket['tokens'], 2),
            'capacity' => $bucket['capacity'],
            'rate_per_second' => $this->getEffectiveRate($key),
            'wait_time_ms' => $this->getWaitTime($key),
            'last_refill' => date('c', (int)$bucket['last_refill']),
            'last_request' => $bucket['last_request'] ? date('c', (int)$bucket['last_request']) : null,
            'is_rate_limited' => $bucket['tokens'] < 1
        ];
    }

    public function updateFromHeaders(string $key, array $headers): void
    {
        $headerMappings = $this->config->getHeaderMappings();
        
        // Update dynamic limit from headers (e.g., Amazon's x-amzn-RateLimit-Limit)
        if (!empty($headerMappings['limit']) && isset($headers[$headerMappings['limit']])) {
            $headerLimit = (float)$headers[$headerMappings['limit']];
            if ($headerLimit > 0) {
                $newLimit = $this->config->applySafetyBuffer($headerLimit);
                $this->dynamicLimits[$key] = $newLimit;
                
                // Update bucket capacity if needed
                $this->initializeBucket($key);
                $this->buckets[$key]['capacity'] = (int)$newLimit;
                
                $this->logger->info("Updated rate limit from header", [
                    'key' => $key,
                    'header_limit' => $headerLimit,
                    'applied_limit' => $newLimit,
                    'safety_buffer' => $this->config->getSafetyBuffer()
                ]);
            }
        }
        
        // Update remaining tokens from headers
        if (!empty($headerMappings['remaining']) && isset($headers[$headerMappings['remaining']])) {
            $remaining = (int)$headers[$headerMappings['remaining']];
            $this->initializeBucket($key);
            
            // Only update if header remaining is less than current tokens (more conservative)
            if ($remaining < $this->buckets[$key]['tokens']) {
                $this->buckets[$key]['tokens'] = $remaining;
                
                $this->logger->debug("Updated tokens from header", [
                    'key' => $key,
                    'header_remaining' => $remaining,
                    'bucket_tokens' => $this->buckets[$key]['tokens']
                ]);
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
            $rate = $this->getEffectiveRate($key);
            $capacity = max($this->config->getBurstCapacity(), (int)ceil($rate));
            
            $this->buckets[$key] = [
                'tokens' => $capacity,
                'capacity' => $capacity,
                'last_refill' => microtime(true),
                'last_request' => null
            ];
            
            $this->logger->debug("Initialized bucket", [
                'key' => $key,
                'capacity' => $capacity,
                'rate_per_second' => $rate
            ]);
        }
    }

    /**
     * Refill tokens in bucket based on elapsed time
     */
    private function refillBucket(string $key): void
    {
        $bucket = &$this->buckets[$key];
        $now = microtime(true);
        $elapsed = $now - $bucket['last_refill'];
        
        if ($elapsed > 0) {
            $tokensToAdd = $elapsed * $this->getEffectiveRate($key);
            $bucket['tokens'] = min($bucket['capacity'], $bucket['tokens'] + $tokensToAdd);
            $bucket['last_refill'] = $now;
        }
    }

    /**
     * Get effective rate for a key (considering dynamic limits and endpoint-specific limits)
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
                    
                    // Clean old entries (older than 1 hour)
                    $this->cleanOldEntries();
                    
                    $this->logger->debug("Loaded rate limiter state", [
                        'buckets_count' => count($this->buckets),
                        'dynamic_limits_count' => count($this->dynamicLimits)
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->warning("Failed to load rate limiter state", [
                    'state_file' => $stateFile,
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
            $this->logger->warning("Failed to save rate limiter state", [
                'state_file' => $stateFile,
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
        
        // Make relative to project root if not absolute
        if ($configFile[0] !== '/') {
            return getcwd() . '/' . ltrim($configFile, '/');
        }
        
        return $configFile;
    }

    /**
     * Clean entries older than 1 hour to prevent memory leaks
     */
    private function cleanOldEntries(): void
    {
        $cutoff = microtime(true) - 3600; // 1 hour
        
        foreach ($this->buckets as $key => $bucket) {
            if ($bucket['last_refill'] < $cutoff && ($bucket['last_request'] ?? 0) < $cutoff) {
                unset($this->buckets[$key]);
            }
        }
    }
}