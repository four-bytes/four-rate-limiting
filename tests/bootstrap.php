<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Set timezone for consistent testing
date_default_timezone_set('UTC');

// Create temp directory for test state files
$tempDir = sys_get_temp_dir() . '/four-rate-limiting-tests';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

// Clean up any existing test state files
$files = glob($tempDir . '/*_rate_limit_state.json');
foreach ($files as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}