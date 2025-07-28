<?php
// filepath: /debug_date.php
// Quick test script to debug date parsing

define('CONFIG_ACCESS', true);
$config = require_once __DIR__ . '/config/config.php';

$CHALLENGE_END_DATE = $config['app']['challenge_end_date'];

echo "=== Date Parsing Debug ===\n";
echo "Config End Date: {$CHALLENGE_END_DATE}\n";

// Old method (problematic)
$oldTimestamp = strtotime($CHALLENGE_END_DATE);
echo "strtotime() result: {$oldTimestamp}\n";
echo "strtotime() date: " . ($oldTimestamp ? date('Y-m-d H:i:s T', $oldTimestamp) : 'FAILED') . "\n";

// New method (correct)
$endDateTime = new DateTime($CHALLENGE_END_DATE);
$nowDateTime = new DateTime('now', new DateTimeZone('UTC'));

echo "DateTime End: " . $endDateTime->format('Y-m-d H:i:s T') . "\n";
echo "DateTime Now: " . $nowDateTime->format('Y-m-d H:i:s T') . "\n";

$challengeEnded = $nowDateTime >= $endDateTime;
echo "Challenge Ended: " . ($challengeEnded ? 'YES' : 'NO') . "\n";

$timeLeft = $endDateTime->getTimestamp() - $nowDateTime->getTimestamp();
echo "Seconds until end: {$timeLeft}\n";
echo "Days until end: " . round($timeLeft / 86400, 1) . "\n";
?>