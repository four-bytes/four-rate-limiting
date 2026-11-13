<?php

declare(strict_types=1);

namespace Four\RateLimit;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Abstract base class for all rate limiter implementations.
 *
 * Contains shared logic for state persistence (file or PSR-16 cache),
 * path validation and cleanup.
 */
abstract class AbstractRateLimiter implements RateLimiterInterface
{
    protected array $state = [];
    protected array $dynamicLimits = [];
    private bool $dirty = false;

    public function __construct(
        protected readonly RateLimitConfiguration $config,
        protected readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?CacheInterface $cache = null,
    ) {
        if ($this->config->shouldPersistState()) {
            $this->loadState();
        }

        // No register_shutdown_function — libraries must not register global shutdown handlers.
        // In long-running processes (Swoole, RoadRunner), shutdown is never called.
        // Instead: __destruct() for automatic flushing, or call flushState() explicitly.
    }

    public function __destruct()
    {
        $this->flushState();
    }

    // ---------------------------------------------------------------
    // Abstract methods — implemented by algorithms
    // ---------------------------------------------------------------

    abstract protected function getStateKey(): string;

    /**
     * Generates a unique cache key suffix based on the config.
     * Prevents key collisions with multiple instances of the same algorithm.
     */
    protected function getCacheKeySuffix(): string
    {
        // Hash from stateFile (if present) or rate+burst+window as fallback
        $identity = $this->config->getStateFile()
            ?? sprintf('r%.4f_b%d_w%d', $this->config->getRatePerSecond(), $this->config->getBurstCapacity(), $this->config->getWindowSizeMs());
        return substr(md5($identity), 0, 8);
    }

    // ---------------------------------------------------------------
    // State persistence (PSR-16 cache or file)
    // ---------------------------------------------------------------

    protected function markDirty(): void
    {
        $this->dirty = true;
    }

    public function flushState(): void
    {
        if (!$this->dirty || !$this->config->shouldPersistState()) {
            return;
        }
        $this->persistState();
        $this->dirty = false;
    }

    protected function loadState(): void
    {
        if ($this->cache !== null) {
            try {
                $data = $this->cache->get($this->getStateKey());
                if (is_array($data)) {
                    $this->hydrateState($data);
                }
            } catch (\Throwable $e) {
                $this->logger->warning("PSR-16 cache get() failed", [
                    'key' => $this->getStateKey(),
                    'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        $stateFile = $this->getStateFilePath();
        if ($stateFile === null || !file_exists($stateFile)) {
            return;
        }

        try {
            $raw = file_get_contents($stateFile);
            $data = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($data)) {
                $this->hydrateState($data);
            }
        } catch (\Throwable $e) {
            $this->logger->warning("Rate limiter state could not be loaded", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function persistState(): void
    {
        $data = $this->extractState();
        $data['timestamp'] = microtime(true);

        if ($this->cache !== null) {
            try {
                $ttl = $this->config->getCleanupIntervalSeconds() * 2; // 2× cleanup interval as safety buffer
                $success = $this->cache->set($this->getStateKey(), $data, $ttl);
                if (!$success) {
                    $this->logger->warning("PSR-16 cache set() returned false", [
                        'key' => $this->getStateKey(),
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning("PSR-16 cache set() failed", [
                    'key' => $this->getStateKey(),
                    'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        $stateFile = $this->getStateFilePath();
        if ($stateFile === null) {
            return;
        }

        try {
            $dir = dirname($stateFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Atomic write via temp file
            $tmp = $stateFile . '.tmp.' . getmypid();
            file_put_contents($tmp, json_encode($data)); // no JSON_PRETTY_PRINT
            rename($tmp, $stateFile);
        } catch (\Throwable $e) {
            $this->logger->warning("Rate limiter state could not be saved", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // Subclasses override for their state
    protected function hydrateState(array $data): void
    {
        $this->state = $data['state'] ?? [];
        $this->dynamicLimits = $data['dynamic_limits'] ?? [];
        $this->cleanOldEntries();
    }

    protected function extractState(): array
    {
        return [
            'state' => $this->state,
            'dynamic_limits' => $this->dynamicLimits,
        ];
    }

    // ---------------------------------------------------------------
    // Path validation (C-04: path traversal)
    // ---------------------------------------------------------------

    protected function getStateFilePath(): ?string
    {
        $configFile = $this->config->getStateFile();
        if ($configFile === null || $configFile === '') {
            return null;
        }

        if ($configFile[0] !== '/') {
            $resolved = getcwd() . '/' . $configFile;
        } else {
            $resolved = $configFile;
        }

        // Path traversal protection: normalize path, check for '..' after normalization
        $normalized = str_replace(['\\', '//'], ['/', '/'], $resolved);
        // Remove `.` and `..` segments
        $parts = explode('/', $normalized);
        $clean = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($clean);
            } else {
                $clean[] = $part;
            }
        }
        $finalPath = '/' . implode('/', $clean);

        // Whitelist: path must be below getcwd() or /tmp
        $allowedRoots = [getcwd(), sys_get_temp_dir()];
        $allowed = false;
        foreach ($allowedRoots as $root) {
            $root = rtrim($root, '/');
            if (str_starts_with($finalPath, $root . '/') || $finalPath === $root) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            $this->logger->warning("State file rejected: outside allowed directories", [
                'path' => $finalPath,
                'allowed_roots' => $allowedRoots,
            ]);
            return null;
        }

        return $finalPath;
    }

    // ---------------------------------------------------------------
    // PSR-7 header normalization
    // ---------------------------------------------------------------

    /**
     * Normalizes PSR-7 header arrays to flat key=>value pairs.
     * PSR-7: array<string, string[]> → Flat: array<string, string>
     *
     * Also accepts already flat arrays (backward compatibility).
     */
    protected function flattenHeaders(array $headers): array
    {
        $flat = [];
        foreach ($headers as $name => $value) {
            $flat[strtolower((string) $name)] = is_array($value) ? ($value[0] ?? '') : (string) $value;
        }
        return $flat;
    }

    // ---------------------------------------------------------------
    // Cleanup
    // ---------------------------------------------------------------

    protected function cleanOldEntries(): void
    {
        $cutoff = microtime(true) - $this->config->getCleanupIntervalSeconds();
        $this->doCleanOldEntries($cutoff);
    }

    protected function doCleanOldEntries(float $cutoff): void
    {
        // Subclasses may override
    }

    // ---------------------------------------------------------------
    // Interface methods (F-02)
    // ---------------------------------------------------------------

    public function resetAll(): void
    {
        $this->state = [];
        $this->dynamicLimits = [];
        $this->markDirty();
    }

    public function getAllStatuses(): array
    {
        $result = [];
        foreach (array_keys($this->state) as $key) {
            $result[$key] = $this->getStatus($key);
        }
        return $result;
    }

    public function getTypedStatus(string $key): RateLimitStatus
    {
        $raw = $this->getStatus($key);
        return RateLimitStatus::fromArray($raw['algorithm'] ?? 'unknown', $key, $raw);
    }

    public function getAllTypedStatuses(): array
    {
        $result = [];
        foreach (array_keys($this->state) as $key) {
            $result[$key] = $this->getTypedStatus($key);
        }
        return $result;
    }

    public function cleanup(int $maxAgeSeconds = 3600): int
    {
        $before = count($this->state);
        $cutoff = microtime(true) - $maxAgeSeconds;
        $this->doCleanOldEntries($cutoff);
        $after = count($this->state);
        $deleted = $before - $after;
        if ($deleted > 0) {
            $this->markDirty();
        }
        return $deleted;
    }
}
