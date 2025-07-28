<?php
// filepath: /api/public-config.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Load secure configuration
define('CONFIG_ACCESS', true);
$config = require_once __DIR__ . '/../config/config.php';

// Only return PUBLIC, non-sensitive configuration
$public_config = [
    'api_base_url' => $config['api']['base_url'],
    'update_interval_seconds' => $config['app']['update_interval_seconds'],
    'timezone' => $config['app']['timezone'],
    'challenge_end_date' => $config['app']['challenge_end_date']
];

echo json_encode($public_config);
?>