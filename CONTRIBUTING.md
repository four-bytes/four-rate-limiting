# Contributing

Thanks for taking the time to contribute to `four-rate-limiting`.

## Requirements

- PHP 8.4+
- Composer

## Setup

```bash
composer install
```

## Coding standards

- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/).
- Every PHP file starts with `declare(strict_types=1);`.
- Keep the public API small and backward compatible; flag breaking changes in an issue first.
- Write code comments and commit messages in English.

## Before opening a pull request

Run the full local gate and make sure it is green:

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/phpcs src tests
```

- Add or update tests for behavioral changes.
- Update `HISTORY.md` for user-visible changes.
- Keep commits focused and use conventional commit messages (e.g. `fix: ...`).

## Pull requests

1. Open an issue describing the problem or feature.
2. Create a branch from `main`.
3. Implement the change with tests.
4. Open a pull request referencing the issue.

## License

By contributing, you agree that your contributions are licensed under the
Apache License 2.0 (see `LICENSE`).

## Releases

- Releases are tagged `vX.Y.Z` (semantic versioning); older tags without the `v` prefix stay as they are.
