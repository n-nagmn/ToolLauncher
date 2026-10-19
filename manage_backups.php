<?php
header('Content-Type: application/json; charset=utf-8');

// 補助関数
// バックアップZIPに含めるファイル（ゲームデータ・共有データ・スタンプ・チャット）
function backup_data_files($dir) {
    $files = [];
    foreach (['games.json', 'share.json', 'stamps.json', 'chat.json'] as $name) {
        $path = $dir . '/' . $name;
        if (is_file($path)) {
            $files[$path] = $name;
        }
    }
    return $files;
}

// uploads/配下の画像ファイル（白名单: 拡張子を許可のみ。サブディレクトリは含まない）
function backup_upload_files($dir) {
    $files = [];
    $uploads = $dir . '/uploads';
    if (!is_dir($uploads)) return $files;
    foreach (glob($uploads . '/*') ?: [] as $path) {
        if (!is_file($path)) continue;
        $base = basename($path);
        if (preg_match('/^(stamp|img)_[0-9a-f]+\.(png|jpe?g|gif|webp)$/i', $base)) {
            $files[$path] = 'uploads/' . $base;
        }
    }
    return $files;
}

// レートリミット（post_chat.php / post_share.php と同じパターン: 30 req / 60 sec → 429）
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

// ゲームJSONバックアップの検証（restore: games.jsonへ書き戻す前に各エントリを検証する）
// save_games.php と同じルール: 配列であること / id・name・url必須 / URLスキームの拒否
// 検証に通れば正規化された配列を返す。失敗時はnull
function validate_games_backup($restored) {
    if (!is_array($restored)) return null;
    $required_fields = ['id', 'name', 'url'];
    $validated = [];
    foreach ($restored as $index => $game) {
        if (!is_array($game)) return null;
        foreach ($required_fields as $field) {
            if (!isset($game[$field]) || (is_string($game[$field]) && trim($game[$field]) === '')) {
                return null;
            }
        }
        $game_id = $game['id'];
        if (is_numeric($game_id)) {
            $game_id = (string)intval($game_id);
        } else {
            $game_id = trim((string)$game_id);
        }
        if ($game_id === '') return null;

        // URL検証: save_games.php と同じスキーム拒否
        // 絶対URLのみ構文チェック。相対パスやuploads/等の内部パスはそのまま許可
        $clean_url = trim((string)$game['url']);
        $has_scheme = strpos($clean_url, '://') !== false;
        $is_http = preg_match('#^https?://#i', $clean_url);
        $has_traversal = preg_match('#(^|/)\.\.(/|$)#', $clean_url);
        // javascript:/data:/vbscript:/file: 等の危険なスキームを明示的に拒否
        $has_dangerous_scheme = preg_match('#^(javascript|data|vbscript|file):#i', trim((string)$game['url']));
        if (($has_scheme && !$is_http)
            || $has_dangerous_scheme
            || ($is_http && !filter_var($clean_url, FILTER_VALIDATE_URL))
            || $has_traversal) {
            $clean_url = '';
        }

        $validated[] = [
            'id' => $game_id,
            'name' => mb_substr(trim((string)$game['name']), 0, 100, 'UTF-8'),
            'url' => $clean_url,
            'image' => isset($game['image']) ? trim((string)$game['image']) : '',
            'category' => isset($game['category']) ? mb_substr(trim((string)$game['category']), 0, 50, 'UTF-8') : '',
            'playersMin' => isset($game['playersMin']) ? max(0, intval($game['playersMin'])) : 0,
            'playersMax' => isset($game['playersMax']) ? max(0, intval($game['playersMax'])) : 0,
            'createdAt' => isset($game['createdAt']) ? intval($game['createdAt']) : (time() * 1000),
            'updatedAt' => isset($game['updatedAt']) ? intval($game['updatedAt']) : (time() * 1000),
            'lastPlayed' => isset($game['lastPlayed']) ? intval($game['lastPlayed']) : 0,
            'hasUpdate' => isset($game['hasUpdate']) ? filter_var($game['hasUpdate'], FILTER_VALIDATE_BOOLEAN) : false,
            'remoteUpdatedAt' => isset($game['remoteUpdatedAt']) ? intval($game['remoteUpdatedAt']) : 0,
            'lastHash' => isset($game['lastHash']) ? trim((string)$game['lastHash']) : '',
            'isPinned' => isset($game['isPinned']) ? filter_var($game['isPinned'], FILTER_VALIDATE_BOOLEAN) : false,
            'playCount' => isset($game['playCount']) ? max(0, intval($game['playCount'])) : 0,
            'activeUsers' => isset($game['activeUsers']) && is_array($game['activeUsers']) ? $game['activeUsers'] : [],
            'creator' => isset($game['creator']) ? mb_substr(trim((string)$game['creator']), 0, 10, 'UTF-8') : ''
        ];
    }
    return $validated;
}

