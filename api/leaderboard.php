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
    error_log("🎯 Starting token balance fetch for wallet: {$wallet}");
    
    // Try Solana RPC first (more complete)
    $tokens = getTokenBalancesRPC($wallet);
    error_log("🔹 RPC returned " . count($tokens) . " tokens for {$wallet}");
    
    // If RPC fails, fallback to Helius
    if (empty($tokens)) {
        error_log("⚠️ RPC returned no tokens for {$wallet}, trying Helius fallback");
        logWalletActivity($wallet, "RPC returned no tokens, trying Helius fallback");
        $tokens = getTokenBalancesHelius($wallet);
        error_log("🔹 Helius returned " . count($tokens) . " tokens for {$wallet}");
    }
    
    error_log("✅ Final token count for {$wallet}: " . count($tokens));
    if (!empty($tokens)) {
        foreach ($tokens as $mint => $amount) {
            error_log("   - {$mint}: {$amount}");
        }
    }
    
    return $tokens;
}

function getTokenBalancesRPC($wallet) {
    error_log("🌐 Starting RPC token fetch for: {$wallet}");
    
    // Validate wallet address first
    if (strlen($wallet) !== 44) {
        error_log("❌ Invalid wallet address length for {$wallet}: " . strlen($wallet) . " characters");
        return [];
    }
    
    // List of RPC endpoints to try
    $rpcEndpoints = [
        'https://api.mainnet-beta.solana.com',
        'https://rpc.ankr.com/solana',
        'https://solana-mainnet.g.alchemy.com/v2/demo'
    ];
    
    $tokens = [];
    
    foreach ($rpcEndpoints as $index => $endpoint) {
        error_log("🔄 Trying RPC endpoint " . ($index + 1) . ": {$endpoint}");
        
        // Add delay to prevent rate limiting
        if ($index > 0) usleep(500000); // 500ms delay for fallback endpoints
        
        // Correct RPC request structure
        $requestData = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'getTokenAccountsByOwner',
            'params' => [
                $wallet,
                [
                    'programId' => 'TokenkegQfeZyiNwAJbNbGKPfvXJ4bKbPDPqbL6tLZvg'
                ],
                [
                    'encoding' => 'jsonParsed'
                ]
            ]
        ];
        
        error_log("📡 Request payload: " . json_encode($requestData));
        
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'User-Agent: Mozilla/5.0 (compatible; LeaderboardBot/1.0)'
                ],
                'content' => json_encode($requestData),
                'timeout' => 15,
                'ignore_errors' => true
            ]
        ]);
        
        $response = @file_get_contents($endpoint, false, $context);
        
        if ($response === false) {
            error_log("❌ RPC endpoint {$endpoint} failed for: {$wallet}");
            continue;
        }
        
        error_log("📨 Response from {$endpoint}: " . substr($response, 0, 200) . "...");
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("🚫 JSON decode error from {$endpoint} for {$wallet}: " . json_last_error_msg());
            continue;
        }
        
        // Check for RPC error
        if (isset($result['error'])) {
            error_log("🚨 RPC error from {$endpoint} for {$wallet}: " . json_encode($result['error']));
            continue;
        }
        
        // Success! Process the tokens
        if (isset($result['result']['value']) && is_array($result['result']['value'])) {
            $accountCount = count($result['result']['value']);
            error_log("✅ Successfully got {$accountCount} token accounts from {$endpoint} for: {$wallet}");
            
            foreach ($result['result']['value'] as $account) {
                if (!isset($account['account']['data']['parsed']['info'])) {
                    error_log("⚠️ Account missing parsed info, skipping");
                    continue;
                }
                
                $accountData = $account['account']['data']['parsed']['info'];
                $mint = $accountData['mint'] ?? '';
                $tokenAmount = $accountData['tokenAmount'] ?? [];
                $amount = floatval($tokenAmount['uiAmount'] ?? 0);
                
                error_log("🔍 Found token account: mint={$mint}, amount={$amount}");
                
                if ($mint && $amount > 0) {
                    error_log("✅ Valid token for {$wallet}: {$mint} = {$amount}");
                    $tokens[$mint] = ($tokens[$mint] ?? 0) + $amount;
                } else {
                    error_log("⚠️ Skipping: mint={$mint}, amount={$amount}");
                }
            }
            
            // Break out of loop if we got a successful response
            break;
        } else {
            error_log("❌ No token accounts in response from {$endpoint}");
        }
    }
    
    error_log("🏁 Final RPC token count for {$wallet}: " . count($tokens));
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

