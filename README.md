# four-rate-limiting

[![PHP Version](https://img.shields.io/badge/php-^8.4-blue.svg)](https://packagist.org/packages/four-bytes/four-rate-limiting)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Generic rate limiting library for PHP 8.4+. Four algorithms, header-based dynamic tracking, state persistence, PSR-16 cache backend.

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

## Tests

```bash
composer test
composer phpstan
composer cs-check
```

## License

MIT License. See [LICENSE](LICENSE).
