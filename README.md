# four-rate-limiting

[![PHP Version](https://img.shields.io/badge/php-^8.4-blue.svg)](https://packagist.org/packages/four-bytes/four-rate-limiting)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Generische Rate-Limiting-Library für PHP 8.4+. Vier Algorithmen, Header-basiertes dynamisches Tracking, State Persistence, PSR-16 Cache-Backend.

## Philosophie

Die Library stellt **keine fertigen Marketplace-Presets** bereit. Jeder API-Client kennt seine eigenen Rate-Limit-Regeln am besten — die Konfiguration gehört in den jeweiligen Client, nicht in eine generische Library.

## Installation

```bash
composer require four-bytes/four-rate-limiting
```

## Schnellstart

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
    // Request ausführen
}

// Nach dem Request: Headers auswerten
$limiter->updateFromHeaders('my-api', $response->getHeaders());
```

## Algorithmen

| Algorithmus | Konstante | Wann verwenden |
|-------------|-----------|----------------|
| Token Bucket | `ALGORITHM_TOKEN_BUCKET` | Burst erlaubt, glatter Durchschnitt |
| Fixed Window | `ALGORITHM_FIXED_WINDOW` | Festes Zeitfenster (z.B. pro Minute) |
| Sliding Window | `ALGORITHM_SLIDING_WINDOW` | Rollendes Fenster (z.B. QPD ohne Midnight-Reset) |
| Leaky Bucket | `ALGORITHM_LEAKY_BUCKET` | Gleichmäßiger Durchfluss, kein Burst |

## Konfiguration

```php
new RateLimitConfiguration(
    algorithm: string,        // Algorithmus-Konstante
    ratePerSecond: float,     // Durchschnittliche Rate
    burstCapacity: int,       // Max. gleichzeitige Requests
    safetyBuffer: float,      // 0.0–1.0, Standard: 0.8
    endpointLimits: array,    // Endpoint-spezifische Overrides
    headerMappings: array,    // Response-Header → interne Felder
    windowSizeMs: int,        // Fenstergröße in ms
    persistState: bool,       // State über Requests persistieren
    stateFile: ?string,       // Pfad zur State-Datei
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

## Erweiterte Nutzung

### createCustom — ohne Config-Objekt

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

### Dynamisches Tracking via Response-Headers

```php
$limiter->updateFromHeaders('my-api', [
    'X-RateLimit-Limit'     => '60',
    'X-RateLimit-Remaining' => '42',
]);
```

### Rate-Limit-Status abfragen

```php
$status = $limiter->getStatus('my-api');
// ['tokens' => 8, 'capacity' => 10, 'rate_per_second' => 5.0]
```

### PSR-16 Cache-Backend

Die Library unterstützt PSR-16 Cache-Adapter für verteilte Rate-Limits (Redis, APCu, Memcached).

```php
use Four\RateLimit\RateLimiterFactory;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Cache\Adapter\RedisAdapter;

// Redis-Adapter erstellen
$redisConnection = \Symfony\Component\Cache\Adapter\RedisAdapter::createConnection(
    'redis://localhost:6379'
);
$redisAdapter = new \Symfony\Component\Cache\Adapter\RedisAdapter($redisConnection);
$cache = new Psr16Cache($redisAdapter);

// Factory mit Cache-Backend erstellen
$factory = new RateLimiterFactory();
$limiter = $factory->create($config, $cache);

// Der Limiter nutzt jetzt das Cache-Backend für State-Persistence
$limiter->isAllowed('my-api');
```

Alternativ mit Memcached:

```php
$memcached = new \Memcached();
$memcached->addServer('localhost', 11211);
$memcachedAdapter = new \Symfony\Component\Cache\Adapter\MemcachedAdapter($memcached);
$cache = new Psr16Cache($memcachedAdapter);

$factory = new RateLimiterFactory();
$limiter = $factory->create($config, $cache);
```

### RateLimitStatus DTO

Die Methode `getTypedStatus()` gibt ein typed DTO zurück mit allen relevanten Informationen:

```php
$status = $limiter->getTypedStatus('my-api');

// $status ist eine RateLimitStatus-Instanz:
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
    echo "Rate Limited! Warte {$status->waitTimeMs}ms";
}

echo "Auslastung: {$status->usagePercent}%";
```

### HTTP-Client-Integration

Die `RateLimitMiddleware` kapselt die komplette Rate-Limit-Logik für HTTP-Clients:
- Pre-Request: Token verbrauchen via `waitForAllowed()`
- Post-Response: Header-State synchronisieren via `updateFromHeaders()`
- 429 Retry: Exponential Backoff mit konfigurierbaren Retries

#### Verwendung

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
        'limit'     => 'x-limit-per-day',
        'remaining' => 'x-remaining-today',
    ],
    windowSizeMs: 86_400_000,
    stateFile: '/tmp/etsy_rate_limit.json',
);

$factory = new RateLimiterFactory();
$limiter = $factory->create($config);

$middleware = new RateLimitMiddleware(
    rateLimiter: $limiter,
    key: 'etsy',
    maxRetries: 3,
    backoffMultiplier: 2.0,
    maxWaitMs: 10000,
    maxBackoffMs: 30000,
);

