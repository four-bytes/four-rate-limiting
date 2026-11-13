<?php

declare(strict_types=1);

namespace Four\RateLimit\Tests;

use Four\RateLimit\AbstractRateLimiter;
use Four\RateLimit\Algorithm\TokenBucketRateLimiter;
use Four\RateLimit\Algorithm\FixedWindowRateLimiter;
use Four\RateLimit\Algorithm\SlidingWindowRateLimiter;
use Four\RateLimit\Algorithm\LeakyBucketRateLimiter;
use Four\RateLimit\RateLimitConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for AbstractRateLimiter (F-02: resetAll, getAllStatuses, cleanup)
 * and for the C-03 bug fix in TokenBucketRateLimiter.
 */
#[CoversClass(AbstractRateLimiter::class)]
#[CoversClass(TokenBucketRateLimiter::class)]
#[CoversClass(FixedWindowRateLimiter::class)]
#[CoversClass(SlidingWindowRateLimiter::class)]
#[CoversClass(LeakyBucketRateLimiter::class)]
#[CoversClass(RateLimitConfiguration::class)]
class AbstractRateLimiterTest extends TestCase
{
    private function makeConfig(string $algorithm = RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET): RateLimitConfiguration
    {
        return new RateLimitConfiguration(
            algorithm: $algorithm,
            ratePerSecond: 5.0,
            burstCapacity: 10,
            safetyBuffer: 1.0,
            persistState: false,
        );
    }

    // ---------------------------------------------------------------
    // C-03 bug fix: burstCapacity is the bucket capacity
    // ---------------------------------------------------------------

    public function testTokenBucketCapacityIsBurstCapacity(): void
    {
        $config = new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 100.0, // High rate — previously this would have been max(burst, rate) = 100
            burstCapacity: 10,
            safetyBuffer: 1.0,
            persistState: false,
        );

        $limiter = new TokenBucketRateLimiter($config, new NullLogger());

