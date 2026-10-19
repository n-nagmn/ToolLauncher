<?php
/**
 * Web Game Launcher Pro - Chat/Bulletin Board Post Endpoint
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
$max_body_size = 1024 * 1024; // 1MB

// Rate limiting
$rate_limit_file = __DIR__ . '/.rate_limit_chat';
$rate_limit_window = 60;
$rate_limit_max = 30;
$client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// H4: read + count + append happen inside a single flock, and every call
// prunes ALL stale keys so the file does not grow unbounded.
function checkRateLimit($file, $ip, $window, $max) {
    $fp = @fopen($file, 'c');
    if ($fp === false) {
        return true;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return true;
    }
    $raw = stream_get_contents($fp);
    $data = $raw !== false && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    if (!is_array($data)) {
        $data = [];
    }
    $now = time();
    // Prune every key (all stale keys on every call)
    foreach (array_keys($data) as $k) {
        $data[$k] = array_values(array_filter((array)$data[$k], function($t) use ($now, $window) {
            return is_int($t) && ($now - $t) < $window;
        }));
        if (empty($data[$k])) {
            unset($data[$k]);
        }
    }
    $key = md5($ip);
    $allowed = isset($data[$key]) ? count($data[$key]) < $max : true;
    if ($allowed) {
        $data[$key][] = $now;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
    }
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $allowed;
}

if (!checkRateLimit($rate_limit_file, $client_ip, $rate_limit_window, $rate_limit_max)) {
    http_response_code(429);
    $response["message"] = "リクエストが多すぎます。しばらく経ってからお試しください。";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// Lock file (react/delete/post all perform read-modify-write under the same lock)
$lock_file = __DIR__ . '/chat.lock';
$fp_lock = @fopen($lock_file, 'c');
@chmod($lock_file, 0666);
if (!$fp_lock || !flock($fp_lock, LOCK_EX)) {
    http_response_code(503);
    $response["message"] = "サーバーが混雑しています。もう一度お試しください。";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    if ($fp_lock) { fclose($fp_lock); }
    exit;
}

// Atomic write helper: temp file + rename
function atomicWriteJson($path, $data) {
    $tmp = $path . '.' . getmypid() . '.tmp';
    $result = @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    if ($result !== false) {
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            $result = false;
        } else {
            @chmod($path, 0666);
        }
    }
    return $result !== false;
}

// Emoji validation: reject quotes, angle brackets, parens, control chars, and overly long strings
function isValidEmoji($emoji) {
    if (!is_string($emoji) || $emoji === '') return false;
    if (mb_strlen($emoji, 'UTF-8') > 16) return false;
    if (preg_match('/[\'"<>()\\\\\x00-\x1f]/u', $emoji)) return false;
    return true;
}

$json_data = file_get_contents('php://input');

// H5: cap the request body before decoding
if ($json_data === false || strlen($json_data) > $max_body_size) {
    http_response_code(400);
    $response["message"] = "データサイズが大きすぎます（最大1MB）";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    flock($fp_lock, LOCK_UN);
    fclose($fp_lock);
    exit;
}

if (empty($json_data)) {
    // L10: empty body is a client error
    http_response_code(400);
    $response["message"] = "データが空です";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    flock($fp_lock, LOCK_UN);
    fclose($fp_lock);
    exit;
}

$decoded = json_decode($json_data, true);
if (!is_array($decoded)) {
    $decoded = [];
}

$action = $decoded['action'] ?? 'post';

if ($action === 'react') {
    $timestamp = $decoded['timestamp'] ?? 0;
    $emoji = $decoded['emoji'] ?? '';
    $clientId = $decoded['clientId'] ?? '';
    if (!$timestamp || !is_string($emoji) || $emoji === '' || !is_string($clientId) || $clientId === '') {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Missing react data"]);
        flock($fp_lock, LOCK_UN);
        fclose($fp_lock);
        exit;
    }
    if (!isValidEmoji($emoji) || mb_strlen($clientId, 'UTF-8') > 64) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid react data"]);
        flock($fp_lock, LOCK_UN);
        fclose($fp_lock);
        exit;
    }

    $chat = [];
    if (file_exists($file_path)) {
        $chat = json_decode(file_get_contents($file_path), true) ?: [];
    }

    $updated = false;
    foreach ($chat as &$msg) {
        if (isset($msg['timestamp']) && $msg['timestamp'] === $timestamp) {
            if (!isset($msg['reactions'])) {
                $msg['reactions'] = [];
            }
            if (!isset($msg['reactions'][$emoji])) {
                $msg['reactions'][$emoji] = [];
            }

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
    unset($msg);

    if ($updated) {
        if (!atomicWriteJson($file_path, $chat)) {
            $response["message"] = "chat.json への書き込みに失敗しました。";
            http_response_code(500);
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(["status" => "success"]);
        }
    } else {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Message not found"]);
    }
    flock($fp_lock, LOCK_UN);
    fclose($fp_lock);
    exit;
}

if ($action === 'delete') {
    // H1: ownership check. Only the author of a message may delete it.
    // The unauthenticated timestamp-delete branch has been removed.
    $clientId = isset($decoded['clientId']) ? trim((string)$decoded['clientId']) : '';
    $id_to_delete = isset($decoded['id']) ? trim((string)$decoded['id']) : '';

    if ($clientId === '' || $id_to_delete === '') {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "削除権限がありません。自分のメッセージのみ削除できます。"]);
        flock($fp_lock, LOCK_UN);
        fclose($fp_lock);
        exit;
    }

    $chat_data = [];
    if (file_exists($file_path)) {
        $raw = file_get_contents($file_path);
        $chat_data = json_decode($raw, true) ?: [];
    }
    if (!is_array($chat_data)) {
        $chat_data = [];
    }

    $found = false;
    $chat_data = array_values(array_filter($chat_data, function($msg) use ($id_to_delete, $clientId, &$found) {
        if (isset($msg['id']) && $msg['id'] === $id_to_delete) {
            // Only the owner may delete their own message
            if (isset($msg['clientId']) && $msg['clientId'] === $clientId) {
                $found = true;
                return false;
            }
            return true; // not the owner: keep
        }
        return true;
    }));

    if (!$found) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Message not found"]);
        flock($fp_lock, LOCK_UN);
        fclose($fp_lock);
        exit;
    }

    if (!atomicWriteJson($file_path, $chat_data)) {
        $response["message"] = "chat.json への書き込みに失敗しました。";
        http_response_code(500);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(["status" => "success"]);
    }
    flock($fp_lock, LOCK_UN);
    fclose($fp_lock);
    exit;
}

// Default post action
$name = isset($decoded['name']) ? mb_substr(trim((string)$decoded['name']), 0, 30, 'UTF-8') : '名無しさん';
$message = isset($decoded['message']) ? trim((string)$decoded['message']) : '';
$gameId = !empty($decoded['gameId']) ? trim((string)$decoded['gameId']) : null;
$timestamp = time() * 1000;

if ($name === '' || $message === '') {
    // L10: empty message is a client error
    http_response_code(400);
    $response["message"] = "名前とメッセージを入力してください";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    flock($fp_lock, LOCK_UN);
    fclose($fp_lock);
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
            flock($fp_lock, LOCK_UN);
            fclose($fp_lock);
            exit;
        }
    }
} else if (mb_strlen($message, 'UTF-8') > 300) {
    $response["message"] = "通常チャットの最大文字数は300文字です。";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    flock($fp_lock, LOCK_UN);
    fclose($fp_lock);
    exit;
}

if ($line_count >= 9 && !$is_aa) {
    $response["message"] = "複数行のAA（アスキーアート）を送信する場合は、メッセージの先頭に /a を付けてください。";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    flock($fp_lock, LOCK_UN);
    fclose($fp_lock);
    exit;
}

$clientId = isset($decoded['clientId']) ? trim((string)$decoded['clientId']) : null;
if ($clientId !== null && $clientId !== '' && mb_strlen($clientId, 'UTF-8') > 64) {
    $clientId = mb_substr($clientId, 0, 64, 'UTF-8');
}
$replyTo = isset($decoded['replyTo']) ? $decoded['replyTo'] : null;

$new_message = [
    'id' => uniqid('msg_', true), // L3: more collision-resistant IDs
    'name' => $name,
    'message' => $message,
    'gameId' => $gameId,
    'timestamp' => $timestamp,
    'clientId' => $clientId,
    'replyTo' => $replyTo
];

$existing_messages = [];

// Read-modify-write under the lock (acquired at the top of the script)
if (file_exists($file_path)) {
    $existing_raw = file_get_contents($file_path);
    $existing_messages = json_decode($existing_raw, true) ?: [];
}
if (!is_array($existing_messages)) {
    $existing_messages = [];
}

array_push($existing_messages, $new_message);

// Keep only the last $max_messages
if (count($existing_messages) > $max_messages) {
    $existing_messages = array_slice($existing_messages, -$max_messages);
}

$result = atomicWriteJson($file_path, $existing_messages);

flock($fp_lock, LOCK_UN);
fclose($fp_lock);

if ($result !== false) {
    $response["status"] = "success";
    $response["message"] = "投稿しました";
    $response["data"] = $new_message;
    http_response_code(200);
} else {
    $response["message"] = "chat.json への書き込みに失敗しました。";
    http_response_code(500);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
