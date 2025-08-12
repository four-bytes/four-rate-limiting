<?php

declare(strict_types=1);

namespace Four\RateLimit;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Rate Limiter Factory
 *
 * Creates appropriate rate limiter instances based on configuration.
 * Supports multiple algorithms and marketplace-specific configurations.
 */
class RateLimiterFactory
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger()
    ) {}

    /**
     * Create rate limiter from configuration
     */
    public function create(RateLimitConfiguration $config): RateLimiterInterface
    {
        return match ($config->getAlgorithm()) {
            RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET => new Algorithm\TokenBucketRateLimiter($config, $this->logger),
            RateLimitConfiguration::ALGORITHM_FIXED_WINDOW => new Algorithm\FixedWindowRateLimiter($config, $this->logger),
            RateLimitConfiguration::ALGORITHM_SLIDING_WINDOW => new Algorithm\SlidingWindowRateLimiter($config, $this->logger),
            RateLimitConfiguration::ALGORITHM_LEAKY_BUCKET => new Algorithm\LeakyBucketRateLimiter($config, $this->logger),
            default => throw new \InvalidArgumentException("Unsupported rate limiter algorithm: {$config->getAlgorithm()}")
        };
    }

    /**
     * Create rate limiter for Amazon SP-API
     */
    public function createForAmazon(): RateLimiterInterface
    {
        return $this->create(RateLimitConfiguration::forAmazon());
    }

    /**
     * Create rate limiter for eBay API
     */
    public function createForEbay(): RateLimiterInterface
    {
        return $this->create(RateLimitConfiguration::forEbay());
    }

    /**
     * Create rate limiter for Discogs API
     */
    public function createForDiscogs(): RateLimiterInterface
    {
        return $this->create(RateLimitConfiguration::forDiscogs());
    }

    /**
     * Create rate limiter for Bandcamp API
     */
    public function createForBandcamp(): RateLimiterInterface
    {
        return $this->create(RateLimitConfiguration::forBandcamp());
    }

    /**
     * Create marketplace-specific rate limiter by name
     */
    public function createForMarketplace(string $marketplace): RateLimiterInterface
    {
        return match (strtolower($marketplace)) {
            'amazon' => $this->createForAmazon(),
            'ebay' => $this->createForEbay(),  
            'discogs' => $this->createForDiscogs(),
            'bandcamp' => $this->createForBandcamp(),
            default => throw new \InvalidArgumentException("Unsupported marketplace: {$marketplace}")
        };
    }

    /**
     * Create custom rate limiter with specific parameters
     */
    public function createCustom(
        string $algorithm,
        float $ratePerSecond,
        int $burstCapacity,
        float $safetyBuffer = 0.8,
        array $endpointLimits = [],
        array $headerMappings = [],
        ?string $stateFile = null
    ): RateLimiterInterface {
        $config = new RateLimitConfiguration(
            algorithm: $algorithm,
            ratePerSecond: $ratePerSecond,
            burstCapacity: $burstCapacity,
            safetyBuffer: $safetyBuffer,
            endpointLimits: $endpointLimits,
            headerMappings: $headerMappings,
            stateFile: $stateFile
        );
        
        return $this->create($config);
    }
}