// Get token price from Dexscreener with caching and rate limiting
function getTokenPrice($mint) {
    static $priceCache = [];
    static $lastRequestTime = 0;
    $cacheLifetime = 120; // 2 minutes cache
    $rateLimitDelay = 500000; // 500ms delay between requests
    
    $now = time();
    $cacheKey = $mint;
    
    // Enhanced logging for price requests
    error_log("🔍 Requesting price for token: {$mint}");
    
    // Check cache first
    if (isset($priceCache[$cacheKey]) && 
        ($now - $priceCache[$cacheKey]['timestamp']) < $cacheLifetime) {
        error_log("💾 Using cached price for {$mint}: {$priceCache[$cacheKey]['price']}");
        return $priceCache[$cacheKey]['price'];
    }
    
    // Rate limiting - wait between requests
    $timeSinceLastRequest = microtime(true) - $lastRequestTime;
    if ($timeSinceLastRequest < ($rateLimitDelay / 1000000)) {
        $sleepTime = $rateLimitDelay - ($timeSinceLastRequest * 1000000);
        error_log("⏱️ Rate limiting: sleeping for " . round($sleepTime / 1000) . "ms before requesting {$mint}");
        usleep($sleepTime);
    }
    
    $url = "https://api.dexscreener.com/latest/dex/tokens/{$mint}";
    error_log("🌐 Fetching from Dexscreener: {$url}");
    
    $response = @file_get_contents($url);
    $lastRequestTime = microtime(true);
    
    $price = 0;
    if ($response === false) {
        error_log("❌ Failed to fetch data from Dexscreener for token: {$mint} (possible rate limit)");
        
        // Return cached value if available, even if expired
        if (isset($priceCache[$cacheKey])) {
            error_log("🔄 Using expired cache for token: {$mint}, price: {$priceCache[$cacheKey]['price']}");
            return $priceCache[$cacheKey]['price'];
        }
        error_log("💀 No cached data available for {$mint}, returning 0");
        return 0;
    }
    
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("🚫 JSON decode error for {$mint}: " . json_last_error_msg());
        return 0;
    }
    
    $pairs = $data['pairs'] ?? [];
    
    if (empty($pairs)) {
        error_log("📭 No trading pairs found for token: {$mint}");
        // Cache 0 price to avoid repeated failed requests
        $priceCache[$cacheKey] = [
            'price' => 0,
            'timestamp' => $now
        ];
        return 0;
    }
    
    error_log("📊 Found " . count($pairs) . " trading pairs for {$mint}");
    
    // Sort by liquidity (USD value)
    usort($pairs, function($a, $b) {
        $liqA = floatval($a['liquidity']['usd'] ?? 0);
        $liqB = floatval($b['liquidity']['usd'] ?? 0);
        return $liqB <=> $liqA;
    });
    
    // Log top 3 pairs for debugging
    for ($i = 0; $i < min(3, count($pairs)); $i++) {
        $pair = $pairs[$i];
        $liquidity = floatval($pair['liquidity']['usd'] ?? 0);
        $priceUsd = floatval($pair['priceUsd'] ?? 0);
        $baseToken = $pair['baseToken']['address'] ?? '';
        $quoteToken = $pair['quoteToken']['address'] ?? '';
        
        error_log("💱 Pair " . ($i + 1) . " for {$mint}: base={$baseToken}, quote={$quoteToken}, priceUSD={$priceUsd}, liquidity=\${$liquidity}");
    }
    
    foreach ($pairs as $pair) {
        $baseToken = $pair['baseToken']['address'] ?? '';
        $quoteToken = $pair['quoteToken']['address'] ?? '';
        $priceUsd = floatval($pair['priceUsd'] ?? 0);
        $liquidity = floatval($pair['liquidity']['usd'] ?? 0);
        
        // If our token is the base token, use priceUsd directly
        if ($baseToken === $mint && $priceUsd > 0) {
            $price = $priceUsd;
            error_log("✅ Found price for {$mint} as base token: \${$priceUsd} (liquidity: \${$liquidity})");
            break;
        }
        // If our token is the quote token, invert the price
        elseif ($quoteToken === $mint && $priceUsd > 0) {
            $price = 1 / $priceUsd;
            error_log("✅ Found price for {$mint} as quote token: \${$price} (inverted from \${$priceUsd}, liquidity: \${$liquidity})");
            break;
        }
    }
    
    // Cache the result
    $priceCache[$cacheKey] = [
        'price' => $price,
        'timestamp' => $now
    ];
    
    if ($price === 0) {
        error_log("💔 No valid price found for token: {$mint}");
    } else {
        error_log("💰 Final price for {$mint}: \${$price}");
    }
    
    return $price;
}

