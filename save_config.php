<?php
/**
 * Tool Launcher Pro - Config Save Endpoint (Fixed)
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "POSTメソッドのみ許可されています"], JSON_UNESCAPED_UNICODE);
    exit;
}

$response = ["status" => "error", "message" => ""];
$file_path = __DIR__ . '/config.json';
$max_file_size = 256 * 1024;

// Rate limiting
$rate_limit_file = __DIR__ . '/.rate_limit_config';
$rate_limit_window = 60;
$rate_limit_max = 20;
$client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

function checkRateLimit($file, $ip, $window, $max) {
    $data = [];
    if (file_exists($file)) {
        $raw = file_get_contents($file);
        $data = json_decode($raw, true) ?: [];
    }
    $now = time();
    $key = md5($ip);
    if (isset($data[$key])) {
        $data[$key] = array_filter($data[$key], function($t) use ($now, $window) {
            return ($now - $t) < $window;
        });
        if (count($data[$key]) >= $max) return false;
    }
    $data[$key][] = $now;
    file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}

if (!checkRateLimit($rate_limit_file, $client_ip, $rate_limit_window, $rate_limit_max)) {
    http_response_code(429);
    $response["message"] = "リクエストが多すぎます。しばらく経ってからお試しください。";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

$json_data = file_get_contents('php://input');

if (empty($json_data)) {
    $response["message"] = "データが空です";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

if (strlen($json_data) > $max_file_size) {
    $response["message"] = "データサイズが大きすぎます（最大256KB）";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

$decoded = json_decode($json_data, true);
if ($decoded === null && strtolower(trim($json_data)) !== 'null') {
    $response["message"] = "JSONの形式が不正です: " . json_last_error_msg();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_array($decoded)) {
    $response["message"] = "設定データはオブジェクトである必要があります";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// Schema validation
$allowed_keys = [
    'gridSize', 'showTitle', 'clockFormat', 'accentColor', 'bgImage',
    'borderRadius', 'cardGap', 'openInNewTab', 'enableAnimations', 'localBackup',
    'categoryOrder'
];

$validated = [];
foreach ($decoded as $key => $value) {
    if (!in_array($key, $allowed_keys, true)) continue;

    switch ($key) {
        case 'showTitle':
        case 'openInNewTab':
        case 'enableAnimations':
        case 'localBackup':
            $validated[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            break;
        case 'accentColor':
            $color = ltrim(trim($value), '#');
            $validated[$key] = preg_match('/^[0-9A-Fa-f]{6}$/', $color) ? '#' . strtolower($color) : '#00e6e6'; 
            break;
        case 'bgImage':
            $url = trim($value);
            $validated[$key] = (empty($url) || filter_var($url, FILTER_VALIDATE_URL)) ? $url : '';
            break;
        case 'gridSize':
        case 'borderRadius':
        case 'cardGap':
            $validated[$key] = preg_replace('/[^0-9px]/', '', trim($value)) ?: '240px';
            break;
        case 'clockFormat':
            $validated[$key] = in_array($value, ['12h', '24h'], true) ? $value : '24h';
            break;
        case 'categoryOrder':
            if (is_array($value)) {
                $validated[$key] = array_map(function($v) {
                    return htmlspecialchars(mb_substr(trim($v), 0, 50), ENT_QUOTES, 'UTF-8');
                }, $value);
            }
            break;
        default:
            $validated[$key] = is_string($value) ? htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8') : $value;
    }
}

// Try backup (non-critical)
$backup_success = false;
if (file_exists($file_path)) {
    $backup_dir = __DIR__ . '/backups';
    $dir_created = is_dir($backup_dir) ? true : @mkdir($backup_dir, 0755, true);

    if ($dir_created && is_writable($backup_dir)) {
        $backup_name = $backup_dir . '/config_' . date('Ymd_His') . '_' . uniqid() . '.json';
        if (@copy($file_path, $backup_name)) {
            $backup_success = true;
            $backups = glob($backup_dir . '/config_*.json');
            if ($backups && count($backups) > 5) {
                usort($backups, function($a, $b) { return filemtime($a) - filemtime($b); });
                foreach (array_slice($backups, 0, count($backups) - 5) as $old) {
                    @unlink($old);
                }
            }
        }
    }
}

$json_output = json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($json_output === false) {
    $response["message"] = "JSONエンコードに失敗しました";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

$result = @file_put_contents($file_path, $json_output, LOCK_EX);
if ($result !== false) {
    @chmod($file_path, 0666);
    $response["status"] = "success";
    $response["message"] = "設定を保存しました";
    $response["backup"] = $backup_success ? 'created' : 'skipped';
    http_response_code(200);
} else {
    $response["message"] = "config.json への書き込みに失敗しました。パーミッションを確認してください（chmod 666 config.json）";
    http_response_code(500);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
