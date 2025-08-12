<?php

declare(strict_types=1);

namespace Four\RateLimit\Tests;

use Four\RateLimit\RateLimiterFactory;
use Four\RateLimit\RateLimiterInterface;
use Four\RateLimit\RateLimitConfiguration;
use Four\RateLimit\Algorithm\TokenBucketRateLimiter;
use Four\RateLimit\Algorithm\FixedWindowRateLimiter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RateLimiterFactoryTest extends TestCase
{
    private RateLimiterFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RateLimiterFactory(new NullLogger());
    }

    public function testCreateForAmazon(): void
    {
        $rateLimiter = $this->factory->createForAmazon();
        
        $this->assertInstanceOf(RateLimiterInterface::class, $rateLimiter);
        $this->assertInstanceOf(TokenBucketRateLimiter::class, $rateLimiter);
    }

    public function testCreateForEbay(): void
    {
        $rateLimiter = $this->factory->createForEbay();
        
        $this->assertInstanceOf(RateLimiterInterface::class, $rateLimiter);
        $this->assertInstanceOf(FixedWindowRateLimiter::class, $rateLimiter);
    }

    public function testCreateForMarketplace(): void
    {
        $amazonLimiter = $this->factory->createForMarketplace('amazon');
        $ebayLimiter = $this->factory->createForMarketplace('ebay');
        $discogsLimiter = $this->factory->createForMarketplace('discogs');
        $bandcampLimiter = $this->factory->createForMarketplace('bandcamp');

        $this->assertInstanceOf(RateLimiterInterface::class, $amazonLimiter);
        $this->assertInstanceOf(RateLimiterInterface::class, $ebayLimiter);
        $this->assertInstanceOf(RateLimiterInterface::class, $discogsLimiter);
        $this->assertInstanceOf(RateLimiterInterface::class, $bandcampLimiter);
    }

    public function testCreateForUnsupportedMarketplace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported marketplace: unsupported');
        
        $this->factory->createForMarketplace('unsupported');
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
            stateFile: null
        );

        $this->assertInstanceOf(RateLimiterInterface::class, $rateLimiter);
        $this->assertInstanceOf(TokenBucketRateLimiter::class, $rateLimiter);
    }

    public function testCreateWithConfiguration(): void
    {
        $config = new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 10.0,
            burstCapacity: 20
        );

        $rateLimiter = $this->factory->create($config);

        $this->assertInstanceOf(RateLimiterInterface::class, $rateLimiter);
        $this->assertInstanceOf(TokenBucketRateLimiter::class, $rateLimiter);
    }

    public function testCreateWithUnsupportedAlgorithm(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported algorithm: unsupported_algorithm');
        
        $config = new RateLimitConfiguration(
            algorithm: 'unsupported_algorithm',
            ratePerSecond: 10.0,
            burstCapacity: 20
        );

        $this->factory->create($config);
    }
}