// Get SOL price in USD with caching
function getSolPriceUsd() {
    static $solPriceCache = null;
    static $lastSolPriceTime = 0;
    $cacheLifetime = 60; // 1 minute cache for SOL price
    
    $now = time();
    
    // Check cache
    if ($solPriceCache !== null && ($now - $lastSolPriceTime) < $cacheLifetime) {
        error_log("💾 Using cached SOL price: \${$solPriceCache}");
        return $solPriceCache;
    }
    
    error_log("🔍 Fetching fresh SOL price...");
    
    // Try Jupiter first
    $url = "https://api.jup.ag/v4/price?ids=So11111111111111111111111111111111111111112&vsToken=USDC";
    error_log("🌐 Trying Jupiter API: {$url}");
    
    $response = @file_get_contents($url);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        $price = floatval($data['data']['So11111111111111111111111111111111111111112']['price'] ?? 0);
        if ($price > 0) {
            $solPriceCache = $price;
            $lastSolPriceTime = $now;
            error_log("✅ SOL price from Jupiter: \${$price}");
            return $price;
        } else {
            error_log("⚠️ Jupiter returned invalid price: " . json_encode($data));
        }
    } else {
        error_log("❌ Failed to fetch SOL price from Jupiter");
    }
    
    // Fallback to Dexscreener
    error_log("🔄 Falling back to Dexscreener for SOL price...");
    $price = getTokenPrice("So11111111111111111111111111111111111111112");
    if ($price > 0) {
        $solPriceCache = $price;
        $lastSolPriceTime = $now;
        error_log("✅ SOL price from Dexscreener: \${$price}");
    } else {
        error_log("💀 Failed to get SOL price from Dexscreener");
    }
    
    return $price;
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

// Safe file writing with backup
function safeWriteJsonFile($filepath, $data) {
    $jsonData = json_encode($data, JSON_PRETTY_PRINT);
    $tempFile = $filepath . '.tmp';
    $backupFile = $filepath . '.backup';
    
    // Backup der aktuellen Datei erstellen
    if (file_exists($filepath)) {
        copy($filepath, $backupFile);
    }
    
    // In temporäre Datei schreiben
    if (file_put_contents($tempFile, $jsonData, LOCK_EX) !== false) {
        // Validiere JSON
        $testData = json_decode(file_get_contents($tempFile), true);
        if (json_last_error() === JSON_ERROR_NONE && !empty($testData)) {
            // Atomic rename
            if (rename($tempFile, $filepath)) {
                return true;
            }
        }
    }
    
    // Bei Fehler: Backup wiederherstellen
    if (file_exists($backupFile)) {
        copy($backupFile, $filepath);
    }
    
    // Cleanup
    @unlink($tempFile);
    return false;
}

