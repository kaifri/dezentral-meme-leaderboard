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

// Global variables for Helius API and wallet config
$HELIUS_API_KEY = $config['api']['helius_api_key'];
$WINNER_POT_WALLET = $config['api']['winner_pot_wallet'];
$CONFIG_FILE = __DIR__ . '/../config/wallets.json';
$START_SOL_FILE = __DIR__ . '/../data/start_sol_balances.json';

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

// Get SOL balance
function getSolBalance($wallet) {
    $data = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'getBalance',
        'params' => [$wallet]
    ];
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($data)
        ]
    ]);
    
    $response = file_get_contents('https://api.mainnet-beta.solana.com', false, $context);
    $result = json_decode($response, true);
    
    if (isset($result['result']['value'])) {
        return $result['result']['value'] / 1000000000;
    }
    return 0;
}

// Get token balances
function getTokenBalances($wallet) {
    global $HELIUS_API_KEY;
    
    $url = "https://api.helius.xyz/v0/addresses/{$wallet}/balances?api-key={$HELIUS_API_KEY}";
    $response = file_get_contents($url);
    $data = json_decode($response, true);
    
    $tokens = [];
    if (isset($data['tokens']) && is_array($data['tokens'])) {
        foreach ($data['tokens'] as $token) {
            $mint = $token['mint'];
            $amount = floatval($token['amount']) / pow(10, intval($token['decimals']));
            if ($amount > 0) {
                $tokens[$mint] = ($tokens[$mint] ?? 0) + $amount;
            }
        }
    }
    
    return $tokens;
}

// Get token price from Dexscreener
function getTokenPrice($mint) {
    $url = "https://api.dexscreener.com/latest/dex/tokens/{$mint}";
    $response = @file_get_contents($url);
    
    if ($response === false) return 0;
    
    $data = json_decode($response, true);
    $pairs = $data['pairs'] ?? [];
    
    if (empty($pairs)) return 0;
    
    // Sort by liquidity
    usort($pairs, function($a, $b) {
        $liqA = floatval($a['liquidity']['usd'] ?? 0);
        $liqB = floatval($b['liquidity']['usd'] ?? 0);
        return $liqB <=> $liqA;
    });
    
    foreach ($pairs as $pair) {
        $baseToken = $pair['baseToken']['address'] ?? '';
        $quoteToken = $pair['quoteToken']['address'] ?? '';
        $priceUsd = floatval($pair['priceUsd'] ?? 0);
        
        if ($baseToken === $mint && $priceUsd > 0) {
            return $priceUsd;
        } elseif ($quoteToken === $mint && $priceUsd > 0) {
            return 1 / $priceUsd;
        }
    }
    
    return 0;
}

// Get SOL price in USD
function getSolPriceUsd() {
    // Try Jupiter first
    $url = "https://api.jup.ag/v4/price?ids=So11111111111111111111111111111111111111112&vsToken=USDC";
    $response = @file_get_contents($url);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        $price = floatval($data['data']['So11111111111111111111111111111111111111112']['price'] ?? 0);
        if ($price > 0) return $price;
    }
    
    // Fallback to Dexscreener
    return getTokenPrice("So11111111111111111111111111111111111111112");
}

