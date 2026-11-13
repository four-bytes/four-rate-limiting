# four-rate-limiting

Production-ready rate limiting library for PHP APIs.

## Language
English — code, docs, issues, PRs and commits are written in English.

## Project
- Company: Four Bytes
- Org: four-bytes
- Purpose: token-bucket / fixed-window / sliding-window / leaky-bucket rate limiters with PSR-16 cache backends

## Stack
- PHP 8.4+
- PSR-16 cache
- No framework

## Key paths
- Interface: src/RateLimiterInterface.php
- Factory: src/RateLimiterFactory.php
- Algorithms: src/Algorithm/
- Middleware: src/Http/RateLimitMiddleware.php