try {
    $response = $middleware->execute(fn() => $httpClient->sendRequest($request));
} catch (RateLimitExceededException $e) {
    // Rate Limit erschöpft nach allen Retries
    echo "Key: {$e->key}, Wait: {$e->waitTimeMs}ms";
}
```

#### PSR-7 Header-Kompatibilität

Die Library normalisiert PSR-7 Header automatisch (`array<string, string[]>` → `array<string, string>`).
`$response->getHeaders()` kann direkt an `updateFromHeaders()` übergeben werden.

---

## Beispiel-Konfigurationen

Typische Konfigurationen für bekannte APIs — als Referenz für eigene Clients.
Die Werte direkt in den jeweiligen API-Client einbauen, nicht hier.

### Etsy (Stand: Feb 2026)

```php
// QPD: 100.000/Tag Sliding Window, QPS: 150/s
// Quelle: https://developers.etsy.com/documentation/essentials/rate-limits
new RateLimitConfiguration(
    algorithm: RateLimitConfiguration::ALGORITHM_SLIDING_WINDOW,
    ratePerSecond: 1.157,
    burstCapacity: 150,
    safetyBuffer: 0.9,
    headerMappings: [
        'limit'     => 'x-limit-per-day',
        'remaining' => 'x-remaining-today',
    ],
    windowSizeMs: 86_400_000,
    stateFile: '/tmp/etsy_rate_limit_state.json',
);
```

### Amazon SP-API (Stand: 2025)

```php
// Restore Rate: 0.0167/s (1 req/min), Burst: 1
// Quelle: https://developer-docs.amazon.com/sp-api/docs/usage-plans-and-rate-limits-in-the-sp-api
new RateLimitConfiguration(
    algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
    ratePerSecond: 0.0167,
    burstCapacity: 1,
    safetyBuffer: 0.8,
    headerMappings: [
        'limit'     => 'x-amzn-RateLimit-Limit',
        'remaining' => 'x-amzn-RateLimit-Remaining',
    ],
    stateFile: '/tmp/amazon_rate_limit_state.json',
);
```

### eBay (Stand: 2025)

```php
// 5.000 Calls/Tag Fixed Window
new RateLimitConfiguration(
    algorithm: RateLimitConfiguration::ALGORITHM_FIXED_WINDOW,
    ratePerSecond: 0.0579,
    burstCapacity: 20,
    safetyBuffer: 0.8,
    windowSizeMs: 86_400_000,
    stateFile: '/tmp/ebay_rate_limit_state.json',
);
```

### Discogs (Stand: 2025)

```php
// 60 Requests/Minute Sliding Window (authenticated)
// Quelle: https://www.discogs.com/developers#page:home,header:home-rate-limiting
new RateLimitConfiguration(
    algorithm: RateLimitConfiguration::ALGORITHM_SLIDING_WINDOW,
    ratePerSecond: 1.0,
    burstCapacity: 60,
    safetyBuffer: 0.85,
    headerMappings: [
        'limit'     => 'X-Discogs-Ratelimit',
        'remaining' => 'X-Discogs-Ratelimit-Remaining',
    ],
    windowSizeMs: 60_000,
    stateFile: '/tmp/discogs_rate_limit_state.json',
);
```

### Bandcamp (Stand: 2025)

```php
// Kein offizielles Limit dokumentiert, konservativ: 1 req/s
new RateLimitConfiguration(
    algorithm: RateLimitConfiguration::ALGORITHM_LEAKY_BUCKET,
    ratePerSecond: 1.0,
    burstCapacity: 5,
    safetyBuffer: 0.7,
    stateFile: '/tmp/bandcamp_rate_limit_state.json',
);
```

### TikTok Shop (Stand: 2025)

```php
// 100 Requests/10s per Access Token
new RateLimitConfiguration(
    algorithm: RateLimitConfiguration::ALGORITHM_SLIDING_WINDOW,
    ratePerSecond: 10.0,
    burstCapacity: 100,
    safetyBuffer: 0.8,
    windowSizeMs: 10_000,
    stateFile: '/tmp/tiktok_rate_limit_state.json',
);
```

---

## Architektur

```
Four\RateLimit\
├── RateLimiterInterface          # Vertrag für alle Rate Limiter
├── RateLimiterFactory            # Erstellt Rate Limiter aus Config
├── RateLimitConfiguration        # Konfigurations-Value-Object
├── AbstractRateLimiter           # Basisklasse mit State-Persistence + PSR-16
├── RateLimitStatus               # Readonly DTO für Status-Abfragen
├── Algorithm\
│   ├── TokenBucketRateLimiter    # Token-Bucket-Implementierung
│   ├── FixedWindowRateLimiter    # Fixed-Window-Implementierung
│   ├── SlidingWindowRateLimiter  # Sliding-Window-Implementierung
│   └── LeakyBucketRateLimiter    # Leaky-Bucket-Implementierung
├── Exception\
│   └── RateLimitExceededException # Rate Limit + Retries erschöpft
└── Http\
    └── RateLimitMiddleware       # PSR-18 HTTP-Client-Integration
```

## Tests

```bash
composer test
composer phpstan
composer cs-check
```

## Lizenz

MIT License. Siehe [LICENSE](LICENSE).
