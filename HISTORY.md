# HISTORY

## [1.3.0] — 2026-02-25

### HTTP-Client-Integration
- `Four\RateLimit\Http\RateLimitMiddleware` — PSR-18-kompatible Middleware für API-Clients
  - Pre-Request: `waitForAllowed()` Token verbrauchen
  - Post-Response: `updateFromHeaders()` Header-State synchronisieren
  - 429 Retry: Exponential Backoff mit konfigurierbaren maxRetries/backoffMultiplier
  - `parseRetryAfter()` für Sekunden und HTTP-Date-Format
- `Four\RateLimit\Exception\RateLimitExceededException` — Library-eigene Exception

### PSR-7 Header-Kompatibilität
- `flattenHeaders()` in AbstractRateLimiter — normalisiert PSR-7 `string[]` → `string` automatisch
- Alle 4 Algorithmen rufen `flattenHeaders()` in `updateFromHeaders()` auf
- `psr/http-message: ^2.0` als Dependency

## [1.2.1] — 2026-02-25

### Kritische Fixes (Code-Review)
- **#1** `register_shutdown_function` → `__destruct()` — Libraries registrieren keine globalen Shutdown-Handler mehr (long-running Prozess-kompatibel: Swoole, RoadRunner)
- **#2** PSR-16 `set()` nutzt jetzt TTL (`cleanupIntervalSeconds × 2`) statt ewig im Cache zu bleiben
- **#3** Cache-Keys sind Config-spezifisch (`four_rl_tb_<hash>`) — keine Key-Kollisionen bei mehreren Instanzen
- **#4** `SlidingWindowRateLimiter::cleanExpiredRequests()` — `array_values()` nach `array_filter()` verhindert Sparse-Arrays
- **#5/#6** PSR-16 Exception-Handling: `get()`/`set()` in try/catch, Return-Value-Prüfung bei `set()`
- **#10** `waitForAllowed()` Busy-Loop-Guard: mindestens 1ms Sleep wenn `getWaitTime()` 0 zurückgibt

### Performance
- **#11** `getStatus()` inline wait-time-Berechnung (vermeidet doppeltes `initializeBucket`/`refillBucket`)
- **#12** `SlidingWindowRateLimiter`: `[0]`/`array_key_last()` statt `min()`/`max()` — O(1) statt O(n)

### Validierung & API
- **#13** `cleanupIntervalSeconds` Validierung (>= 1) in `RateLimitConfiguration`
- **#14** `getTypedStatus()` + `getAllTypedStatuses()` — `RateLimitStatus` DTO im Interface integriert
- `suggest`-Block in `composer.json` für PSR-16 Cache-Implementierungen (symfony/cache, phpfastcache)

## [1.2.0] — 2026-02-25

### Kritische Bugfixes
- **C-01** Race Conditions: atomic write via temp-file + PID, dirty-flag verhindert 1000x I/O/s
- **C-02** Input-Validierung: `RateLimitConfiguration` wirft `\InvalidArgumentException` bei ungültigen Werten
- **C-03** TokenBucket: `capacity`-Bug behoben — `burstCapacity` ist jetzt korrekt die Obergrenze
- **C-04** Path Traversal: `getStateFilePath()` normalisiert Pfade + Whitelist-Check

### Refactoring / Technical Debt
- **T-01** `AbstractRateLimiter` Basisklasse eingeführt — ~400 Zeilen Duplikation eliminiert
- **T-02** `RateLimitStatus` readonly DTO hinzugefügt (typisierter Status-Rückgabewert)
- **T-04** FixedWindow: PHPDoc-Warnung zum Bunny-Hop-Problem ergänzt
- **T-05** LeakyBucket: Start-Verhalten dokumentiert (level=0 = sofort erlaubt)
- **T-06** `cleanupIntervalSeconds` als konfigurierbarer Parameter in `RateLimitConfiguration`

### Neue Features
- **F-01** PSR-16 (`psr/simple-cache`) als optionaler Persistence-Backend (Redis, APCu, Memcached)
- **F-02** `resetAll()`, `getAllStatuses()`, `cleanup()` zum Interface und AbstractRateLimiter hinzugefügt
- **F-03** Header-Konstanten (`HEADER_LIMIT`, `HEADER_REMAINING` etc.) in `RateLimitConfiguration`
- `RateLimiterFactory::createWithCache()` für PSR-16-Backends

### Tests & Infrastruktur
- PHP 8.4 als Mindestanforderung gesetzt (`composer.json`)
- Neue Testklassen: `AbstractRateLimiterTest`, `RateLimitConfigurationTest` (46 Tests gesamt)
- `psr/simple-cache: ^3.0` als neue Dependency
## [1.1.0] — 2026-02-25
- Marketplace-Presets aus Code entfernt (MarketplacePresets.php, forAmazon/forEbay/forDiscogs/forBandcamp in Factory + Config)
- createForAmazon/createForEbay/createForDiscogs/createForBandcamp/createForMarketplace aus RateLimiterFactory entfernt
- Presets als Beispiel-Konfigurationen in README.md dokumentiert (Etsy, Amazon, eBay, Discogs, Bandcamp, TikTok Shop)
- Tests auf direkte RateLimitConfiguration-Instanzen umgestellt (kein Preset-Aufruf mehr)
- Philosophie: Konfiguration gehört in den jeweiligen API-Client, nicht in die generische Library
## [1.3.0] — 2026-02-25
### HTTP-Client-Integration
- **PSR-7 Header-Flattening**: `flattenHeaders()` in `AbstractRateLimiter` — normalisiert `array<string, string[]>` zu `array<string, string>`
- Alle 4 Algorithmen (`TokenBucket`, `LeakyBucket`, `SlidingWindow`, `FixedWindow`): `updateFromHeaders()` ruft `flattenHeaders()` am Anfang auf
- **`RateLimitExceededException`** (`src/Exception/`) — neue Exception mit `key`, `waitTimeMs`, `maxWaitMs` Properties
- **`RateLimitMiddleware`** (`src/Http/`) — PSR-18-kompatible Middleware: Pre-Request waitForAllowed, Post-Response Header-Update, 429-Retry mit Exponential Backoff
- `psr/http-message: ^2.0` als neue Pflicht-Dependency (für `ResponseInterface`)
