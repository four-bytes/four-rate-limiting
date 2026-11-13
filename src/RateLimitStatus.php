<?php

declare(strict_types=1);

namespace Four\RateLimit;

/**
 * Rate-Limit-Status DTO
 *
 * Typed return type for getStatus() calls.
 * Replaces the unstructured arrays of the individual algorithms.
 *
 * Backward compatibility: getStatus() still returns an array (interface),
 * but implementations may use RateLimitStatus internally and call toArray().
 */
readonly class RateLimitStatus
{
    public function __construct(
        public string $algorithm,
        public string $key,
        public bool $isRateLimited,
        public int $waitTimeMs,
        public float $usagePercent,
        public array $raw = [],
    ) {}

    public function toArray(): array
    {
        return array_merge($this->raw, [
            'algorithm' => $this->algorithm,
            'key' => $this->key,
            'is_rate_limited' => $this->isRateLimited,
            'wait_time_ms' => $this->waitTimeMs,
            'usage_percent' => round($this->usagePercent, 2),
        ]);
    }

    public static function fromArray(string $algorithm, string $key, array $data): self
    {
        $isRateLimited = $data['is_rate_limited'] ?? false;
        $waitTimeMs = $data['wait_time_ms'] ?? 0;

        // Try to compute usage_percent from the raw data
        $usagePercent = 0.0;
        if (isset($data['tokens_available'], $data['capacity']) && $data['capacity'] > 0) {
            $usagePercent = (1 - $data['tokens_available'] / $data['capacity']) * 100;
        } elseif (isset($data['count'], $data['limit']) && $data['limit'] > 0) {
            $usagePercent = ($data['count'] / $data['limit']) * 100;
        } elseif (isset($data['bucket_level'], $data['capacity']) && $data['capacity'] > 0) {
            $usagePercent = ($data['bucket_level'] / $data['capacity']) * 100;
        }

        return new self(
            algorithm: $algorithm,
            key: $key,
            isRateLimited: (bool)$isRateLimited,
            waitTimeMs: (int)$waitTimeMs,
            usagePercent: $usagePercent,
            raw: $data,
        );
    }
}
