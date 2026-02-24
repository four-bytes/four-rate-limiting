<?php

declare(strict_types=1);

namespace Four\RateLimit\Tests;

use Four\RateLimit\RateLimiterFactory;
use Four\RateLimit\RateLimiterInterface;
use Four\RateLimit\RateLimitConfiguration;
use Four\RateLimit\Algorithm\TokenBucketRateLimiter;
use Four\RateLimit\Algorithm\FixedWindowRateLimiter;
use Four\RateLimit\Algorithm\SlidingWindowRateLimiter;
use Four\RateLimit\Algorithm\LeakyBucketRateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(RateLimiterFactory::class)]
#[CoversClass(\Four\RateLimit\AbstractRateLimiter::class)]
#[CoversClass(RateLimitConfiguration::class)]
#[CoversClass(TokenBucketRateLimiter::class)]
#[CoversClass(FixedWindowRateLimiter::class)]
#[CoversClass(SlidingWindowRateLimiter::class)]
#[CoversClass(LeakyBucketRateLimiter::class)]
class RateLimiterFactoryTest extends TestCase
{
    private RateLimiterFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RateLimiterFactory(new NullLogger());
    }

    public function testCreateWithTokenBucketConfig(): void
    {
        $config = new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 0.0167,
            burstCapacity: 1,
            safetyBuffer: 0.8,
            headerMappings: [
                'limit'     => 'x-amzn-RateLimit-Limit',
                'remaining' => 'x-amzn-RateLimit-Remaining',
            ],
            stateFile: null,
        );

        $limiter = $this->factory->create($config);

        $this->assertInstanceOf(RateLimiterInterface::class, $limiter);
        $this->assertInstanceOf(TokenBucketRateLimiter::class, $limiter);
    }

    public function testCreateWithFixedWindowConfig(): void
    {
        // Entspricht einer typischen eBay-ähnlichen Konfiguration
        $config = new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_FIXED_WINDOW,
            ratePerSecond: 0.0579,
            burstCapacity: 20,
            safetyBuffer: 0.8,
            windowSizeMs: 86_400_000,
            stateFile: null,
        );

        $limiter = $this->factory->create($config);

        $this->assertInstanceOf(RateLimiterInterface::class, $limiter);
        $this->assertInstanceOf(FixedWindowRateLimiter::class, $limiter);
    }

    public function testCreateWithSlidingWindowConfig(): void
    {
        // Entspricht einer typischen Discogs-ähnlichen Konfiguration
        $config = new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_SLIDING_WINDOW,
            ratePerSecond: 1.0,
            burstCapacity: 60,
            safetyBuffer: 0.85,
            headerMappings: [
                'limit'     => 'X-Discogs-Ratelimit',
                'remaining' => 'X-Discogs-Ratelimit-Remaining',
            ],
            windowSizeMs: 60_000,
            stateFile: null,
        );

        $limiter = $this->factory->create($config);

        $this->assertInstanceOf(RateLimiterInterface::class, $limiter);
        $this->assertInstanceOf(SlidingWindowRateLimiter::class, $limiter);
    }

    public function testCreateWithLeakyBucketConfig(): void
    {
        // Entspricht einer konservativen Konfiguration (z.B. Bandcamp)
        $config = new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_LEAKY_BUCKET,
            ratePerSecond: 1.0,
            burstCapacity: 5,
            safetyBuffer: 0.7,
            stateFile: null,
        );

        $limiter = $this->factory->create($config);

        $this->assertInstanceOf(RateLimiterInterface::class, $limiter);
        $this->assertInstanceOf(LeakyBucketRateLimiter::class, $limiter);
    }

    public function testCreateCustom(): void
    {
        $rateLimiter = $this->factory->createCustom(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 5.0,
            burstCapacity: 10,
            safetyBuffer: 0.8,
            endpointLimits: ['test' => 2.0],
            headerMappings: ['limit' => 'X-Rate-Limit'],
            stateFile: null,
        );

        $this->assertInstanceOf(RateLimiterInterface::class, $rateLimiter);
        $this->assertInstanceOf(TokenBucketRateLimiter::class, $rateLimiter);
    }

    public function testCreateWithConfiguration(): void
    {
        $config = new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 10.0,
            burstCapacity: 20,
        );

        $rateLimiter = $this->factory->create($config);

        $this->assertInstanceOf(RateLimiterInterface::class, $rateLimiter);
        $this->assertInstanceOf(TokenBucketRateLimiter::class, $rateLimiter);
    }

    public function testCreateWithUnsupportedAlgorithm(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported algorithm: unsupported_algorithm');

        new RateLimitConfiguration(
            algorithm: 'unsupported_algorithm',
            ratePerSecond: 10.0,
            burstCapacity: 20,
        );
    }

    public function testCreateWithEndpointLimits(): void
    {
        $config = new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 10.0,
            burstCapacity: 20,
            endpointLimits: [
                'orders'   => 0.0167,
                'listings' => 5.0,
            ],
        );

        $limiter = $this->factory->create($config);

        $this->assertInstanceOf(RateLimiterInterface::class, $limiter);
        $this->assertSame(0.0167, $config->getEndpointLimit('orders'));
        $this->assertSame(5.0, $config->getEndpointLimit('listings'));
        $this->assertNull($config->getEndpointLimit('nonexistent'));
    }
}
