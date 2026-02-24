<?php

declare(strict_types=1);

namespace Four\RateLimit\Algorithm;

use Four\RateLimit\AbstractRateLimiter;
use Four\RateLimit\RateLimitConfiguration;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Token Bucket Rate Limiter
 *
 * Implementiert den Token-Bucket-Algorithmus für Rate Limiting.
 * Erlaubt Bursts bis zur Bucket-Kapazität, danach Beschränkung auf die Nachfüllrate.
 * Ideal für APIs, die anfängliche Bursts und dann gleichmäßigen Fluss erlauben.
 *
 * $this->state[$key] = ['tokens' => float, 'capacity' => int, 'last_refill' => float, 'last_request' => ?float]
 */
class TokenBucketRateLimiter extends AbstractRateLimiter
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
        return 'four_rl_tb_' . $this->getCacheKeySuffix();
    }

    public function isAllowed(string $key, int $tokens = 1): bool
    {
        $this->initializeBucket($key);
        $this->refillBucket($key);

        $bucket = &$this->state[$key];

        if ($bucket['tokens'] >= $tokens) {
            $bucket['tokens'] -= $tokens;
            $bucket['last_request'] = microtime(true);
            $this->markDirty();

            $this->logger->debug("Rate limit allowed", [
                'key' => $key,
                'tokens_requested' => $tokens,
                'tokens_remaining' => $bucket['tokens'],
            ]);

            return true;
        }

        $this->logger->debug("Rate limit exceeded", [
            'key' => $key,
            'tokens_requested' => $tokens,
            'tokens_available' => $bucket['tokens'],
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
            $waitTimeMs = min($this->getWaitTime($key), 1000);
            if ($waitTimeMs > 0) {
                usleep($waitTimeMs * 1000);
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
        $this->initializeBucket($key);
        $this->refillBucket($key);

        $bucket = $this->state[$key];

        if ($bucket['tokens'] >= 1) {
            return 0;
        }

        $tokensNeeded = 1 - $bucket['tokens'];
        $refillRate = $this->getEffectiveRate($key);

        if ($refillRate <= 0) {
            return 30000;
        }

        return (int)ceil(($tokensNeeded / $refillRate) * 1000);
    }

    public function reset(string $key): void
    {
        $this->initializeBucket($key);
        $bucket = &$this->state[$key];
        $bucket['tokens'] = $bucket['capacity'];
        $bucket['last_refill'] = microtime(true);
        $this->markDirty();

        $this->logger->info("Rate limiter reset", ['key' => $key]);
    }

    public function getStatus(string $key): array
    {
        $this->initializeBucket($key);
        $this->refillBucket($key);

        $bucket = $this->state[$key];

        // Inline wait-time-Berechnung (vermeidet doppeltes initializeBucket/refillBucket)
        $waitTimeMs = 0;
        if ($bucket['tokens'] < 1) {
            $tokensNeeded = 1 - $bucket['tokens'];
            $refillRate = $this->getEffectiveRate($key);
            $waitTimeMs = $refillRate > 0 ? (int)ceil(($tokensNeeded / $refillRate) * 1000) : 30000;
        }

        return [
            'algorithm' => 'token_bucket',
            'key' => $key,
            'tokens_available' => round($bucket['tokens'], 2),
            'capacity' => $bucket['capacity'],
            'rate_per_second' => $this->getEffectiveRate($key),
            'wait_time_ms' => $waitTimeMs,
            'last_refill' => date('c', (int)$bucket['last_refill']),
            'last_request' => $bucket['last_request'] ? date('c', (int)$bucket['last_request']) : null,
            'is_rate_limited' => $bucket['tokens'] < 1,
        ];
    }

    public function updateFromHeaders(string $key, array $headers): void
    {
        $headerMappings = $this->config->getHeaderMappings();

        if (!empty($headerMappings[RateLimitConfiguration::HEADER_LIMIT]) && isset($headers[$headerMappings[RateLimitConfiguration::HEADER_LIMIT]])) {
            $headerLimit = (float)$headers[$headerMappings[RateLimitConfiguration::HEADER_LIMIT]];
            if ($headerLimit > 0) {
                $newLimit = $this->config->applySafetyBuffer($headerLimit);
                $this->dynamicLimits[$key] = $newLimit;
                $this->initializeBucket($key);
                $this->state[$key]['capacity'] = (int)$newLimit;
                $this->markDirty();
            }
        }

        if (!empty($headerMappings[RateLimitConfiguration::HEADER_REMAINING]) && isset($headers[$headerMappings[RateLimitConfiguration::HEADER_REMAINING]])) {
            $remaining = (int)$headers[$headerMappings[RateLimitConfiguration::HEADER_REMAINING]];
            $this->initializeBucket($key);
            if ($remaining < $this->state[$key]['tokens']) {
                $this->state[$key]['tokens'] = $remaining;
                $this->markDirty();
            }
        }
    }

    /**
     * Bucket für einen Key initialisieren falls noch nicht vorhanden.
     *
     * C-03 Fix: burstCapacity ist die Bucket-Kapazität (Obergrenze), nicht max(burst, rate).
     * Die rate ist nur die Nachfüllrate pro Sekunde.
     */
    private function initializeBucket(string $key): void
    {
        if (!isset($this->state[$key])) {
            // C-03 Fix: capacity = burstCapacity (Obergrenze des Buckets)
            $capacity = $this->config->getBurstCapacity();

            $this->state[$key] = [
                'tokens' => (float)$capacity,
                'capacity' => $capacity,
                'last_refill' => microtime(true),
                'last_request' => null,
            ];
        }
    }

    private function refillBucket(string $key): void
    {
        $bucket = &$this->state[$key];
        $now = microtime(true);
        $elapsed = $now - $bucket['last_refill'];

        if ($elapsed > 0) {
            $tokensToAdd = $elapsed * $this->getEffectiveRate($key);
            $bucket['tokens'] = min((float)$bucket['capacity'], $bucket['tokens'] + $tokensToAdd);
            $bucket['last_refill'] = $now;
        }
    }

    private function getEffectiveRate(string $key): float
    {
        if (isset($this->dynamicLimits[$key])) {
            return $this->dynamicLimits[$key];
        }

        $endpointLimit = $this->config->getEndpointLimit($key);
        if ($endpointLimit !== null) {
            return $this->config->applySafetyBuffer($endpointLimit);
        }

        return $this->config->applySafetyBuffer($this->config->getRatePerSecond());
    }

    protected function doCleanOldEntries(float $cutoff): void
    {
        foreach ($this->state as $key => $bucket) {
            if ($bucket['last_refill'] < $cutoff && ($bucket['last_request'] ?? 0) < $cutoff) {
                unset($this->state[$key]);
            }
        }
    }
}
