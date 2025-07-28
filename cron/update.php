<?php
// filepath: /cron/update.php
// Cron job script - runs every 30 seconds
// Enhanced version with proper error handling and logging

// Add these at the top for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load secure configuration
define('CONFIG_ACCESS', true);
$config = require_once __DIR__ . '/../config/config.php';

// Rename this function to avoid conflict with leaderboard.php
function cronLogMessage($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logFile = __DIR__ . '/../logs/cron.log';
    
    // Create logs directory if it doesn't exist
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logEntry = "[{$timestamp}] [{$level}] {$message}\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    
    // Also output to console for Plesk cron logs
    echo $logEntry;
}

// Test logging immediately
cronLogMessage("Update script started", 'INFO');

// Include leaderboard.php after defining our function
require_once __DIR__ . '/../api/leaderboard.php';

// Set timezone
date_default_timezone_set('Europe/Berlin');

// Check if update is already running (prevent overlapping)
$lockFile = __DIR__ . '/../data/update.lock';
if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    if (time() - $lockTime < 60) { // Lock expires after 60 seconds
        cronLogMessage("Update already running, skipping...", 'WARNING');
        exit;
    } else {
        // Remove stale lock file
        unlink($lockFile);
        cronLogMessage("Removed stale lock file", 'INFO');
    }
}

// Create lock file
file_put_contents($lockFile, date('Y-m-d H:i:s'));

try {
    cronLogMessage("Starting leaderboard update...", 'INFO');
    
    // Debug: Log the configured end date before processing
    $configuredEndDate = $config['app']['challenge_end_date'] ?? 'NOT SET';
    cronLogMessage("DEBUG - Configured challenge_end_date from config: {$configuredEndDate}", 'DEBUG');
    
    // Make config globally available for leaderboard.php
    $GLOBALS['config'] = $config;
    
    // Measure execution time
    $startTime = microtime(true);
    
    // Call the update function with config
    $data = updateLeaderboard($config);
    
    $endTime = microtime(true);
    $executionTime = round(($endTime - $startTime) * 1000, 2); // in milliseconds
    
    if ($data) {
        $walletCount = count($data['data']);
        $challengeStatus = $data['challenge_ended'] ? 'ENDED' : 'ACTIVE';
        
        // Enhanced logging with debug info
        cronLogMessage("✅ Update successful: {$walletCount} wallets processed in {$executionTime}ms, Challenge: {$challengeStatus}", 'SUCCESS');
        
        if (isset($data['debug'])) {
            cronLogMessage("Debug - Raw End Date from config: {$data['debug']['raw_end_date']}", 'DEBUG');
            cronLogMessage("Debug - Parsed End Date: {$data['debug']['end_date_parsed']}", 'DEBUG');
            cronLogMessage("Debug - Current Time: {$data['debug']['current_time']}", 'DEBUG');
            cronLogMessage("Debug - Challenge Ended: " . ($data['challenge_ended'] ? 'YES' : 'NO'), 'DEBUG');
        }
        
        // Log some stats
        if (!empty($data['data'])) {
            $leader = $data['data'][0];
            cronLogMessage("Current leader: {$leader['username']} with {$leader['total']} SOL ({$leader['change_pct']}%)", 'INFO');
        }
        
    } else {
        cronLogMessage("❌ Update failed - updateLeaderboard() returned null/false", 'ERROR');
    }
    
} catch (Exception $e) {
    cronLogMessage("❌ Update failed with exception: " . $e->getMessage(), 'ERROR');
    cronLogMessage("Stack trace: " . $e->getTraceAsString(), 'DEBUG');
    
} catch (Error $e) {
    cronLogMessage("❌ Update failed with fatal error: " . $e->getMessage(), 'FATAL');
    cronLogMessage("Stack trace: " . $e->getTraceAsString(), 'DEBUG');
    
} finally {
    // Always remove lock file
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

// Log rotation (keep only last 1000 lines)
$logFile = __DIR__ . '/../logs/cron.log';
if (file_exists($logFile) && filesize($logFile) > 1024 * 1024) { // > 1MB
    $lines = file($logFile);
    if (count($lines) > 1000) {
        $keepLines = array_slice($lines, -1000);
        file_put_contents($logFile, implode('', $keepLines));
        cronLogMessage("Log file rotated, kept last 1000 entries", 'INFO');
    }
}

cronLogMessage("Update cycle completed\n" . str_repeat('-', 50), 'INFO');
?>