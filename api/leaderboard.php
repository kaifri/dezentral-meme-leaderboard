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
function getTokenPrice($mint, $preventRecursion = false) {
    // Special case for SOL token to prevent recursion
    if (strtolower($mint) === strtolower("So11111111111111111111111111111111111111112") && !$preventRecursion) {
        logMessage("SOL token detected, using direct USD price from Jupiter", 'DEBUG');
        return getSolPriceUsd();
    }
    
    // Skip known stable coins
    if ($mint === "EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v") { // USDC
        return 1; // USDC is basically $1
    }
    
    logMessage("Getting price for token: " . substr($mint, 0, 12) . "...", 'DEBUG');
    
    // Check for manual override
    $overridesFile = __DIR__ . '/../config/token_price_overrides.json';
    if (file_exists($overridesFile)) {
        $overrides = json_decode(file_get_contents($overridesFile), true);
        if (isset($overrides[$mint])) {
            $override = $overrides[$mint];
            if (time() - $override['timestamp'] < 3600) { // Valid for 1 hour
                logMessage("Using manual price override: " . $override['price_usd'] . " USD", 'INFO');
                return $override['price_usd'];
            }
        }
    }
    
    // Get price from Dexscreener - simpler approach that worked before
    $url = "https://api.dexscreener.com/latest/dex/tokens/{$mint}";
    $response = @file_get_contents($url);
    
    if ($response === false) {
        logMessage("Dexscreener API request failed for token: " . substr($mint, 0, 12), 'WARNING');
        return 0;
    }
    
    $data = json_decode($response, true);
    $pairs = $data['pairs'] ?? [];
    
    if (empty($pairs)) {
        logMessage("No pairs found for token: " . substr($mint, 0, 12), 'WARNING');
        return 0;
    }
    
    // Sort by liquidity
    usort($pairs, function($a, $b) {
        $liqA = floatval($a['liquidity']['usd'] ?? 0);
        $liqB = floatval($b['liquidity']['usd'] ?? 0);
        return $liqB <=> $liqA;
    });
    
    // Log the top pairs for debugging
    $topPairsCount = min(3, count($pairs));
    for ($i = 0; $i < $topPairsCount; $i++) {
        $pair = $pairs[$i];
        logMessage("Top pair #{$i}: " . 
               ($pair['baseToken']['symbol'] ?? '?') . "/" . 
               ($pair['quoteToken']['symbol'] ?? '?') . 
               " - Liq: $" . ($pair['liquidity']['usd'] ?? 0), 'DEBUG');
    }
    
    // First try direct SOL pairs for better calculation
    foreach ($pairs as $pair) {
        $baseToken = $pair['baseToken']['address'] ?? '';
        $quoteToken = $pair['quoteToken']['address'] ?? '';
        
        // Direct SOL pair
        if (strtolower($quoteToken) === strtolower("So11111111111111111111111111111111111111112") && 
            strtolower($baseToken) === strtolower($mint)) {
            $priceInSol = floatval($pair['priceNative'] ?? 0);
            if ($priceInSol > 0) {
                logMessage("Found direct SOL pair price: " . $priceInSol . " SOL", 'DEBUG');
                // Convert to USD for consistent return values
                $solPriceUsd = getSolPriceUsd();
                return $priceInSol * $solPriceUsd;
            }
        }
        
        // Inverse SOL pair
        if (strtolower($baseToken) === strtolower("So11111111111111111111111111111111111111112") && 
            strtolower($quoteToken) === strtolower($mint)) {
            $priceInSol = 1 / floatval($pair['priceNative'] ?? 0);
            if ($priceInSol > 0 && is_finite($priceInSol)) {
                logMessage("Found inverse SOL pair price: " . $priceInSol . " SOL", 'DEBUG');
                // Convert to USD for consistent return values
                $solPriceUsd = getSolPriceUsd();
                return $priceInSol * $solPriceUsd;
            }
        }
    }
    
    // Then try USD pairs (directly)
    foreach ($pairs as $pair) {
        $baseToken = $pair['baseToken']['address'] ?? '';
        $quoteToken = $pair['quoteToken']['address'] ?? '';
        $priceUsd = floatval($pair['priceUsd'] ?? 0);
        
        if (strtolower($baseToken) === strtolower($mint) && $priceUsd > 0) {
            logMessage("Found direct USD price: $" . $priceUsd, 'DEBUG');
            return $priceUsd;
        } else if (strtolower($quoteToken) === strtolower($mint) && $priceUsd > 0) {
            $inversePrice = 1 / $priceUsd;
            logMessage("Found inverse USD price: $" . $inversePrice, 'DEBUG');
            return $inversePrice;
        }
    }
    
    logMessage("No valid price found for token: " . substr($mint, 0, 12), 'WARNING');
    return 0;
}

