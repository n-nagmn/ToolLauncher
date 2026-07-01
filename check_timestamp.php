<?php
header('Content-Type: application/json');

$files = [
    'games' => __DIR__ . '/games.json',
    'config' => __DIR__ . '/config.json'
];

$timestamps = [];
foreach ($files as $key => $path) {
    $timestamps[$key] = file_exists($path) ? filemtime($path) : 0;
}

echo json_encode($timestamps);
