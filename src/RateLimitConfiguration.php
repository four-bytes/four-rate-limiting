<?php

declare(strict_types=1);

namespace Four\RateLimit;

/**
 * Rate Limit Configuration
 *
 * Holds configuration for rate limiting including algorithm type,
 * limits, safety buffers, and marketplace-specific settings.
 */
class RateLimitConfiguration
{
    public const ALGORITHM_TOKEN_BUCKET = 'token_bucket';
    public const ALGORITHM_FIXED_WINDOW = 'fixed_window';
    public const ALGORITHM_SLIDING_WINDOW = 'sliding_window';
    public const ALGORITHM_LEAKY_BUCKET = 'leaky_bucket';

    public function __construct(
        private readonly string $algorithm,
        private readonly float $ratePerSecond,
        private readonly int $burstCapacity,
        private readonly float $safetyBuffer = 0.8,
        private readonly array $endpointLimits = [],
        private readonly array $headerMappings = [],
        private readonly int $windowSizeMs = 1000,
        private readonly bool $persistState = true,
        private readonly ?string $stateFile = null
    ) {
        if (!in_array($this->algorithm, [
            self::ALGORITHM_TOKEN_BUCKET,
            self::ALGORITHM_FIXED_WINDOW,
            self::ALGORITHM_SLIDING_WINDOW,
            self::ALGORITHM_LEAKY_BUCKET
        ])) {
            throw new \InvalidArgumentException("Unsupported algorithm: {$this->algorithm}");
        }
    }

    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    public function getRatePerSecond(): float
    {
        return $this->ratePerSecond;
    }

    public function getBurstCapacity(): int
    {
        return $this->burstCapacity;
    }

    public function getSafetyBuffer(): float
    {
        return $this->safetyBuffer;
    }

    public function getEndpointLimits(): array
    {
        return $this->endpointLimits;
    }

    public function getEndpointLimit(string $endpoint): ?float
    {
        return $this->endpointLimits[$endpoint] ?? null;
    }

    public function getHeaderMappings(): array
    {
        return $this->headerMappings;
    }

    public function getWindowSizeMs(): int
    {
        return $this->windowSizeMs;
    }

    public function shouldPersistState(): bool
    {
        return $this->persistState;
    }

    public function getStateFile(): ?string
    {
        return $this->stateFile;
    }

    /**
     * Create configuration for Amazon SP-API
     */
    public static function forAmazon(): self
    {
        return new self(
            algorithm: self::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 10.0,
            burstCapacity: 20,
            safetyBuffer: 0.8,
            endpointLimits: [
                'orders' => 0.0167, // 1 per minute, burst 20
                'listings' => 5.0,   // 5 per second
                'feeds' => 0.0167,   // 1 per minute  
                'reports' => 0.0222, // 1 per 45 seconds
                'inventory' => 2.0,  // 2 per second
                'fulfillment' => 2.0 // 2 per second
            ],
            headerMappings: [
                'limit' => 'x-amzn-RateLimit-Limit',
                'remaining' => 'x-amzn-RateLimit-Remaining'
            ],
            stateFile: '/var/amazon_rate_limit_state.json'
        );
    }

    /**
     * Create configuration for eBay Trading API
     */
    public static function forEbay(): self
    {
        return new self(
            algorithm: self::ALGORITHM_FIXED_WINDOW,
            ratePerSecond: 1.39, // 5000 per day = ~1.39/sec
            burstCapacity: 10,
            safetyBuffer: 0.9,
            endpointLimits: [
                'orders' => 2.78,    // 10000 per hour = ~2.78/sec
                'inventory' => 1.39, // 5000 per day
                'listings' => 1.39,  // 5000 per day
            ],
            headerMappings: [
                'daily_limit' => 'X-eBay-API-Analytics-DAILY-LIMIT',
                'daily_remaining' => 'X-eBay-API-Analytics-DAILY-REMAINING',
                'hourly_limit' => 'X-eBay-API-Analytics-HOURLY-LIMIT',
                'hourly_remaining' => 'X-eBay-API-Analytics-HOURLY-REMAINING'
            ],
            windowSizeMs: 86400000, // Daily window
            stateFile: '/var/ebay_rate_limit_state.json'
        );
    }

    /**
     * Create configuration for Discogs API
     */
    public static function forDiscogs(): self
    {
        return new self(
            algorithm: self::ALGORITHM_SLIDING_WINDOW,
            ratePerSecond: 1.0, // 60 per minute = 1/sec
            burstCapacity: 5,
            safetyBuffer: 0.8,
            headerMappings: [
                'limit' => 'X-Discogs-Ratelimit',
                'remaining' => 'X-Discogs-Ratelimit-Remaining'
            ],
            windowSizeMs: 60000, // 1 minute window
            stateFile: '/var/discogs_rate_limit_state.json'
        );
    }

    /**
     * Create configuration for Bandcamp (conservative, unofficial API)
     */
    public static function forBandcamp(): self
    {
        return new self(
            algorithm: self::ALGORITHM_LEAKY_BUCKET,
            ratePerSecond: 0.5, // Very conservative
            burstCapacity: 2,
            safetyBuffer: 0.4,
            stateFile: '/var/bandcamp_rate_limit_state.json'
        );
    }

    /**
     * Apply safety buffer to a rate limit value
     */
    public function applySafetyBuffer(float $limit): float
    {
        return $limit * $this->safetyBuffer;
    }
}