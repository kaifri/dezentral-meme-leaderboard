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
$CHALLENGE_END_DATE = $config['app']['challenge_end_date']; // This should work but apparently doesn't
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
function updateLeaderboard($configOverride = null) {
    global $CONFIG_FILE, $START_SOL_FILE, $DATA_FILE, $WINNER_POT_WALLET, $CHALLENGE_END_DATE;
    
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
    error_log("Raw Challenge End Date from config: " . ($challengeEndDateRaw ?? 'NULL'));
    
    if (!$challengeEndDateRaw) {
        error_log("ERROR: challenge_end_date not found in config!");
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
            error_log("Challenge End Date: " . $challengeEndDateRaw);
            error_log("Parsed End DateTime: " . $endDateTime->format('Y-m-d H:i:s T'));
            error_log("Current DateTime: " . $nowDateTime->format('Y-m-d H:i:s T'));
            error_log("Challenge Ended: " . ($challengeEnded ? 'YES' : 'NO'));
        } catch (Exception $e) {
            error_log("ERROR parsing challenge_end_date: " . $e->getMessage());
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
        
        // Get swap data (placeholder for now)
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
                        $swapData = [
                            'swap_count' => $lastEntry['swap_count'] ?? 0,
                            'total_volume_sol' => $lastEntry['swap_volume'] ?? 0,
                            'avg_trade_size' => $lastEntry['avg_trade'] ?? 0
                        ];
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
            'change_pct' => round($changePct, 2),
            'swap_count' => $swapData['swap_count'] ?? 0,
            'swap_volume' => $swapData['total_volume_sol'] ?? 0,
            'avg_trade' => $swapData['avg_trade_size'] ?? 0
        ];
    }
    
    // Sort by total
    usort($leaderboard, function($a, $b) {
        return $b['total'] <=> $a['total'];
    });
    
    // Badge-Logik - FIX: Now that swap fields exist
    $mostActiveTrader = 0;
    $volumeKing = 0;
    
    foreach ($leaderboard as $entry) {
        if ($entry['swap_count'] > $mostActiveTrader) {
            $mostActiveTrader = $entry['swap_count'];
        }
        if ($entry['swap_volume'] > $volumeKing) {
            $volumeKing = $entry['swap_volume'];
        }
    }
    
    // Badges zuweisen
    foreach ($leaderboard as &$entry) {
        $entry['most_active_trader'] = ($entry['swap_count'] == $mostActiveTrader && $mostActiveTrader > 0);
        $entry['volume_king'] = ($entry['swap_volume'] == $volumeKing && $volumeKing > 0);
    }
    
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
            'challenge_ended' => $challengeEnded,
            'config_loaded' => isset($config) ? 'YES' : 'NO'
        ]
    ];
    
    // Save to file
    file_put_contents($DATA_FILE, json_encode($outputData, JSON_PRETTY_PRINT));
    
    return $outputData;
}

// Get Swap History
function getSwapHistory($wallet, $startDate) {
    global $HELIUS_API_KEY;
    
    $url = "https://api.helius.xyz/v0/addresses/{$wallet}/transactions?api-key={$HELIUS_API_KEY}";
    // Filter für DEX-Transaktionen seit Challenge-Start
    
    // Parse Swap-Daten von Jupiter, Raydium, Orca etc.
    return [
        'total_volume_sol' => 0,
        'swap_count' => 0,
        'avg_trade_size' => 0,
        'unique_tokens' => 0,
        'best_token_gain' => 0,
        'total_swap_pnl' => 0
    ];
}

// Handle request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // No auth required anymore
    
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
    
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
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