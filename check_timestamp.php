<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$files = [
    'games' => __DIR__ . '/games.json',
    'config' => __DIR__ . '/config.json',
    'chat' => __DIR__ . '/chat.json',
    'share' => __DIR__ . '/share.json'
];

$timestamps = [];
foreach ($files as $key => $path) {
    $timestamps[$key] = file_exists($path) ? filemtime($path) : 0;
}

echo json_encode($timestamps);
