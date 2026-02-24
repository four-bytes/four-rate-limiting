<?php

declare(strict_types=1);

namespace Four\RateLimit;

/**
 * Rate Limiter Interface
 *
 * Definiert den Vertrag für alle Rate-Limiting-Implementierungen.
 * Unterstützt mehrere Algorithmen: TokenBucket, FixedWindow, SlidingWindow, LeakyBucket.
 */
interface RateLimiterInterface
{
    /**
     * Prüft ob eine Operation unter den aktuellen Rate Limits erlaubt ist.
     *
     * @param string $key Eindeutiger Bezeichner der rate-limitierten Ressource
     * @param int $tokens Anzahl zu verbrauchender Tokens (default: 1)
     * @return bool True wenn erlaubt, false wenn rate-limitiert
     */
    public function isAllowed(string $key, int $tokens = 1): bool;

    /**
     * Wartet bis das Rate Limit die Operation erlaubt.
     *
     * @param string $key Eindeutiger Bezeichner der rate-limitierten Ressource
     * @param int $tokens Anzahl zu verbrauchender Tokens (default: 1)
     * @param int $maxWaitMs Maximale Wartezeit in Millisekunden (default: 30000)
     * @return bool True wenn Operation erlaubt wurde, false bei Timeout
     */
    public function waitForAllowed(string $key, int $tokens = 1, int $maxWaitMs = 30000): bool;

    /**
     * Gibt die Zeit bis zum nächsten verfügbaren Token zurück.
     *
     * @param string $key Eindeutiger Bezeichner der rate-limitierten Ressource
     * @return int Millisekunden bis zum nächsten Token, 0 wenn sofort verfügbar
     */
    public function getWaitTime(string $key): int;

    /**
     * Setzt den Rate-Limiter-State für einen bestimmten Key zurück.
     *
     * @param string $key Eindeutiger Bezeichner der rate-limitierten Ressource
     */
    public function reset(string $key): void;

    /**
     * Gibt den aktuellen Rate-Limit-Status zurück.
     *
     * @param string $key Eindeutiger Bezeichner der rate-limitierten Ressource
     * @return array Status-Informationen inkl. Tokens, Limits, Reset-Zeit
     */
    public function getStatus(string $key): array;

    /**
     * Aktualisiert Rate Limits aus API-Response-Headern.
     *
     * @param string $key Eindeutiger Bezeichner der rate-limitierten Ressource
     * @param array $headers HTTP-Response-Header
     */
    public function updateFromHeaders(string $key, array $headers): void;

    /**
     * Reset rate limiter state für alle Keys
     */
    public function resetAll(): void;

    /**
     * Status aller bekannten Keys abrufen
     *
     * @return array<string, array> Key → Status-Array
     */
    public function getAllStatuses(): array;

    /**
     * Alte Einträge aufräumen
     *
     * @param int $maxAgeSeconds Maximales Alter in Sekunden (default: 3600)
     * @return int Anzahl gelöschter Keys
     */
    public function cleanup(int $maxAgeSeconds = 3600): int;

    /**
     * Gibt den typisierten Rate-Limit-Status als DTO zurück.
     *
     * @param string $key Eindeutiger Bezeichner der rate-limitierten Ressource
     */
    public function getTypedStatus(string $key): \Four\RateLimit\RateLimitStatus;

    /**
     * Gibt typisierte Status aller bekannten Keys zurück.
     *
     * @return array<string, \Four\RateLimit\RateLimitStatus>
     */
    public function getAllTypedStatuses(): array;
}
