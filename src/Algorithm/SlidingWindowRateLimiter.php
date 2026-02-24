<?php

declare(strict_types=1);

namespace Four\RateLimit\Algorithm;

use Four\RateLimit\AbstractRateLimiter;
use Four\RateLimit\RateLimitConfiguration;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Sliding Window Rate Limiter
 *
 * Implementiert Sliding-Window-Rate-Limiting für gleichmäßigere Rate-Verteilung.
 * Führt ein rollierendes Zeitfenster mit Requests. Ideal für Discogs API.
 *
 * $this->state[$key] = ['requests' => float[], 'last_request' => ?float]
 *
 * TODO T-03: Bei sehr hoher Last circular buffer erwägen, um array_filter-Overhead zu vermeiden.
 */
class SlidingWindowRateLimiter extends AbstractRateLimiter
{
    public function __construct(
        RateLimitConfiguration $config,
        LoggerInterface $logger = new NullLogger(),
        ?CacheInterface $cache = null,
    ) {
        parent::__construct($config, $logger, $cache);
    }

    protected function getStateKey(): string
    {
        return 'four_rate_limit_sliding_window';
    }

    protected function hydrateState(array $data): void
    {
        // Rückwärtskompatibilität: alte State-Dateien nutzen 'windows', neue 'state'
        $this->state = $data['windows'] ?? $data['state'] ?? [];
        $this->dynamicLimits = $data['dynamic_limits'] ?? [];
        $this->cleanOldEntries();
    }

    protected function extractState(): array
    {
        return [
            'windows' => $this->state,
            'dynamic_limits' => $this->dynamicLimits,
        ];
    }

    public function isAllowed(string $key, int $tokens = 1): bool
    {
        $this->initializeWindow($key);
        $this->cleanExpiredRequests($key);

        $window = &$this->state[$key];
        $limit = $this->getEffectiveLimit($key);

        if (count($window['requests']) + $tokens <= $limit) {
            $now = microtime(true);

            for ($i = 0; $i < $tokens; $i++) {
                $window['requests'][] = $now;
            }
            $window['last_request'] = $now;
            $this->markDirty();

            $this->logger->debug("Sliding window rate limit allowed", [
                'key' => $key,
                'tokens_requested' => $tokens,
                'current_count' => count($window['requests']),
                'limit' => $limit,
                'oldest_request' => !empty($window['requests']) ? date('c', (int)min($window['requests'])) : null,
            ]);

            return true;
        }

        $this->logger->debug("Sliding window rate limit exceeded", [
            'key' => $key,
            'tokens_requested' => $tokens,
            'current_count' => count($window['requests']),
            'limit' => $limit,
            'wait_time_ms' => $this->getWaitTime($key),
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

            $waitTimeMs = min($this->getWaitTime($key), 2000);
            if ($waitTimeMs > 0) {
                usleep($waitTimeMs * 1000);
            }
        }

        return false;
    }

    public function getWaitTime(string $key): int
    {
        $this->initializeWindow($key);
        $this->cleanExpiredRequests($key);

        $window = $this->state[$key];
        $limit = $this->getEffectiveLimit($key);

        if (count($window['requests']) < $limit) {
            return 0;
        }

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
        $this->state[$key]['requests'] = [];
        $this->markDirty();

        $this->logger->info("Sliding window rate limiter reset", ['key' => $key]);
    }

    public function getStatus(string $key): array
    {
        $this->initializeWindow($key);
        $this->cleanExpiredRequests($key);

        $window = $this->state[$key];
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
            'is_rate_limited' => $requestCount >= $limit,
        ];
    }

