# HISTORY


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
