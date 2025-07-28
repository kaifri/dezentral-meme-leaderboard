<?php
// Only set headers and handle HTTP requests if running via web server
if (isset($_SERVER['REQUEST_METHOD'])) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

// Load secure configuration only if not already defined
if (!defined('CONFIG_ACCESS')) {
    define('CONFIG_ACCESS', true);
    $config = require_once __DIR__ . '/../config/config.php';
    
    // Check if config loaded properly
    if ($config === false || !is_array($config)) {
        error_log("Failed to load config file");
        if (isset($_SERVER['REQUEST_METHOD'])) {
            http_response_code(500);
            echo json_encode(['error' => 'Configuration error']);
        }
        exit;
    }
    
    // Extract configuration values with fallbacks
    $HELIUS_API_KEY = $config['api']['helius_api_key'] ?? '';
    $WINNER_POT_WALLET = $config['api']['winner_pot_wallet'] ?? '';
    $CHALLENGE_END_DATE = $config['app']['challenge_end_date'] ?? '';
    $CACHE_TIMEOUT = $config['app']['cache_timeout_seconds'] ?? 30;
    
    // File paths
    $CONFIG_FILE = __DIR__ . '/../config/wallets.json';
    $START_SOL_FILE = __DIR__ . '/../data/start_sol_balances.json';
    $DATA_FILE = __DIR__ . '/../data/leaderboard.json';
}

// Authentication function
function checkAuth() {
    global $config;
    
    if (!isset($_POST['password'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Password required']);
        exit;
    }
    
    if ($_POST['password'] !== ($config['app']['admin_password'] ?? '')) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid password']);
        exit;
    }
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
        return $result['result']['value'] / 1000000000; // Convert lamports to SOL
    }
    return 0;
}

// Get token balances
function getTokenBalances($wallet) {
    global $HELIUS_API_KEY;
    
    $url = "https://api.helius.xyz/v0/addresses/{$wallet}/balances?api-key={$HELIUS_API_KEY}";
    
    // Log the API call
    error_log("Fetching token balances for wallet: {$wallet}");
    error_log("Helius URL: {$url}");
    
    $response = file_get_contents($url);
    
    if ($response === false) {
        error_log("Failed to fetch token balances from Helius for wallet: {$wallet}");
        return [];
    }
    
    $data = json_decode($response, true);
    
    // Log the raw response for debugging
    error_log("Helius response for {$wallet}: " . substr($response, 0, 500) . "...");
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error for wallet {$wallet}: " . json_last_error_msg());
        return [];
    }
    
    $tokens = [];
    if (isset($data['tokens']) && is_array($data['tokens'])) {
        error_log("Found " . count($data['tokens']) . " raw tokens for wallet: {$wallet}");
        
        foreach ($data['tokens'] as $token) {
            $mint = $token['mint'];
            $rawAmount = $token['amount'];
            $decimals = intval($token['decimals']);
            $amount = floatval($rawAmount) / pow(10, $decimals);
            
            error_log("Token {$mint}: raw={$rawAmount}, decimals={$decimals}, calculated={$amount}");
            
            if ($amount > 0) {
                $tokens[$mint] = ($tokens[$mint] ?? 0) + $amount;
                error_log("Added token {$mint} with amount {$amount}");
            } else {
                error_log("Skipped token {$mint} - zero amount");
            }
        }
    } else {
        error_log("No tokens array found in response for wallet: {$wallet}");
        if (isset($data['error'])) {
            error_log("Helius API error: " . json_encode($data['error']));
        }
    }
    
    error_log("Final token count for {$wallet}: " . count($tokens));
    return $tokens;
}

