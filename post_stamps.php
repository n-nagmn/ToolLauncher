<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$stamps_file = __DIR__ . '/stamps.json';

// Check for action=upload_image in GET params
if (isset($_GET['action']) && $_GET['action'] === 'upload_image') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
        exit;
    }

    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        // サイズ上限 2MB
        if ($_FILES['image']['size'] > 2 * 1024 * 1024) {
            echo json_encode(['status' => 'error', 'message' => '画像サイズが2MBを超えるため保存できません']);
            exit;
        }

        $uploadDir = __DIR__ . "/uploads/";
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
            @chmod($uploadDir, 0777);
        }

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
                echo json_encode(['status' => 'error', 'message' => 'PNG・JPEG・GIF・WEBPのみ対応しています']);
                exit;
            }
            $filename = uniqid('stamp_') . '.' . $ext;
            $targetPath = $uploadDir . $filename;
            if (@move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
                @chmod($targetPath, 0666);
                $imageUrl = "uploads/" . rawurlencode($filename);
                echo json_encode(['status' => 'success', 'url' => $imageUrl]);
                exit;
            }
        }
        echo json_encode(['status' => 'error', 'message' => 'Invalid image or save failed']);
        exit;
    }
    
    echo json_encode(['status' => 'error', 'message' => 'No file uploaded']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (file_exists($stamps_file)) {
        $raw = file_get_contents($stamps_file);
        $stamps = json_decode($raw, true);
        // 配列でない・要素が文字列でない場合は空リストとして返す
        if (!is_array($stamps)) {
            echo json_encode([]);
        } else {
            $stamps = array_values(array_filter($stamps, 'is_string'));
            echo json_encode($stamps, JSON_UNESCAPED_UNICODE);
        }
    } else {
        echo json_encode([]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json_data = file_get_contents('php://input');
    $decoded = json_decode($json_data, true);

    if (is_array($decoded)) {
        $stamps = array_values(array_filter($decoded, 'is_string'));
        // 上限100個・各100文字
        $stamps = array_slice($stamps, 0, 100);
        $stamps = array_map(function($s) { return mb_substr(trim((string)$s), 0, 100, 'UTF-8'); }, $stamps);
        $stamps = array_filter($stamps, function($s) { return $s !== ''; });
        $stamps = array_values($stamps);
        $encoded = json_encode($stamps, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'JSONエンコードに失敗しました']);
            exit;
        }

        $lock_file = $stamps_file . '.lock';
        // 'c'モードを使う（'w'だとロック取得時にファイルが切り詰められる）
        $lock_fp = @fopen($lock_file, 'c');
        if ($lock_fp && flock($lock_fp, LOCK_EX)) {
            $write_ok = @file_put_contents($stamps_file, $encoded);
            @chmod($stamps_file, 0666);
            flock($lock_fp, LOCK_UN);
        }
        if ($lock_fp) fclose($lock_fp);

        if ($write_ok !== false) {
            echo json_encode(['status' => 'success']);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'stamps.json への書き込みに失敗しました']);
        }
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    }
    exit;
}
