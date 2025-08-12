<?php

declare(strict_types=1);

namespace Four\RateLimit\Preset;

use Four\RateLimit\RateLimitConfiguration;

/**
 * Marketplace Rate Limiting Presets
 * 
 * Production-tested rate limiting configurations for major e-commerce platforms.
 * These presets are based on real-world usage and API documentation analysis.
 */
class MarketplacePresets
{
    /**
     * Amazon SP-API Rate Limiting Configuration
     * 
     * Based on Amazon's current rate limits as of 2025:
     * - Orders API: 1 request per minute, burst 20
     * - Listings API: 5 requests per second
     * - Dynamic rate limiting based on response headers
     */
    public static function amazon(array $overrides = []): RateLimitConfiguration
    {
        $defaults = [
            'algorithm' => RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            'ratePerSecond' => 10.0,
            'burstCapacity' => 20,
            'safetyBuffer' => 0.8,
            'endpointLimits' => [
                'orders' => 0.0167,        // 1 per minute
                'listings' => 5.0,         // 5 per second
                'feeds' => 0.0167,         // 1 per minute
                'reports' => 0.0222,       // 1 per 45 seconds
                'inventory' => 2.0,        // 2 per second
                'fulfillment' => 2.0,      // 2 per second
                'catalog' => 2.0,          // 2 per second
                'pricing' => 5.0,          // 5 per second
            ],
            'headerMappings' => [
                'limit' => 'x-amzn-RateLimit-Limit',
                'remaining' => 'x-amzn-RateLimit-Remaining'
            ],
            'stateFile' => '/tmp/amazon_rate_limit_state.json'
        ];
        
        $config = array_merge($defaults, $overrides);
        
        return new RateLimitConfiguration(
            algorithm: $config['algorithm'],
            ratePerSecond: $config['ratePerSecond'],
            burstCapacity: $config['burstCapacity'],
            safetyBuffer: $config['safetyBuffer'],
            endpointLimits: $config['endpointLimits'],
            headerMappings: $config['headerMappings'],
            stateFile: $config['stateFile']
        );
    }

    /**
     * eBay Trading API Rate Limiting Configuration
     * 
     * eBay uses daily and hourly limits:
     * - 5000 requests per day for most operations
     * - 10000 requests per hour for order operations
     * - Uses fixed window algorithm
     */
    public static function ebay(array $overrides = []): RateLimitConfiguration
    {
        $defaults = [
            'algorithm' => RateLimitConfiguration::ALGORITHM_FIXED_WINDOW,
            'ratePerSecond' => 1.39,      // ~5000 per day
            'burstCapacity' => 10,
            'safetyBuffer' => 0.9,
            'endpointLimits' => [
                'orders' => 2.78,         // 10000 per hour ≈ 2.78/sec
                'inventory' => 1.39,      // 5000 per day ≈ 1.39/sec
                'listings' => 1.39,       // 5000 per day
                'fulfillment' => 1.39,    // 5000 per day
                'selling' => 1.39,        // 5000 per day
            ],
            'headerMappings' => [
                'daily_limit' => 'X-eBay-API-Analytics-DAILY-LIMIT',
                'daily_remaining' => 'X-eBay-API-Analytics-DAILY-REMAINING',
                'hourly_limit' => 'X-eBay-API-Analytics-HOURLY-LIMIT',
                'hourly_remaining' => 'X-eBay-API-Analytics-HOURLY-REMAINING'
            ],
            'windowSizeMs' => 86400000,   // 24 hours
            'stateFile' => '/tmp/ebay_rate_limit_state.json'
        ];
        
        $config = array_merge($defaults, $overrides);
        
        return new RateLimitConfiguration(
            algorithm: $config['algorithm'],
            ratePerSecond: $config['ratePerSecond'],
            burstCapacity: $config['burstCapacity'],
            safetyBuffer: $config['safetyBuffer'],
            endpointLimits: $config['endpointLimits'],
            headerMappings: $config['headerMappings'],
            windowSizeMs: $config['windowSizeMs'],
            stateFile: $config['stateFile']
        );
    }

