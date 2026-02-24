<?php

declare(strict_types=1);

namespace Four\RateLimit;

/**
 * Rate Limit Configuration
 *
 * Konfigurationsobjekt für Rate Limiting: Algorithmus, Limits,
 * Safety Buffer, Header-Mappings und State Persistence.
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
            throw new \InvalidArgumentException("ratePerSecond muss > 0 sein, {$this->ratePerSecond} gegeben.");
        }
        if ($this->burstCapacity < 1) {
            throw new \InvalidArgumentException("burstCapacity muss >= 1 sein, {$this->burstCapacity} gegeben.");
        }
        if ($this->safetyBuffer <= 0.0 || $this->safetyBuffer > 1.0) {
            throw new \InvalidArgumentException("safetyBuffer muss > 0.0 und <= 1.0 sein, {$this->safetyBuffer} gegeben.");
        }
        if ($this->windowSizeMs <= 0) {
            throw new \InvalidArgumentException("windowSizeMs muss > 0 sein, {$this->windowSizeMs} gegeben.");
        }
        if ($this->cleanupIntervalSeconds < 1) {
            throw new \InvalidArgumentException("cleanupIntervalSeconds muss >= 1 sein, {$this->cleanupIntervalSeconds} gegeben.");
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
     * Safety Buffer auf einen Rate-Limit-Wert anwenden
     */
    public function applySafetyBuffer(float $limit): float
    {
        return $limit * $this->safetyBuffer;
    }
}
