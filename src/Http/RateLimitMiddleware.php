<?php

declare(strict_types=1);

namespace Four\RateLimit\Http;

use Four\RateLimit\Exception\RateLimitExceededException;
use Four\RateLimit\RateLimiterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Rate limit middleware for HTTP clients.
 *
 * Encapsulates the complete rate limit logic:
 * 1. Before the request: consume a token (waitForAllowed)
 * 2. After the response: update header state (updateFromHeaders)
 * 3. On 429: exponential backoff + retry
 *
 * Usage in the API client:
 *
 *   $middleware = new RateLimitMiddleware($rateLimiter, 'etsy');
 *   $response = $middleware->execute(fn() => $httpClient->sendRequest($request));
 */
class RateLimitMiddleware
{
    public function __construct(
        private readonly RateLimiterInterface $rateLimiter,
        private readonly string $key,
        private readonly int $maxRetries = 3,
        private readonly float $backoffMultiplier = 2.0,
        private readonly int $maxWaitMs = 10000,
        private readonly int $maxBackoffMs = 30000,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Executes an HTTP request with rate limit protection.
     *
     * @param callable(): ResponseInterface $sendRequest Function that sends the HTTP request
     * @return ResponseInterface The API response
     * @throws RateLimitExceededException When rate limit + retries are exhausted
     */
    public function execute(callable $sendRequest): ResponseInterface
    {
        $attempt = 0;

        while (true) {
            // 1. Pre-request: consume token
            $allowed = $this->rateLimiter->waitForAllowed($this->key, 1, $this->maxWaitMs);
            if (!$allowed) {
                $waitTimeMs = $this->rateLimiter->getWaitTime($this->key);
                throw new RateLimitExceededException($this->key, $waitTimeMs, $this->maxWaitMs);
            }

            // 2. Send request
            $response = $sendRequest();

            // 3. Post-response: update headers
            $this->rateLimiter->updateFromHeaders($this->key, $response->getHeaders());

            // 4. On 429: retry with exponential backoff
            if ($response->getStatusCode() === 429) {
                $attempt++;
                if ($attempt > $this->maxRetries) {
                    $retryAfter = $this->parseRetryAfter($response);
                    throw new RateLimitExceededException(
                        $this->key,
                        $retryAfter * 1000,
                        $this->maxWaitMs,
                        sprintf(
                            'Rate limit for "%s" exhausted after %d retries. Server says: retry after %ds.',
                            $this->key, $this->maxRetries, $retryAfter,
                        ),
                    );
                }

                $retryAfter = $this->parseRetryAfter($response);
                $backoffMs = (int) ($retryAfter * 1000 * pow($this->backoffMultiplier, $attempt - 1));
                $backoffMs = min($backoffMs, $this->maxBackoffMs);

                $this->logger->warning('HTTP 429 Rate Limit, Retry', [
                    'key' => $this->key,
                    'attempt' => $attempt,
                    'max_retries' => $this->maxRetries,
                    'backoff_ms' => $backoffMs,
                    'retry_after_header' => $retryAfter,
                ]);

                usleep($backoffMs * 1000);
                continue;
            }

            return $response;
        }
    }

    /**
     * Parses the retry-after header (seconds).
     */
    private function parseRetryAfter(ResponseInterface $response): int
    {
        $header = $response->getHeaderLine('retry-after');
        if ($header === '') {
            return 1; // Default: 1 second
        }

        // retry-after can be seconds or an HTTP date
        if (is_numeric($header)) {
            return max(1, (int) $header);
        }

        // HTTP date → difference to now
        $retryTime = strtotime($header);
        if ($retryTime !== false) {
            return max(1, $retryTime - time());
        }

        return 1;
    }
}
