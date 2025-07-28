<?php
// filepath: /cron/update.php
// Cron job script - runs every 30 seconds
// Enhanced version with proper error handling and logging

// Load secure configuration
define('CONFIG_ACCESS', true);
$config = require_once __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../api/leaderboard.php';

// Set timezone
date_default_timezone_set('Europe/Berlin');

// Enhanced logging function
function logMessage($message, $level = 'INFO') {
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

// Check if update is already running (prevent overlapping)
$lockFile = __DIR__ . '/../data/update.lock';
if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    if (time() - $lockTime < 60) { // Lock expires after 60 seconds
        logMessage("Update already running, skipping...", 'WARNING');
        exit;
    } else {
        // Remove stale lock file
        unlink($lockFile);
        logMessage("Removed stale lock file", 'INFO');
    }
}

// Create lock file
file_put_contents($lockFile, date('Y-m-d H:i:s'));

try {
    logMessage("Starting leaderboard update...", 'INFO');
    
    // Measure execution time
    $startTime = microtime(true);
    
    // Call the update function
    $data = updateLeaderboard();
    
    $endTime = microtime(true);
    $executionTime = round(($endTime - $startTime) * 1000, 2); // in milliseconds
    
    if ($data) {
        $walletCount = count($data['data']);
        $challengeStatus = $data['challenge_ended'] ? 'ENDED' : 'ACTIVE';
        
        logMessage("✅ Update successful: {$walletCount} wallets processed in {$executionTime}ms, Challenge: {$challengeStatus}", 'SUCCESS');
        
        // Log some stats
        if (!empty($data['data'])) {
            $leader = $data['data'][0];
            logMessage("Current leader: {$leader['username']} with {$leader['total']} SOL ({$leader['change_pct']}%)", 'INFO');
        }
        
    } else {
        logMessage("❌ Update failed - updateLeaderboard() returned null/false", 'ERROR');
    }
    
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

// Log rotation (keep only last 1000 lines)
$logFile = __DIR__ . '/../logs/cron.log';
if (file_exists($logFile) && filesize($logFile) > 1024 * 1024) { // > 1MB
    $lines = file($logFile);
    if (count($lines) > 1000) {
        $keepLines = array_slice($lines, -1000);
        file_put_contents($logFile, implode('', $keepLines));
        logMessage("Log file rotated, kept last 1000 entries", 'INFO');
    }
}

logMessage("Update cycle completed\n" . str_repeat('-', 50), 'INFO');
?>