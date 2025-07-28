<?php
// filepath: /admin/index.php
session_start();

// Load secure configuration
define('CONFIG_ACCESS', true);
$config = require_once __DIR__ . '/../config/config.php';

$ADMIN_PASSWORD = $config['app']['admin_password'];

// File paths
$WALLETS_FILE = __DIR__ . '/../config/wallets.json';
$START_SOL_FILE = __DIR__ . '/../data/start_sol_balances.json';

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Handle login
$login_error = '';
if ($_POST && isset($_POST['password'])) {
    if ($_POST['password'] === $ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: index.php');
        exit;
    } else {
        $login_error = 'Invalid password';
    }
}

// Check if logged in
$logged_in = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];

// Handle wallet addition
$success_message = '';
$error_message = '';
if ($logged_in && $_POST && isset($_POST['add_wallet'])) {
    $username = trim($_POST['username']);
    $wallet = trim($_POST['wallet']);
    $start_balance = floatval($_POST['start_balance']);
    
    if (empty($username) || empty($wallet)) {
        $error_message = 'Username and wallet address are required';
    } elseif ($start_balance <= 0) {
        $error_message = 'Start balance must be greater than 0';
    } else {
        // Load current wallets
        $wallets = json_decode(file_get_contents($WALLETS_FILE), true);
        $start_balances = json_decode(file_get_contents($START_SOL_FILE), true);
        
        // Check if wallet already exists
        $wallet_exists = false;
        foreach ($wallets as $existing_wallet) {
            if ($existing_wallet['wallet'] === $wallet) {
                $wallet_exists = true;
                break;
            }
        }
        
        if ($wallet_exists) {
            $error_message = 'Wallet already exists';
        } else {
            // Add new wallet
            $wallets[] = [
                'username' => $username,
                'wallet' => $wallet
            ];
            
            // Add start balance
            $start_balances[$wallet] = $start_balance;
            
            // Save files
            file_put_contents($WALLETS_FILE, json_encode($wallets, JSON_PRETTY_PRINT));
            file_put_contents($START_SOL_FILE, json_encode($start_balances, JSON_PRETTY_PRINT));
            
            $success_message = "Wallet added successfully: {$username} ({$wallet})";
        }
    }
}

// Load current wallets for display
$current_wallets = [];
$current_start_balances = [];
if ($logged_in && file_exists($WALLETS_FILE) && file_exists($START_SOL_FILE)) {
    $current_wallets = json_decode(file_get_contents($WALLETS_FILE), true);
    $current_start_balances = json_decode(file_get_contents($START_SOL_FILE), true);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Solana Leaderboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-white min-h-screen">
    
    <?php if (!$logged_in): ?>
        <!-- Login Form -->
        <div class="min-h-screen flex items-center justify-center px-4">
            <div class="bg-gray-800 p-8 rounded-xl shadow-2xl w-full max-w-md">
                <h1 class="text-2xl font-bold mb-6 text-center text-blue-400">Admin Login</h1>
                
                <?php if ($login_error): ?>
                    <div class="bg-red-800/50 border border-red-600 text-red-200 p-3 rounded mb-4">
                        <?php echo htmlspecialchars($login_error); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="mb-6">
                        <label class="block text-gray-300 text-sm font-medium mb-2">Password</label>
                        <input type="password" name="password" required
                               class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <button type="submit" 
                            class="w-full bg-blue-600 hover:bg-blue-700 py-2 px-4 rounded font-medium transition-colors">
                        Login
                    </button>
                </form>
            </div>
        </div>
        
    <?php else: ?>
        <!-- Admin Dashboard -->
        <div class="container mx-auto px-4 py-8">
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-3xl font-bold text-blue-400">Leaderboard Admin</h1>
                <a href="?logout=1" class="bg-red-600 hover:bg-red-700 px-4 py-2 rounded text-sm transition-colors">
                    Logout
                </a>
            </div>
            
            <!-- Messages -->
            <?php if ($success_message): ?>
                <div class="bg-green-800/50 border border-green-600 text-green-200 p-4 rounded mb-6">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="bg-red-800/50 border border-red-600 text-red-200 p-4 rounded mb-6">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <!-- Add Wallet Form -->
            <div class="bg-gray-800 p-6 rounded-xl mb-8">
                <h2 class="text-xl font-semibold mb-4 text-yellow-400">Add New Wallet</h2>
                
                <form method="POST" class="grid md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-gray-300 text-sm font-medium mb-2">Username</label>
                        <input type="text" name="username" required
                               class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded focus:outline-none focus:border-blue-500"
                               placeholder="Enter username">
                    </div>
                    
                    <div>
                        <label class="block text-gray-300 text-sm font-medium mb-2">Wallet Address</label>
                        <input type="text" name="wallet" required
                               class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded focus:outline-none focus:border-blue-500 font-mono text-sm"
                               placeholder="Solana wallet address">
                    </div>
                    
                    <div>
                        <label class="block text-gray-300 text-sm font-medium mb-2">Start Balance (SOL)</label>
                        <input type="number" name="start_balance" step="0.0001" min="0.0001" required
                               class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded focus:outline-none focus:border-blue-500"
                               placeholder="0.5000">
                    </div>
                    
                    <div class="flex items-end">
                        <button type="submit" name="add_wallet" value="1"
                                class="w-full bg-green-600 hover:bg-green-700 py-2 px-4 rounded font-medium transition-colors">
                            Add Wallet
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Current Wallets -->
            <div class="bg-gray-800 rounded-xl overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-700">
                    <h2 class="text-xl font-semibold text-yellow-400">Current Wallets (<?php echo count($current_wallets); ?>)</h2>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-gray-300">#</th>
                                <th class="px-6 py-3 text-left text-gray-300">Username</th>
                                <th class="px-6 py-3 text-left text-gray-300">Wallet Address</th>
                                <th class="px-6 py-3 text-right text-gray-300">Start Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($current_wallets)): ?>
                                <tr>
                                    <td colspan="4" class="px-6 py-8 text-center text-gray-400">
                                        No wallets configured yet
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($current_wallets as $index => $wallet): ?>
                                    <tr class="border-t border-gray-700 hover:bg-gray-700/50">
                                        <td class="px-6 py-4 text-gray-400"><?php echo $index + 1; ?></td>
                                        <td class="px-6 py-4 font-medium"><?php echo htmlspecialchars($wallet['username']); ?></td>
                                        <td class="px-6 py-4 font-mono text-sm text-blue-400">
                                            <a href="https://solscan.io/account/<?php echo htmlspecialchars($wallet['wallet']); ?>" 
                                               target="_blank" class="hover:text-blue-300">
                                                <?php echo htmlspecialchars($wallet['wallet']); ?>
                                            </a>
                                        </td>
                                        <td class="px-6 py-4 text-right font-mono text-green-400">
                                            <?php echo number_format($current_start_balances[$wallet['wallet']] ?? 0, 4); ?> SOL
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Instructions -->
            <div class="mt-8 bg-blue-900/20 border border-blue-700 p-4 rounded-lg">
                <h3 class="text-blue-400 font-semibold mb-2">Instructions</h3>
                <ul class="text-gray-300 text-sm space-y-1">
                    <li>• Add wallets that should be tracked in the leaderboard</li>
                    <li>• Start balance should reflect the SOL amount at contest start</li>
                    <li>• Changes will be visible on the next leaderboard refresh (every 30 seconds)</li>
                    <li>• Wallet addresses must be valid Solana addresses</li>
                </ul>
            </div>
        </div>
    <?php endif; ?>
    
</body>
</html>