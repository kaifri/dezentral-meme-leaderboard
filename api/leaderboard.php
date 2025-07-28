<?php
// filepath: /api/leaderboard.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Load configuration
$api_config = json_decode(file_get_contents(__DIR__ . '/../config/api.json'), true);
$app_config = json_decode(file_get_contents(__DIR__ . '/../config/app.json'), true);

// Configuration from files
$API_TOKEN = $api_config['api_token'];
$HELIUS_API_KEY = $api_config['helius_api_key'];
$WINNER_POT_WALLET = $api_config['winner_pot_wallet'];
$CHALLENGE_END_DATE = $app_config['challenge_end_date'];
$CACHE_TIMEOUT = $app_config['cache_timeout_seconds'];

// File paths
$CONFIG_FILE = __DIR__ . '/../config/wallets.json';
$START_SOL_FILE = __DIR__ . '/../data/start_sol_balances.json';
$DATA_FILE = __DIR__ . '/../data/leaderboard.json';

// Auth check
function checkAuth() {
    global $API_TOKEN;
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

// Update leaderboard data
function updateLeaderboard() {
    global $CONFIG_FILE, $START_SOL_FILE, $DATA_FILE, $WINNER_POT_WALLET, $CHALLENGE_END_DATE;
    
    // Load config
    $wallets = json_decode(file_get_contents($CONFIG_FILE), true);
    $startSols = json_decode(file_get_contents($START_SOL_FILE), true);
    
    // Check if challenge ended
    $challengeEnded = time() >= strtotime($CHALLENGE_END_DATE);
    
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
        'challenge_end_date' => $CHALLENGE_END_DATE
    ];
    
    // Save to file
    file_put_contents($DATA_FILE, json_encode($outputData, JSON_PRETTY_PRINT));
    
    return $outputData;
}

// Handle request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    checkAuth();
    
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
    checkAuth();
    
    // Manual update
    $data = updateLeaderboard();
    echo json_encode(['message' => 'Update successful', 'data' => $data]);
    
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>