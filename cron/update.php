<?php
// filepath: /cron/update.php
// Cron job script - runs every 15 seconds
require_once __DIR__ . '/../api/leaderboard.php';

// Force update
$data = updateLeaderboard();
if ($data) {
    error_log("Leaderboard cron update successful: " . date('Y-m-d H:i:s'));
} else {
    error_log("Leaderboard cron update failed: " . date('Y-m-d H:i:s'));
}
?>