// Update the getSolPriceUsd function too, to avoid recursion
function getSolPriceUsd() {
    // Try Jupiter first - this is more reliable for SOL price
    $url = "https://api.jup.ag/v4/price?ids=So11111111111111111111111111111111111111112&vsToken=USDC";
    $response = @file_get_contents($url);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        $price = 0;
        
        // Case-insensitive lookup
        foreach ($data['data'] ?? [] as $key => $value) {
            if (strtolower($key) === strtolower("So11111111111111111111111111111111111111112")) {
                $price = floatval($value['price'] ?? 0);
                break;
            }
        }
        
        if ($price > 0) {
            logMessage("Got SOL price from Jupiter: $" . $price, 'DEBUG');
            return $price;
        }
    }
    
    // Use hardcoded fallback - DON'T call getTokenPrice to avoid recursion
    logMessage("Using hardcoded SOL price fallback", 'WARNING');
    return 30.0; // Hardcoded SOL price - update this regularly
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
function updateLeaderboard($configInput = null) {
    global $CONFIG_FILE, $START_SOL_FILE, $DATA_FILE, $WINNER_POT_WALLET;
    
    logMessage("Starting leaderboard update...", 'INFO');
    
    // Load config - priority: parameter > global > file
    if ($configInput) {
        $configToUse = $configInput; // Renamed to avoid shadowing
    } elseif (isset($GLOBALS['config'])) {
        $configToUse = $GLOBALS['config'];
    } else {
        // Fallback: load config directly
        define('CONFIG_ACCESS', true);
        $configToUse = require_once __DIR__ . '/../config/config.php';
    }
    
    // Debug the config we're actually using
    logMessage("Config structure: " . (isset($configToUse['app']['challenge_end_date']) ? 
        "Has challenge_end_date: " . $configToUse['app']['challenge_end_date'] : 
        "Missing challenge_end_date"), 'DEBUG');
    
    // Load wallets and start SOL values
    $wallets = json_decode(file_get_contents($CONFIG_FILE), true);
    $startSols = json_decode(file_get_contents($START_SOL_FILE), true);
    
    // Get challenge end date from config
    $challengeEndDateRaw = $configToUse['app']['challenge_end_date'] ?? null;
    
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
    logMessage("SOL price in USD: " . $solPriceUsd, 'DEBUG');
    
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
                // Try direct SOL-based price first (value already in SOL)
                $tokenPriceInSol = getTokenPrice($mint);
                
                if ($tokenPriceInSol > 0) {
                    $oldValue = $tokenValue;
                    $tokenValue += $amount * $tokenPriceInSol;
                    logMessage("Token " . substr($mint, 0, 8) . "... value: " . 
                               $amount . " × " . $tokenPriceInSol . " = " . 
                               ($amount * $tokenPriceInSol) . " SOL", 'DEBUG');
                } else {
                    // Fallback to USD price divided by SOL price
                    $tokenPriceUsd = getTokenPrice($mint);
                    if ($tokenPriceUsd > 0 && $solPriceUsd > 0) {
                        $tokenValue += $amount * ($tokenPriceUsd / $solPriceUsd);
                        logMessage("Token " . substr($mint, 0, 8) . "... value via USD: " . 
                                   $amount . " × (" . $tokenPriceUsd . " / " . $solPriceUsd . ") = " . 
                                   ($amount * ($tokenPriceUsd / $solPriceUsd)) . " SOL", 'DEBUG');
                    }
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
            'current_time' => $nowDateTime->format('Y-m-d H:i:s T'),
            'is_valid_date' => !empty($challengeEndDateRaw) && strtotime($challengeEndDateRaw) !== false
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