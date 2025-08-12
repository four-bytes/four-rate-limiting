<?php

declare(strict_types=1);

namespace Four\RateLimit\Algorithm;

use Four\RateLimit\RateLimiterInterface;
use Four\RateLimit\RateLimitConfiguration;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fixed Window Rate Limiter
 *
 * Implements fixed window rate limiting (e.g., 1000 requests per hour).
 * Resets counter at fixed intervals. Good for APIs with daily/hourly limits.
 * Ideal for eBay API with daily/hourly rate limits.
 */
class FixedWindowRateLimiter implements RateLimiterInterface
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
        $this->checkWindowReset($key);
        
        $window = &$this->windows[$key];
        $limit = $this->getEffectiveLimit($key);
        
        if ($window['count'] + $tokens <= $limit) {
            $window['count'] += $tokens;
            $window['last_request'] = microtime(true);
            
            $this->saveState();
            
            $this->logger->debug("Fixed window rate limit allowed", [
                'key' => $key,
                'tokens_requested' => $tokens,
                'current_count' => $window['count'],
                'limit' => $limit,
                'window_start' => date('c', (int)$window['window_start'])
            ]);
            
            return true;
        }
        
        $this->logger->debug("Fixed window rate limit exceeded", [
            'key' => $key,
            'tokens_requested' => $tokens,
            'current_count' => $window['count'],
            'limit' => $limit,
            'window_resets_at' => date('c', (int)$window['window_end']),
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
            
            $waitTimeMs = min($this->getWaitTime($key), 5000); // Max 5 second wait between checks
            if ($waitTimeMs > 0) {
                usleep(min($waitTimeMs * 1000, 1000000)); // Max 1 second sleep
            }
        }
        
        $this->logger->warning("Fixed window rate limit wait timeout", [
            'key' => $key,
            'tokens' => $tokens,
            'max_wait_ms' => $maxWaitMs
        ]);
        
        return false;
    }

    public function getWaitTime(string $key): int
    {
        $this->initializeWindow($key);
        $this->checkWindowReset($key);
        
        $window = $this->windows[$key];
        $limit = $this->getEffectiveLimit($key);
        
        if ($window['count'] < $limit) {
            return 0;
        }
        
        $waitTime = $window['window_end'] - microtime(true);
        return max(0, (int)ceil($waitTime * 1000));
    }

    public function reset(string $key): void
    {
        $this->initializeWindow($key);
        $window = &$this->windows[$key];
        
        $now = microtime(true);
        $window['count'] = 0;
        $window['window_start'] = $now;
        $window['window_end'] = $now + $this->config->getWindowSizeMs() / 1000.0;
        
        $this->saveState();
        
        $this->logger->info("Fixed window rate limiter reset", ['key' => $key]);
    }

    public function getStatus(string $key): array
    {
        $this->initializeWindow($key);
        $this->checkWindowReset($key);
        
        $window = $this->windows[$key];
        $limit = $this->getEffectiveLimit($key);
        
        return [
            'algorithm' => 'fixed_window',
            'key' => $key,
            'count' => $window['count'],
            'limit' => $limit,
            'remaining' => max(0, $limit - $window['count']),
            'window_start' => date('c', (int)$window['window_start']),
            'window_end' => date('c', (int)$window['window_end']),
            'window_size_ms' => $this->config->getWindowSizeMs(),
            'wait_time_ms' => $this->getWaitTime($key),
            'last_request' => $window['last_request'] ? date('c', (int)$window['last_request']) : null,
            'is_rate_limited' => $window['count'] >= $limit
        ];
    }

    public function updateFromHeaders(string $key, array $headers): void
    {
        $headerMappings = $this->config->getHeaderMappings();
        
        // Update dynamic limit from headers
        $updated = false;
        
        // Check for daily limits (eBay style)
        if (!empty($headerMappings['daily_limit']) && isset($headers[$headerMappings['daily_limit']])) {
            $dailyLimit = (int)$headers[$headerMappings['daily_limit']];
            if ($dailyLimit > 0) {
                // Convert daily limit to per-second rate
                $newLimit = $this->config->applySafetyBuffer($dailyLimit / 86400.0); // 86400 seconds in a day
                $this->dynamicLimits[$key . '_daily'] = $newLimit;
                $updated = true;
                
                $this->logger->info("Updated daily rate limit from header", [
                    'key' => $key,
                    'daily_limit' => $dailyLimit,
                    'per_second_limit' => $newLimit
                ]);
            }
        }
        
        // Check for hourly limits
        if (!empty($headerMappings['hourly_limit']) && isset($headers[$headerMappings['hourly_limit']])) {
            $hourlyLimit = (int)$headers[$headerMappings['hourly_limit']];
            if ($hourlyLimit > 0) {
                $newLimit = $this->config->applySafetyBuffer($hourlyLimit / 3600.0); // 3600 seconds in an hour
                $this->dynamicLimits[$key . '_hourly'] = $newLimit;
                $updated = true;
                
                $this->logger->info("Updated hourly rate limit from header", [
                    'key' => $key,
                    'hourly_limit' => $hourlyLimit,
                    'per_second_limit' => $newLimit
                ]);
            }
        }
        
        // Update remaining count from headers
        if (!empty($headerMappings['daily_remaining']) && isset($headers[$headerMappings['daily_remaining']])) {
            $remaining = (int)$headers[$headerMappings['daily_remaining']];
            $this->initializeWindow($key);
            
            // Calculate how many requests we can make in current window
            $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;
            $dailyWindowRatio = $windowSizeSec / 86400.0; // What fraction of the day is our window
            $windowRemaining = (int)ceil($remaining * $dailyWindowRatio);
            
            // Only update if header remaining suggests less availability
            $currentRemaining = $this->getEffectiveLimit($key) - $this->windows[$key]['count'];
            if ($windowRemaining < $currentRemaining) {
                $this->windows[$key]['count'] = max(0, $this->getEffectiveLimit($key) - $windowRemaining);
                $updated = true;
                
                $this->logger->debug("Updated count from remaining header", [
                    'key' => $key,
                    'daily_remaining' => $remaining,
                    'window_remaining' => $windowRemaining,
                    'updated_count' => $this->windows[$key]['count']
                ]);
            }
        }
        
        if ($updated) {
            $this->saveState();
        }
    }

    /**
     * Initialize window for a key if not exists
     */
    private function initializeWindow(string $key): void
    {
        if (!isset($this->windows[$key])) {
            $now = microtime(true);
            $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;
            
            $this->windows[$key] = [
                'count' => 0,
                'window_start' => $now,
                'window_end' => $now + $windowSizeSec,
                'last_request' => null
            ];
            
            $this->logger->debug("Initialized fixed window", [
                'key' => $key,
                'window_size_ms' => $this->config->getWindowSizeMs(),
                'limit' => $this->getEffectiveLimit($key)
            ]);
        }
    }

    /**
     * Check if window needs to be reset
     */
    private function checkWindowReset(string $key): void
    {
        $window = &$this->windows[$key];
        $now = microtime(true);
        
        if ($now >= $window['window_end']) {
            // Reset window
            $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;
            $window['count'] = 0;
            $window['window_start'] = $now;
            $window['window_end'] = $now + $windowSizeSec;
            
            $this->logger->debug("Fixed window reset", [
                'key' => $key,
                'new_window_end' => date('c', (int)$window['window_end'])
            ]);
            
            $this->saveState();
        }
    }

    /**
     * Get effective limit for a key
     */
    private function getEffectiveLimit(string $key): int
    {
        // Check for dynamic limits first
        $dynamicLimit = $this->dynamicLimits[$key] ?? null;
        if ($dynamicLimit !== null) {
            $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;
            return max(1, (int)ceil($dynamicLimit * $windowSizeSec));
        }
        
        // Check for endpoint-specific limit
        $endpointLimit = $this->config->getEndpointLimit($key);
        if ($endpointLimit !== null) {
            $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;
            return max(1, (int)ceil($this->config->applySafetyBuffer($endpointLimit) * $windowSizeSec));
        }
        
        // Fall back to default rate
        $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;
        $rate = $this->config->applySafetyBuffer($this->config->getRatePerSecond());
        return max(1, (int)ceil($rate * $windowSizeSec));
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
                    
                    // Clean expired windows
                    $this->cleanExpiredWindows();
                    
                    $this->logger->debug("Loaded fixed window state", [
                        'windows_count' => count($this->windows),
                        'dynamic_limits_count' => count($this->dynamicLimits)
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->warning("Failed to load fixed window state", [
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
            $this->logger->warning("Failed to save fixed window state", [
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
     * Clean expired windows to prevent memory leaks
     */
    private function cleanExpiredWindows(): void
    {
        $now = microtime(true);
        
        foreach ($this->windows as $key => $window) {
            // Remove windows that ended more than 1 hour ago
            if ($window['window_end'] < ($now - 3600)) {
                unset($this->windows[$key]);
            }
        }
    }
}