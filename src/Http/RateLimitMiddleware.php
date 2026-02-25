<?php

declare(strict_types=1);

namespace Four\RateLimit\Http;

use Four\RateLimit\Exception\RateLimitExceededException;
use Four\RateLimit\RateLimiterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Rate-Limit-Middleware für HTTP-Clients.
 *
 * Kapselt die komplette Rate-Limit-Logik:
 * 1. Vor dem Request: Token verbrauchen (waitForAllowed)
 * 2. Nach dem Response: Header-State aktualisieren (updateFromHeaders)
 * 3. Bei 429: Exponential Backoff + Retry
 *
 * Verwendung im API-Client:
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
     * Führt einen HTTP-Request mit Rate-Limit-Schutz aus.
     *
     * @param callable(): ResponseInterface $sendRequest Funktion die den HTTP-Request sendet
     * @return ResponseInterface Die API-Response
     * @throws RateLimitExceededException Wenn Rate-Limit + Retries erschöpft
     */
    public function execute(callable $sendRequest): ResponseInterface
    {
        $attempt = 0;

        while (true) {
            // 1. Pre-Request: Token verbrauchen
            $allowed = $this->rateLimiter->waitForAllowed($this->key, 1, $this->maxWaitMs);
            if (!$allowed) {
                $waitTimeMs = $this->rateLimiter->getWaitTime($this->key);
                throw new RateLimitExceededException($this->key, $waitTimeMs, $this->maxWaitMs);
            }

            // 2. Request senden
            $response = $sendRequest();

            // 3. Post-Response: Headers aktualisieren
            $this->rateLimiter->updateFromHeaders($this->key, $response->getHeaders());

            // 4. Bei 429: Retry mit Exponential Backoff
            if ($response->getStatusCode() === 429) {
                $attempt++;
                if ($attempt > $this->maxRetries) {
                    $retryAfter = $this->parseRetryAfter($response);
                    throw new RateLimitExceededException(
                        $this->key,
                        $retryAfter * 1000,
                        $this->maxWaitMs,
                        sprintf(
                            'Rate limit für "%s" nach %d Retries erschöpft. Server sagt: retry nach %ds.',
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
     * Parst den retry-after Header (Sekunden).
     */
    private function parseRetryAfter(ResponseInterface $response): int
    {
        $header = $response->getHeaderLine('retry-after');
        if ($header === '') {
            return 1; // Default: 1 Sekunde
        }

        // retry-after kann Sekunden oder HTTP-Date sein
        if (is_numeric($header)) {
            return max(1, (int) $header);
        }

        // HTTP-Date → Differenz zu jetzt
        $retryTime = strtotime($header);
        if ($retryTime !== false) {
            return max(1, $retryTime - time());
        }

        return 1;
    }
}
