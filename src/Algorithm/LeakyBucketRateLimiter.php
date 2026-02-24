<?php

declare(strict_types=1);

namespace Four\RateLimit\Algorithm;

use Four\RateLimit\AbstractRateLimiter;
use Four\RateLimit\RateLimitConfiguration;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Leaky Bucket Rate Limiter
 *
 * Implementiert den Leaky-Bucket-Algorithmus für sehr gleichmäßiges Rate Limiting.
 * Requests "lecken" mit konstanter Rate heraus. Ideal für konservative APIs wie Bandcamp.
 * Bietet das gleichmäßigste Traffic-Shaping.
 *
 * Bucket startet leer (level=0), erste Requests sind sofort erlaubt bis zur Kapazität.
 *
 * $this->state[$key] = ['level' => float, 'last_leak' => float, 'last_request' => ?float]
 */
class LeakyBucketRateLimiter extends AbstractRateLimiter
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
        return 'four_rate_limit_leaky_bucket';
    }

    protected function hydrateState(array $data): void
    {
        // Rückwärtskompatibilität: alte State-Dateien nutzen 'buckets', neue 'state'
        $this->state = $data['buckets'] ?? $data['state'] ?? [];
        $this->dynamicLimits = $data['dynamic_limits'] ?? [];
        // Leakage für alle Buckets nachholen (um Downtime zu berücksichtigen)
        foreach (array_keys($this->state) as $key) {
            $this->processLeakage($key);
        }
        $this->cleanOldEntries();
    }

    protected function extractState(): array
    {
        return [
            'buckets' => $this->state,
            'dynamic_limits' => $this->dynamicLimits,
        ];
    }

    public function isAllowed(string $key, int $tokens = 1): bool
    {
        $this->initializeBucket($key);
        $this->processLeakage($key);

        $bucket = &$this->state[$key];
        $capacity = $this->getBucketCapacity($key);

        if ($bucket['level'] + $tokens <= $capacity) {
            $bucket['level'] += $tokens;
            $bucket['last_request'] = microtime(true);
            $this->markDirty();

            $this->logger->debug("Leaky bucket rate limit allowed", [
                'key' => $key,
                'tokens_requested' => $tokens,
                'bucket_level' => $bucket['level'],
                'capacity' => $capacity,
                'leak_rate' => $this->getEffectiveRate($key),
            ]);

            return true;
        }

        $this->logger->debug("Leaky bucket rate limit exceeded", [
            'key' => $key,
            'tokens_requested' => $tokens,
            'bucket_level' => $bucket['level'],
            'capacity' => $capacity,
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

            $waitTimeMs = min($this->getWaitTime($key), 1000);
            if ($waitTimeMs > 0) {
                usleep($waitTimeMs * 1000);
            }
        }

        return false;
    }

    public function getWaitTime(string $key): int
    {
        $this->initializeBucket($key);
        $this->processLeakage($key);

        $bucket = $this->state[$key];
        $capacity = $this->getBucketCapacity($key);
        $tokensNeeded = 1;

        $availableSpace = $capacity - $bucket['level'];
        if ($availableSpace >= $tokensNeeded) {
            return 0;
        }

        $tokensToLeak = $tokensNeeded - $availableSpace;
        $leakRate = $this->getEffectiveRate($key);

        if ($leakRate <= 0) {
            return 30000;
        }

        $waitTimeSeconds = $tokensToLeak / $leakRate;
        return (int)ceil($waitTimeSeconds * 1000);
    }

    public function reset(string $key): void
    {
        $this->initializeBucket($key);
        $bucket = &$this->state[$key];
        $bucket['level'] = 0;
        $bucket['last_leak'] = microtime(true);
        $this->markDirty();

        $this->logger->info("Leaky bucket rate limiter reset", ['key' => $key]);
    }

    public function getStatus(string $key): array
    {
        $this->initializeBucket($key);
        $this->processLeakage($key);

        $bucket = $this->state[$key];
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
            'is_rate_limited' => ($bucket['level'] >= $capacity),
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
                $this->markDirty();

                $this->logger->info("Updated leaky bucket rate from header", [
                    'key' => $key,
                    'header_limit' => $headerLimit,
                    'applied_rate' => $newLimit,
                    'safety_buffer' => $this->config->getSafetyBuffer(),
                ]);
            }
        }

        if (!empty($headerMappings[RateLimitConfiguration::HEADER_REMAINING]) && isset($headers[$headerMappings[RateLimitConfiguration::HEADER_REMAINING]])) {
            $remaining = (int)$headers[$headerMappings[RateLimitConfiguration::HEADER_REMAINING]];
            $this->initializeBucket($key);

            $capacity = $this->getBucketCapacity($key);
            $currentLevel = $this->state[$key]['level'];

            if ($remaining < $capacity * 0.9) {
                $suggestedLevel = $capacity * (1 - $remaining / $capacity);
                if ($suggestedLevel > $currentLevel) {
                    $this->state[$key]['level'] = min($capacity, $suggestedLevel);
                    $this->markDirty();

                    $this->logger->debug("Adjusted leaky bucket level from remaining header", [
                        'key' => $key,
                        'api_remaining' => $remaining,
                        'old_level' => $currentLevel,
                        'new_level' => $this->state[$key]['level'],
                    ]);
                }
            }
        }
    }

    private function initializeBucket(string $key): void
    {
        if (!isset($this->state[$key])) {
            // T-05: Bucket startet leer (level=0), erste Requests sind sofort erlaubt
            $this->state[$key] = [
                'level' => 0.0,
                'last_leak' => microtime(true),
                'last_request' => null,
            ];

            $this->logger->debug("Initialized leaky bucket", [
                'key' => $key,
                'capacity' => $this->getBucketCapacity($key),
                'leak_rate' => $this->getEffectiveRate($key),
            ]);
        }
    }

    private function processLeakage(string $key): void
    {
        $bucket = &$this->state[$key];
        $now = microtime(true);
        $elapsed = $now - $bucket['last_leak'];

        if ($elapsed > 0 && $bucket['level'] > 0) {
            $leakRate = $this->getEffectiveRate($key);
            $leakage = $elapsed * $leakRate;

            $oldLevel = $bucket['level'];
            // max(0, ...) verhindert negatives Level wenn Bucket leer
            $bucket['level'] = max(0.0, $bucket['level'] - $leakage);
            $bucket['last_leak'] = $now;

            if ($oldLevel !== $bucket['level']) {
                $this->logger->debug("Leaky bucket processed leakage", [
                    'key' => $key,
                    'elapsed_sec' => round($elapsed, 3),
                    'leak_rate' => $leakRate,
                    'leakage' => round($leakage, 3),
                    'old_level' => round($oldLevel, 2),
                    'new_level' => round($bucket['level'], 2),
                ]);
            }
        } elseif ($elapsed > 0) {
            // Auch last_leak aktualisieren wenn Bucket schon leer ist
            $bucket['last_leak'] = $now;
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

    private function getBucketCapacity(string $key): int
    {
        return max(1, $this->config->getBurstCapacity());
    }

    protected function doCleanOldEntries(float $cutoff): void
    {
        foreach ($this->state as $key => $bucket) {
            $lastActivity = max($bucket['last_leak'], $bucket['last_request'] ?? 0);

            if ($bucket['level'] <= 0 && $lastActivity < $cutoff) {
                unset($this->state[$key]);
            }
        }
    }
}