    public function updateFromHeaders(string $key, array $headers): void
    {
        $headerMappings = $this->config->getHeaderMappings();

        if (!empty($headerMappings[RateLimitConfiguration::HEADER_LIMIT]) && isset($headers[$headerMappings[RateLimitConfiguration::HEADER_LIMIT]])) {
            $headerLimit = (int)$headers[$headerMappings[RateLimitConfiguration::HEADER_LIMIT]];
            if ($headerLimit > 0) {
                $newLimit = (int)floor($this->config->applySafetyBuffer($headerLimit));
                $this->dynamicLimits[$key] = $newLimit;
                $this->markDirty();

                $this->logger->info("Updated sliding window limit from header", [
                    'key' => $key,
                    'header_limit' => $headerLimit,
                    'applied_limit' => $newLimit,
                    'safety_buffer' => $this->config->getSafetyBuffer(),
                ]);
            }
        }

        if (!empty($headerMappings[RateLimitConfiguration::HEADER_REMAINING]) && isset($headers[$headerMappings[RateLimitConfiguration::HEADER_REMAINING]])) {
            $remaining = (int)$headers[$headerMappings[RateLimitConfiguration::HEADER_REMAINING]];
            $this->initializeWindow($key);

            $currentCount = count($this->state[$key]['requests']);
            $limit = $this->getEffectiveLimit($key);
            $expectedRemaining = $limit - $currentCount;

            if ($remaining < $expectedRemaining) {
                $excessRequests = $expectedRemaining - $remaining;
                $now = microtime(true);

                for ($i = 0; $i < $excessRequests; $i++) {
                    $this->state[$key]['requests'][] = $now - ($i * 0.001);
                }
                $this->markDirty();

                $this->logger->debug("Adjusted sliding window count from remaining header", [
                    'key' => $key,
                    'api_remaining' => $remaining,
                    'our_remaining' => $expectedRemaining,
                    'added_requests' => $excessRequests,
                    'new_count' => count($this->state[$key]['requests']),
                ]);
            }
        }
    }

    private function initializeWindow(string $key): void
    {
        if (!isset($this->state[$key])) {
            $this->state[$key] = [
                'requests' => [],
                'last_request' => null,
            ];

            $this->logger->debug("Initialized sliding window", [
                'key' => $key,
                'window_size_ms' => $this->config->getWindowSizeMs(),
                'limit' => $this->getEffectiveLimit($key),
            ]);
        }
    }

    private function cleanExpiredRequests(string $key): void
    {
        $window = &$this->state[$key];
        $now = microtime(true);
        $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;
        $cutoff = $now - $windowSizeSec;

        $originalCount = count($window['requests']);
        // TODO T-03: Bei sehr hoher Last circular buffer erwägen
        $window['requests'] = array_filter($window['requests'], fn($timestamp) => $timestamp > $cutoff);

        $removedCount = $originalCount - count($window['requests']);
        if ($removedCount > 0) {
            $this->logger->debug("Cleaned expired requests from sliding window", [
                'key' => $key,
                'removed_count' => $removedCount,
                'remaining_count' => count($window['requests']),
                'cutoff' => date('c', (int)$cutoff),
            ]);
        }
    }

    private function getEffectiveLimit(string $key): int
    {
        if (isset($this->dynamicLimits[$key])) {
            return $this->dynamicLimits[$key];
        }

        $endpointLimit = $this->config->getEndpointLimit($key);
        if ($endpointLimit !== null) {
            $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;
            return max(1, (int)floor($this->config->applySafetyBuffer($endpointLimit) * $windowSizeSec));
        }

        $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;
        $rate = $this->config->applySafetyBuffer($this->config->getRatePerSecond());
        return max(1, (int)floor($rate * $windowSizeSec));
    }

    protected function doCleanOldEntries(float $cutoff): void
    {
        foreach ($this->state as $key => $window) {
            $lastRequest = $window['last_request'] ?? 0;
            $hasRecentRequests = !empty($window['requests']) && max($window['requests']) >= $cutoff;

            if ($lastRequest < $cutoff && !$hasRecentRequests) {
                unset($this->state[$key]);
            }
        }
    }
}
