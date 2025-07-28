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

// Enhanced logging function (same as in update.php)
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
    
    // Also keep error_log for backward compatibility
    error_log($message);
}

// Update leaderboard data
function updateLeaderboard($configOverride = null) {
    global $CONFIG_FILE, $START_SOL_FILE, $DATA_FILE, $WINNER_POT_WALLET, $CHALLENGE_END_DATE;
    
    logMessage("=== LEADERBOARD UPDATE START ===", 'INFO');
    logMessage("updateLeaderboard() called with configOverride: " . ($configOverride ? 'YES' : 'NO'), 'DEBUG');
    
    // Load config - priority: parameter > global > file
    if ($configOverride) {
        $config = $configOverride;
        logMessage("Using config from parameter", 'DEBUG');
    } elseif (isset($GLOBALS['config'])) {
        $config = $GLOBALS['config'];
        logMessage("Using config from GLOBALS", 'DEBUG');
    } else {
        // Fallback: load config directly
        logMessage("Loading config directly from file", 'DEBUG');
        define('CONFIG_ACCESS', true);
        $config = require_once __DIR__ . '/../config/config.php';
    }
    
    // Debug: Log the entire config structure
    logMessage("Config structure: " . print_r($config, true), 'DEBUG');
    
    // Load wallets and start SOL values
    $wallets = json_decode(file_get_contents($CONFIG_FILE), true);
    $startSols = json_decode(file_get_contents($START_SOL_FILE), true);
    
    logMessage("Loaded " . count($wallets) . " wallets from config file", 'INFO');
    logMessage("Loaded " . count($startSols) . " start SOL values", 'INFO');
    
    // Get challenge end date from config
    $challengeEndDateRaw = $config['app']['challenge_end_date'] ?? null;
    
    // Debug logging
    logMessage("Raw Challenge End Date from config: " . ($challengeEndDateRaw ?? 'NULL'), 'DEBUG');
    logMessage("Config app section: " . print_r($config['app'] ?? 'NOT SET', true), 'DEBUG');
    
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
            logMessage("Challenge Ended: " . ($challengeEnded ? 'YES' : 'NO'), 'INFO');
        } catch (Exception $e) {
            logMessage("ERROR parsing challenge_end_date: " . $e->getMessage(), 'ERROR');
            $challengeEnded = false;
            $endDateTime = new DateTime();
            $nowDateTime = new DateTime('now', new DateTimeZone('UTC'));
        }
    }
    
    // Get winner pot balance
    $winnerPotBalance = getSolBalance($WINNER_POT_WALLET);
    logMessage("Winner pot balance: " . $winnerPotBalance, 'INFO');
    
    $leaderboard = [];
    $solPriceUsd = getSolPriceUsd();
    logMessage("SOL price USD: " . $solPriceUsd, 'INFO');
    
    foreach ($wallets as $entry) {
        $wallet = $entry['wallet'];
        $username = $entry['username'] ?? substr($wallet, 0, 6);
        
        logMessage("Processing wallet: " . $wallet . " (username: " . $username . ")", 'DEBUG');
        
        if (!isset($startSols[$wallet])) {
            logMessage("WARNING: No start SOL value for wallet " . $wallet, 'WARNING');
            continue;
        }
        
        $sol = getSolBalance($wallet);
        $tokenValue = 0;
        
        logMessage("Wallet " . $wallet . " - SOL balance: " . $sol, 'DEBUG');
        
        // Get swap data (placeholder for now)
        $swapData = getSwapHistory($wallet, $config['app']['challenge_start_date']);
        logMessage("Swap data for " . $wallet . ": " . print_r($swapData, true), 'DEBUG');
        
        if (!$challengeEnded) {
            $tokens = getTokenBalances($wallet);
            logMessage("Token balances for " . $wallet . ": " . print_r($tokens, true), 'DEBUG');
            
            foreach ($tokens as $mint => $amount) {
                $tokenPriceUsd = getTokenPrice($mint);
                if ($tokenPriceUsd > 0 && $solPriceUsd > 0) {
                    $tokenValueAdd = $amount * ($tokenPriceUsd / $solPriceUsd);
                    $tokenValue += $tokenValueAdd;
                    logMessage("Token " . $mint . ": amount=" . $amount . ", price_usd=" . $tokenPriceUsd . ", value_sol=" . $tokenValueAdd, 'DEBUG');
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
                        logMessage("Using frozen values for " . $wallet . " - tokens: " . $tokenValue, 'DEBUG');
                        break;
                    }
                }
            }
        }
        
        $total = $sol + $tokenValue;
        $start = $startSols[$wallet];
        $changePct = $start > 0 ? (($total - $start) / $start * 100) : 0;
        
        logMessage("Wallet " . $wallet . " final: SOL=" . $sol . ", tokens=" . $tokenValue . ", total=" . $total . ", start=" . $start . ", change=" . $changePct . "%", 'INFO');
        
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
    
    logMessage("Leaderboard sorted, " . count($leaderboard) . " entries", 'INFO');
    
    // Badge-Logik
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
    
    logMessage("Badge thresholds - Most Active: " . $mostActiveTrader . ", Volume King: " . $volumeKing, 'DEBUG');
    
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
    logMessage("Data saved to file: " . $DATA_FILE, 'INFO');
    
    logMessage("=== LEADERBOARD UPDATE END ===", 'INFO');
    return $outputData;
}

