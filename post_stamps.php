<?php
header('Content-Type: application/json; charset=utf-8');

$stamps_file = __DIR__ . '/stamps.json';

// Check for action=upload_image in GET params
if (isset($_GET['action']) && $_GET['action'] === 'upload_image') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
        exit;
    }
    
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . "/uploads/";
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
            @chmod($uploadDir, 0777);
        }
        
        $check = getimagesize($_FILES['image']['tmp_name']);
        if($check !== false) {
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            if (!$ext) $ext = 'png';
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
        echo file_get_contents($stamps_file);
    } else {
        echo json_encode([]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json_data = file_get_contents('php://input');
    $decoded = json_decode($json_data, true);

    if ($decoded !== null) {
        $stamps = array_values($decoded);
        $encoded = json_encode($stamps, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        $lock_file = $stamps_file . '.lock';
        $lock_fp = fopen($lock_file, 'w');
        if (flock($lock_fp, LOCK_EX)) {
            @file_put_contents($stamps_file, $encoded);
            @chmod($stamps_file, 0666);
            flock($lock_fp, LOCK_UN);
        }
        fclose($lock_fp);
        
        echo json_encode(['status' => 'success']);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    }
    exit;
}
