<?php
// filepath: /cron/status_check.php
// Status check script - can be run manually to check system health

require_once __DIR__ . '/../api/leaderboard.php';

function checkStatus() {
    echo "=== Solana Leaderboard Status Check ===\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Check config files
    $configFiles = [
        'API Config' => __DIR__ . '/../config/api.json',
        'App Config' => __DIR__ . '/../config/app.json',
        'Wallets' => __DIR__ . '/../config/wallets.json'
    ];
    
    foreach ($configFiles as $name => $file) {
        if (file_exists($file)) {
            echo "✅ {$name}: OK\n";
        } else {
            echo "❌ {$name}: MISSING ({$file})\n";
        }
    }
    
    // Check data files
    $dataFile = __DIR__ . '/../data/leaderboard.json';
    if (file_exists($dataFile)) {
        $age = time() - filemtime($dataFile);
        echo "✅ Data file: OK (last updated {$age}s ago)\n";
        
        $data = json_decode(file_get_contents($dataFile), true);
        if ($data) {
            echo "   - Wallets tracked: " . count($data['data']) . "\n";
            echo "   - Challenge ended: " . ($data['challenge_ended'] ? 'YES' : 'NO') . "\n";
            if (!empty($data['data'])) {
                $leader = $data['data'][0];
                echo "   - Current leader: {$leader['username']} ({$leader['total']} SOL)\n";
            }
        }
    } else {
        echo "❌ Data file: MISSING\n";
    }
    
    // Check log file
    $logFile = __DIR__ . '/../logs/cron.log';
    if (file_exists($logFile)) {
        $lines = file($logFile);
        $lastLine = trim(end($lines));
        echo "✅ Log file: OK (" . count($lines) . " entries)\n";
        echo "   Last entry: " . $lastLine . "\n";
    } else {
        echo "⚠️  Log file: Not created yet\n";
    }
    
    // Test API connectivity
    echo "\n=== API Connectivity Test ===\n";
    
    // Test Solana RPC
    $testWallet = "11111111111111111111111111111112"; // System program
    try {
        $balance = getSolBalance($testWallet);
        echo "✅ Solana RPC: OK\n";
    } catch (Exception $e) {
        echo "❌ Solana RPC: FAILED - " . $e->getMessage() . "\n";
    }
    
    // Test Helius (if configured)
    $apiConfig = json_decode(file_get_contents(__DIR__ . '/../config/api.json'), true);
    if (!empty($apiConfig['helius_api_key'])) {
        try {
            $tokens = getTokenBalances($testWallet);
            echo "✅ Helius API: OK\n";
        } catch (Exception $e) {
            echo "❌ Helius API: FAILED - " . $e->getMessage() . "\n";
        }
    } else {
        echo "⚠️  Helius API: No key configured\n";
    }
    
    echo "\n=== Status Check Complete ===\n";
}

checkStatus();
?>