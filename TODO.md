# TODO: four-rate-limiting

**Erstellt:** 2026-02-25
**Basis:** Interne Code-Analyse (16 identifizierte Probleme)
**Version:** 1.1.0

---

## 🔴 Kritisch

- [x] **C-01** Race Conditions bei State-Persistence beheben
  - Problem: `saveState()` wird nach JEDEM `isAllowed()`-Call aufgerufen → bei hoher Last 1000x File-I/O/sec
  - Kein File-Lock → Race Conditions bei gleichzeitigem Zugriff (mehrere Prozesse)
  - `JSON_PRETTY_PRINT` macht State-Dateien unnötig groß
  - Lösung: Batch-Updates (dirty flag + flush bei Shutdown/Intervall) oder PSR-6/PSR-16 Cache als optionalen Persistence-Layer erlauben

- [x] **C-02** Input-Validierung in `RateLimitConfiguration` ergänzen
  - `safetyBuffer` muss `> 0.0` und `<= 1.0` sein
  - `ratePerSecond` muss `> 0` sein
  - `burstCapacity` muss `>= 1` sein
  - `windowSizeMs` muss `> 0` sein
  - Alle Checks im Constructor mit `\InvalidArgumentException`

- [x] **C-03** Logik-Fehler in `TokenBucket::capacity`-Berechnung korrigieren
  - Zeile ~199: `$capacity = max($this->config->getBurstCapacity(), (int)ceil($rate))`
  - Bug: `ratePerSecond=100` + `burstCapacity=10` → capacity wird 100 statt 10
  - `burstCapacity` soll Obergrenze sein, nicht `max(burst, rate)`

- [x] **C-04** Path Traversal in `getStateFilePath()` absichern
  - `ltrim($configFile, '/')` verhindert keine `..`-Segmente
  - Lösung: `realpath()` + Whitelist-Verzeichnis-Check oder `basename()` für relative Pfade

---

## 🟠 Schwächen (Technical Debt)

- [x] **T-01** `AbstractRateLimiter` Basisklasse einführen
  - Ca. 400 Zeilen duplizierter Code in allen 4 Algorithmen:
    `loadState()`, `saveState()`, `getStateFilePath()`, `cleanOldEntries()`
  - Lösung: `abstract class AbstractRateLimiter implements RateLimiterInterface`
  - Algorithmen erben davon, implementieren nur `doIsAllowed()`, `doGetWaitTime()`

- [x] **T-02** `getStatus()` typisieren — DTO statt `array`
  - Alle 4 Implementierungen geben unterschiedliche Array-Strukturen zurück
  - Lösung: `RateLimitStatus` readonly class als Return-Type
  ```php
  readonly class RateLimitStatus {
      public function __construct(
          public string $algorithm,
          public string $key,
          public bool $isAllowed,
          public int $waitTimeMs,
          public float $usagePercent,
          public array $raw = [],
      ) {}
  }
  ```

- [x] **T-03** `SlidingWindowRateLimiter` Performance optimieren
  - `array_filter()` über alle gespeicherten Timestamps bei jedem Request → O(n)
  - Bei 60 req/min über 60 Minuten = 3600 Einträge, jedes Mal gefiltert
  - Lösung: Circular Buffer oder sortiertes Array mit Binary-Search-Cutoff

- [x] **T-04** `FixedWindowRateLimiter` Bunny-Hop-Problem dokumentieren/mitigieren
  - Bekanntes Problem: Requests häufen sich am Window-Ende an (2x Rate möglich)
  - Kurzfristig: PHPDoc-Warnung hinzufügen
  - Langfristig: Sliding-Window als Empfehlung wenn gleichmäßiger Fluss nötig

- [x] **T-05** `LeakyBucketRateLimiter` Start-Verhalten korrigieren
  - Startet bei `level = 0` → erster Request muss warten bis Bucket voll
  - Erwartetes Verhalten: Initial-Burst erlaubt (Bucket startet leer = sofort erlaubt)
  - Semantik prüfen und angleichen an Standard-Definition

- [x] **T-06** Cleanup-Interval konfigurierbar machen
  - `cleanOldEntries()` nutzt hardcoded `3600` Sekunden in allen Algorithmen
  - Lösung: `cleanupIntervalSeconds` als Parameter in `RateLimitConfiguration`

---

## 🟡 Fehlende Features

