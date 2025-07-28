<?php
// filepath: /cron/update.php
// Cron job script - runs every 30 seconds
// Enhanced version with proper error handling and logging

// Load secure configuration FIRST
define('CONFIG_ACCESS', true);
$config = require_once __DIR__ . '/../config/config.php';

// **IMPORTANT: Set global variables from config before including leaderboard.php**
$HELIUS_API_KEY = $config['api']['helius_api_key'];
$WINNER_POT_WALLET = $config['api']['winner_pot_wallet'];
$CHALLENGE_END_DATE = $config['app']['challenge_end_date'];
$CACHE_TIMEOUT = $config['app']['cache_timeout_seconds'];

require_once __DIR__ . '/../api/leaderboard.php';

// Set timezone
date_default_timezone_set('Europe/Berlin');

try {
    logMessage("Starting leaderboard update...", 'INFO');
    
    // Measure execution time
    $startTime = microtime(true);
    
    // Call the update function (now globals are properly set)
    $data = updateLeaderboard();
    
    $endTime = microtime(true);
    $executionTime = round(($endTime - $startTime) * 1000, 2); // in milliseconds
    
    logMessage("✅ Leaderboard updated successfully in " . $executionTime . " ms.", 'INFO');
    
} catch (Exception $e) {
    logMessage("❌ Update failed with exception: " . $e->getMessage(), 'ERROR');
    logMessage("Stack trace: " . $e->getTraceAsString(), 'DEBUG');
    
} catch (Error $e) {
    logMessage("❌ Update failed with fatal error: " . $e->getMessage(), 'FATAL');
    logMessage("Stack trace: " . $e->getTraceAsString(), 'DEBUG');
    
} finally {
    // Always remove lock file
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}
?>