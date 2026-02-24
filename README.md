# four-rate-limiting

[![PHP Version](https://img.shields.io/badge/php-^8.1-blue.svg)](https://packagist.org/packages/four-bytes/four-rate-limiting)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Generische Rate-Limiting-Library für PHP 8.1+. Vier Algorithmen, Header-basiertes dynamisches Tracking, State Persistence.

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
    public function getStatus(string $key): array;
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
└── Algorithm\
    ├── TokenBucketRateLimiter    # Token-Bucket-Implementierung
    ├── FixedWindowRateLimiter    # Fixed-Window-Implementierung
    ├── SlidingWindowRateLimiter  # Sliding-Window-Implementierung
    └── LeakyBucketRateLimiter    # Leaky-Bucket-Implementierung
```

## Tests

```bash
composer test
composer phpstan
composer cs-check
```

## Lizenz

MIT License. Siehe [LICENSE](LICENSE).
