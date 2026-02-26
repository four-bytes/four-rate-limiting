# HISTORY

## [1.3.0] — 2026-02-25

### HTTP Client Integration
- `Four\RateLimit\Http\RateLimitMiddleware` — PSR-18 compatible middleware for API clients
  - Pre-request: consume token via `waitForAllowed()`
  - Post-response: synchronize header state via `updateFromHeaders()`
  - 429 retry: exponential backoff with configurable maxRetries/backoffMultiplier
  - `parseRetryAfter()` for seconds and HTTP date format
- `Four\RateLimit\Exception\RateLimitExceededException` — library-own exception

### PSR-7 Header Compatibility
- `flattenHeaders()` in AbstractRateLimiter — normalizes PSR-7 `string[]` → `string` automatically
- All 4 algorithms call `flattenHeaders()` in `updateFromHeaders()`
- `psr/http-message: ^2.0` added as dependency

## [1.2.1] — 2026-02-25

### Critical Fixes (Code Review)
- **#1** `register_shutdown_function` → `__destruct()` — libraries no longer register global shutdown handlers (long-running process compatible: Swoole, RoadRunner)
- **#2** PSR-16 `set()` now uses TTL (`cleanupIntervalSeconds × 2`) instead of persisting indefinitely
- **#3** Cache keys are config-specific (`four_rl_tb_<hash>`) — no key collisions between multiple instances
- **#4** `SlidingWindowRateLimiter::cleanExpiredRequests()` — `array_values()` after `array_filter()` prevents sparse arrays
- **#5/#6** PSR-16 exception handling: `get()`/`set()` wrapped in try/catch, return value checked for `set()`
- **#10** `waitForAllowed()` busy-loop guard: minimum 1ms sleep when `getWaitTime()` returns 0

### Performance
- **#11** `getStatus()` inline wait-time calculation (avoids double `initializeBucket`/`refillBucket`)
- **#12** `SlidingWindowRateLimiter`: `[0]`/`array_key_last()` instead of `min()`/`max()` — O(1) instead of O(n)

### Validation & API
- **#13** `cleanupIntervalSeconds` validation (>= 1) in `RateLimitConfiguration`
- **#14** `getTypedStatus()` + `getAllTypedStatuses()` — `RateLimitStatus` DTO integrated into interface
- `suggest` block in `composer.json` for PSR-16 cache implementations (symfony/cache, phpfastcache)

## [1.2.0] — 2026-02-25

### Critical Bug Fixes
- **C-01** Race conditions: atomic write via temp-file + PID, dirty-flag prevents 1000x I/O/s
- **C-02** Input validation: `RateLimitConfiguration` throws `\InvalidArgumentException` on invalid values
- **C-03** TokenBucket: `capacity` bug fixed — `burstCapacity` is now correctly the upper limit
- **C-04** Path traversal: `getStateFilePath()` normalizes paths + whitelist check

### Refactoring / Technical Debt
- **T-01** `AbstractRateLimiter` base class introduced — ~400 lines of duplication eliminated
- **T-02** `RateLimitStatus` readonly DTO added (typed status return value)
- **T-04** FixedWindow: PHPDoc warning added for the thundering herd problem
- **T-05** LeakyBucket: start behavior documented (level=0 = allowed immediately)
- **T-06** `cleanupIntervalSeconds` as configurable parameter in `RateLimitConfiguration`

### New Features
- **F-01** PSR-16 (`psr/simple-cache`) as optional persistence backend (Redis, APCu, Memcached)
- **F-02** `resetAll()`, `getAllStatuses()`, `cleanup()` added to interface and AbstractRateLimiter
- **F-03** Header constants (`HEADER_LIMIT`, `HEADER_REMAINING` etc.) in `RateLimitConfiguration`
- `RateLimiterFactory::createWithCache()` for PSR-16 backends

### Tests & Infrastructure
- PHP 8.4 set as minimum requirement (`composer.json`)
- New test classes: `AbstractRateLimiterTest`, `RateLimitConfigurationTest` (46 tests total)
- `psr/simple-cache: ^3.0` added as dependency

## [1.1.0] — 2026-02-25

- Removed API-specific presets from library code — configuration belongs in the consuming client
- `RateLimiterFactory` simplified — no more preset factory methods
- Tests updated to use `RateLimitConfiguration` directly