- [x] **F-01** PSR-6 / PSR-16 Cache als Persistence-Backend
  - Aktuell nur File-basiert → kein Redis, kein Memcached, kein APCu
  - Für Pipelinq/multi-process Umgebungen: Dragonfly/Redis als State-Backend nötig
  - Interface: `withCache(CacheItemPoolInterface $cache): static`

- [x] **F-02** `resetAll()` + `getAllStatuses()` + `cleanup()` zum Interface hinzufügen
  ```php
  public function resetAll(): void;
  public function getAllStatuses(): array;   // RateLimitStatus[]
  public function cleanup(int $maxAgeSeconds = 3600): int;  // gelöschte Keys
  ```

- [x] **F-03** Header-Handling vereinheitlichen
  - `FixedWindowRateLimiter` nutzt `daily_limit`/`hourly_limit` als Header-Keys
  - Alle anderen nutzen `limit`/`remaining`
  - Lösung: Einheitliche Header-Key-Konstanten in `RateLimitConfiguration`

---

## 🔵 Tests

- [x] **TS-01** `FixedWindowRateLimiterTest` erstellen
  - Window-Reset, Limit-Überschreitung, `updateFromHeaders()`

- [x] **TS-02** `SlidingWindowRateLimiterTest` erstellen
  - Sliding-Verhalten (kein midnight-reset), Timestamp-Cleanup, Performance

- [x] **TS-03** `LeakyBucketRateLimiterTest` erstellen
  - Leak-Rate, Overflow, Start-Verhalten

- [x] **TS-04** Edge-Case Tests für alle Algorithmen
  - Negative Tokens, Zero Tokens, sehr große Token-Anfragen
  - Clock-Skew (Systemuhr springt zurück)
  - Corrupted State-Datei → graceful degradation

- [x] **TS-05** Concurrency-Tests (soweit in PHP testbar)
  - Parallel-Prozesse schreiben State-Datei gleichzeitig
  - Sicherstellen dass kein Request doppelt genehmigt wird

- [x] **TS-06** PHP-Version auf 8.4 anheben (aktuell `^8.1`)
  - `composer.json`: `"php": "^8.4"` — aligned mit rest des Ökosystems

---

## Prioritäts-Reihenfolge

1. **C-02** Input-Validierung (einfach, verhindert stille Fehler)
2. **C-03** TokenBucket Capacity-Bug (korrigiert falsches Verhalten)
3. **T-01** AbstractRateLimiter (reduziert 400 Zeilen Duplikation, Basis für alles andere)
4. **C-01** Race Conditions / Persistence (nach T-01, da Basisklasse vereinfacht)
5. **F-01** PSR-16 Cache-Backend (für Pipelinq-Integration nötig)
6. **C-04** Path Traversal (Security)
7. Rest nach Bedarf

---

## Status

🔴 Kritisch (4): C-01, C-02, C-03, C-04
🟠 Technical Debt (6): T-01 bis T-06
🟡 Features (3): F-01, F-02, F-03
🔵 Tests (6): TS-01 bis TS-06

**Gesamt: 19 Tasks**

---

## ✅ Erledigt in 1.2.0 (2026-02-25)

Alle 19 Tasks abgeschlossen. Siehe HISTORY.md für Details.

## ✅ Erledigt in 1.2.1 (2026-02-25)

14 Code-Review-Issues behoben:
- #1 `register_shutdown_function` → `__destruct()` (long-running Prozess-kompatibel)
- #2 PSR-16 TTL + Exception-Handling + Return-Value-Prüfung
- #3 Config-spezifische Cache-Keys (md5 Hash-Suffix)
- #4 SlidingWindow `array_values()` nach `array_filter()`
- #5/#6 PSR-16 Exception-Handling in get()/set()
- #10 `waitForAllowed()` Busy-Loop-Guard (min. 1ms Sleep)
- #11 `getStatus()` Inline Wait-Time-Berechnung
- #12 SlidingWindow O(1) min/max statt O(n)
- #13 `cleanupIntervalSeconds >= 1` Validierung
- #14 `getTypedStatus()` + `getAllTypedStatuses()` via DTO

## ✅ Erledigt in 1.3.0 (2026-02-25)

HTTP-Client-Integration:
- `RateLimitMiddleware` — PSR-18-kompatible Middleware (Pre-Request, Post-Response, 429-Retry)
- `RateLimitExceededException` — Library-eigene Exception
- `flattenHeaders()` — PSR-7 `string[]` → `string` Normalisierung in allen Algorithmen
- `psr/http-message: ^2.0` als Dependency