// Get Swap History - Improved implementation using DeFi Activities
function getSwapHistory($wallet, $startDate) {
    global $HELIUS_API_KEY;
    
    logMessage("getSwapHistory called for wallet: " . $wallet . ", startDate: " . $startDate, 'DEBUG');
    
    // Initialize swap data
    $swapData = [
        'total_volume_sol' => 0,
        'swap_count' => 0,
        'avg_trade_size' => 0,
        'unique_tokens' => 0,
        'best_token_gain' => 0,
        'total_swap_pnl' => 0
    ];
    
    try {
        // Convert start date to timestamp
        $startTimestamp = strtotime($startDate);
        if ($startTimestamp === false) {
            logMessage("Invalid start date format: " . $startDate, 'ERROR');
            return $swapData;
        }
        
        // Fetch DeFi activities from Helius API (better for swaps)
        $activities = fetchDefiActivitiesFromHelius($wallet, $startTimestamp);
        
        if (empty($activities)) {
            logMessage("No DeFi activities found for wallet: " . $wallet, 'DEBUG');
            return $swapData;
        }
        
        logMessage("Found " . count($activities) . " DeFi activities for wallet: " . $wallet, 'DEBUG');
        
        $swapCount = 0;
        $totalVolume = 0;
        $uniqueTokens = [];
        
        // Analyze each activity
        foreach ($activities as $activity) {
            $swapInfo = analyzeDefiActivity($activity);
            
            if ($swapInfo !== null) {
                $swapCount++;
                $totalVolume += $swapInfo['volume_sol'];
                
                // Track unique tokens
                if (!empty($swapInfo['token_in'])) {
                    $uniqueTokens[$swapInfo['token_in']] = true;
                }
                if (!empty($swapInfo['token_out'])) {
                    $uniqueTokens[$swapInfo['token_out']] = true;
                }
                
                logMessage("Swap detected: " . $swapInfo['description'] . " - Volume: " . $swapInfo['volume_sol'] . " SOL", 'DEBUG');
            }
        }
        
        // Calculate statistics
        $avgTradeSize = $swapCount > 0 ? $totalVolume / $swapCount : 0;
        
        $swapData = [
            'total_volume_sol' => round($totalVolume, 6),
            'swap_count' => $swapCount,
            'avg_trade_size' => round($avgTradeSize, 6),
            'unique_tokens' => count($uniqueTokens),
            'best_token_gain' => 0, // TODO: Implement PnL calculation
            'total_swap_pnl' => 0   // TODO: Implement PnL calculation
        ];
        
        logMessage("Swap analysis complete for " . $wallet . ": " . json_encode($swapData), 'DEBUG');
        
    } catch (Exception $e) {
        logMessage("Error in getSwapHistory for " . $wallet . ": " . $e->getMessage(), 'ERROR');
    }
    
    return $swapData;
}

