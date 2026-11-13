<?php

declare(strict_types=1);

namespace Four\RateLimit;

/**
 * Rate Limiter Interface
 *
 * Defines the contract for all rate limiting implementations.
 * Supports multiple algorithms: TokenBucket, FixedWindow, SlidingWindow, LeakyBucket.
 */
interface RateLimiterInterface
{
    /**
     * Checks whether an operation is allowed under the current rate limits.
     *
     * @param string $key Unique identifier of the rate-limited resource
     * @param int $tokens Number of tokens to consume (default: 1)
     * @return bool True if allowed, false if rate-limited
     */
    public function isAllowed(string $key, int $tokens = 1): bool;

    /**
     * Waits until the rate limit allows the operation.
     *
     * @param string $key Unique identifier of the rate-limited resource
     * @param int $tokens Number of tokens to consume (default: 1)
     * @param int $maxWaitMs Maximum wait time in milliseconds (default: 30000)
     * @return bool True if the operation was allowed, false on timeout
     */
    public function waitForAllowed(string $key, int $tokens = 1, int $maxWaitMs = 30000): bool;

    /**
     * Returns the time until the next available token.
     *
     * @param string $key Unique identifier of the rate-limited resource
     * @return int Milliseconds until the next token, 0 if immediately available
     */
    public function getWaitTime(string $key): int;

    /**
     * Resets the rate limiter state for a specific key.
     *
     * @param string $key Unique identifier of the rate-limited resource
     */
    public function reset(string $key): void;

    /**
     * Returns the current rate limit status.
     *
     * @param string $key Unique identifier of the rate-limited resource
     * @return array Status information incl. tokens, limits, reset time
     */
    public function getStatus(string $key): array;

    /**
     * Updates rate limits from API response headers.
     *
     * @param string $key Unique identifier of the rate-limited resource
     * @param array $headers HTTP response headers
     */
    public function updateFromHeaders(string $key, array $headers): void;

    /**
     * Reset rate limiter state for all keys
     */
    public function resetAll(): void;

    /**
     * Retrieve the status of all known keys
     *
     * @return array<string, array> Key → status array
     */
    public function getAllStatuses(): array;

    /**
     * Clean up old entries
     *
     * @param int $maxAgeSeconds Maximum age in seconds (default: 3600)
     * @return int Number of deleted keys
     */
    public function cleanup(int $maxAgeSeconds = 3600): int;

    /**
     * Returns the typed rate limit status as a DTO.
     *
     * @param string $key Unique identifier of the rate-limited resource
     */
    public function getTypedStatus(string $key): \Four\RateLimit\RateLimitStatus;

    /**
     * Returns typed statuses of all known keys.
     *
     * @return array<string, \Four\RateLimit\RateLimitStatus>
     */
    public function getAllTypedStatuses(): array;
}