// Get token price from Dexscreener
function getTokenPrice($mint) {
    $url = "https://api.dexscreener.com/latest/dex/tokens/{$mint}";
    $response = @file_get_contents($url);
    
    if ($response === false) {
        error_log("Failed to fetch data from Dexscreener for token: {$mint}");
        return 0;
    }
    
    $data = json_decode($response, true);
    $pairs = $data['pairs'] ?? [];
    
    if (empty($pairs)) {
        error_log("No trading pairs found for token: {$mint}");
        return 0;
    }
    
    // Sort by liquidity (USD value)
    usort($pairs, function($a, $b) {
        $liqA = floatval($a['liquidity']['usd'] ?? 0);
        $liqB = floatval($b['liquidity']['usd'] ?? 0);
        return $liqB <=> $liqA;
    });
    
    foreach ($pairs as $pair) {
        $baseToken = $pair['baseToken']['address'] ?? '';
        $quoteToken = $pair['quoteToken']['address'] ?? '';
        $priceUsd = floatval($pair['priceUsd'] ?? 0);
        
        // If our token is the base token, use priceUsd directly
        if ($baseToken === $mint && $priceUsd > 0) {
            return $priceUsd;
        }
        // If our token is the quote token, invert the price
        elseif ($quoteToken === $mint && $priceUsd > 0) {
            return 1 / $priceUsd;
        }
    }
    
    error_log("No valid price found for token: {$mint}");
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

// Log wallet activity to a file
function logWalletActivity($wallet, $message) {
    $logDir = __DIR__ . '/../logs';
    
    // Create logs directory if it doesn't exist
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/' . $wallet . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// Update leaderboard data
function updateLeaderboard() {
    global $CONFIG_FILE, $START_SOL_FILE, $DATA_FILE, $WINNER_POT_WALLET, $CHALLENGE_END_DATE;
    
    // Load config
    $wallets = json_decode(file_get_contents($CONFIG_FILE), true);
    $startSols = json_decode(file_get_contents($START_SOL_FILE), true);
    
    // Check if challenge ended
    $endDateTime = new DateTime($CHALLENGE_END_DATE, new DateTimeZone('UTC'));
    $nowDateTime = new DateTime('now', new DateTimeZone('UTC'));
    $challengeEnded = $nowDateTime >= $endDateTime;
    
    // Get winner pot balance
    $winnerPotBalance = getSolBalance($WINNER_POT_WALLET);
    
    $leaderboard = [];
    $solPriceUsd = getSolPriceUsd();
    
    foreach ($wallets as $entry) {
        $wallet = $entry['wallet'];
        $username = $entry['username'] ?? substr($wallet, 0, 6);
        
        logWalletActivity($wallet, "=== PROCESSING WALLET: {$username} ===");
        
        if (!isset($startSols[$wallet])) {
            logWalletActivity($wallet, "ERROR: No start SOL balance found, skipping wallet");
            continue;
        }
        
        $sol = getSolBalance($wallet);
        logWalletActivity($wallet, "SOL balance: {$sol}");
        
        $tokenValue = 0;
        
        if (!$challengeEnded) {
            $tokens = getTokenBalances($wallet);
            logWalletActivity($wallet, "Found " . count($tokens) . " tokens");
            
            foreach ($tokens as $mint => $amount) {
                logWalletActivity($wallet, "Processing token {$mint} with amount {$amount}");
                
                $tokenPriceUsd = getTokenPrice($mint);
                logWalletActivity($wallet, "Token {$mint} price: {$tokenPriceUsd} USD");
                
                if ($tokenPriceUsd > 0 && $solPriceUsd > 0) {
                    $tokenValueSol = $amount * ($tokenPriceUsd / $solPriceUsd);
                    $tokenValue += $tokenValueSol;
                    logWalletActivity($wallet, "Added {$tokenValueSol} SOL value from token {$mint}");
                } else {
                    $reason = $tokenPriceUsd <= 0 ? "no price found" : "SOL price unavailable";
                    logWalletActivity($wallet, "Skipped token {$mint} - {$reason}");
                }
            }
        } else {
            // Use frozen token values from last update
            if (file_exists($DATA_FILE)) {
                $lastData = json_decode(file_get_contents($DATA_FILE), true);
                foreach ($lastData['data'] as $lastEntry) {
                    if ($lastEntry['wallet'] === $wallet) {
                        $tokenValue = $lastEntry['tokens'];
                        logWalletActivity($wallet, "Using frozen token value: {$tokenValue} SOL");
                        break;
                    }
                }
            } else {
                logWalletActivity($wallet, "No previous data found for frozen token values");
            }
        }
        
        $total = $sol + $tokenValue;
        $start = $startSols[$wallet];
        $changePct = $start > 0 ? (($total - $start) / $start * 100) : 0;
        
        logWalletActivity($wallet, "SUMMARY: SOL={$sol}, Tokens={$tokenValue}, Total={$total}, Change={$changePct}%");
        logWalletActivity($wallet, "=== END PROCESSING ===\n");
        
        $leaderboard[] = [
            'username' => $username,
            'wallet' => $wallet,
            'sol' => round($sol, 4),
            'tokens' => round($tokenValue, 4),
            'total' => round($total, 4),
            'change_pct' => round($changePct, 2)
        ];
    }
    
    // Sort by total (descending)
    usort($leaderboard, function($a, $b) {
        return $b['total'] <=> $a['total'];
    });
    
    $result = [
        'updated' => date('Y-m-d H:i:s'),
        'data' => $leaderboard,
        'winner_pot' => [
            'wallet' => $WINNER_POT_WALLET,
            'balance' => round($winnerPotBalance, 4)
        ],
        'challenge_ended' => $challengeEnded,
        'challenge_end_date' => $CHALLENGE_END_DATE
    ];
    
    // Save to file
    file_put_contents($DATA_FILE, json_encode($result, JSON_PRETTY_PRINT));
    
    return $result;
}

// Only handle HTTP requests if running via web server
if (isset($_SERVER['REQUEST_METHOD'])) {
    // Handle request
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Return cached data if exists and is recent
        if (file_exists($DATA_FILE)) {
            $fileTime = filemtime($DATA_FILE);
            if (time() - $fileTime < $CACHE_TIMEOUT) {
                echo file_get_contents($DATA_FILE);
                exit;
            }
        }
        
        // Update and return new data
        $data = updateLeaderboard();
        echo json_encode($data);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Keep auth for manual updates via POST
        checkAuth();
        
        // Manual update
        $data = updateLeaderboard();
        echo json_encode(['message' => 'Update successful', 'data' => $data]);
    }
}
?>