// Fetch DeFi activities from Helius API (more accurate for swaps)
function fetchDefiActivitiesFromHelius($wallet, $startTimestamp) {
    global $HELIUS_API_KEY;
    
    $allActivities = [];
    $before = null;
    $maxPages = 50; // Increase to get more history
    $pageCount = 0;
    
    logMessage("Starting to fetch activities for wallet: " . $wallet . " from timestamp: " . $startTimestamp . " (" . date('Y-m-d H:i:s', $startTimestamp) . ")", 'DEBUG');
    
    do {
        // Use parsed-transactions endpoint with DeFi focus
        $url = "https://api.helius.xyz/v0/addresses/{$wallet}/transactions?api-key={$HELIUS_API_KEY}&limit=100&commitment=confirmed";
        if ($before) {
            $url .= "&before={$before}";
        }
        
        logMessage("Fetching page " . ($pageCount + 1) . " for wallet " . substr($wallet, 0, 8) . "... from: " . $url, 'DEBUG');
        
        $response = @file_get_contents($url);
        if ($response === false) {
            logMessage("Failed to fetch activities from Helius API for wallet: " . $wallet, 'ERROR');
            break;
        }
        
        $data = json_decode($response, true);
        if (!isset($data) || !is_array($data)) {
            logMessage("Invalid response from Helius API for wallet: " . $wallet, 'ERROR');
            break;
        }
        
        logMessage("Received " . count($data) . " transactions in page " . ($pageCount + 1) . " for wallet " . substr($wallet, 0, 8) . "...", 'DEBUG');
        
        $foundOlderTx = false;
        $addedInThisPage = 0;
        
        foreach ($data as $tx) {
            $txTimestamp = $tx['timestamp'] ?? 0;
            $txSignature = $tx['signature'] ?? 'unknown';
            
            // Debug: Log first few transactions of each page
            if ($addedInThisPage < 3) {
                logMessage("TX " . ($addedInThisPage + 1) . ": " . substr($txSignature, 0, 12) . "... - " . date('Y-m-d H:i:s', $txTimestamp) . " - Type: " . ($tx['type'] ?? 'UNKNOWN'), 'DEBUG');
            }
            
            // Stop if we've gone past our start date (too old)
            if ($txTimestamp < $startTimestamp) {
                logMessage("Found transaction older than start date: " . date('Y-m-d H:i:s', $txTimestamp) . " < " . date('Y-m-d H:i:s', $startTimestamp), 'DEBUG');
                $foundOlderTx = true;
                break;
            }
            
            // Only include transactions from challenge start onwards
            if ($txTimestamp >= $startTimestamp) {
                $allActivities[] = $tx;
                $addedInThisPage++;
            }
            
            $before = $tx['signature'] ?? null;
        }
        
        logMessage("Added " . $addedInThisPage . " activities from page " . ($pageCount + 1) . " for wallet " . substr($wallet, 0, 8) . "...", 'DEBUG');
        
        $pageCount++;
        
        // Stop conditions
        if ($foundOlderTx || empty($data) || count($data) < 100 || $pageCount >= $maxPages) {
            logMessage("Stopping pagination: foundOlder=" . ($foundOlderTx ? 'YES' : 'NO') . ", emptyData=" . (empty($data) ? 'YES' : 'NO') . ", dataCount=" . count($data) . ", pageCount=" . $pageCount, 'DEBUG');
            break;
        }
        
        // Small delay to respect API limits
        usleep(200000); // 0.2 second
        
    } while (true);
    
    logMessage("FINAL: Fetched " . count($allActivities) . " activities from " . $pageCount . " pages for wallet " . substr($wallet, 0, 8) . "... (filtered from challenge start)", 'INFO');
    
    // Debug: Show transaction type distribution
    $typeDistribution = [];
    foreach ($allActivities as $activity) {
        $type = $activity['type'] ?? 'UNKNOWN';
        $typeDistribution[$type] = ($typeDistribution[$type] ?? 0) + 1;
    }
    logMessage("Transaction type distribution for " . substr($wallet, 0, 8) . "...: " . json_encode($typeDistribution), 'DEBUG');
    
    return $allActivities;
}

