<?php
// filepath: /api/leaderboard.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Load secure configuration
define('CONFIG_ACCESS', true);
$config = require_once __DIR__ . '/../config/config.php';

// Extract configuration values
$HELIUS_API_KEY = $config['api']['helius_api_key'];
$WINNER_POT_WALLET = $config['api']['winner_pot_wallet'];
$CHALLENGE_END_DATE = $config['app']['challenge_end_date'];
$CACHE_TIMEOUT = $config['app']['cache_timeout_seconds'];

// File paths
$CONFIG_FILE = __DIR__ . '/../config/wallets.json';
$START_SOL_FILE = __DIR__ . '/../data/start_sol_balances.json';
$DATA_FILE = __DIR__ . '/../data/leaderboard.json';

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
    
    // Add debug logging
    error_log("Fetching price for token: {$mint}");
    
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
    
    error_log("Found " . count($pairs) . " pairs for token: {$mint}");
    
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
        
        error_log("Checking pair: base={$baseToken}, quote={$quoteToken}, priceUsd={$priceUsd}");
        
        // If our token is the base token, use priceUsd directly
        if ($baseToken === $mint && $priceUsd > 0) {
            error_log("Found price for {$mint} as base token: {$priceUsd}");
            return $priceUsd;
        }
        // If our token is the quote token, invert the price
        elseif ($quoteToken === $mint && $priceUsd > 0) {
            $invertedPrice = 1 / $priceUsd;
            error_log("Found inverted price for {$mint} as quote token: {$invertedPrice}");
            return $invertedPrice;
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

// Get token price from Jupiter
function getTokenPriceJupiter($mint) {
    $url = "https://api.jup.ag/v4/price?ids={$mint}&vsToken=USDC";
    $response = @file_get_contents($url);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        $price = floatval($data['data'][$mint]['price'] ?? 0);
        if ($price > 0) return $price;
    }
    
    return 0;
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
    
    // Check if challenge ended - FIX: Ensure both times are in UTC
    $endDateTime = new DateTime($CHALLENGE_END_DATE, new DateTimeZone('UTC'));
    $nowDateTime = new DateTime('now', new DateTimeZone('UTC'));
    $challengeEnded = $nowDateTime >= $endDateTime;
    
    // Debug logging
    error_log("Challenge End Date: " . $CHALLENGE_END_DATE);
    error_log("Parsed End DateTime: " . $endDateTime->format('Y-m-d H:i:s T'));
    error_log("Current DateTime: " . $nowDateTime->format('Y-m-d H:i:s T'));
    error_log("Challenge Ended: " . ($challengeEnded ? 'YES' : 'NO'));
    error_log("Time difference (seconds): " . ($endDateTime->getTimestamp() - $nowDateTime->getTimestamp()));
    
    // Get winner pot balance
    $winnerPotBalance = getSolBalance($WINNER_POT_WALLET);
    
    $leaderboard = [];
    $solPriceUsd = getSolPriceUsd();
    
    foreach ($wallets as $entry) {
        $wallet = $entry['wallet'];
        $username = $entry['username'] ?? substr($wallet, 0, 6);
        
        // Log wallet processing start
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
        'challenge_end_date' => $CHALLENGE_END_DATE,
        // Debug info
        'debug' => [
            'end_date_parsed' => $endDateTime->format('Y-m-d H:i:s T'),
            'current_time' => $nowDateTime->format('Y-m-d H:i:s T'),
            'challenge_ended' => $challengeEnded
        ]
    ];
    
    // Save to file
    file_put_contents($DATA_FILE, json_encode($outputData, JSON_PRETTY_PRINT));
    
    return $outputData;
}

// Handle request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Return cached data if exists
    if (file_exists($DATA_FILE)) {
        echo file_get_contents($DATA_FILE);
        exit;
    }
    
    // If no cached data exists, return empty structure
    echo json_encode([
        'updated' => null,
        'data' => [],
        'winner_pot' => ['wallet' => $WINNER_POT_WALLET, 'balance' => 0],
        'challenge_ended' => false,
        'challenge_end_date' => $CHALLENGE_END_DATE
    ]);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Keep auth for manual updates via POST
    checkAuth();
    
    // Manual update
    $data = updateLeaderboard();
    echo json_encode(['message' => 'Update successful', 'data' => $data]);
}

// Auth check function (only used for POST requests now)
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
?>