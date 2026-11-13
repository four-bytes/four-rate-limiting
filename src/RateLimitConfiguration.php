<?php

declare(strict_types=1);

namespace Four\RateLimit;

/**
 * Rate Limit Configuration
 *
 * Configuration object for rate limiting: algorithm, limits,
 * safety buffer, header mappings and state persistence.
 */
class RateLimitConfiguration
{
    public const HEADER_LIMIT = 'limit';
    public const HEADER_REMAINING = 'remaining';
    public const HEADER_RESET = 'reset';
    public const HEADER_RETRY_AFTER = 'retry_after';
    public const HEADER_DAILY_LIMIT = 'daily_limit';
    public const HEADER_HOURLY_LIMIT = 'hourly_limit';
    public const HEADER_DAILY_REMAINING = 'daily_remaining';

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
        private readonly ?string $stateFile = null,
        private readonly int $cleanupIntervalSeconds = 3600,
    ) {
        if (!in_array($this->algorithm, [
            self::ALGORITHM_TOKEN_BUCKET,
            self::ALGORITHM_FIXED_WINDOW,
            self::ALGORITHM_SLIDING_WINDOW,
            self::ALGORITHM_LEAKY_BUCKET,
        ])) {
            throw new \InvalidArgumentException("Unsupported algorithm: {$this->algorithm}");
        }

        if ($this->ratePerSecond <= 0) {
            throw new \InvalidArgumentException("ratePerSecond must be > 0, {$this->ratePerSecond} given.");
        }
        if ($this->burstCapacity < 1) {
            throw new \InvalidArgumentException("burstCapacity must be >= 1, {$this->burstCapacity} given.");
        }
        if ($this->safetyBuffer <= 0.0 || $this->safetyBuffer > 1.0) {
            throw new \InvalidArgumentException("safetyBuffer must be > 0.0 and <= 1.0, {$this->safetyBuffer} given.");
        }
        if ($this->windowSizeMs <= 0) {
            throw new \InvalidArgumentException("windowSizeMs must be > 0, {$this->windowSizeMs} given.");
        }
        if ($this->cleanupIntervalSeconds < 1) {
            throw new \InvalidArgumentException("cleanupIntervalSeconds must be >= 1, {$this->cleanupIntervalSeconds} given.");
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

    public function getCleanupIntervalSeconds(): int
    {
        return $this->cleanupIntervalSeconds;
    }

    /**
     * Apply the safety buffer to a rate limit value
     */
    public function applySafetyBuffer(float $limit): float
    {
        return $limit * $this->safetyBuffer;
    }
}
