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
$file_path = __DIR__ . '/share.json';
$max_messages = 100;

$is_multipart = isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false;

if ($is_multipart) {
    $decoded = $_POST;
} else {
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

    $chat = [];
    if (file_exists($file_path)) {
        $chat = json_decode(file_get_contents($file_path), true) ?: [];
    }

    // リアクションは短時間のロックで更新競合（lost update）を防ぐ
    $lock_file = __DIR__ . '/share.lock';
    $fp_lock = @fopen($lock_file, 'c');
    $locked = $fp_lock && flock($fp_lock, LOCK_EX);

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
        @file_put_contents($file_path, json_encode($chat, JSON_UNESCAPED_UNICODE));
        @chmod($file_path, 0666);
    }
    if ($locked) { flock($fp_lock, LOCK_UN); }
    if ($fp_lock) { fclose($fp_lock); }

    if ($updated) {
        echo json_encode(["status" => "success"]);
    } else {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Message not found"]);
    }
    exit;
}

if ($action === 'delete') {
    $timestamp_to_delete = isset($decoded['timestamp']) ? intval($decoded['timestamp']) : 0;
    $id_to_delete = isset($decoded['id']) ? trim($decoded['id']) : '';

    // ID指定: 競合防止のロック付きで削除
    if ($id_to_delete !== '') {
        $lock_file = __DIR__ . '/share.lock';
        $fp_lock = @fopen($lock_file, 'c');
        $locked = $fp_lock && flock($fp_lock, LOCK_EX);

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

        @file_put_contents($file_path, json_encode($chat_data, JSON_UNESCAPED_UNICODE));
        @chmod($file_path, 0666);
        if ($locked) { flock($fp_lock, LOCK_UN); }
        if ($fp_lock) { fclose($fp_lock); }

        if ($found) {
            echo json_encode(["status" => "success"]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Message not found"]);
        }
        exit;
    }

    if ($timestamp_to_delete > 0) {
        $chat_data = [];
        if (file_exists($file_path)) {
            $raw = file_get_contents($file_path);
            $chat_data = json_decode($raw, true) ?: [];
        }
        // Filter out the message
        $chat_data = array_values(array_filter($chat_data, function($msg) use ($timestamp_to_delete) {
            return (!isset($msg['timestamp']) || $msg['timestamp'] !== $timestamp_to_delete);
        }));
        
        @file_put_contents($file_path, json_encode($chat_data, JSON_UNESCAPED_UNICODE));
        @chmod($file_path, 0666);
        echo json_encode(["status" => "success"]);
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid timestamp"]);
    }
    exit;
}

// Default post action
$name = isset($decoded['name']) ? mb_substr(trim($decoded['name']), 0, 30, 'UTF-8') : '名無しさん';
$message = isset($decoded['message']) ? trim($decoded['message']) : '';
$gameId = !empty($decoded['gameId']) ? trim($decoded['gameId']) : null;
$timestamp = time() * 1000;

if ($name === '' || $message === '') {
    // If it's a share with an image, message can be empty? User said "画像と文字を入れれるように", let's require at least some text or an image.
    if (!($is_multipart && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK)) {
        if ($message === '') {
            $response["message"] = "名前とメッセージを入力してください";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

$line_count = substr_count($message, "\n") + 1;
$is_aa = stripos(trim($message), '/a') === 0;

if ($is_aa) {
    if (isset($decoded['isShare']) && $decoded['isShare'] === 'true') {
        $response["message"] = "シェア投稿ではアスキーアートは許可されていません。";
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $lines = explode("\n", $message);
    foreach ($lines as $line) {
        if (mb_strlen(trim($line, "\r\n"), 'UTF-8') > 1000) {
            $response["message"] = "アスキーアートの1行の最大文字数は1000文字までです。";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
} else if (mb_strlen($message, 'UTF-8') > 300) {
    $response["message"] = "最大文字数は300文字です。";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($line_count >= 9 && !$is_aa) {
    if (isset($decoded['isShare']) && $decoded['isShare'] === 'true') {
        $response["message"] = "改行が多すぎます（最大8行）。アスキーアートは許可されていません。";
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        $response["message"] = "複数行のAA（アスキーアート）を送信する場合は、メッセージの先頭に /a を付けてください。";
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$imageUrl = null;
if ($is_multipart && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . "/uploads/";
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
        @chmod($uploadDir, 0777);
    }
    
    // サイズ上限 4MB
    if ($_FILES['image']['size'] > 4 * 1024 * 1024) {
        $response["message"] = "画像サイズが4MBを超えるため保存できません";
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Check if it's an image
    $check = @getimagesize($_FILES['image']['tmp_name']);
    if($check !== false) {
        // 拡張子はMIME型から決定（ファイル名の .php 等を許可しない）
        $ext_map = [
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_GIF  => 'gif',
            IMAGETYPE_WEBP => 'webp',
        ];
        $ext = $ext_map[$check[2]] ?? null;
        if (!$ext) {
            $response["message"] = "PNG・JPEG・GIF・WEBPのみ対応しています";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        $filename = uniqid('img_') . '.' . $ext;
        $targetPath = $uploadDir . $filename;
        if (@move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
            @chmod($targetPath, 0666);
            $imageUrl = "uploads/" . rawurlencode($filename);
        } else {
            $response["message"] = "画像の保存に失敗しました。";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        $response["message"] = "画像ファイルのみアップロード可能です。";
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$clientId = isset($decoded['clientId']) ? mb_substr(trim($decoded['clientId']), 0, 64) : null;
$replyTo = null;
if (isset($decoded['replyTo'])) {
    $rt = $decoded['replyTo'];
    if (is_string($rt)) $rt = json_decode($rt, true);
    if (is_array($rt)) {
        $replyTo = [
            'timestamp' => isset($rt['timestamp']) ? intval($rt['timestamp']) : 0,
            'name' => isset($rt['name']) ? mb_substr(trim($rt['name']), 0, 30, 'UTF-8') : '',
            'message' => isset($rt['message']) ? mb_substr(trim($rt['message']), 0, 100, 'UTF-8') : ''
        ];
    }
}

$new_message = [
    'id' => uniqid('msg_'),
    'name' => $name,
    'message' => $message,
    'gameId' => $gameId,
    'timestamp' => $timestamp,
    'clientId' => $clientId,
    'imageUrl' => $imageUrl,
    'replyTo' => $replyTo
];

$existing_messages = [];
$result = false;

$lock_file = __DIR__ . '/share.lock';
$fp_lock = @fopen($lock_file, 'c');
@chmod($lock_file, 0666);
$locked = $fp_lock && flock($fp_lock, LOCK_EX);

// Proceed even without lock (fallback for permission issues)
if (file_exists($file_path)) {
    $existing_raw = file_get_contents($file_path);
    $existing_messages = json_decode($existing_raw, true) ?: [];
}

array_push($existing_messages, $new_message);

// Keep only the last $max_messages
if (count($existing_messages) > $max_messages) {
    $existing_messages = array_slice($existing_messages, -$max_messages);
}

$json_output = json_encode($existing_messages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($json_output !== false) {
    $result = @file_put_contents($file_path, $json_output);
    @chmod($file_path, 0666);
}

if ($locked) { flock($fp_lock, LOCK_UN); }
if ($fp_lock) { fclose($fp_lock); }

if ($result !== false) {
    @chmod($file_path, 0666);
    $response["status"] = "success";
    $response["message"] = "投稿しました";
    $response["data"] = $new_message;
    http_response_code(200);
} else {
    $response["message"] = "share.json への書き込みに失敗しました。";
    http_response_code(500);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);

