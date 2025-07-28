<?php
// Disable error display to prevent HTML output in JSON responses
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Only set headers and handle HTTP requests if running via web server
if (isset($_SERVER['REQUEST_METHOD'])) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    
    // Clean output buffer to prevent any stray output
    if (ob_get_level()) {
        ob_clean();
    }
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
    
    // Extract configuration values with fallbacks - only if not already set
    if (!isset($HELIUS_API_KEY)) $HELIUS_API_KEY = $config['api']['helius_api_key'] ?? '';
    if (!isset($WINNER_POT_WALLET)) $WINNER_POT_WALLET = $config['api']['winner_pot_wallet'] ?? '';
    if (!isset($CHALLENGE_END_DATE)) $CHALLENGE_END_DATE = $config['app']['challenge_end_date'] ?? '';
    if (!isset($CACHE_TIMEOUT)) $CACHE_TIMEOUT = $config['app']['cache_timeout_seconds'] ?? 30;
    
    // File paths - only if not already set
    if (!isset($CONFIG_FILE)) $CONFIG_FILE = __DIR__ . '/../config/wallets.json';
    if (!isset($START_SOL_FILE)) $START_SOL_FILE = __DIR__ . '/../data/start_sol_balances.json';
    if (!isset($DATA_FILE)) $DATA_FILE = __DIR__ . '/../data/leaderboard.json';
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

// Get token balances with RPC primary, Helius fallback
function getTokenBalances($wallet) {
    // Try Solana RPC first (more complete)
    $tokens = getTokenBalancesRPC($wallet);
    
    // If RPC fails, fallback to Helius
    if (empty($tokens)) {
        logWalletActivity($wallet, "RPC returned no tokens, trying Helius fallback");
        $tokens = getTokenBalancesHelius($wallet);
    }
    
    return $tokens;
}

function getTokenBalancesRPC($wallet) {
    // Add delay to prevent rate limiting
    usleep(200000); // Increase to 200ms delay between RPC calls
    
    $data = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'getTokenAccountsByOwner',
        'params' => [
            $wallet, 
            ['programId' => 'TokenkegQfeZyiNwAJbNbGKPfvXJ4bKbPDPqbL6tLZvg'],
            ['encoding' => 'jsonParsed']
        ]
    ];
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($data),
            'timeout' => 10 // Add timeout
        ]
    ]);
    
    logWalletActivity($wallet, "Fetching token balances from Solana RPC");
    
    // Use @ to suppress warnings that break JSON output
    $response = @file_get_contents('https://api.mainnet-beta.solana.com', false, $context);
    
    if ($response === false) {
        logWalletActivity($wallet, "ERROR: Failed to fetch from Solana RPC");
        return [];
    }
    
    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        logWalletActivity($wallet, "JSON decode error: " . json_last_error_msg());
        return [];
    }
    
    $tokens = [];
    if (isset($result['result']['value']) && is_array($result['result']['value'])) {
        logWalletActivity($wallet, "Found " . count($result['result']['value']) . " token accounts from RPC");
        
        foreach ($result['result']['value'] as $account) {
            if (!isset($account['account']['data']['parsed']['info'])) {
                continue;
            }
            
            $accountData = $account['account']['data']['parsed']['info'];
            $mint = $accountData['mint'] ?? '';
            $tokenAmount = $accountData['tokenAmount'] ?? [];
            $amount = floatval($tokenAmount['uiAmount'] ?? 0);
            
            if ($mint && $amount > 0) {
                logWalletActivity($wallet, "RPC Token {$mint}: amount={$amount}");
                $tokens[$mint] = ($tokens[$mint] ?? 0) + $amount;
                logWalletActivity($wallet, "Added RPC token {$mint} with amount {$amount}");
            }
        }
    } else {
        logWalletActivity($wallet, "No token accounts found in RPC response");
        if (isset($result['error'])) {
            logWalletActivity($wallet, "RPC error: " . json_encode($result['error']));
        }
    }
    
    logWalletActivity($wallet, "Final RPC token count: " . count($tokens));
    return $tokens;
}

function getTokenBalancesHelius($wallet) {
    global $HELIUS_API_KEY;
    
    $url = "https://api.helius.xyz/v0/addresses/{$wallet}/balances?api-key={$HELIUS_API_KEY}";
    
    logWalletActivity($wallet, "Fetching token balances from Helius API");
    
    // Use @ to suppress warnings
    $response = @file_get_contents($url);
    
    if ($response === false) {
        logWalletActivity($wallet, "ERROR: Failed to fetch from Helius API");
        return [];
    }
    
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        logWalletActivity($wallet, "JSON decode error: " . json_last_error_msg());
        return [];
    }
    
    $tokens = [];
    if (isset($data['tokens']) && is_array($data['tokens'])) {
        logWalletActivity($wallet, "Found " . count($data['tokens']) . " raw tokens from Helius");
        
        foreach ($data['tokens'] as $token) {
            $mint = $token['mint'] ?? '';
            $rawAmount = $token['amount'] ?? 0;
            $decimals = intval($token['decimals'] ?? 0);
            $amount = floatval($rawAmount) / pow(10, $decimals);
            
            if ($mint && $amount > 0) {
                logWalletActivity($wallet, "Helius Token {$mint}: amount={$amount}");
                $tokens[$mint] = ($tokens[$mint] ?? 0) + $amount;
                logWalletActivity($wallet, "Added Helius token {$mint} with amount {$amount}");
            }
        }
    } else {
        logWalletActivity($wallet, "No tokens array found in Helius response");
    }
    
    logWalletActivity($wallet, "Final Helius token count: " . count($tokens));
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
    try {
        // Handle request
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            // Return cached data if exists and is recent
            if (file_exists($DATA_FILE)) {
                $fileTime = filemtime($DATA_FILE);
                if (time() - $fileTime < $CACHE_TIMEOUT) {
                    $cachedData = file_get_contents($DATA_FILE);
                    if ($cachedData !== false) {
                        echo $cachedData;
                        exit;
                    }
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
    } catch (Exception $e) {
        error_log("API Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Internal server error']);
    } catch (Error $e) {
        error_log("API Fatal Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Internal server error']);
    }
}
?>