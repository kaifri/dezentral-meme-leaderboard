<?php
// Cron job script - runs every 30 seconds
// Enhanced version with proper error handling and logging

// Function definitions first
function logMessage($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    
    // Log to file
    $logFile = __DIR__ . '/../logs/cron.log';
    if (!is_dir(dirname($logFile))) {
        mkdir(dirname($logFile), 0755, true);
    }
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    
    // Also output to console
    echo $logEntry;
}

// Create lock file path
$lockFile = __DIR__ . '/../data/update.lock';

// Check for existing lock file
if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    $currentTime = time();
    
    // If lock is older than 5 minutes, remove it (stale lock)
    if ($currentTime - $lockTime > 300) {
        unlink($lockFile);
        logMessage("Removed stale lock file", 'WARNING');
    } else {
        logMessage("Update already running, exiting", 'INFO');
        exit(0);
    }
}

// Create lock file
file_put_contents($lockFile, getmypid());

// Load configuration
if (!defined('CONFIG_ACCESS')) {
    define('CONFIG_ACCESS', true);
}

$config = require_once __DIR__ . '/../config/config.php';

if (!$config || !is_array($config)) {
    logMessage("Failed to load configuration", 'FATAL');
    if (file_exists($lockFile)) unlink($lockFile);
    exit(1);
}

// Set global variables from config BEFORE including leaderboard.php
$HELIUS_API_KEY = $config['api']['helius_api_key'] ?? '';
$WINNER_POT_WALLET = $config['api']['winner_pot_wallet'] ?? '';
$CHALLENGE_END_DATE = $config['app']['challenge_end_date'] ?? '';
$CACHE_TIMEOUT = $config['app']['cache_timeout_seconds'] ?? 30;

// Set file paths BEFORE including leaderboard.php
$CONFIG_FILE = __DIR__ . '/../config/wallets.json';
$START_SOL_FILE = __DIR__ . '/../data/start_sol_balances.json';
$DATA_FILE = __DIR__ . '/../data/leaderboard.json';

// Log configuration values for debugging
logMessage("Config loaded - API Key: " . (empty($HELIUS_API_KEY) ? 'EMPTY' : 'SET'), 'DEBUG');
logMessage("Winner pot wallet: " . $WINNER_POT_WALLET, 'DEBUG');
logMessage("Challenge end date: " . $CHALLENGE_END_DATE, 'DEBUG');
logMessage("Config file: " . $CONFIG_FILE, 'DEBUG');
logMessage("Data file: " . $DATA_FILE, 'DEBUG');

// Check if required files exist
if (!file_exists($CONFIG_FILE)) {
    logMessage("Wallets config file not found: " . $CONFIG_FILE, 'FATAL');
    if (file_exists($lockFile)) unlink($lockFile);
    exit(1);
}

if (!file_exists($START_SOL_FILE)) {
    logMessage("Start SOL balances file not found: " . $START_SOL_FILE, 'FATAL');
    if (file_exists($lockFile)) unlink($lockFile);
    exit(1);
}

// Include the leaderboard functions AFTER setting globals
require_once __DIR__ . '/../api/leaderboard.php';

// Set timezone
date_default_timezone_set('Europe/Berlin');

try {
    logMessage("Starting leaderboard update...", 'INFO');
    
    // Measure execution time
    $startTime = microtime(true);
    
    // Call the update function
    $data = updateLeaderboard();
    
    $endTime = microtime(true);
    $executionTime = round(($endTime - $startTime) * 1000, 2); // in milliseconds
    
    $walletCount = count($data['data'] ?? []);
    $winnerPotBalance = $data['winner_pot']['balance'] ?? 0;
    
    logMessage("✅ Update completed successfully", 'SUCCESS');
    logMessage("📊 Processed {$walletCount} wallets in {$executionTime}ms", 'INFO');
    logMessage("💰 Winner pot balance: {$winnerPotBalance} SOL", 'INFO');
    
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

logMessage("Update process finished", 'INFO');
?>