<?php

declare(strict_types=1);

namespace Four\RateLimit;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Rate Limiter Factory
 *
 * Erstellt Rate-Limiter-Instanzen anhand einer RateLimitConfiguration.
 * Die Konfiguration gehört in den jeweiligen API-Client.
 */
class RateLimiterFactory
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger()
    ) {}

    /**
     * Rate Limiter aus Konfiguration erstellen
     */
    public function create(RateLimitConfiguration $config, ?CacheInterface $cache = null): RateLimiterInterface
    {
        return match ($config->getAlgorithm()) {
            RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET => new Algorithm\TokenBucketRateLimiter($config, $this->logger, $cache),
            RateLimitConfiguration::ALGORITHM_FIXED_WINDOW => new Algorithm\FixedWindowRateLimiter($config, $this->logger, $cache),
            RateLimitConfiguration::ALGORITHM_SLIDING_WINDOW => new Algorithm\SlidingWindowRateLimiter($config, $this->logger, $cache),
            RateLimitConfiguration::ALGORITHM_LEAKY_BUCKET => new Algorithm\LeakyBucketRateLimiter($config, $this->logger, $cache),
            default => throw new \InvalidArgumentException("Unsupported rate limiter algorithm: {$config->getAlgorithm()}")
        };
    }

    /**
     * Rate Limiter mit konkreten Parametern erstellen (ohne Config-Objekt)
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

    /**
     * Rate Limiter mit PSR-16 Cache-Backend erstellen
     */
    public static function createWithCache(
        string $algorithm,
        float $ratePerSecond,
        int $burstCapacity,
        CacheInterface $cache,
        float $safetyBuffer = 0.8,
        array $endpointLimits = [],
        array $headerMappings = [],
        int $windowSizeMs = 1000,
    ): RateLimiterInterface {
        $config = new RateLimitConfiguration(
            algorithm: $algorithm,
            ratePerSecond: $ratePerSecond,
            burstCapacity: $burstCapacity,
            safetyBuffer: $safetyBuffer,
            endpointLimits: $endpointLimits,
            headerMappings: $headerMappings,
            windowSizeMs: $windowSizeMs,
            persistState: true,
            stateFile: null,
        );
        $factory = new self();
        return $factory->create($config, cache: $cache);
    }
}
