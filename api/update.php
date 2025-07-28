<?php
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Load configuration first
define('CONFIG_ACCESS', true);
$config = require_once __DIR__ . '/../config/config.php';

// Include the leaderboard functions
require_once __DIR__ . '/leaderboard.php';

// Enhanced logging function (same as in leaderboard.php)
function logMessage($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logFile = __DIR__ . '/../logs/update.log';
    
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logEntry = "[{$timestamp}] [{$level}] {$message}\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// Handle requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get auth header
    $headers = getallheaders();
    $auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    
    if (!$auth_header || !str_starts_with($auth_header, 'Bearer ')) {
        logMessage("Unauthorized update attempt - missing auth header", 'WARNING');
        http_response_code(401);
        echo json_encode(['error' => 'Missing authorization header']);
        exit;
    }
    
    $token = substr($auth_header, 7);
    if ($token !== $config['api']['token']) {
        logMessage("Unauthorized update attempt - invalid token", 'WARNING');
        http_response_code(401);
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }
    
    logMessage("Starting scheduled leaderboard update...", 'INFO');
    
    try {
        // **KEY FIX: Pass the config to updateLeaderboard**
        $result = updateLeaderboard($config);
        
        logMessage("Leaderboard update completed successfully", 'INFO');
        echo json_encode([
            'success' => true,
            'timestamp' => date('c'),
            'message' => 'Leaderboard updated successfully',
            'debug' => [
                'config_passed' => 'YES',
                'challenge_end_date' => $config['app']['challenge_end_date'] ?? 'MISSING'
            ]
        ]);
        
    } catch (Exception $e) {
        logMessage("ERROR during leaderboard update: " . $e->getMessage(), 'ERROR');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Update failed',
            'message' => $e->getMessage()
        ]);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Handle preflight requests
    http_response_code(200);
    
} else {
    logMessage("Invalid request method for update: " . $_SERVER['REQUEST_METHOD'], 'WARNING');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>