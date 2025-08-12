<?php

declare(strict_types=1);

namespace Four\RateLimit\Algorithm;

use Four\RateLimit\RateLimiterInterface;
use Four\RateLimit\RateLimitConfiguration;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Sliding Window Rate Limiter
 *
 * Implements sliding window rate limiting for smoother rate distribution.
 * Maintains a rolling window of requests. Ideal for Discogs API.
 */
class SlidingWindowRateLimiter implements RateLimiterInterface
{
    private array $windows = [];
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
        $this->initializeWindow($key);
        $this->cleanExpiredRequests($key);
        
        $window = &$this->windows[$key];
        $limit = $this->getEffectiveLimit($key);
        
        if (count($window['requests']) + $tokens <= $limit) {
            $now = microtime(true);
            
            // Add the new requests
            for ($i = 0; $i < $tokens; $i++) {
                $window['requests'][] = $now;
            }
            $window['last_request'] = $now;
            
            $this->saveState();
            
            $this->logger->debug("Sliding window rate limit allowed", [
                'key' => $key,
                'tokens_requested' => $tokens,
                'current_count' => count($window['requests']),
                'limit' => $limit,
                'oldest_request' => !empty($window['requests']) ? date('c', (int)min($window['requests'])) : null
            ]);
            
            return true;
        }
        
        $this->logger->debug("Sliding window rate limit exceeded", [
            'key' => $key,
            'tokens_requested' => $tokens,
            'current_count' => count($window['requests']),
            'limit' => $limit,
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
            
            $waitTimeMs = min($this->getWaitTime($key), 2000); // Max 2 second wait between checks
            if ($waitTimeMs > 0) {
                usleep($waitTimeMs * 1000);
            }
        }
        
        $this->logger->warning("Sliding window rate limit wait timeout", [
            'key' => $key,
            'tokens' => $tokens,
            'max_wait_ms' => $maxWaitMs
        ]);
        
        return false;
    }

    public function getWaitTime(string $key): int
    {
        $this->initializeWindow($key);
        $this->cleanExpiredRequests($key);
        
        $window = $this->windows[$key];
        $limit = $this->getEffectiveLimit($key);
        
        if (count($window['requests']) < $limit) {
            return 0;
        }
        
        // Find the oldest request that needs to expire
        if (empty($window['requests'])) {
            return 0;
        }
        
        $oldestRequest = min($window['requests']);
        $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;
        $expiryTime = $oldestRequest + $windowSizeSec;
        $waitTime = $expiryTime - microtime(true);
        
        return max(0, (int)ceil($waitTime * 1000));
    }

    public function reset(string $key): void
    {
        $this->initializeWindow($key);
        $this->windows[$key]['requests'] = [];
        
        $this->saveState();
        
        $this->logger->info("Sliding window rate limiter reset", ['key' => $key]);
    }

    public function getStatus(string $key): array
    {
        $this->initializeWindow($key);
        $this->cleanExpiredRequests($key);
        
        $window = $this->windows[$key];
        $limit = $this->getEffectiveLimit($key);
        $requestCount = count($window['requests']);
        
        return [
            'algorithm' => 'sliding_window',
            'key' => $key,
            'current_count' => $requestCount,
            'limit' => $limit,
            'remaining' => max(0, $limit - $requestCount),
            'window_size_ms' => $this->config->getWindowSizeMs(),
            'oldest_request' => !empty($window['requests']) ? date('c', (int)min($window['requests'])) : null,
            'newest_request' => !empty($window['requests']) ? date('c', (int)max($window['requests'])) : null,
            'wait_time_ms' => $this->getWaitTime($key),
            'last_request' => $window['last_request'] ? date('c', (int)$window['last_request']) : null,
            'is_rate_limited' => $requestCount >= $limit
        ];
    }

