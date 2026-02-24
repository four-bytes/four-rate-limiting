<?php

declare(strict_types=1);

namespace Four\RateLimit;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Abstrakte Basisklasse für alle Rate-Limiter-Implementierungen.
 *
 * Enthält gemeinsame Logik für State-Persistence (File oder PSR-16 Cache),
 * Path-Validierung und Cleanup.
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

        // Shutdown-Handler für Batch-Flush
        register_shutdown_function([$this, 'flushState']);
    }

    // ---------------------------------------------------------------
    // Abstrakte Methoden — von Algorithmen implementiert
    // ---------------------------------------------------------------

    abstract protected function getStateKey(): string;

    // ---------------------------------------------------------------
    // State-Persistence (PSR-16 Cache oder File)
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
            $data = $this->cache->get($this->getStateKey());
            if (is_array($data)) {
                $this->hydrateState($data);
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
            $this->logger->warning("Rate-Limiter State konnte nicht geladen werden", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function persistState(): void
    {
        $data = $this->extractState();
        $data['timestamp'] = microtime(true);

        if ($this->cache !== null) {
            $this->cache->set($this->getStateKey(), $data);
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
            file_put_contents($tmp, json_encode($data)); // kein JSON_PRETTY_PRINT
            rename($tmp, $stateFile);
        } catch (\Throwable $e) {
            $this->logger->warning("Rate-Limiter State konnte nicht gespeichert werden", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // Subklassen überschreiben für ihren State
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
    // Path-Validierung (C-04: Path Traversal)
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

        // Path-Traversal-Schutz: Normalisiere Pfad, prüfe auf '..' nach Normalisierung
        $normalized = str_replace(['\\', '//'], ['/', '/'], $resolved);
        // Entferne `.` und `..` Segmente
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

        // Whitelist: Pfad muss unterhalb von getcwd() oder /tmp liegen
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
            $this->logger->warning("State-Datei außerhalb erlaubter Verzeichnisse abgelehnt", [
                'path' => $finalPath,
                'allowed_roots' => $allowedRoots,
            ]);
            return null;
        }

        return $finalPath;
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
        // Subklassen können überschreiben
    }

    // ---------------------------------------------------------------
    // Interface-Methoden (F-02)
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
