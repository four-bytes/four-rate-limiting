<?php

declare(strict_types=1);

namespace Four\RateLimit\Exception;

/**
 * Thrown when a rate limit has been exceeded and
 * the maximum wait time has elapsed.
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
                'Rate limit for "%s" exceeded. Next token in %dms (max wait time: %dms).',
                $key, $waitTimeMs, $maxWaitMs,
            ),
            0,
            $previous,
        );
    }
}