// Update leaderboard data
function updateLeaderboard() {
    global $CONFIG_FILE, $START_SOL_FILE, $DATA_FILE, $WINNER_POT_WALLET, $CHALLENGE_END_DATE;
    
    $wallets = json_decode(file_get_contents($CONFIG_FILE), true);
    $startSols = json_decode(file_get_contents($START_SOL_FILE), true);
    
    date_default_timezone_set('Europe/Berlin');
    
    $leaderboard = [];
    $solPriceUsd = getSolPriceUsd();
    
    error_log("🚀 Starting leaderboard update with SOL price: \${$solPriceUsd}");
    
    // Check if challenge ended
    $endDateTime = new DateTime($CHALLENGE_END_DATE, new DateTimeZone('UTC'));
    $nowDateTime = new DateTime('now', new DateTimeZone('UTC'));
    $challengeEnded = $nowDateTime >= $endDateTime;
    
    error_log("⏰ Challenge status: " . ($challengeEnded ? 'ENDED' : 'ACTIVE') . " (End: {$CHALLENGE_END_DATE})");
    
    foreach ($wallets as $entry) {
        $wallet = $entry['wallet'];
        $username = $entry['username'] ?? substr($wallet, 0, 6);
        
        error_log("👤 Processing wallet: {$username} ({$wallet})");
        
        // Get SOL balance
        $sol = getSolBalance($wallet);
        error_log("💰 SOL balance for {$username}: {$sol} SOL");
        
        // Get token balances
        $tokens = getTokenBalances($wallet);
        error_log("🎯 Found " . count($tokens) . " token types for {$username}");
        
        // Calculate token value with detailed logging
        $tokenValue = 0;
        $tokenCount = 0;
        foreach ($tokens as $mint => $amount) {
            $tokenCount++;
            error_log("🔹 Processing token {$tokenCount} for {$username}: {$mint} (amount: {$amount})");
            
            $tokenPriceUsd = getTokenPrice($mint);
            
            if ($tokenPriceUsd > 0 && $solPriceUsd > 0) {
                $tokenValueInSol = $amount * ($tokenPriceUsd / $solPriceUsd);
                $tokenValue += $tokenValueInSol;
                
                error_log("💎 Token value calculated: {$amount} × (\${$tokenPriceUsd} / \${$solPriceUsd}) = {$tokenValueInSol} SOL");
                
                // Use the enhanced logging function from cron/update.php if available
                if (function_exists('logTokenPricing')) {
                    logTokenPricing($wallet, $mint, $amount, $tokenPriceUsd, $solPriceUsd, $tokenValueInSol);
                }
            } else {
                error_log("⚠️ Skipping token {$mint} for {$username}: tokenPrice=\${$tokenPriceUsd}, solPrice=\${$solPriceUsd}");
            }
        }
        
        $total = $sol + $tokenValue;
        $start = $startSols[$wallet] ?? 0;
        $changePct = $start > 0 ? (($total - $start) / $start * 100) : 0;
        
        error_log("📊 Summary for {$username}: SOL={$sol}, Tokens={$tokenValue}, Total={$total}, Start={$start}, Change={$changePct}%");
        
        $leaderboard[] = [
            'username' => $username,
            'wallet' => $wallet,
            'sol' => round($sol, 4),
            'tokens' => round($tokenValue, 4),
            'total' => round($total, 4),
            'change_pct' => round($changePct, 2),
            'sol_only' => round($sol, 4) // For final ranking when challenge ends
        ];
    }
    
    // Sort leaderboard - if challenge ended, sort by SOL only, otherwise by total
    if ($challengeEnded) {
        error_log("🏁 Sorting by SOL balance only (challenge ended)");
        usort($leaderboard, function($a, $b) {
            return $b['sol_only'] <=> $a['sol_only'];
        });
    } else {
        error_log("📈 Sorting by total value (challenge active)");
        usort($leaderboard, function($a, $b) {
            return $b['total'] <=> $a['total'];
        });
    }
    
    // Log final ranking
    error_log("🏆 Final leaderboard order:");
    foreach ($leaderboard as $i => $entry) {
        $rank = $i + 1;
        $sortValue = $challengeEnded ? $entry['sol_only'] : $entry['total'];
        error_log("   {$rank}. {$entry['username']}: {$sortValue} SOL");
    }
    
    // Get winner pot balance
    $winnerPotBalance = getSolBalance($WINNER_POT_WALLET);
    $winnerPotUsd = $winnerPotBalance * $solPriceUsd;
    
    error_log("💰 Winner pot: {$winnerPotBalance} SOL (~\${$winnerPotUsd})");
    
    $result = [
        'updated' => date('Y-m-d H:i:s'), // German time
        'data' => $leaderboard,
        'winner_pot' => [
            'wallet' => $WINNER_POT_WALLET,
            'balance' => round($winnerPotBalance, 4),
            'balance_usd' => round($winnerPotUsd, 2), // Add USD value
            'sol_price_usd' => round($solPriceUsd, 2) // Add current SOL price
        ],
        'challenge_ended' => $challengeEnded,
        'challenge_end_date' => $CHALLENGE_END_DATE,
        'final_ranking_by_sol_only' => $challengeEnded
    ];
    
    // Safe atomic write
    if (safeWriteJsonFile($DATA_FILE, $result)) {
        error_log("✅ Leaderboard file written successfully at " . date('Y-m-d H:i:s'));
    } else {
        error_log("❌ Failed to write leaderboard file");
    }
    
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