// ZIPバックアップを作成する。成功したらパス、失敗したらfalse
function create_backup_zip($backup_name, $dir) {
    $files = array_merge(backup_data_files($dir), backup_upload_files($dir));
    if (empty($files)) return false;
    $zip = new ZipArchive();
    if ($zip->open($backup_name, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    foreach ($files as $local => $archive) {
        if (!$zip->addFile($local, $archive)) {
            $zip->close();
            return false;
        }
    }
    $zip->close();
    return $backup_name;
}

// ZIPバックアップを復元する。展開ルール:
// - 直下(またはサブフォルダ直下)の games.json / share.json / stamps.json / chat.json はディレクトリ直下に配置
// - uploads/ 配下のファイルは uploads/ に配置し、ファイル名は白名单のみ許可
// 不正なメンバーが含まれている場合はfalseを返す
function restore_from_zip($zip_path, $dir) {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return false;
    }
    $data_names = ['games.json', 'share.json', 'stamps.json', 'chat.json'];
    $count = $zip->numFiles;
    for ($i = 0; $i < $count; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false || $name === '') continue;
        // Windowsスタイルのパス区切りを統一する
        $norm = str_replace('\\', '/', $name);
        // パストラバーサル（..）を含むメンバーは拒否
        if (strpos($norm, '..') !== false) {
            $zip->close();
            return false;
        }
        // ディレクトリエントリはスキップ
        if (substr($norm, -1) === '/') continue;

        $target = null;
        $parts = explode('/', $norm);
        $base = end($parts);
        $depth = count($parts);

        // 1) ゲームデータ等のJSONファイル
        if (in_array($base, $data_names, true) && $depth <= 2) {
            $target = $dir . '/' . $base;
        }
        // 2) uploads/ 配下の画像ファイル（ファイル名白名单のみ）
        elseif (preg_match('/^(stamp|img)_[0-9a-f]+\.(png|jpe?g|gif|webp)$/i', $base)) {
            if (!is_dir($dir . '/uploads')) {
                if (!@mkdir($dir . '/uploads', 0777, true)) {
                    $zip->close();
                    return false;
                }
                @chmod($dir . '/uploads', 0777);
            }
            $target = $dir . '/uploads/' . $base;
        }
        // 3) その他のメンバーは無視（任意のファイルを配置しない）

        if ($target !== null) {
            $content = $zip->getFromName($name);
            if ($content === false) {
                $zip->close();
                return false;
            }
            if (@file_put_contents($target, $content) === false) {
                $zip->close();
                return false;
            }
            @chmod($target, 0666);
        }
    }
    $zip->close();
    return true;
}

$backup_dir = __DIR__ . '/backups';
if (!is_dir($backup_dir)) {
    @mkdir($backup_dir, 0777, true);
    @chmod($backup_dir, 0777);
}

$games_file = __DIR__ . '/games.json';
$share_file = __DIR__ . '/share.json';
$stamps_file = __DIR__ . '/stamps.json';
$uploads_dir = __DIR__ . '/uploads';
$chat_file = __DIR__ . '/chat.json';

