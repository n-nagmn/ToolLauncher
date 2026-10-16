<?php
/**
 * Tool Launcher Pro - Chat/Bulletin Board Post Endpoint
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
$file_path = __DIR__ . '/chat.json';
$max_messages = 100;

// Rate limiting
$rate_limit_file = __DIR__ . '/.rate_limit_chat';
$rate_limit_window = 60;
$rate_limit_max = 60;
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

$decoded = json_decode($json_data, true);
if (!is_array($decoded)) {
    $decoded = [];
}

// リアクション絵文字の検証（XSS/改ざん防止: 16文字以下・引用符・括弧・角括弧を含まない文字列のみ許可）
function isValidEmoji($emoji) {
    return is_string($emoji)
        && $emoji !== ''
        && mb_strlen($emoji, 'UTF-8') <= 16
        && strpos($emoji, "'") === false
        && strpos($emoji, '"') === false
        && strpos($emoji, '(') === false
        && strpos($emoji, ')') === false
        && strpos($emoji, '<') === false
        && strpos($emoji, '>') === false;
}

// 原子的な書き込み（一時ファイル + rename）
function atomicWrite($path, $content) {
    $tmp = $path . '.' . getmypid() . '.tmp';
    $result = @file_put_contents($tmp, $content, LOCK_EX);
    if ($result !== false && @rename($tmp, $path)) {
        return true;
    }
    @unlink($tmp);
    return false;
}

$action = $decoded['action'] ?? 'post';

if ($action === 'react') {
    $timestamp = $decoded['timestamp'] ?? 0;
    $emoji = $decoded['emoji'] ?? '';
    $clientId = $decoded['clientId'] ?? '';
    if (!$timestamp || !$emoji || !$clientId) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Missing react data"]);
        exit;
    }
    if (!isValidEmoji($emoji)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid emoji"]);
        exit;
    }

    // リアクションは短時間のロックで更新競合（lost update）を防ぐ
    // 読み込みもロック内で行う
    $lock_file = __DIR__ . '/chat.lock';
    $fp_lock = @fopen($lock_file, 'c');
    if (!$fp_lock || !flock($fp_lock, LOCK_EX)) {
        if ($fp_lock) fclose($fp_lock);
        http_response_code(503);
        echo json_encode(["status" => "error", "message" => "サーバーが混雑しています。もう一度お試しください。"]);
        exit;
    }

    $chat = [];
    if (file_exists($file_path)) {
        $chat = json_decode(file_get_contents($file_path), true) ?: [];
    }
    if (!is_array($chat)) $chat = [];

    $updated = false;
    foreach ($chat as &$msg) {
        if (isset($msg['timestamp']) && $msg['timestamp'] === $timestamp) {
            if (!isset($msg['reactions'])) {
                $msg['reactions'] = [];
            }
            if (!isset($msg['reactions'][$emoji])) {
                $msg['reactions'][$emoji] = [];
            }
            
            if (!is_array($msg['reactions'][$emoji])) $msg['reactions'][$emoji] = [];
            $idx = array_search($clientId, $msg['reactions'][$emoji]);
            if ($idx !== false) {
                // Remove reaction
                array_splice($msg['reactions'][$emoji], $idx, 1);
                if (empty($msg['reactions'][$emoji])) {
                    unset($msg['reactions'][$emoji]);
                }
            } else {
                // Add reaction
                $msg['reactions'][$emoji][] = $clientId;
            }
            $updated = true;
            break;
        }
    }
    
    if ($updated) {
        $written = atomicWrite($file_path, json_encode($chat, JSON_UNESCAPED_UNICODE));
        @chmod($file_path, 0666);
        flock($fp_lock, LOCK_UN);
        fclose($fp_lock);

        if ($written) {
            echo json_encode(["status" => "success"]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "chat.json への書き込みに失敗しました。"]);
        }
        exit;
    }

    flock($fp_lock, LOCK_UN);
    fclose($fp_lock);
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Message not found"]);
    exit;
}

if ($action === 'delete') {
    $timestamp_to_delete = isset($decoded['timestamp']) ? intval($decoded['timestamp']) : 0;
    $id_to_delete = isset($decoded['id']) ? trim((string)$decoded['id']) : '';

    // ID指定: 競合防止のロック付きで削除
    if ($id_to_delete !== '') {
        $lock_file = __DIR__ . '/chat.lock';
        $fp_lock = @fopen($lock_file, 'c');
        if (!$fp_lock || !flock($fp_lock, LOCK_EX)) {
            if ($fp_lock) fclose($fp_lock);
            http_response_code(503);
            echo json_encode(["status" => "error", "message" => "サーバーが混雑しています。もう一度お試しください。"]);
            exit;
        }

        $chat_data = [];
        if (file_exists($file_path)) {
            $raw = file_get_contents($file_path);
            $chat_data = json_decode($raw, true) ?: [];
        }
        if (!is_array($chat_data)) $chat_data = [];
        $found = false;
        $chat_data = array_values(array_filter($chat_data, function($msg) use ($id_to_delete, &$found) {
            if (isset($msg['id']) && $msg['id'] === $id_to_delete) {
                $found = true;
                return false;
            }
            return true;
        }));

        atomicWrite($file_path, json_encode($chat_data, JSON_UNESCAPED_UNICODE));
        @chmod($file_path, 0666);
        flock($fp_lock, LOCK_UN);
        fclose($fp_lock);

        if ($found) {
            echo json_encode(["status" => "success"]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Message not found"]);
        }
        exit;
    }

    if ($timestamp_to_delete > 0) {
        $lock_file = __DIR__ . '/chat.lock';
        $fp_lock = @fopen($lock_file, 'c');
        if (!$fp_lock || !flock($fp_lock, LOCK_EX)) {
            if ($fp_lock) fclose($fp_lock);
            http_response_code(503);
            echo json_encode(["status" => "error", "message" => "サーバーが混雑しています。もう一度お試しください。"]);
            exit;
        }

        $chat_data = [];
        if (file_exists($file_path)) {
            $raw = file_get_contents($file_path);
            $chat_data = json_decode($raw, true) ?: [];
        }
        // Filter out the message
        $chat_data = array_values(array_filter($chat_data, function($msg) use ($timestamp_to_delete) {
            return (!isset($msg['timestamp']) || $msg['timestamp'] !== $timestamp_to_delete);
        }));

        atomicWrite($file_path, json_encode($chat_data, JSON_UNESCAPED_UNICODE));
        @chmod($file_path, 0666);
        flock($fp_lock, LOCK_UN);
        fclose($fp_lock);

        echo json_encode(["status" => "success"]);
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid timestamp"]);
    }
    exit;
}

// Default post action
$name = isset($decoded['name']) ? mb_substr(trim((string)$decoded['name']), 0, 30, 'UTF-8') : '名無しさん';
$message = isset($decoded['message']) ? trim((string)$decoded['message']) : '';
$gameId = !empty($decoded['gameId']) ? trim((string)$decoded['gameId']) : null;
$timestamp = time() * 1000;

if ($name === '' || $message === '') {
    $response["message"] = "名前とメッセージを入力してください";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

$line_count = substr_count($message, "\n") + 1;
$is_aa = stripos(trim($message), '/a') === 0;

if ($is_aa) {
    $lines = explode("\n", $message);
    foreach ($lines as $line) {
        if (mb_strlen(trim($line, "\r\n"), 'UTF-8') > 1000) {
            $response["message"] = "アスキーアートの1行の最大文字数は1000文字までです。";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
} else if (mb_strlen($message, 'UTF-8') > 300) {
    $response["message"] = "通常チャットの最大文字数は300文字です。";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($line_count >= 9 && !$is_aa) {
    $response["message"] = "複数行のAA（アスキーアート）を送信する場合は、メッセージの先頭に /a を付けてください。";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

$clientId = isset($decoded['clientId']) ? mb_substr(trim((string)$decoded['clientId']), 0, 64) : null;
$replyTo = null;
if (isset($decoded['replyTo']) && is_array($decoded['replyTo'])) {
    $rt = $decoded['replyTo'];
    $replyTo = [
        'timestamp' => isset($rt['timestamp']) ? intval($rt['timestamp']) : 0,
        'name' => isset($rt['name']) ? mb_substr(trim((string)$rt['name']), 0, 30, 'UTF-8') : '',
        'message' => isset($rt['message']) ? mb_substr(trim((string)$rt['message']), 0, 100, 'UTF-8') : ''
    ];
}

$new_message = [
    'id' => uniqid('msg_'),
    'name' => $name,
    'message' => $message,
    'gameId' => $gameId,
    'timestamp' => $timestamp,
    'clientId' => $clientId,
    'replyTo' => $replyTo
];

$existing_messages = [];
$result = false;

$lock_file = __DIR__ . '/chat.lock';
$fp_lock = @fopen($lock_file, 'c');
@chmod($lock_file, 0666);
// ロックを取得できない場合は503を返す（ロックなしで書き続けるとlost updateが起こる）
if (!$fp_lock || !flock($fp_lock, LOCK_EX)) {
    if ($fp_lock) fclose($fp_lock);
    $response["message"] = "サーバーが混雑しています。もう一度お試しください。";
    http_response_code(503);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

if (file_exists($file_path)) {
    $existing_raw = file_get_contents($file_path);
    $existing_messages = json_decode($existing_raw, true) ?: [];
}
if (!is_array($existing_messages)) $existing_messages = [];

array_push($existing_messages, $new_message);

// Keep only the last $max_messages
if (count($existing_messages) > $max_messages) {
    $existing_messages = array_slice($existing_messages, -$max_messages);
}

$json_output = json_encode($existing_messages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$result = ($json_output !== false) && atomicWrite($file_path, $json_output);
@chmod($file_path, 0666);

flock($fp_lock, LOCK_UN);
fclose($fp_lock);

if ($result) {
    $response["status"] = "success";
    $response["message"] = "投稿しました";
    $response["data"] = $new_message;
    http_response_code(200);
} else {
    $response["message"] = "chat.json への書き込みに失敗しました。";
    http_response_code(500);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);

