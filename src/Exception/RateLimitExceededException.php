<?php

declare(strict_types=1);

namespace Four\RateLimit\Exception;

/**
 * Wird geworfen wenn ein Rate Limit überschritten wurde und
 * die maximale Wartezeit abgelaufen ist.
 */
class RateLimitExceededException extends \RuntimeException
{
    public function __construct(
        public readonly string $key,
        public readonly int $waitTimeMs,
        public readonly int $maxWaitMs,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?: sprintf(
                'Rate limit für "%s" überschritten. Nächster Token in %dms (max. Wartezeit: %dms).',
                $key, $waitTimeMs, $maxWaitMs,
            ),
            0,
            $previous,
        );
    }
}