$json_data = file_get_contents('php://input');
$decoded = json_decode($json_data, true) ?: [];
$action = $_GET['action'] ?? $decoded['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'download') {
    // Rate limiting（ダウンロードは認証がないため、無制限のダウンロードを防止する）
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!checkRateLimit(__DIR__ . '/.rate_limit_backups', $client_ip, 60, 30)) {
        http_response_code(429);
        echo "Too many requests. Please try again later.";
        exit;
    }

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
    if (create_backup_zip($backup_name, __DIR__)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="backup.zip"');
        header('Content-Length: ' . filesize($backup_name));
        readfile($backup_name);
        unlink($backup_name);
        exit;
    } else {
        @unlink($backup_name);
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
    $response = ["status" => "error", "message" => ""];

    // Rate limiting (30 req / 60 sec → 429)
    $rate_limit_file = __DIR__ . '/.rate_limit_backups';
    $rate_limit_window = 60;
    $rate_limit_max = 30;
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!checkRateLimit($rate_limit_file, $client_ip, $rate_limit_window, $rate_limit_max)) {
        http_response_code(429);
        $response["message"] = "リクエストが多すぎます。しばらく経ってからお試しください。";
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'create') {
        $backup_name = $backup_dir . '/backup_' . date('Ymd_His') . '_' . uniqid() . '.zip';
        if (create_backup_zip($backup_name, __DIR__)) {
            @chmod($backup_name, 0666);
            $response["status"] = "success";
            $response["message"] = "バックアップを作成しました";
        } else {
            @unlink($backup_name);
            $response["message"] = "バックアップの作成に失敗しました";
        }
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
            if (restore_from_zip($tmp_name, __DIR__)) {
                // ZIPの展開はメンバーのパスチェックのみ行い、games.jsonの中身は検証されない
                // 復元後のgames.jsonをvalidate_games_backupで検証し、不正データが混入した場合にロールバック
                if (is_file($games_file)) {
                    $restored_json = json_decode((string)@file_get_contents($games_file), true);
                    $validated = validate_games_backup($restored_json);
                    if ($validated === null) {
                        $response["message"] = "復元後のgames.jsonに不正なデータが含まれています。復元を中止しました。";
                        echo json_encode($response, JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    $restored_json = json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    if ($restored_json === false || @file_put_contents($games_file, $restored_json) === false) {
                        $response["message"] = "復元後のgames.jsonの書き込みに失敗しました";
                        echo json_encode($response, JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                }
                @chmod($games_file, 0666);
                @chmod($share_file, 0666);
                @chmod($stamps_file, 0666);
                @chmod($chat_file, 0666);
                $response["status"] = "success";
                $response["message"] = "アップロードされたZIPから復元しました";
            } else {
                $response["message"] = "ZIPファイルの展開に失敗しました";
            }
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
        } else {
            // 非ZIPのJSONバックアップ: 検証してからgames.jsonへ書き戻す
            $backup_content = @file_get_contents($tmp_name);
            if ($backup_content === false) {
                $response["message"] = "復元に失敗しました";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $restored = json_decode($backup_content, true);
            if (!is_array($restored)) {
                $response["message"] = "復元できません。有効なJSON配列ではありません。";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                exit;
            }
            // 検証してからgames.jsonへ書き戻す（各エントリにid/name/url必須・URLスキーム検証）
            $validated = validate_games_backup($restored);
            if ($validated === null) {
                $response["message"] = "復元できません。バックアップに不正なデータが含まれています。";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $restored_json = json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($restored_json === false || @file_put_contents($games_file, $restored_json) === false) {
                $response["message"] = "復元に失敗しました";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                exit;
            }
            @chmod($games_file, 0666);
            $response["status"] = "success";
            $response["message"] = "アップロードされたJSONから復元しました";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
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
            if (restore_from_zip($target_backup, __DIR__)) {
                // ZIPの展開はメンバーのパスチェックのみ行い、games.jsonの中身は検証されない
                // 復元後のgames.jsonをvalidate_games_backupで検証し、不正データが混入した場合にロールバック
                if (is_file($games_file)) {
                    $restored_json = json_decode((string)@file_get_contents($games_file), true);
                    $validated = validate_games_backup($restored_json);
                    if ($validated === null) {
                        $response["message"] = "復元後のgames.jsonに不正なデータが含まれています。復元を中止しました。";
                        echo json_encode($response, JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    $restored_json = json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    if ($restored_json === false || @file_put_contents($games_file, $restored_json) === false) {
                        $response["message"] = "復元後のgames.jsonの書き込みに失敗しました。パーミッションを確認してください。";
                        echo json_encode($response, JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                }
                @chmod($games_file, 0666);
                @chmod($share_file, 0666);
                @chmod($stamps_file, 0666);
                @chmod($chat_file, 0666);
                $response["status"] = "success";
                $response["message"] = "ZIPバックアップから復元しました";
            } else {
                $response["message"] = "ZIPファイルの展開に失敗しました";
            }
        } else {
            // JSONバックアップ: 検証してからgames.jsonへ書き戻す
            $backup_content = @file_get_contents($target_backup);
            if ($backup_content === false) {
                $response["message"] = "復元に失敗しました。パーミッションを確認してください。";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $restored = json_decode($backup_content, true);
            if (!is_array($restored)) {
                $response["message"] = "復元できません。有効なJSON配列ではありません。";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                exit;
            }
            // 検証してからgames.jsonへ書き戻す（各エントリにid/name/url必須・URLスキーム検証）
            $validated = validate_games_backup($restored);
            if ($validated === null) {
                $response["message"] = "復元できません。バックアップに不正なデータが含まれています。";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                exit;
            }
            $restored_json = json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($restored_json === false || @file_put_contents($games_file, $restored_json) === false) {
                $response["message"] = "復元に失敗しました。パーミッションを確認してください。";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                exit;
            }
            @chmod($games_file, 0666);
            $response["status"] = "success";
            $response["message"] = "JSONバックアップから復元しました";
        }
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
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
        } else {
            $response["message"] = "削除に失敗しました。パーミッションを確認してください。";
        }
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $response["message"] = "無効なアクションです";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(["status" => "error", "message" => "Bad Request"], JSON_UNESCAPED_UNICODE);
?>
