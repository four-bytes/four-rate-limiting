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

        // Kein register_shutdown_function — Libraries sollen keine globalen Shutdown-Handler
        // registrieren. In long-running Prozessen (Swoole, RoadRunner) wird shutdown nie aufgerufen.
        // Stattdessen: __destruct() für automatisches Flushing, oder explizit flushState() aufrufen.
    }

    public function __destruct()
    {
        $this->flushState();
    }

    // ---------------------------------------------------------------
    // Abstrakte Methoden — von Algorithmen implementiert
    // ---------------------------------------------------------------

    abstract protected function getStateKey(): string;

    /**
     * Generiert einen eindeutigen Cache-Key-Suffix basierend auf der Config.
     * Verhindert Key-Kollisionen bei mehreren Instanzen desselben Algorithmus.
     */
    protected function getCacheKeySuffix(): string
    {
        // Hash aus stateFile (falls vorhanden) oder rate+burst+window als Fallback
        $identity = $this->config->getStateFile()
            ?? sprintf('r%.4f_b%d_w%d', $this->config->getRatePerSecond(), $this->config->getBurstCapacity(), $this->config->getWindowSizeMs());
        return substr(md5($identity), 0, 8);
    }

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
            try {
                $data = $this->cache->get($this->getStateKey());
                if (is_array($data)) {
                    $this->hydrateState($data);
                }
            } catch (\Throwable $e) {
                $this->logger->warning("PSR-16 Cache get() fehlgeschlagen", [
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
            try {
                $ttl = $this->config->getCleanupIntervalSeconds() * 2; // 2× Cleanup-Interval als Sicherheitspuffer
                $success = $this->cache->set($this->getStateKey(), $data, $ttl);
                if (!$success) {
                    $this->logger->warning("PSR-16 Cache set() gab false zurück", [
                        'key' => $this->getStateKey(),
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning("PSR-16 Cache set() fehlgeschlagen", [
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
