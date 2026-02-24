<?php

declare(strict_types=1);

namespace Four\RateLimit\Tests;

use Four\RateLimit\RateLimitConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RateLimitConfiguration::class)]
class RateLimitConfigurationTest extends TestCase
{
    // ---------------------------------------------------------------
    // Basis-Konstruktion
    // ---------------------------------------------------------------

    public function testValidConfigurationCreation(): void
    {
        $config = new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 5.0,
            burstCapacity: 10,
        );

        $this->assertSame(RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET, $config->getAlgorithm());
        $this->assertSame(5.0, $config->getRatePerSecond());
        $this->assertSame(10, $config->getBurstCapacity());
        $this->assertSame(0.8, $config->getSafetyBuffer());
        $this->assertSame(1000, $config->getWindowSizeMs());
        $this->assertSame(3600, $config->getCleanupIntervalSeconds());
    }

    // ---------------------------------------------------------------
    // Input-Validierung
    // ---------------------------------------------------------------

    public function testThrowsOnZeroRatePerSecond(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ratePerSecond muss > 0 sein');

        new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 0.0,
            burstCapacity: 10,
        );
    }

    public function testThrowsOnNegativeRatePerSecond(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ratePerSecond muss > 0 sein');

        new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: -1.0,
            burstCapacity: 10,
        );
    }

    public function testThrowsOnZeroBurstCapacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('burstCapacity muss >= 1 sein');

        new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 5.0,
            burstCapacity: 0,
        );
    }

    public function testThrowsOnNegativeBurstCapacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('burstCapacity muss >= 1 sein');

        new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 5.0,
            burstCapacity: -5,
        );
    }

    public function testThrowsOnZeroSafetyBuffer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('safetyBuffer muss > 0.0 und <= 1.0 sein');

        new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 5.0,
            burstCapacity: 10,
            safetyBuffer: 0.0,
        );
    }

    public function testThrowsOnSafetyBufferAboveOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('safetyBuffer muss > 0.0 und <= 1.0 sein');

        new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 5.0,
            burstCapacity: 10,
            safetyBuffer: 1.1,
        );
    }

    public function testSafetyBufferExactlyOneIsValid(): void
    {
        $config = new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 5.0,
            burstCapacity: 10,
            safetyBuffer: 1.0,
        );

        $this->assertSame(1.0, $config->getSafetyBuffer());
    }

    public function testThrowsOnZeroWindowSizeMs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('windowSizeMs muss > 0 sein');

        new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 5.0,
            burstCapacity: 10,
            windowSizeMs: 0,
        );
    }

    public function testThrowsOnNegativeWindowSizeMs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('windowSizeMs muss > 0 sein');

        new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 5.0,
            burstCapacity: 10,
            windowSizeMs: -100,
        );
    }

    // ---------------------------------------------------------------
    // Header-Konstanten
    // ---------------------------------------------------------------

    public function testHeaderConstants(): void
    {
        $this->assertSame('limit', RateLimitConfiguration::HEADER_LIMIT);
        $this->assertSame('remaining', RateLimitConfiguration::HEADER_REMAINING);
        $this->assertSame('reset', RateLimitConfiguration::HEADER_RESET);
        $this->assertSame('retry_after', RateLimitConfiguration::HEADER_RETRY_AFTER);
        $this->assertSame('daily_limit', RateLimitConfiguration::HEADER_DAILY_LIMIT);
        $this->assertSame('hourly_limit', RateLimitConfiguration::HEADER_HOURLY_LIMIT);
        $this->assertSame('daily_remaining', RateLimitConfiguration::HEADER_DAILY_REMAINING);
    }

    // ---------------------------------------------------------------
    // cleanupIntervalSeconds
    // ---------------------------------------------------------------

    public function testDefaultCleanupIntervalSeconds(): void
    {
        $config = new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 5.0,
            burstCapacity: 10,
        );

        $this->assertSame(3600, $config->getCleanupIntervalSeconds());
    }

    public function testCustomCleanupIntervalSeconds(): void
    {
        $config = new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 5.0,
            burstCapacity: 10,
            cleanupIntervalSeconds: 7200,
        );

        $this->assertSame(7200, $config->getCleanupIntervalSeconds());
    }
}