    /**
     * Discogs API Rate Limiting Configuration
     * 
     * Discogs enforces 60 requests per minute with headers indicating usage.
     * Uses sliding window for smooth distribution.
     */
    public static function discogs(array $overrides = []): RateLimitConfiguration
    {
        $defaults = [
            'algorithm' => RateLimitConfiguration::ALGORITHM_SLIDING_WINDOW,
            'ratePerSecond' => 1.0,       // 60 per minute
            'burstCapacity' => 5,
            'safetyBuffer' => 0.8,
            'headerMappings' => [
                'limit' => 'X-Discogs-Ratelimit',
                'remaining' => 'X-Discogs-Ratelimit-Remaining'
            ],
            'windowSizeMs' => 60000,      // 1 minute
            'stateFile' => '/tmp/discogs_rate_limit_state.json'
        ];
        
        $config = array_merge($defaults, $overrides);
        
        return new RateLimitConfiguration(
            algorithm: $config['algorithm'],
            ratePerSecond: $config['ratePerSecond'],
            burstCapacity: $config['burstCapacity'],
            safetyBuffer: $config['safetyBuffer'],
            headerMappings: $config['headerMappings'],
            windowSizeMs: $config['windowSizeMs'],
            stateFile: $config['stateFile']
        );
    }

    /**
     * TikTok Shop API Rate Limiting Configuration
     * 
     * TikTok Shop has varying limits by endpoint:
     * - Product API: 10 requests per second
     * - Order API: 5 requests per second
     * - General API: 2 requests per second
     */
    public static function tiktokShop(array $overrides = []): RateLimitConfiguration
    {
        $defaults = [
            'algorithm' => RateLimitConfiguration::ALGORITHM_TOKEN_BUCKET,
            'ratePerSecond' => 2.0,
            'burstCapacity' => 10,
            'safetyBuffer' => 0.7,
            'endpointLimits' => [
                'products' => 10.0,       // 10 per second
                'orders' => 5.0,          // 5 per second
                'fulfillment' => 3.0,     // 3 per second
                'finance' => 1.0,         // 1 per second
                'authorization' => 0.5,   // 30 per minute
            ],
            'stateFile' => '/tmp/tiktok_rate_limit_state.json'
        ];
        
        $config = array_merge($defaults, $overrides);
        
        return new RateLimitConfiguration(
            algorithm: $config['algorithm'],
            ratePerSecond: $config['ratePerSecond'],
            burstCapacity: $config['burstCapacity'],
            safetyBuffer: $config['safetyBuffer'],
            endpointLimits: $config['endpointLimits'] ?? [],
            stateFile: $config['stateFile']
        );
    }

    /**
     * Bandcamp API Rate Limiting Configuration
     * 
     * Very conservative configuration for unofficial/undocumented APIs.
     * Uses leaky bucket for steady, predictable request flow.
     */
    public static function bandcamp(array $overrides = []): RateLimitConfiguration
    {
        $defaults = [
            'algorithm' => RateLimitConfiguration::ALGORITHM_LEAKY_BUCKET,
            'ratePerSecond' => 0.5,       // Very conservative
            'burstCapacity' => 2,
            'safetyBuffer' => 0.4,        // Extra conservative
            'stateFile' => '/tmp/bandcamp_rate_limit_state.json'
        ];
        
        $config = array_merge($defaults, $overrides);
        
        return new RateLimitConfiguration(
            algorithm: $config['algorithm'],
            ratePerSecond: $config['ratePerSecond'],
            burstCapacity: $config['burstCapacity'],
            safetyBuffer: $config['safetyBuffer'],
            stateFile: $config['stateFile']
        );
    }

    /**
     * Get all available marketplace presets
     */
    public static function getAvailableMarketplaces(): array
    {
        return ['amazon', 'ebay', 'discogs', 'tiktok-shop', 'bandcamp'];
    }

    /**
     * Create preset by marketplace name
     */
    public static function forMarketplace(string $marketplace, array $overrides = []): RateLimitConfiguration
    {
        return match (strtolower($marketplace)) {
            'amazon' => self::amazon($overrides),
            'ebay' => self::ebay($overrides),
            'discogs' => self::discogs($overrides),
            'tiktok-shop', 'tiktokshop' => self::tiktokShop($overrides),
            'bandcamp' => self::bandcamp($overrides),
            default => throw new \InvalidArgumentException("Unsupported marketplace: {$marketplace}")
        };
    }
}