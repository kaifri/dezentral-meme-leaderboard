<?php
// filepath: /api/leaderboard.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Load secure configuration
define('CONFIG_ACCESS', true);
$config = require_once __DIR__ . '/../config/config.php';

// File paths
$DATA_FILE = __DIR__ . '/../data/leaderboard.json';
$CACHE_TIMEOUT = $config['app']['cache_timeout_seconds'];

// Enhanced logging function
function logMessage($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logFile = __DIR__ . '/../logs/leaderboard.log';
    
    // Create logs directory if it doesn't exist
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logEntry = "[{$timestamp}] [{$level}] {$message}\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// Handle request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    logMessage("GET request received for leaderboard", 'INFO');
    
    // Check if data file exists
    if (!file_exists($DATA_FILE)) {
        logMessage("ERROR: Leaderboard data file not found: " . $DATA_FILE, 'ERROR');
        http_response_code(404);
        echo json_encode([
            'error' => 'Leaderboard data not available',
            'message' => 'Data is being generated. Please try again in a moment.',
            'updated' => null,
            'data' => []
        ]);
        exit;
    }
    
    // Get file age
    $fileTime = filemtime($DATA_FILE);
    $fileAge = time() - $fileTime;
    
    logMessage("Returning leaderboard data (file age: " . $fileAge . "s, cache timeout: " . $CACHE_TIMEOUT . "s)", 'INFO');
    
    // Read and return the data
    $jsonData = file_get_contents($DATA_FILE);
    $data = json_decode($jsonData, true);
    
    if ($data === null) {
        logMessage("ERROR: Invalid JSON in leaderboard data file", 'ERROR');
        http_response_code(500);
        echo json_encode([
            'error' => 'Invalid leaderboard data',
            'message' => 'Data file is corrupted. Please try again later.',
            'updated' => null,
            'data' => []
        ]);
        exit;
    }
    
    // Add cache info to response
    $data['cache_info'] = [
        'file_age_seconds' => $fileAge,
        'cache_timeout_seconds' => $CACHE_TIMEOUT,
        'is_stale' => $fileAge > $CACHE_TIMEOUT,
        'last_modified' => date('c', $fileTime)
    ];
    
    // If data is very stale (more than 2x cache timeout), add warning
    if ($fileAge > ($CACHE_TIMEOUT * 2)) {
        $data['warning'] = 'Data may be outdated. Update system might be experiencing issues.';
        logMessage("WARNING: Data is very stale (age: " . $fileAge . "s)", 'WARNING');
    }
    
    echo json_encode($data);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logMessage("POST request received - triggering manual update", 'INFO');
    
    // Auth check for manual updates
    checkAuth();
    
    // Trigger update by calling update.php
    $updateUrl = 'http://localhost' . dirname($_SERVER['REQUEST_URI']) . '/update.php';
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $config['api']['token']
            ],
            'content' => json_encode(['trigger' => 'manual'])
        ]
    ]);
    
    $response = @file_get_contents($updateUrl, false, $context);
    
    if ($response === false) {
        logMessage("ERROR: Failed to trigger update via " . $updateUrl, 'ERROR');
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to trigger update',
            'message' => 'Update system is not responding'
        ]);
        exit;
    }
    
    $updateResult = json_decode($response, true);
    
    logMessage("Manual update triggered successfully", 'INFO');
    echo json_encode([
        'message' => 'Update triggered successfully',
        'update_result' => $updateResult
    ]);
    
} else {
    logMessage("Invalid request method: " . $_SERVER['REQUEST_METHOD'], 'WARNING');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

// Auth check function
function checkAuth() {
    global $config;
    $API_TOKEN = $config['api']['token'];
    
    $headers = getallheaders();
    $auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    
    if (!$auth_header || !str_starts_with($auth_header, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['error' => 'Missing or invalid authorization header']);
        exit;
    }
    
    $token = substr($auth_header, 7);
    if ($token !== $API_TOKEN) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }
}

// Log rotation
$logFile = __DIR__ . '/../logs/leaderboard.log';
if (file_exists($logFile) && filesize($logFile) > 1024 * 1024) { // > 1MB
    $lines = file($logFile);
    if (count($lines) > 1000) {
        $keepLines = array_slice($lines, -1000);
        file_put_contents($logFile, implode('', $keepLines));
        logMessage("Log file rotated, kept last 1000 lines", 'INFO');
    }
}
?>