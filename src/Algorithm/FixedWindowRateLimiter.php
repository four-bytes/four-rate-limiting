<?php

declare(strict_types=1);

namespace Four\RateLimit\Algorithm;

use Four\RateLimit\AbstractRateLimiter;
use Four\RateLimit\RateLimitConfiguration;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Fixed Window Rate Limiter
 *
 * Implementiert Fixed-Window-Rate-Limiting (z. B. 1000 Requests pro Stunde).
 * Setzt den Zähler in festen Intervallen zurück. Gut für APIs mit täglichen/stündlichen Limits.
 * Ideal für eBay API mit täglichen/stündlichen Rate Limits.
 *
 * $this->state[$key] = ['count' => int, 'window_start' => float, 'window_end' => float, 'last_request' => ?float]
 *
 * @warning Bekanntes Bunny-Hop-Problem: Requests können sich am Fenster-Ende häufen,
 *          was kurzzeitig bis zu 2× der konfigurierten Rate ermöglicht.
 *          Für gleichmäßigeren Fluss SlidingWindowRateLimiter verwenden.
 */
class FixedWindowRateLimiter extends AbstractRateLimiter
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
        return 'four_rl_fw_' . $this->getCacheKeySuffix();
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
        $this->checkWindowReset($key);

        $window = &$this->state[$key];
        $limit = $this->getEffectiveLimit($key);

        if ($window['count'] + $tokens <= $limit) {
            $window['count'] += $tokens;
            $window['last_request'] = microtime(true);
            $this->markDirty();

            $this->logger->debug("Fixed window rate limit allowed", [
                'key' => $key,
                'tokens_requested' => $tokens,
                'current_count' => $window['count'],
                'limit' => $limit,
                'window_start' => date('c', (int)$window['window_start']),
            ]);

            return true;
        }

        $this->logger->debug("Fixed window rate limit exceeded", [
            'key' => $key,
            'tokens_requested' => $tokens,
            'current_count' => $window['count'],
            'limit' => $limit,
            'window_resets_at' => date('c', (int)$window['window_end']),
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

            $waitTimeMs = min($this->getWaitTime($key), 5000);
            if ($waitTimeMs > 0) {
                usleep(min($waitTimeMs * 1000, 1000000));
            } else {
                // Busy-Loop-Guard: mindestens 1ms warten wenn getWaitTime() 0 sagt
                // aber isAllowed() trotzdem false (Race-Condition mit Refill)
                usleep(1000);
            }
        }

        return false;
    }

    public function getWaitTime(string $key): int
    {
        $this->initializeWindow($key);
        $this->checkWindowReset($key);

        $window = $this->state[$key];
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
        $window = &$this->state[$key];

        $now = microtime(true);
        $window['count'] = 0;
        $window['window_start'] = $now;
        $window['window_end'] = $now + $this->config->getWindowSizeMs() / 1000.0;
        $this->markDirty();

        $this->logger->info("Fixed window rate limiter reset", ['key' => $key]);
    }

    public function getStatus(string $key): array
    {
        $this->initializeWindow($key);
        $this->checkWindowReset($key);

        $window = $this->state[$key];
        $limit = $this->getEffectiveLimit($key);

        // Inline wait-time-Berechnung (vermeidet doppeltes initializeWindow/checkWindowReset)
        $waitTimeMs = 0;
        if ($window['count'] >= $limit) {
            $waitTime = $window['window_end'] - microtime(true);
            $waitTimeMs = max(0, (int)ceil($waitTime * 1000));
        }

        return [
            'algorithm' => 'fixed_window',
            'key' => $key,
            'count' => $window['count'],
            'limit' => $limit,
            'remaining' => max(0, $limit - $window['count']),
            'window_start' => date('c', (int)$window['window_start']),
            'window_end' => date('c', (int)$window['window_end']),
            'window_size_ms' => $this->config->getWindowSizeMs(),
            'wait_time_ms' => $waitTimeMs,
            'last_request' => $window['last_request'] ? date('c', (int)$window['last_request']) : null,
            'is_rate_limited' => $window['count'] >= $limit,
        ];
    }

    public function updateFromHeaders(string $key, array $headers): void
    {
        $headers = $this->flattenHeaders($headers);
        $headerMappings = $this->config->getHeaderMappings();
        $updated = false;

        // Tägliche Limits prüfen (eBay-Stil)
        if (!empty($headerMappings[RateLimitConfiguration::HEADER_DAILY_LIMIT]) && isset($headers[$headerMappings[RateLimitConfiguration::HEADER_DAILY_LIMIT]])) {
            $dailyLimit = (int)$headers[$headerMappings[RateLimitConfiguration::HEADER_DAILY_LIMIT]];
            if ($dailyLimit > 0) {
                $newLimit = $this->config->applySafetyBuffer($dailyLimit / 86400.0);
                $this->dynamicLimits[$key . '_daily'] = $newLimit;
                $updated = true;

                $this->logger->info("Updated daily rate limit from header", [
                    'key' => $key,
                    'daily_limit' => $dailyLimit,
                    'per_second_limit' => $newLimit,
                ]);
            }
        }

        // Stündliche Limits prüfen
        if (!empty($headerMappings[RateLimitConfiguration::HEADER_HOURLY_LIMIT]) && isset($headers[$headerMappings[RateLimitConfiguration::HEADER_HOURLY_LIMIT]])) {
            $hourlyLimit = (int)$headers[$headerMappings[RateLimitConfiguration::HEADER_HOURLY_LIMIT]];
            if ($hourlyLimit > 0) {
                $newLimit = $this->config->applySafetyBuffer($hourlyLimit / 3600.0);
                $this->dynamicLimits[$key . '_hourly'] = $newLimit;
                $updated = true;

                $this->logger->info("Updated hourly rate limit from header", [
                    'key' => $key,
                    'hourly_limit' => $hourlyLimit,
                    'per_second_limit' => $newLimit,
                ]);
            }
        }

        // Verbleibende tägliche Requests aus Header übernehmen
        if (!empty($headerMappings[RateLimitConfiguration::HEADER_DAILY_REMAINING]) && isset($headers[$headerMappings[RateLimitConfiguration::HEADER_DAILY_REMAINING]])) {
            $remaining = (int)$headers[$headerMappings[RateLimitConfiguration::HEADER_DAILY_REMAINING]];
            $this->initializeWindow($key);

            $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;
            $dailyWindowRatio = $windowSizeSec / 86400.0;
            $windowRemaining = (int)ceil($remaining * $dailyWindowRatio);

            $currentRemaining = $this->getEffectiveLimit($key) - $this->state[$key]['count'];
            if ($windowRemaining < $currentRemaining) {
                $this->state[$key]['count'] = max(0, $this->getEffectiveLimit($key) - $windowRemaining);
                $updated = true;

                $this->logger->debug("Updated count from remaining header", [
                    'key' => $key,
                    'daily_remaining' => $remaining,
                    'window_remaining' => $windowRemaining,
                    'updated_count' => $this->state[$key]['count'],
                ]);
            }
        }

        if ($updated) {
            $this->markDirty();
        }
    }

    private function initializeWindow(string $key): void
    {
        if (!isset($this->state[$key])) {
            $now = microtime(true);
            $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;

            $this->state[$key] = [
                'count' => 0,
                'window_start' => $now,
                'window_end' => $now + $windowSizeSec,
                'last_request' => null,
            ];

            $this->logger->debug("Initialized fixed window", [
                'key' => $key,
                'window_size_ms' => $this->config->getWindowSizeMs(),
                'limit' => $this->getEffectiveLimit($key),
            ]);
        }
    }

    private function checkWindowReset(string $key): void
    {
        $window = &$this->state[$key];
        $now = microtime(true);

        if ($now >= $window['window_end']) {
            $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;
            $window['count'] = 0;
            $window['window_start'] = $now;
            $window['window_end'] = $now + $windowSizeSec;

            $this->logger->debug("Fixed window reset", [
                'key' => $key,
                'new_window_end' => date('c', (int)$window['window_end']),
            ]);

            $this->markDirty();
        }
    }

    private function getEffectiveLimit(string $key): int
    {
        $dynamicLimit = $this->dynamicLimits[$key] ?? null;
        if ($dynamicLimit !== null) {
            $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;
            return max(1, (int)ceil($dynamicLimit * $windowSizeSec));
        }

        $endpointLimit = $this->config->getEndpointLimit($key);
        if ($endpointLimit !== null) {
            $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;
            return max(1, (int)ceil($this->config->applySafetyBuffer($endpointLimit) * $windowSizeSec));
        }

        $windowSizeSec = $this->config->getWindowSizeMs() / 1000.0;
        $rate = $this->config->applySafetyBuffer($this->config->getRatePerSecond());
        return max(1, (int)ceil($rate * $windowSizeSec));
    }

    protected function doCleanOldEntries(float $cutoff): void
    {
        foreach ($this->state as $key => $window) {
            if ($window['window_end'] < $cutoff) {
                unset($this->state[$key]);
            }
        }
    }
}