    public function updateFromHeaders(string $key, array $headers): void
    {
        $headerMappings = $this->config->getHeaderMappings();
        
        // Update dynamic limit from headers (e.g., Discogs X-Discogs-Ratelimit)
        if (!empty($headerMappings['limit']) && isset($headers[$headerMappings['limit']])) {
            $headerLimit = (int)$headers[$headerMappings['limit']];
            if ($headerLimit > 0) {
                $newLimit = (int)floor($this->config->applySafetyBuffer($headerLimit));
                $this->dynamicLimits[$key] = $newLimit;
                
                $this->logger->info("Updated sliding window limit from header", [
                    'key' => $key,
                    'header_limit' => $headerLimit,
                    'applied_limit' => $newLimit,
                    'safety_buffer' => $this->config->getSafetyBuffer()
                ]);
            }
        }
        
        // Update remaining count from headers (e.g., Discogs X-Discogs-Ratelimit-Remaining)
        if (!empty($headerMappings['remaining']) && isset($headers[$headerMappings['remaining']])) {
            $remaining = (int)$headers[$headerMappings['remaining']];
            $this->initializeWindow($key);
            
            $currentCount = count($this->windows[$key]['requests']);
            $limit = $this->getEffectiveLimit($key);
            $expectedRemaining = $limit - $currentCount;
            
            // If API says we have less remaining than we think, adjust our count
            if ($remaining < $expectedRemaining) {
                $excessRequests = $expectedRemaining - $remaining;
                $now = microtime(true);
                
                // Add phantom requests to align with API's view
                for ($i = 0; $i < $excessRequests; $i++) {
                    $this->windows[$key]['requests'][] = $now - ($i * 0.001); // Slightly staggered
                }
                
                $this->logger->debug("Adjusted sliding window count from remaining header", [
                    'key' => $key,
                    'api_remaining' => $remaining,
                    'our_remaining' => $expectedRemaining,
                    'added_requests' => $excessRequests,
                    'new_count' => count($this->windows[$key]['requests'])
                ]);
            }
        }
        
        $this->saveState();
    }

    /**
     * Initialize window for a key if not exists
     */
    private function initializeWindow(string $key): void
    {
        if (!isset($this->windows[$key])) {
            $this->windows[$key] = [
                'requests' => [],
                'last_request' => null
            ];
            
            $this->logger->debug("Initialized sliding window", [
                'key' => $key,
                'window_size_ms' => $this->config->getWindowSizeMs(),
                'limit' => $this->getEffectiveLimit($key)
            ]);
        }
    }

    /**
     * Clean expired requests from the window
     */
    private function cleanExpiredRequests(string $key): void
    {
        $window = &$this->windows[$key];
        $now = microtime(true);
        $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;
        $cutoff = $now - $windowSizeSec;
        
        $originalCount = count($window['requests']);
        $window['requests'] = array_filter($window['requests'], fn($timestamp) => $timestamp > $cutoff);
        
        $removedCount = $originalCount - count($window['requests']);
        if ($removedCount > 0) {
            $this->logger->debug("Cleaned expired requests from sliding window", [
                'key' => $key,
                'removed_count' => $removedCount,
                'remaining_count' => count($window['requests']),
                'cutoff' => date('c', (int)$cutoff)
            ]);
        }
    }

    /**
     * Get effective limit for a key
     */
    private function getEffectiveLimit(string $key): int
    {
        // Check for dynamic limit first
        if (isset($this->dynamicLimits[$key])) {
            return $this->dynamicLimits[$key];
        }
        
        // Check for endpoint-specific limit
        $endpointLimit = $this->config->getEndpointLimit($key);
        if ($endpointLimit !== null) {
            $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;
            return max(1, (int)floor($this->config->applySafetyBuffer($endpointLimit) * $windowSizeSec));
        }
        
        // Fall back to default rate
        $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;
        $rate = $this->config->applySafetyBuffer($this->config->getRatePerSecond());
        return max(1, (int)floor($rate * $windowSizeSec));
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
                    $this->windows = $data['windows'] ?? [];
                    $this->dynamicLimits = $data['dynamic_limits'] ?? [];
                    
                    // Clean old entries
                    $this->cleanOldWindows();
                    
                    $this->logger->debug("Loaded sliding window state", [
                        'windows_count' => count($this->windows),
                        'dynamic_limits_count' => count($this->dynamicLimits)
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->warning("Failed to load sliding window state", [
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
                'windows' => $this->windows,
                'dynamic_limits' => $this->dynamicLimits,
                'timestamp' => microtime(true)
            ];
            
            file_put_contents($stateFile, json_encode($data, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            $this->logger->warning("Failed to save sliding window state", [
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
     * Clean old windows to prevent memory leaks
     */
    private function cleanOldWindows(): void
    {
        $cutoff = microtime(true) - 3600; // 1 hour
        
        foreach ($this->windows as $key => $window) {
            $lastActivity = $window['last_request'] ?? 0;
            $hasRecentRequests = !empty($window['requests']) && max($window['requests']) > $cutoff;
            
            if ($lastActivity < $cutoff && !$hasRecentRequests) {
                unset($this->windows[$key]);
            }
        }
    }
}