<?php
header('Content-Type: application/json; charset=utf-8');

$backup_dir = __DIR__ . '/backups';
if (!is_dir($backup_dir)) {
    @mkdir($backup_dir, 0777, true);
    @chmod($backup_dir, 0777);
}

$games_file = __DIR__ . '/games.json';
$share_file = __DIR__ . '/share.json';
$stamps_file = __DIR__ . '/stamps.json';
$uploads_dir = __DIR__ . '/uploads';
$chat_file = __DIR__ . '/chat.json'; // added chat.json just in case

$json_data = file_get_contents('php://input');
$decoded = json_decode($json_data, true) ?: [];
$action = $_GET['action'] ?? $decoded['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'download') {
    $filename = $_GET['filename'] ?? '';
    if (empty($filename) || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
        http_response_code(400);
        echo "Invalid filename";
        exit;
    }
    $target_file = $backup_dir . '/' . $filename;
    if (!file_exists($target_file)) {
        http_response_code(404);
        echo "File not found";
        exit;
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($target_file) . '"');
    header('Content-Length: ' . filesize($target_file));
    readfile($target_file);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'export') {
    $backup_name = $backup_dir . '/export_' . date('Ymd_His') . '_' . uniqid() . '.zip';
    $py_cmd = "python3 -c \"
import zipfile, os
with zipfile.ZipFile('" . $backup_name . "', 'w', zipfile.ZIP_DEFLATED) as zf:
    if os.path.exists('" . $games_file . "'): zf.write('" . $games_file . "', 'games.json')
    if os.path.exists('" . $share_file . "'): zf.write('" . $share_file . "', 'share.json')
    if os.path.exists('" . $stamps_file . "'): zf.write('" . $stamps_file . "', 'stamps.json')
    if os.path.exists('" . $uploads_dir . "'):
        for root, dirs, files in os.walk('" . $uploads_dir . "'):
            for file in files:
                filepath = os.path.join(root, file)
                arcname = os.path.relpath(filepath, '" . __DIR__ . "')
                zf.write(filepath, arcname)
\" 2>&1";
    exec($py_cmd, $out, $ret);
    if ($ret === 0 && file_exists($backup_name)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="backup.zip"');
        header('Content-Length: ' . filesize($backup_name));
        readfile($backup_name);
        unlink($backup_name);
        exit;
    } else {
        http_response_code(500);
        echo "Failed to create zip";
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    $files = array_merge(glob($backup_dir . '/games_*.json') ?: [], glob($backup_dir . '/backup_*.zip') ?: []);
    $backups = [];
    if ($files) {
        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'time' => filemtime($file)
            ];
        }
        usort($backups, function($a, $b) {
            return $b['time'] - $a['time'];
        });
    }
    echo json_encode(["status" => "success", "backups" => $backups], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'create') {
        $backup_name = $backup_dir . '/backup_' . date('Ymd_His') . '_' . uniqid() . '.zip';
        $py_cmd = "python3 -c \"
import zipfile, os
with zipfile.ZipFile('" . $backup_name . "', 'w', zipfile.ZIP_DEFLATED) as zf:
    if os.path.exists('" . $games_file . "'): zf.write('" . $games_file . "', 'games.json')
    if os.path.exists('" . $share_file . "'): zf.write('" . $share_file . "', 'share.json')
    if os.path.exists('" . $stamps_file . "'): zf.write('" . $stamps_file . "', 'stamps.json')
    if os.path.exists('" . $uploads_dir . "'):
        for root, dirs, files in os.walk('" . $uploads_dir . "'):
            for file in files:
                filepath = os.path.join(root, file)
                arcname = os.path.relpath(filepath, '" . __DIR__ . "')
                zf.write(filepath, arcname)
\" 2>&1";
        exec($py_cmd, $out, $ret);
        
        if ($ret === 0 && file_exists($backup_name)) {
            @chmod($backup_name, 0666);
            $response["status"] = "success";
            $response["message"] = "バックアップを作成しました";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $response["message"] = "バックアップの作成に失敗しました";
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'upload_restore') {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $response["message"] = "アップロードに失敗しました";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $tmp_name = $_FILES['file']['tmp_name'];
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        
        if ($ext === 'zip') {
            $dest = __DIR__;
            $py_cmd = "python3 -c \"
import sys, zipfile, os

try:
    with zipfile.ZipFile('". $tmp_name ."', 'r') as zf:
        for member in zf.namelist():
            base = os.path.basename(member)
            if not base: continue
            
            target_path = None
            if base in ['games.json', 'share.json', 'stamps.json']:
                target_path = os.path.join('". $dest ."', base)
            elif 'uploads/' in member or 'uploads' in member or base.startswith('stamp_') or base.startswith('img_'):
                target_path = os.path.join('". $dest ."', 'uploads', base)
                
            if target_path:
                if os.path.exists(target_path):
                    try:
                        os.remove(target_path)
                    except Exception:
                        pass
                os.makedirs(os.path.dirname(target_path), exist_ok=True)
                with zf.open(member) as source, open(target_path, 'wb') as target:
                    target.write(source.read())
except Exception as e:
    print(f'{type(e).__name__}: {str(e)}')
    sys.exit(1)
\" 2>&1";
            exec($py_cmd, $out, $ret);
            
            if ($ret === 0) {
                @chmod($games_file, 0666);
                @chmod($share_file, 0666);
                @chmod($stamps_file, 0666);
                $response["status"] = "success";
                $response["message"] = "アップロードされたZIPから復元しました";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
            } else {
                $err = implode(" ", $out);
                $response["message"] = "ZIPファイルの展開に失敗しました: " . $err;
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
            }
        } else {
            $backup_content = @file_get_contents($tmp_name);
            if ($backup_content !== false && @file_put_contents($games_file, $backup_content) !== false) {
                @chmod($games_file, 0666);
                $response["status"] = "success";
                $response["message"] = "アップロードされたJSONから復元しました";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
            } else {
                $response["message"] = "復元に失敗しました";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
            }
        }
        exit;
    }

    if ($action === 'restore') {
        $filename = $decoded['filename'] ?? '';
        if (empty($filename) || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            $response["message"] = "不正なファイル名です";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        $target_backup = $backup_dir . '/' . $filename;
        if (!file_exists($target_backup)) {
            $response["message"] = "バックアップファイルが見つかりません";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (pathinfo($target_backup, PATHINFO_EXTENSION) === 'zip') {
            $dest = __DIR__;
            $py_cmd = "python3 -c \"
import sys, zipfile, os

try:
    with zipfile.ZipFile('" . $target_backup . "', 'r') as zf:
        for member in zf.namelist():
            base = os.path.basename(member)
            if not base: continue
            
            target_path = None
            if base in ['games.json', 'share.json', 'stamps.json']:
                target_path = os.path.join('" . $dest . "', base)
            elif 'uploads/' in member or 'uploads' in member or base.startswith('stamp_') or base.startswith('img_'):
                target_path = os.path.join('" . $dest . "', 'uploads', base)
                
            if target_path:
                if os.path.exists(target_path):
                    try:
                        os.remove(target_path)
                    except Exception:
                        pass
                os.makedirs(os.path.dirname(target_path), exist_ok=True)
                with zf.open(member) as source, open(target_path, 'wb') as target:
                    target.write(source.read())
except Exception as e:
    print(f'{type(e).__name__}: {str(e)}')
    sys.exit(1)
\" 2>&1";
            exec($py_cmd, $out, $ret);
            
            if ($ret === 0) {
                @chmod($games_file, 0666);
                @chmod($share_file, 0666);
                @chmod($stamps_file, 0666);
                $response["status"] = "success";
                $response["message"] = "ZIPバックアップから復元しました";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
            } else {
                $err = implode(" ", $out);
                $response["message"] = "ZIPファイルの展開に失敗しました: " . $err;
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
            }
        } else {
            $backup_content = @file_get_contents($target_backup);
            if ($backup_content !== false && @file_put_contents($games_file, $backup_content) !== false) {
                @chmod($games_file, 0666);
                $response["status"] = "success";
                $response["message"] = "JSONバックアップから復元しました";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
            } else {
                $response["message"] = "復元に失敗しました。パーミッションを確認してください。";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
            }
        }
        exit;
    }

    if ($action === 'delete') {
        $filename = $decoded['filename'] ?? '';
        if (empty($filename) || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            $response["message"] = "不正なファイル名です";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        $target_backup = $backup_dir . '/' . $filename;
        if (!file_exists($target_backup)) {
            $response["message"] = "バックアップファイルが見つかりません";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (unlink($target_backup)) {
            $response["status"] = "success";
            $response["message"] = "バックアップを削除しました";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
        } else {
            $response["message"] = "削除に失敗しました。パーミッションを確認してください。";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    
    $response["message"] = "無効なアクションです";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(["status" => "error", "message" => "Bad Request"], JSON_UNESCAPED_UNICODE);
?>
