<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Four\RateLimit\RateLimitConfiguration;
use Four\RateLimit\RateLimiterFactory;

$factory = new RateLimiterFactory();

echo "=== Token bucket with custom configuration ===\n";

$limiter = $factory->createCustom(
    algorithm: RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
    ratePerSecond: 5.0,
    burstCapacity: 10,
    safetyBuffer: 0.8,
    headerMappings: [
        RateLimitConfiguration::HEADER_LIMIT => 'x-rate-limit-limit',
        RateLimitConfiguration::HEADER_REMAINING => 'x-rate-limit-remaining',
    ],
);

$key = 'example.token_bucket';

for ($i = 0; $i < 10; $i++) {
    if ($limiter->isAllowed($key)) {
        echo "Request $i allowed\n";
    } else {
        echo "Request $i rate limited\n";
        break;
    }
}

$status = $limiter->getStatus($key);
printf(
    "Status: %s, tokens available: %.2f/%d, rate: %.2f/s\n",
    $status['algorithm'],
    $status['tokens_available'],
    $status['capacity'],
    $status['rate_per_second'],
);

echo "\n=== Endpoint-specific limits ===\n";

$endpointLimiter = $factory->create(new RateLimitConfiguration(
    algorithm: RateLimitConfiguration::ALGORITHM_SLIDING_WINDOW,
    ratePerSecond: 2.0,
    burstCapacity: 10,
    endpointLimits: [
        'search' => 10.0,
        'upload' => 1.0,
    ],
    windowSizeMs: 1000,
    persistState: false,
));

foreach (['search', 'upload'] as $endpoint) {
    $allowed = $endpointLimiter->isAllowed($endpoint);
    printf("Endpoint %-6s allowed: %s\n", $endpoint, $allowed ? 'yes' : 'no');
}

echo "\n=== Update limits from response headers ===\n";

$limiter->updateFromHeaders($key, [
    'x-rate-limit-limit' => '20',
    'x-rate-limit-remaining' => '15',
]);
echo "Limits updated from response headers\n";

echo "\n=== Wait for a token ===\n";

$start = microtime(true);
if ($limiter->waitForAllowed($key, 1, 5000)) {
    $waitedMs = (int) round((microtime(true) - $start) * 1000);
    echo "Request allowed after waiting {$waitedMs}ms\n";
} else {
    echo "Request still not allowed after timeout\n";
}

echo "\n=== Typed status DTO ===\n";

$typed = $limiter->getTypedStatus($key);
printf(
    "Algorithm: %s, rate limited: %s, wait time: %dms, usage: %.2f%%\n",
    $typed->algorithm,
    $typed->isRateLimited ? 'yes' : 'no',
    $typed->waitTimeMs,
    $typed->usagePercent,
);

echo "\nExample completed!\n";