        $status = $limiter->getStatus('test');
        // C-03 fix: capacity must be burstCapacity (10), NOT max(10, 100) = 100
        $this->assertSame(10, $status['capacity']);
        $this->assertSame(10.0, $status['tokens_available']);
    }

    // ---------------------------------------------------------------
    // F-02: resetAll
    // ---------------------------------------------------------------

    public function testResetAll(): void
    {
        $limiter = new TokenBucketRateLimiter($this->makeConfig(), new NullLogger());

        // Initialize multiple keys
        $limiter->isAllowed('key1');
        $limiter->isAllowed('key2');
        $limiter->isAllowed('key3');

        $this->assertCount(3, $limiter->getAllStatuses());

        $limiter->resetAll();

        $this->assertCount(0, $limiter->getAllStatuses());
    }

    // ---------------------------------------------------------------
    // F-02: getAllStatuses
    // ---------------------------------------------------------------

    public function testGetAllStatuses(): void
    {
        $limiter = new TokenBucketRateLimiter($this->makeConfig(), new NullLogger());

        $limiter->isAllowed('endpoint1');
        $limiter->isAllowed('endpoint2');

        $statuses = $limiter->getAllStatuses();

        $this->assertIsArray($statuses);
        $this->assertArrayHasKey('endpoint1', $statuses);
        $this->assertArrayHasKey('endpoint2', $statuses);
        $this->assertArrayHasKey('algorithm', $statuses['endpoint1']);
        $this->assertSame('token_bucket', $statuses['endpoint1']['algorithm']);
    }

    public function testGetAllStatusesEmpty(): void
    {
        $limiter = new TokenBucketRateLimiter($this->makeConfig(), new NullLogger());
        $this->assertSame([], $limiter->getAllStatuses());
    }

    // ---------------------------------------------------------------
    // F-02: cleanup
    // ---------------------------------------------------------------

    public function testCleanupRemovesOldEntries(): void
    {
        $limiter = new TokenBucketRateLimiter($this->makeConfig(), new NullLogger());

        // Initialize keys
        $limiter->isAllowed('old-key');
        $limiter->isAllowed('new-key');

        // Exhaust tokens to set last_request
        for ($i = 0; $i < 10; $i++) {
            $limiter->isAllowed('old-key');
        }

        // cleanup with maxAgeSeconds=0 deletes everything
        $deleted = $limiter->cleanup(0);

        $this->assertGreaterThanOrEqual(1, $deleted);
    }

    public function testCleanupReturnsDeletedCount(): void
    {
        $limiter = new TokenBucketRateLimiter($this->makeConfig(), new NullLogger());

        $limiter->isAllowed('key1');
        $limiter->isAllowed('key2');

        $before = count($limiter->getAllStatuses());
        $deleted = $limiter->cleanup(0); // Delete everything
        $after = count($limiter->getAllStatuses());

        $this->assertSame($before, $deleted + $after);
    }

    public function testCleanupPreservesActiveEntries(): void
    {
        $limiter = new TokenBucketRateLimiter($this->makeConfig(), new NullLogger());

        $limiter->isAllowed('active-key');

        // With a very large maxAgeSeconds — no entry should be deleted
        $deleted = $limiter->cleanup(99999);

        $this->assertSame(0, $deleted);
        $this->assertArrayHasKey('active-key', $limiter->getAllStatuses());
    }

    // ---------------------------------------------------------------
    // AbstractRateLimiter applies to all algorithms
    // ---------------------------------------------------------------

    /** @dataProvider allAlgorithmsProvider */
    public function testAllAlgorithmsImplementResetAll(string $algorithm): void
    {
        $config = $this->makeConfig($algorithm);
        $limiter = match ($algorithm) {
            RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET => new TokenBucketRateLimiter($config, new NullLogger()),
            RateLimitConfiguration::ALGORITHM_FIXED_WINDOW => new FixedWindowRateLimiter($config, new NullLogger()),
            RateLimitConfiguration::ALGORITHM_SLIDING_WINDOW => new SlidingWindowRateLimiter($config, new NullLogger()),
            RateLimitConfiguration::ALGORITHM_LEAKY_BUCKET => new LeakyBucketRateLimiter($config, new NullLogger()),
            default => throw new \InvalidArgumentException("Unsupported rate limiter algorithm: {$algorithm}"),
        };

        $limiter->isAllowed('key1');
        $limiter->resetAll();

        $this->assertSame([], $limiter->getAllStatuses());
    }

    /** @dataProvider allAlgorithmsProvider */
    public function testAllAlgorithmsImplementCleanup(string $algorithm): void
    {
        $config = $this->makeConfig($algorithm);
        $limiter = match ($algorithm) {
            RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET => new TokenBucketRateLimiter($config, new NullLogger()),
            RateLimitConfiguration::ALGORITHM_FIXED_WINDOW => new FixedWindowRateLimiter($config, new NullLogger()),
            RateLimitConfiguration::ALGORITHM_SLIDING_WINDOW => new SlidingWindowRateLimiter($config, new NullLogger()),
            RateLimitConfiguration::ALGORITHM_LEAKY_BUCKET => new LeakyBucketRateLimiter($config, new NullLogger()),
            default => throw new \InvalidArgumentException("Unsupported rate limiter algorithm: {$algorithm}"),
        };

        $limiter->isAllowed('key1');
        $result = $limiter->cleanup(0);

        $this->assertIsInt($result);
    }

    public static function allAlgorithmsProvider(): array
    {
        return [
            'token_bucket' => [RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET],
            'fixed_window' => [RateLimitConfiguration::ALGORITHM_FIXED_WINDOW],
            'sliding_window' => [RateLimitConfiguration::ALGORITHM_SLIDING_WINDOW],
            'leaky_bucket' => [RateLimitConfiguration::ALGORITHM_LEAKY_BUCKET],
        ];
    }

    // ---------------------------------------------------------------
    // Path traversal protection (C-04)
    // ---------------------------------------------------------------

    public function testPathTraversalIsRejected(): void
    {
        // A path with path traversal is rejected (getStateFilePath returns null)
        // The limiter must not crash — it should simply not persist any state
        $config = new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 5.0,
            burstCapacity: 10,
            persistState: true,
            stateFile: '../../etc/passwd',
        );

        // No error on creation — path traversal is silently caught
        $limiter = new TokenBucketRateLimiter($config, new NullLogger());
        $this->assertTrue($limiter->isAllowed('test'));
    }
}
