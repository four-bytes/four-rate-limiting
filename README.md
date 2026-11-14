# four-rate-limiting

Client-side rate limiting for PHP 8.4+ API clients — stay within third-party API limits with four algorithms, response-header tracking and PSR-16 shared state.

[![Packagist Version](https://img.shields.io/packagist/v/four-bytes/four-rate-limiting)](https://packagist.org/packages/four-bytes/four-rate-limiting)
[![PHP Version](https://img.shields.io/packagist/dependency-v/four-bytes/four-rate-limiting/php)](https://packagist.org/packages/four-bytes/four-rate-limiting)
[![License](https://img.shields.io/packagist/l/four-bytes/four-rate-limiting)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/four-bytes/four-rate-limiting)](https://packagist.org/packages/four-bytes/four-rate-limiting)

## Features

- **Four algorithms** — token bucket, fixed window, sliding window, leaky bucket
- **Header-based tracking** — limits adapt to `X-RateLimit-*` style response headers
- **Per-endpoint limits** and a configurable safety buffer
- **State persistence** — JSON state file or any PSR-16 cache (Redis, APCu, Memcached)
- **HTTP integration** — `RateLimitMiddleware` with 429 retry and exponential backoff
- **No framework dependency**, no API-specific presets

## Requirements

- PHP 8.4+
- `psr/simple-cache` ^3.0, `psr/log` ^3.0, `psr/http-message` ^2.0 (installed automatically)
- Optional: a PSR-16 implementation such as `symfony/cache` for shared state across processes

## Philosophy

This library provides **no API-specific presets**. Every API client knows its own rate limit rules best — configuration belongs in the consuming client, not in a generic library.

## Installation

```bash
composer require four-bytes/four-rate-limiting
```

## Quick Start

```php
use Four\RateLimit\RateLimiterFactory;
use Four\RateLimit\RateLimitConfiguration;

$config = new RateLimitConfiguration(
    algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
    ratePerSecond: 5.0,
    burstCapacity: 10,
);

$factory = new RateLimiterFactory();
$limiter = $factory->create($config);

if ($limiter->isAllowed('my-api')) {
    // execute request
}

// After the request: evaluate response headers
$limiter->updateFromHeaders('my-api', $response->getHeaders());
```

## Algorithms

| Algorithm | Constant | When to use |
|-----------|----------|-------------|
| Token Bucket | `ALGORITHM_TOKEN_BUCKET` | Burst allowed, smooth average |
| Fixed Window | `ALGORITHM_FIXED_WINDOW` | Fixed time window (e.g. per minute) |
| Sliding Window | `ALGORITHM_SLIDING_WINDOW` | Rolling window (e.g. QPD without midnight reset) |
| Leaky Bucket | `ALGORITHM_LEAKY_BUCKET` | Steady throughput, no burst |

## Configuration

```php
new RateLimitConfiguration(
    algorithm: string,        // Algorithm constant
    ratePerSecond: float,     // Average rate
    burstCapacity: int,       // Max concurrent requests
    safetyBuffer: float,      // 0.0–1.0, default: 0.8
    endpointLimits: array,    // Endpoint-specific overrides
    headerMappings: array,    // Response headers → internal fields
    windowSizeMs: int,        // Window size in ms
    persistState: bool,       // Persist state across requests
    stateFile: ?string,       // Path to state file
);
```

## Interface

```php
interface RateLimiterInterface {
    public function isAllowed(string $key, int $tokens = 1): bool;
    public function waitForAllowed(string $key, int $tokens = 1, int $maxWaitMs = 30000): bool;
    public function getWaitTime(string $key): int;
    public function reset(string $key): void;
    public function resetAll(): void;
    public function getStatus(string $key): array;
    public function getTypedStatus(string $key): RateLimitStatus;
    public function getAllStatuses(): array;
    public function getAllTypedStatuses(): array;
    public function cleanup(int $maxAgeSeconds = 3600): int;
    public function updateFromHeaders(string $key, array $headers): void;
}
```

## Advanced Usage

### createCustom

```php
$limiter = $factory->createCustom(
    algorithm: RateLimitConfiguration::ALGORITHM_SLIDING_WINDOW,
    ratePerSecond: 1.0,
    burstCapacity: 60,
    safetyBuffer: 0.85,
    headerMappings: [
        'limit'     => 'X-RateLimit-Limit',
        'remaining' => 'X-RateLimit-Remaining',
    ],
    stateFile: '/tmp/my_api_state.json',
);
```

### Dynamic Header Tracking

```php
$limiter->updateFromHeaders('my-api', [
    'X-RateLimit-Limit'     => '60',
    'X-RateLimit-Remaining' => '42',
]);
```

### Rate Limit Status

```php
$status = $limiter->getStatus('my-api');
// ['tokens' => 8, 'capacity' => 10, 'rate_per_second' => 5.0]
```

The `getTypedStatus()` method returns a typed DTO with all relevant information:

```php
$status = $limiter->getTypedStatus('my-api');

// $status is a RateLimitStatus instance:
// RateLimitStatus {
//     algorithm: "sliding_window",
//     key: "my-api",
//     isRateLimited: false,
//     waitTimeMs: 0,
//     usagePercent: 42.5,
//     raw: [
//         'tokens' => 8.5,
//         'capacity' => 10,
//         'rate_per_second' => 1.157,
//         'last_update' => 1700000000,
//     ],
// }

if ($status->isRateLimited) {
    echo "Rate limited! Wait {$status->waitTimeMs}ms";
}

echo "Usage: {$status->usagePercent}%";
```

### PSR-16 Cache Backend

The library supports any PSR-16 (`Psr\SimpleCache\CacheInterface`) implementation for distributed rate limiting (Redis, APCu, Memcached, etc.).

```php
use Four\RateLimit\RateLimiterFactory;

/** @var \Psr\SimpleCache\CacheInterface $cache */
// Provide any PSR-16 compatible cache implementation

$factory = new RateLimiterFactory();
$limiter = $factory->create($config, $cache);

// The limiter now uses the cache backend for state persistence
$limiter->isAllowed('my-api');
```

### HTTP Client Integration

`RateLimitMiddleware` encapsulates the complete rate limiting logic for HTTP clients:
- Pre-request: consume token via `waitForAllowed()`
- Post-response: synchronize header state via `updateFromHeaders()`
- 429 retry: exponential backoff with configurable retries

```php
use Four\RateLimit\Http\RateLimitMiddleware;
use Four\RateLimit\RateLimiterFactory;
use Four\RateLimit\RateLimitConfiguration;
use Four\RateLimit\Exception\RateLimitExceededException;

$config = new RateLimitConfiguration(
    algorithm: RateLimitConfiguration::ALGORITHM_SLIDING_WINDOW,
    ratePerSecond: 1.157,
    burstCapacity: 150,
    headerMappings: [
        'limit'     => 'X-RateLimit-Limit',
        'remaining' => 'X-RateLimit-Remaining',
    ],
    windowSizeMs: 86_400_000,
    stateFile: '/tmp/my_api_rate_limit.json',
);

$factory = new RateLimiterFactory();
$limiter = $factory->create($config);

$middleware = new RateLimitMiddleware(
    rateLimiter: $limiter,
    key: 'my-api',
    maxRetries: 3,
    backoffMultiplier: 2.0,
    maxWaitMs: 10000,
    maxBackoffMs: 30000,
);

try {
    $response = $middleware->execute(fn() => $httpClient->sendRequest($request));
} catch (RateLimitExceededException $e) {
    // Rate limit exhausted after all retries
    echo "Key: {$e->key}, Wait: {$e->waitTimeMs}ms";
}
```

#### PSR-7 Header Compatibility

The library automatically normalizes PSR-7 headers (`array<string, string[]>` → `array<string, string>`).
`$response->getHeaders()` can be passed directly to `updateFromHeaders()`.

---

## Error Handling

- `RateLimitConfiguration` throws `\InvalidArgumentException` for an unknown algorithm or out-of-range values (`ratePerSecond`, `burstCapacity`, `safetyBuffer`, `windowSizeMs`, `cleanupIntervalSeconds`).
- `RateLimiterFactory::create()` throws `\InvalidArgumentException` for an unsupported algorithm.
- `RateLimitMiddleware::execute()` throws `Four\RateLimit\Exception\RateLimitExceededException` when the wait time exceeds `maxWaitMs` or retries are exhausted; it exposes `key` and `waitTimeMs`.
- PSR-16 cache failures are caught and logged via the PSR-3 logger; the limiter keeps working with in-memory state.

## Architecture

```
Four\RateLimit\
├── RateLimiterInterface          # Contract for all rate limiters
├── RateLimiterFactory            # Creates rate limiters from config
├── RateLimitConfiguration        # Configuration value object
├── AbstractRateLimiter           # Base class with state persistence + PSR-16
├── RateLimitStatus               # Readonly DTO for status queries
├── Algorithm\
│   ├── TokenBucketRateLimiter    # Token bucket implementation
│   ├── FixedWindowRateLimiter    # Fixed window implementation
│   ├── SlidingWindowRateLimiter  # Sliding window implementation
│   └── LeakyBucketRateLimiter    # Leaky bucket implementation
├── Exception\
│   └── RateLimitExceededException # Rate limit + retries exhausted
└── Http\
    └── RateLimitMiddleware       # PSR-18 HTTP client integration
```

## Testing

```bash
composer test      # PHPUnit 11
composer phpstan   # PHPStan level 5
composer cs-check  # PHP_CodeSniffer
```

## Related packages

- [`four-bytes/four-http-client`](https://github.com/four-bytes/four-http-client) — PSR-18 client factory and base `ApiClient`; its rate-limiting middleware uses this package
- API clients built on that foundation, e.g. [`four-bytes/four-lexware-api-client`](https://github.com/four-bytes/four-lexware-api-client) and [`four-bytes/four-discord-client`](https://github.com/four-bytes/four-discord-client)

## Contributing, security, changelog

- Contributing: [CONTRIBUTING.md](CONTRIBUTING.md)
- Security: please report vulnerabilities privately as described in [SECURITY.md](SECURITY.md)
- Changelog: [HISTORY.md](HISTORY.md)

## License

Apache-2.0 — see [LICENSE](LICENSE) and [NOTICE](NOTICE).
