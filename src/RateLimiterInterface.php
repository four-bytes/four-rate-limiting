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
     * Check if an operation is allowed under current rate limits
     *
     * @param string $key Unique identifier for the rate-limited resource
     * @param int $tokens Number of tokens to consume (default: 1)
     * @return bool True if operation is allowed, false if rate limited
     */
    public function isAllowed(string $key, int $tokens = 1): bool;

    /**
     * Wait for rate limit to allow the operation
     * 
     * @param string $key Unique identifier for the rate-limited resource
     * @param int $tokens Number of tokens to consume (default: 1)
     * @param int $maxWaitMs Maximum time to wait in milliseconds (default: 30000)
     * @return bool True if operation became allowed, false if timeout
     */
    public function waitForAllowed(string $key, int $tokens = 1, int $maxWaitMs = 30000): bool;

    /**
     * Get time until next token is available
     *
     * @param string $key Unique identifier for the rate-limited resource
     * @return int Milliseconds until next token, 0 if immediately available
     */
    public function getWaitTime(string $key): int;

    /**
     * Reset rate limiter state for a specific key
     *
     * @param string $key Unique identifier for the rate-limited resource
     */
    public function reset(string $key): void;

    /**
     * Get current rate limit status
     *
     * @param string $key Unique identifier for the rate-limited resource
     * @return array Status information including tokens, limits, reset time
     */
    public function getStatus(string $key): array;

    /**
     * Update rate limits from API response headers
     *
     * @param string $key Unique identifier for the rate-limited resource  
     * @param array $headers HTTP response headers
     */
    public function updateFromHeaders(string $key, array $headers): void;
}