// Get Swap History
function getSwapHistory($wallet, $startDate) {
    global $HELIUS_API_KEY;
    
    // Simplified version - no longer tracking swap volume
    return [
        'total_volume_sol' => 0,
        'swap_count' => 0,
        'avg_trade_size' => 0
    ];
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

// Update leaderboard data - only called by update.php, not automatically on web requests
function updateLeaderboard($configOverride = null) {
    global $CONFIG_FILE, $START_SOL_FILE, $DATA_FILE, $WINNER_POT_WALLET;
    
    logMessage("Starting leaderboard update...", 'INFO');
    
    // Load config - priority: parameter > global > file
    if ($configOverride) {
        $config = $configOverride;
    } elseif (isset($GLOBALS['config'])) {
        $config = $GLOBALS['config'];
    } else {
        // Fallback: load config directly
        define('CONFIG_ACCESS', true);
        $config = require_once __DIR__ . '/../config/config.php';
    }
    
    // Load wallets and start SOL values
    $wallets = json_decode(file_get_contents($CONFIG_FILE), true);
    $startSols = json_decode(file_get_contents($START_SOL_FILE), true);
    
    // Get challenge end date from config
    $challengeEndDateRaw = $config['app']['challenge_end_date'] ?? null;
    
    // Debug logging
    logMessage("Raw Challenge End Date from config: " . ($challengeEndDateRaw ?? 'NULL'), 'DEBUG');
    
    if (!$challengeEndDateRaw) {
        logMessage("ERROR: challenge_end_date not found in config!", 'ERROR');
        $challengeEnded = false;
        $endDateTime = new DateTime();
        $nowDateTime = new DateTime('now', new DateTimeZone('UTC'));
    } else {
        // Parse the ISO 8601 datetime string
        try {
            $endDateTime = new DateTime($challengeEndDateRaw);
            $nowDateTime = new DateTime('now', new DateTimeZone('UTC'));
            $challengeEnded = $nowDateTime >= $endDateTime;
            
            // Debug logging
            logMessage("Challenge End Date: " . $challengeEndDateRaw, 'DEBUG');
            logMessage("Parsed End DateTime: " . $endDateTime->format('Y-m-d H:i:s T'), 'DEBUG');
            logMessage("Current DateTime: " . $nowDateTime->format('Y-m-d H:i:s T'), 'DEBUG');
            logMessage("Challenge Ended: " . ($challengeEnded ? 'YES' : 'NO'), 'DEBUG');
        } catch (Exception $e) {
            logMessage("ERROR parsing challenge_end_date: " . $e->getMessage(), 'ERROR');
            $challengeEnded = false;
            $endDateTime = new DateTime();
            $nowDateTime = new DateTime('now', new DateTimeZone('UTC'));
        }
    }
    
    // Get winner pot balance
    $winnerPotBalance = getSolBalance($WINNER_POT_WALLET);
    
    $leaderboard = [];
    $solPriceUsd = getSolPriceUsd();
    
    foreach ($wallets as $entry) {
        $wallet = $entry['wallet'];
        $username = $entry['username'] ?? substr($wallet, 0, 6);
        
        if (!isset($startSols[$wallet])) {
            continue;
        }
        
        $sol = getSolBalance($wallet);
        $tokenValue = 0;
        
        // Get swap data (simplified)
        $swapData = getSwapHistory($wallet, $config['app']['challenge_start_date']);
        
        if (!$challengeEnded) {
            $tokens = getTokenBalances($wallet);
            foreach ($tokens as $mint => $amount) {
                $tokenPriceUsd = getTokenPrice($mint);
                if ($tokenPriceUsd > 0 && $solPriceUsd > 0) {
                    $tokenValue += $amount * ($tokenPriceUsd / $solPriceUsd);
                }
            }
        } else {
            // Use frozen token values from last update
            if (file_exists($DATA_FILE)) {
                $lastData = json_decode(file_get_contents($DATA_FILE), true);
                foreach ($lastData['data'] as $lastEntry) {
                    if ($lastEntry['wallet'] === $wallet) {
                        $tokenValue = $lastEntry['tokens'];
                        break;
                    }
                }
            }
        }
        
        $total = $sol + $tokenValue;
        $start = $startSols[$wallet];
        $changePct = $start > 0 ? (($total - $start) / $start * 100) : 0;
        
        $leaderboard[] = [
            'username' => $username,
            'wallet' => $wallet,
            'sol' => round($sol, 4),
            'tokens' => round($tokenValue, 4),
            'total' => round($total, 4),
            'change_pct' => round($changePct, 2)
        ];
    }
    
    // Sort by total
    usort($leaderboard, function($a, $b) {
        return $b['total'] <=> $a['total'];
    });
    
    $outputData = [
        'updated' => date('c'),
        'data' => $leaderboard,
        'winner_pot' => [
            'wallet' => $WINNER_POT_WALLET,
            'balance' => round($winnerPotBalance, 4)
        ],
        'challenge_ended' => $challengeEnded,
        'challenge_end_date' => $challengeEndDateRaw,
        // Debug info
        'debug' => [
            'raw_end_date' => $challengeEndDateRaw,
            'end_date_parsed' => $endDateTime->format('Y-m-d H:i:s T'),
            'current_time' => $nowDateTime->format('Y-m-d H:i:s T')
        ]
    ];
    
    // Save to file
    file_put_contents($DATA_FILE, json_encode($outputData, JSON_PRETTY_PRINT));
    logMessage("Leaderboard data updated and saved to file", 'INFO');
    
    return $outputData;
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