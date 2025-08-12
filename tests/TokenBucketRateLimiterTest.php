<?php

declare(strict_types=1);

namespace Four\RateLimit\Tests;

use Four\RateLimit\Algorithm\TokenBucketRateLimiter;
use Four\RateLimit\RateLimitConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TokenBucketRateLimiterTest extends TestCase
{
    private TokenBucketRateLimiter $rateLimiter;
    private string $testKey = 'test.endpoint.user123';

    protected function setUp(): void
    {
        $config = new RateLimitConfiguration(
            algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            ratePerSecond: 5.0,        // 5 requests per second
            burstCapacity: 10,         // Burst up to 10 requests
            safetyBuffer: 1.0,         // No safety buffer for testing
            stateFile: null            // Don't persist state in tests
        );

        $this->rateLimiter = new TokenBucketRateLimiter($config, new NullLogger());
    }

    public function testInitialBurstAllowed(): void
    {
        // Should allow up to burst capacity initially
        for ($i = 0; $i < 10; $i++) {
            $allowed = $this->rateLimiter->isAllowed($this->testKey);
            $this->assertTrue($allowed, "Request $i should be allowed within burst capacity");
        }

        // 11th request should be denied (exceeded burst capacity)
        $this->assertFalse($this->rateLimiter->isAllowed($this->testKey));
    }

    public function testTokenRefill(): void
    {
        // Exhaust burst capacity
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->isAllowed($this->testKey);
        }

        // Next request should be denied
        $this->assertFalse($this->rateLimiter->isAllowed($this->testKey));

        // Sleep for token refill (1 second = 5 tokens at 5/sec rate)
        sleep(1);

        // Should now have ~5 tokens available
        for ($i = 0; $i < 5; $i++) {
            $allowed = $this->rateLimiter->isAllowed($this->testKey);
            $this->assertTrue($allowed, "Request $i should be allowed after refill");
        }

        // 6th request should be denied
        $this->assertFalse($this->rateLimiter->isAllowed($this->testKey));
    }

    public function testMultipleTokenRequest(): void
    {
        // Request 5 tokens at once
        $this->assertTrue($this->rateLimiter->isAllowed($this->testKey, 5));
        
        // Should have 5 tokens left (10 capacity - 5 used)
        $this->assertTrue($this->rateLimiter->isAllowed($this->testKey, 5));
        
        // Should have 0 tokens left - next request should fail
        $this->assertFalse($this->rateLimiter->isAllowed($this->testKey, 1));
    }

    public function testGetWaitTime(): void
    {
        // Exhaust all tokens
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->isAllowed($this->testKey);
        }

        $waitTime = $this->rateLimiter->getWaitTime($this->testKey);
        
        // Should need to wait for token refill (rate is 5/sec = 200ms per token)
        $this->assertGreaterThan(0, $waitTime);
        $this->assertLessThanOrEqual(200, $waitTime); // Max wait for 1 token
    }

    public function testGetStatus(): void
    {
        // Use some tokens
        $this->rateLimiter->isAllowed($this->testKey, 3);

        $status = $this->rateLimiter->getStatus($this->testKey);

        $this->assertIsArray($status);
        $this->assertArrayHasKey('tokens_available', $status);
        $this->assertArrayHasKey('capacity', $status);
        $this->assertArrayHasKey('rate_per_second', $status);
        $this->assertArrayHasKey('algorithm', $status);

        $this->assertEquals(7.0, $status['tokens_available']); // 10 - 3 = 7
        $this->assertEquals(10, $status['capacity']);
        $this->assertEquals(5.0, $status['rate_per_second']);
        $this->assertEquals('token_bucket', $status['algorithm']);
    }

    public function testReset(): void
    {
        // Exhaust all tokens
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->isAllowed($this->testKey);
        }

        // Should be rate limited
        $this->assertFalse($this->rateLimiter->isAllowed($this->testKey));

        // Reset should restore full capacity
        $this->rateLimiter->reset($this->testKey);

        // Should now be allowed again
        $this->assertTrue($this->rateLimiter->isAllowed($this->testKey));
        
        $status = $this->rateLimiter->getStatus($this->testKey);
        $this->assertEquals(9.0, $status['tokens_available']); // 10 - 1 (just used) = 9
    }

    public function testUpdateFromHeaders(): void
    {
        $headers = [
            'x-rate-limit' => '20',
            'x-rate-remaining' => '15'
        ];

        // This should update dynamic limits (exact behavior depends on implementation)
        $this->rateLimiter->updateFromHeaders($this->testKey, $headers);

        // Should not throw any errors
        $this->assertTrue(true);
    }

    public function testWaitForAllowed(): void
    {
        // Exhaust all tokens
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->isAllowed($this->testKey);
        }

        // Try to wait for 1 second (should allow some refill)
        $startTime = microtime(true);
        $allowed = $this->rateLimiter->waitForAllowed($this->testKey, 1, 1000); // 1 second
        $endTime = microtime(true);

        $waitedTime = ($endTime - $startTime) * 1000; // Convert to ms

        $this->assertTrue($allowed, 'Should be allowed after waiting');
        $this->assertGreaterThan(100, $waitedTime, 'Should have waited some time');
        $this->assertLessThan(1100, $waitedTime, 'Should not exceed max wait time significantly');
    }

    public function testMultipleKeys(): void
    {
        $key1 = 'test.endpoint1.user';
        $key2 = 'test.endpoint2.user';

        // Each key should have independent token buckets
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($this->rateLimiter->isAllowed($key1));
        }

        // key1 should be exhausted, but key2 should still work
        $this->assertFalse($this->rateLimiter->isAllowed($key1));
        $this->assertTrue($this->rateLimiter->isAllowed($key2));
    }
}