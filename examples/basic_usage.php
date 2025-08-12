<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Four\RateLimit\RateLimiterFactory;
use Four\RateLimit\Preset\MarketplacePresets;

// Example 1: Using marketplace presets
echo "=== Marketplace Preset Examples ===\n";

$factory = new RateLimiterFactory();

// Amazon SP-API rate limiter
$amazonLimiter = $factory->createForMarketplace('amazon');
echo "Amazon rate limiter created\n";

// Test Amazon rate limiting
$key = 'amazon.orders.seller123';
if ($amazonLimiter->isAllowed($key)) {
    echo "✓ Amazon API call allowed\n";
} else {
    $waitTime = $amazonLimiter->getWaitTime($key);
    echo "✗ Amazon API call rate limited - wait {$waitTime}ms\n";
}

// eBay Trading API rate limiter
$ebayLimiter = $factory->createForMarketplace('ebay');
echo "eBay rate limiter created\n";

// Test eBay rate limiting with multiple tokens
$key = 'ebay.inventory.seller456';
if ($ebayLimiter->isAllowed($key, 3)) { // Request 3 tokens
    echo "✓ eBay API batch call allowed (3 requests)\n";
} else {
    echo "✗ eBay API batch call rate limited\n";
}

echo "\n=== Custom Configuration Example ===\n";

// Example 2: Custom rate limiter
$customLimiter = $factory->createCustom(
    algorithm: 'token_bucket',
    ratePerSecond: 5.0,           // 5 requests per second
    burstCapacity: 20,            // Allow bursts up to 20
    safetyBuffer: 0.8,            // 80% of limits for safety
    endpointLimits: [
        'search' => 10.0,         // Search endpoint: 10/sec
        'upload' => 1.0,          // Upload endpoint: 1/sec
    ]
);

// Test custom endpoint limits
$searchKey = 'myapi.search.user789';
$uploadKey = 'myapi.upload.user789';

echo "Testing custom endpoint limits:\n";

// Test search endpoint (higher limit)
for ($i = 0; $i < 12; $i++) {
    if ($customLimiter->isAllowed($searchKey)) {
        echo "✓ Search request $i allowed\n";
    } else {
        echo "✗ Search request $i rate limited\n";
        break;
    }
}

// Test upload endpoint (lower limit)  
for ($i = 0; $i < 3; $i++) {
    if ($customLimiter->isAllowed($uploadKey)) {
        echo "✓ Upload request $i allowed\n";
    } else {
        echo "✗ Upload request $i rate limited\n";
        break;
    }
}

echo "\n=== Rate Limit Status Example ===\n";

// Example 3: Get rate limit status
$status = $amazonLimiter->getStatus($key);
echo "Amazon rate limiter status:\n";
echo "  Tokens available: " . ($status['tokens'] ?? 'unknown') . "\n";
echo "  Capacity: " . ($status['capacity'] ?? 'unknown') . "\n";
echo "  Last refill: " . ($status['last_refill'] ?? 'never') . "\n";
echo "  Rate per second: " . ($status['rate_per_second'] ?? 'unknown') . "\n";

echo "\n=== Header Update Example ===\n";

// Example 4: Update limits from API response headers
$mockApiHeaders = [
    'x-amzn-RateLimit-Limit' => '20',      // New limit from Amazon
    'x-amzn-RateLimit-Remaining' => '15'   // Remaining requests
];

echo "Updating Amazon rate limiter from API response headers...\n";
$amazonLimiter->updateFromHeaders($key, $mockApiHeaders);

$updatedStatus = $amazonLimiter->getStatus($key);
echo "Updated status:\n";
echo "  Tokens available: " . ($updatedStatus['tokens'] ?? 'unknown') . "\n";
echo "  Dynamic limit: " . ($updatedStatus['dynamic_limit'] ?? 'none') . "\n";

echo "\n=== Wait for Allowed Example ===\n";

// Example 5: Wait for rate limit to allow request
echo "Attempting to wait for rate limit to allow request...\n";
$startTime = microtime(true);

// This will wait up to 5 seconds for the rate limit to allow the request
$allowed = $amazonLimiter->waitForAllowed($key, 1, 5000); // 5000ms = 5 seconds

$endTime = microtime(true);
$waitedTime = round(($endTime - $startTime) * 1000); // Convert to ms

if ($allowed) {
    echo "✓ Request allowed after waiting {$waitedTime}ms\n";
} else {
    echo "✗ Request still not allowed after waiting {$waitedTime}ms\n";
}

echo "\n=== Available Marketplaces ===\n";
$marketplaces = MarketplacePresets::getAvailableMarketplaces();
echo "Supported marketplaces: " . implode(', ', $marketplaces) . "\n";

echo "\nExample completed!\n";