// Analyze a DeFi activity to determine if it's a swap and extract swap info
function analyzeDefiActivity($activity) {
    $txType = $activity['type'] ?? '';
    $description = $activity['description'] ?? '';
    $events = $activity['events'] ?? [];
    $signature = $activity['signature'] ?? '';
    
    // More comprehensive swap detection patterns
    $swapPatterns = [
        'TOKEN_SWAP',
        'SWAP',
        'Jupiter',
        'Raydium',
        'Orca',
        'Serum',
        'Meteora',
        'Whirlpool',
        'swap',
        'exchange',
        'traded',
        'swapped',
        'buy',
        'sell',
        'purchase',
        'AMM_SWAP',
        'DEX_TRADE',
        'LIMIT_ORDER',
        'MARKET_ORDER'
    ];
    
    $isSwap = false;
    $matchedPattern = '';
    
    // Check description and type for swap patterns
    foreach ($swapPatterns as $pattern) {
        if (stripos($description, $pattern) !== false || stripos($txType, $pattern) !== false) {
            $isSwap = true;
            $matchedPattern = $pattern;
            break;
        }
    }
    
    // Also check events for swap indicators
    if (!$isSwap) {
        foreach ($events as $event) {
            $eventType = $event['type'] ?? '';
            $eventDescription = $event['description'] ?? '';
            
            foreach ($swapPatterns as $pattern) {
                if (stripos($eventType, $pattern) !== false || stripos($eventDescription, $pattern) !== false) {
                    $isSwap = true;
                    $matchedPattern = $pattern . ' (in events)';
                    break 2;
                }
            }
        }
    }
    
    // Additional check: if there are token balance changes, it might be a swap
    $tokenBalanceChanges = $activity['tokenBalanceChanges'] ?? [];
    if (!$isSwap && count($tokenBalanceChanges) >= 2) {
        // If there are multiple token balance changes, it's likely a swap
        $hasNegativeChange = false;
        $hasPositiveChange = false;
        
        foreach ($tokenBalanceChanges as $change) {
            $rawAmount = floatval($change['rawTokenAmount']['tokenAmount'] ?? 0);
            if ($rawAmount < 0) $hasNegativeChange = true;
            if ($rawAmount > 0) $hasPositiveChange = true;
        }
        
        if ($hasNegativeChange && $hasPositiveChange) {
            $isSwap = true;
            $matchedPattern = 'token_balance_pattern';
        }
    }
    
    // Log all activities for better debugging (not just swaps)
    if ($isSwap) {
        logMessage("✅ SWAP DETECTED - Pattern: " . $matchedPattern . " - " . substr($signature, 0, 12) . "... - " . $description, 'INFO');
    } else {
        // Only log non-transfers for cleaner output
        if ($txType !== 'TRANSFER') {
            logMessage("❌ NOT A SWAP (" . $txType . ") - " . substr($signature, 0, 12) . "... - " . $description, 'DEBUG');
        }
    }
    
    if (!$isSwap) {
        return null;
    }
    
    // Extract swap details from token balance changes
    $nativeBalanceChange = floatval($activity['nativeBalanceChange'] ?? 0) / 1000000000; // Convert lamports to SOL
    
    $tokenIn = null;
    $tokenOut = null;
    $amountIn = 0;
    $amountOut = 0;
    $volumeSol = 0;
    
    // Analyze token balance changes
    foreach ($tokenBalanceChanges as $change) {
        $mint = $change['mint'] ?? '';
        $rawAmount = floatval($change['rawTokenAmount']['tokenAmount'] ?? 0);
        $decimals = intval($change['rawTokenAmount']['decimals'] ?? 0);
        $amount = $decimals > 0 ? $rawAmount / pow(10, $decimals) : $rawAmount;
        
        if ($rawAmount < 0) {
            // Token sold (negative change)
            $tokenIn = $mint;
            $amountIn = abs($amount);
        } elseif ($rawAmount > 0) {
            // Token bought (positive change)
            $tokenOut = $mint;
            $amountOut = $amount;
        }
    }
    
    // Calculate volume in SOL - improved logic with description parsing
    if (abs($nativeBalanceChange) > 0.0001) {
        $volumeSol = abs($nativeBalanceChange);
        logMessage("📊 Volume from native balance: " . $volumeSol . " SOL", 'DEBUG');
    } else {
        // Try to extract SOL amount from description
        $extractedVolume = extractSolAmountFromDescription($description);
        if ($extractedVolume > 0) {
            $volumeSol = $extractedVolume;
            logMessage("📊 Volume from description: " . $volumeSol . " SOL", 'DEBUG');
        } elseif ($amountIn > 0 || $amountOut > 0) {
            // For token-to-token swaps, estimate volume
            $estimatedVolume = max($amountIn * 0.00001, $amountOut * 0.00001, 0.01);
            $volumeSol = $estimatedVolume;
            logMessage("📊 Estimated volume: " . $volumeSol . " SOL", 'DEBUG');
        } else {
            $volumeSol = 0.01; // Default minimum volume for counting
            logMessage("📊 Default minimum volume: " . $volumeSol . " SOL", 'DEBUG');
        }
    }
    
    return [
        'signature' => $signature,
        'timestamp' => $activity['timestamp'] ?? 0,
        'token_in' => $tokenIn,
        'token_out' => $tokenOut,
        'amount_in' => $amountIn,
        'amount_out' => $amountOut,
        'volume_sol' => $volumeSol,
        'description' => $description,
        'type' => $txType,
        'matched_pattern' => $matchedPattern
    ];
}

// Extract SOL amounts from transaction descriptions
function extractSolAmountFromDescription($description) {
    // Pattern to match SOL amounts in descriptions like:
    // "swapped 0.07 SOL for 287567.30276 Spacy"
    // "swapped 10444.453166 token for 0.113368347 SOL"
    
    $patterns = [
        '/swapped\s+([\d,]+\.?\d*)\s+SOL\s+for/i',      // "swapped X SOL for"
        '/for\s+([\d,]+\.?\d*)\s+SOL$/i',               // "for X SOL"
        '/\s([\d,]+\.?\d*)\s+SOL\s/i',                  // " X SOL "
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $description, $matches)) {
            $amount = str_replace(',', '', $matches[1]);
            $solAmount = floatval($amount);
            
            if ($solAmount > 0 && $solAmount < 1000) { // Reasonable range for SOL amounts
                return $solAmount;
            }
        }
    }
    
    return 0;
}

// Handle request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    logMessage("GET request received for leaderboard", 'INFO');
    
    // Return cached data if exists and is recent
    if (file_exists($DATA_FILE)) {
        $fileTime = filemtime($DATA_FILE);
        if (time() - $fileTime < $CACHE_TIMEOUT) {
            logMessage("Returning cached data (age: " . (time() - $fileTime) . "s)", 'INFO');
            echo file_get_contents($DATA_FILE);
            exit;
        }
    }
    
    // Update and return new data
    logMessage("Cache expired or missing, updating leaderboard", 'INFO');
    $data = updateLeaderboard();
    echo json_encode($data);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logMessage("POST request received for manual update", 'INFO');
    
    // Keep auth for manual updates via POST
    checkAuth();
    
    // Manual update
    $data = updateLeaderboard();
    echo json_encode(['message' => 'Update successful', 'data' => $data]);
    
} else {
    logMessage("Invalid request method: " . $_SERVER['REQUEST_METHOD'], 'WARNING');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

// Log rotation for leaderboard.log (same as update.php)
$logFile = __DIR__ . '/../logs/leaderboard.log';
if (file_exists($logFile) && filesize($logFile) > 1024 * 1024) { // > 1MB
    $lines = file($logFile);
    if (count($lines) > 1000) {
        $keepLines = array_slice($lines, -1000);
        file_put_contents($logFile, implode('', $keepLines));
        logMessage("Log file rotated, kept last 1000 lines", 'INFO');
    }
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