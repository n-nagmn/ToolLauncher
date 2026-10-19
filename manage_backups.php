<?php
/**
 * Web Game Launcher Pro - Backup Management
 *
 * All ZIP handling is done with PHP's ZipArchive extension (no external
 * processes). Uploads are strictly validated:
 *  - non-zip restore: content must decode to a JSON array before it is
 *    re-encoded and written to games.json
 *  - zip restore: members are whitelisted (exact json filenames at the
 *    archive root, and uploads/ files matching stamp_/img_ basenames);
 *    paths containing '..' or absolute paths are rejected; every JSON
 *    member is fully validated before anything is extracted (H3).
 *
 * Restores are transactional (H2): all data files are snapshotted to a
 * temp directory before any modification, and the snapshot is restored
 * on any validation failure. Success is only reported after the whole
 * restore has been validated.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$backup_dir = __DIR__ . '/backups';
if (!is_dir($backup_dir)) {
    @mkdir($backup_dir, 0755, true);
    @chmod($backup_dir, 0755);
}

// Strict pattern for server-side backup filenames (created via the create action)
$backup_filename_regex = '/^backup_\d{8}_\d{6}_[0-9a-f]+\.zip$/';

$games_file = __DIR__ . '/games.json';
$share_file = __DIR__ . '/share.json';
$chat_file = __DIR__ . '/chat.json';
$stamps_file = __DIR__ . '/stamps.json';
$config_file = __DIR__ . '/config.json';
$uploads_dir = __DIR__ . '/uploads';

// Files that may be stored at the archive root
$root_json_files = ['games.json', 'share.json', 'chat.json', 'stamps.json', 'config.json'];

// Regex for allowed upload file basenames (inside uploads/ in the archive)
$upload_file_regex = '/^(stamp_|img_)[A-Za-z0-9_-]+\.(png|jpe?g|gif|webp)$/i';

// M17: per-member extraction size cap (50MB)
$max_member_size = 50 * 1024 * 1024;

// H38: rate limiting for ALL manage_backups actions
$rate_limit_file = __DIR__ . '/.rate_limit_backups';
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

// H38: every action (GET and POST) is rate limited
if (!checkRateLimit($rate_limit_file, $client_ip, $rate_limit_window, $rate_limit_max)) {
    http_response_code(429);
    echo json_encode(["status" => "error", "message" => "リクエストが多すぎます。しばらく経ってからお試しください。"], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Create a backup zip of games.json / share.json / chat.json / stamps.json / uploads/.
 */
function createBackupZip($backup_name, $files, $uploads_dir) {
    if (!class_exists('ZipArchive')) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($backup_name, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    foreach ($files as $file) {
        if (file_exists($file)) {
            $zip->addFile($file, basename($file));
        }
    }
    if (is_dir($uploads_dir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploads_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $rel = ltrim(str_replace('\\', '/', $f->getPathname()), '/');
                $base = __DIR__ . DIRECTORY_SEPARATOR;
                if (strpos($rel, $base) === 0) {
                    $rel = substr($rel, strlen($base));
                }
                $zip->addFile($f->getPathname(), $rel);
            }
        }
    }
    $zip->close();
    return file_exists($backup_name);
}

/**
 * H6: validate a games.json payload. Returns the normalized array, or null
 * if the data is invalid. Rules:
 *  - must be a JSON array (empty arrays are rejected)
 *  - every entry must be an object with:
 *      - numeric id
 *      - non-empty string name
 *      - url with a valid scheme (M24: javascript:/data:/vbscript:/file:
 *        rejected; absolute http(s) URLs must be syntactically valid)
 */
function validateGamesPayload($data) {
    if (!is_array($data) || count($data) === 0) {
        return null;
    }
    $validated = [];
    foreach ($data as $index => $game) {
        if (!is_array($game)) {
            return null;
        }
        // id: must be numeric
        $id = $game['id'] ?? null;
        if (!is_numeric($id) || trim((string)$id) === '' || (float)$id != floor((float)$id)) {
            return null;
        }
        // name: must be a non-empty string
        if (!isset($game['name']) || !is_string($game['name']) || trim($game['name']) === '') {
            return null;
        }
        // url: required string; dangerous schemes rejected
        $url = $game['url'] ?? null;
        if (!is_string($url)) {
            return null;
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (preg_match('#^(javascript|data|vbscript|file):#i', $url)) {
            return null;
        }
        if (strpos($url, '://') !== false) {
            if (!preg_match('#^https?://#i', $url)) {
                return null;
            }
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return null;
            }
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $url)) {
            return null;
        }

        $validated[] = [
            'id' => intval($id),
            'name' => mb_substr(trim($game['name']), 0, 100, 'UTF-8'),
            'url' => $url,
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

/**
 * Validate and write a non-zip backup to games.json.
 * H6: empty arrays are rejected; every entry must have a numeric id, a
 * non-empty string name and a url with a valid scheme.
 * The caller must hold games.lock (H6).
 */
function restoreJsonBackup($content, $games_file) {
    if ($content === false || $content === '') {
        return false;
    }
    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        return false;
    }
    $validated = validateGamesPayload($decoded);
    if ($validated === null) {
        return false;
    }
    // Re-encode the validated data (strips any malformed trailing content)
    $json_output = json_encode(array_values($validated), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json_output === false) {
        return false;
    }
    $tmp = $games_file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json_output, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $games_file)) {
        @unlink($tmp);
        return false;
    }
    @chmod($games_file, 0666);
    return true;
}

/**
 * Extract a backup zip into $dest. Returns [ok, error_message].
 * Members are strictly whitelisted; anything else is rejected.
 * H3: every JSON member is fully validated (json_decode + schema) before
 *     any file is written.
 * M17: no single member may exceed 50MB of uncompressed content.
 */
function extractBackupZip($zip_path, $dest, $root_json_files, $lock_file = null) {
    if (!class_exists('ZipArchive')) {
        return [false, 'ZipArchive拡張機能が利用できません'];
    }

    // Acquire an exclusive lock on the shared restore lock file (same pattern
    // as save_games.php) so a zip restore never races with a game save.
    // M16: 'c' mode so opening the lock file never truncates it.
    $fp_lock = null;
    if ($lock_file !== null) {
        $fp_lock = @fopen($lock_file, 'c');
        if ($fp_lock !== false && !flock($fp_lock, LOCK_EX)) {
            fclose($fp_lock);
            return [false, 'サーバーが混雑しています。もう一度お試しください。'];
        }
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        if ($fp_lock !== null) { flock($fp_lock, LOCK_UN); fclose($fp_lock); }
        return [false, 'ZIPファイルを開けません'];
    }

    // Validate every member before extracting anything
    $members = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false || $name === '') continue;
        // Normalize separators; reject absolute paths and ".." traversal
        $normalized = str_replace('\\', '/', (string)$name);
        if ($normalized === '' || $normalized === '.') continue;
        if ($normalized[0] === '/' || preg_match('/^[a-z]:/i', $normalized)) {
            $zip->close();
            if ($fp_lock !== null) { flock($fp_lock, LOCK_UN); fclose($fp_lock); }
            return [false, '不正なファイルパスが含まれています: ' . $name];
        }
        $parts = explode('/', $normalized);
        if (in_array('..', $parts, true)) {
            $zip->close();
            if ($fp_lock !== null) { flock($fp_lock, LOCK_UN); fclose($fp_lock); }
            return [false, '不正なファイルパスが含まれています: ' . $name];
        }

        $base = array_pop($parts);
        if ($base === '') continue;

        $target_path = null;
        if ($parts === [] && in_array($base, $root_json_files, true)) {
            // JSON files must sit at the archive root
            $target_path = $dest . '/' . $base;
        } elseif (count($parts) === 1 && $parts[0] === 'uploads' && preg_match($upload_file_regex, $base)) {
            // Upload files must be inside uploads/ with a whitelisted basename
            $target_path = $dest . '/uploads/' . $base;
        }
        if ($target_path === null) {
            $zip->close();
            if ($fp_lock !== null) { flock($fp_lock, LOCK_UN); fclose($fp_lock); }
            return [false, '許可されていないファイルが含まれています: ' . $name];
        }

        // M17: enforce the per-member size cap before writing anything
        $stat = $zip->statIndex($i);
        if ($stat === false || !is_array($stat)) {
            $zip->close();
            if ($fp_lock !== null) { flock($fp_lock, LOCK_UN); fclose($fp_lock); }
            return [false, 'ZIPメンバーの読み込みに失敗しました: ' . $name];
        }
        if (($stat['size'] ?? 0) > $max_member_size) {
            $zip->close();
            if ($fp_lock !== null) { flock($fp_lock, LOCK_UN); fclose($fp_lock); }
            return [false, 'ZIPメンバーがサイズ上限（50MB）を超えています: ' . $name];
        }

        $members[] = ['name' => $name, 'target' => $target_path];
    }

    // H3: validate ALL JSON members' content before extracting anything
    foreach ($members as $m) {
        if (substr($m['name'], -5) === '.json') {
            $content = $zip->getFromName($m['name']);
            if ($content === false) {
                $zip->close();
                if ($fp_lock !== null) { flock($fp_lock, LOCK_UN); fclose($fp_lock); }
                return [false, 'ZIPメンバーの読み込みに失敗しました: ' . $m['name']];
            }
            $data = json_decode($content, true);
            if ($data === null && trim((string)$content) !== 'null') {
                $zip->close();
                if ($fp_lock !== null) { flock($fp_lock, LOCK_UN); fclose($fp_lock); }
                return [false, 'JSONファイルの内容が不正です: ' . $m['name']];
            }
            // games.json has the strictest schema
            if (basename($m['name']) === 'games.json') {
                if (validateGamesPayload(is_array($data) ? $data : null) === null) {
                    $zip->close();
                    if ($fp_lock !== null) { flock($fp_lock, LOCK_UN); fclose($fp_lock); }
                    return [false, 'games.jsonの内容が不正です: ' . $m['name']];
                }
            } else {
                // share.json / chat.json / stamps.json / config.json must be valid JSON
                if (!is_array($data)) {
                    $zip->close();
                    if ($fp_lock !== null) { flock($fp_lock, LOCK_UN); fclose($fp_lock); }
                    return [false, 'JSONファイルの内容が不正です: ' . $m['name']];
                }
            }
        }
    }

    // Extract (all members already validated).
    // Each member is written atomically: temp file + rename, so a failed
    // restore never leaves a half-written JSON file behind.
    foreach ($members as $m) {
        $dir = dirname($m['target']);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            @chmod($dir, 0755);
        }
        $src = $zip->getFromName($m['name']);
        if ($src === false) {
            $zip->close();
            if ($fp_lock !== null) { flock($fp_lock, LOCK_UN); fclose($fp_lock); }
            return [false, 'ZIPメンバーの読み込みに失敗しました: ' . $m['name']];
        }
        // M17: re-check actual decompressed size (covers compressed entries
        // whose stored size may be reported differently)
        if (strlen($src) > $max_member_size) {
            $zip->close();
            if ($fp_lock !== null) { flock($fp_lock, LOCK_UN); fclose($fp_lock); }
            return [false, 'ZIPメンバーがサイズ上限（50MB）を超えています: ' . $m['name']];
        }
        $tmp = $m['target'] . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $src, LOCK_EX) === false) {
            $zip->close();
            if ($fp_lock !== null) { flock($fp_lock, LOCK_UN); fclose($fp_lock); }
            return [false, 'ファイルの書き込みに失敗しました: ' . $m['target']];
        }
        if (!@rename($tmp, $m['target'])) {
            @unlink($tmp);
            $zip->close();
            if ($fp_lock !== null) { flock($fp_lock, LOCK_UN); fclose($fp_lock); }
            return [false, 'ファイルの書き込みに失敗しました: ' . $m['target']];
        }
        @chmod($m['target'], 0666);
    }
    $zip->close();
    if ($fp_lock !== null) { flock($fp_lock, LOCK_UN); fclose($fp_lock); }
    return [true, ''];
}

/**
 * H2: snapshot the data files that a restore may modify into a temp
 * directory. Returns the snapshot dir (or null if it could not be made).
 */
function snapshotDataFiles($files, $uploads_dir) {
    $snap = sys_get_temp_dir() . '/backup_snap_' . uniqid('', true);
    if (!@mkdir($snap, 0700, true) && !is_dir($snap)) {
        return null;
    }
    foreach ($files as $path) {
        if (is_file($path)) {
            @copy($path, $snap . '/' . basename($path));
        }
    }
    if (is_dir($uploads_dir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploads_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($uploads_dir) + 1));
                $rel_target = $snap . '/uploads/' . $rel;
                $rel_dir = dirname($rel_target);
                if (!is_dir($rel_dir)) {
                    @mkdir($rel_dir, 0755, true);
                }
                @copy($f->getPathname(), $rel_target);
            }
        }
    }
    return $snap;
}

/**
 * H2: roll the data files back to their snapshotted contents. Files that
 * were created by the failed restore are removed; files that had no
 * snapshot are also removed (they did not exist before the restore).
 */
function restoreSnapshot($snap, $files, $uploads_dir) {
    if ($snap === null || !is_dir($snap)) {
        return;
    }
    foreach ($files as $path) {
        $snap_path = $snap . '/' . basename($path);
        if (is_file($snap_path)) {
            @copy($snap_path, $path);
            @chmod($path, 0666);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }
    if (is_dir($snap . '/uploads')) {
        // remove anything in uploads/ that was not in the snapshot
        $current = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploads_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($current as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($uploads_dir) + 1));
            if ($f->isFile()) {
                if (!is_file($snap . '/uploads/' . $rel)) {
                    @unlink($f->getPathname());
                }
            } elseif ($f->isDir() && $rel !== '' && !is_dir($snap . '/uploads/' . $rel)) {
                @rmdir($f->getPathname());
            }
        }
        // restore snapshot uploads that are missing
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($snap . '/uploads', FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($snap . '/uploads') + 1));
                $target = $uploads_dir . '/' . $rel;
                if (!is_file($target)) {
                    $dir = dirname($target);
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                    @copy($f->getPathname(), $target);
                }
            }
        }
    }
    // cleanup snapshot dir
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($snap, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($snap);
}

/**
 * Final validation pass after extraction (H2: success is only declared
 * after the whole restore is validated). Returns [ok, error].
 */
function validateRestoredFiles($files, $games_file) {
    foreach ($files as $path) {
        if (!is_file($path)) continue;
        $data = json_decode((string)@file_get_contents($path), true);
        if ($data === null && trim((string)@file_get_contents($path)) !== 'null') {
            return [false, '復元後のデータが不正です: ' . basename($path)];
        }
        if (!is_array($data)) {
            return [false, '復元後のデータが不正です: ' . basename($path)];
        }
    }
    // games.json must satisfy the strict schema
    if (is_file($games_file)) {
        if (validateGamesPayload(json_decode((string)@file_get_contents($games_file), true)) === null) {
            return [false, '復元後のgames.jsonに不正なデータが含まれています'];
        }
    }
    return [true, ''];
}

$json_data = file_get_contents('php://input');
$decoded = is_string($json_data) && $json_data !== '' ? (json_decode($json_data, true) ?: []) : [];
if (!is_array($decoded)) {
    $decoded = [];
}
$action = $_GET['action'] ?? $decoded['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'download') {
    $filename = $_GET['filename'] ?? '';
    if (empty($filename) || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
        http_response_code(400);
        echo "Invalid filename";
        exit;
    }
    // H2 (WGL extra): reject filenames containing '..'
    if (strpos($filename, '..') !== false) {
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
    $files = [$games_file, $share_file, $chat_file, $stamps_file, $config_file];
    if (createBackupZip($backup_name, $files, $uploads_dir)) {
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
    $response = ["status" => "error", "message" => ""];

    if ($action === 'create') {
        $backup_name = $backup_dir . '/backup_' . date('Ymd_His') . '_' . uniqid() . '.zip';
        $files = [$games_file, $share_file, $chat_file, $stamps_file, $config_file];
        if (createBackupZip($backup_name, $files, $uploads_dir)) {
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
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        $lock_file = __DIR__ . '/games.lock';
        $data_files = [$games_file, $share_file, $chat_file, $stamps_file, $config_file];

        // H2: snapshot data files before touching anything
        $snapshot = snapshotDataFiles($data_files, $uploads_dir);

        if ($ext === 'zip') {
            $dest = __DIR__;
            list($ok, $err) = extractBackupZip($tmp_name, $dest, $root_json_files, $lock_file);

            if ($ok) {
                // H2: only declare success after full validation
                list($val_ok, $val_err) = validateRestoredFiles($data_files, $games_file);
                if (!$val_ok) {
                    restoreSnapshot($snapshot, $data_files, $uploads_dir);
                    $response["message"] = "復元を中止しました（検証エラー: " . $val_err . "）";
                    echo json_encode($response, JSON_UNESCAPED_UNICODE);
                    exit;
                }
                restoreSnapshot($snapshot, $data_files, $uploads_dir); // cleanup snapshot
                @chmod($games_file, 0666);
                @chmod($share_file, 0666);
                @chmod($chat_file, 0666);
                @chmod($stamps_file, 0666);
                @chmod($config_file, 0666);
                $response["status"] = "success";
                $response["message"] = "アップロードされたZIPから復元しました";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
            } else {
                // H2: restore snapshot on failure
                restoreSnapshot($snapshot, $data_files, $uploads_dir);
                $response["message"] = "ZIPファイルの展開に失敗しました: " . $err;
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
            }
        } else {
            $backup_content = @file_get_contents($tmp_name);
            if (restoreJsonBackup($backup_content, $games_file)) {
                restoreSnapshot($snapshot, $data_files, $uploads_dir); // cleanup snapshot
                @chmod($games_file, 0666);
                $response["status"] = "success";
                $response["message"] = "アップロードされたJSONから復元しました";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
            } else {
                restoreSnapshot($snapshot, $data_files, $uploads_dir);
                $response["message"] = "復元に失敗しました。有効なJSONファイル（ゲームデータ配列）をアップロードしてください。";
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
        // M2: strict filename whitelist - only server-side backup zips
        if (!preg_match($backup_filename_regex, $filename)) {
            $response["message"] = "ファイル名が不正です（backup_YYYYMMDD_HHMMSS_<hex>.zip の形式のみ許可）";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        $target_backup = $backup_dir . '/' . $filename;
        if (!file_exists($target_backup)) {
            $response["message"] = "バックアップファイルが見つかりません";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Zip restore (the strict regex above guarantees this is a .zip file)
        $lock_file = __DIR__ . '/games.lock';
        $data_files = [$games_file, $share_file, $chat_file, $stamps_file, $config_file];

        // H2: snapshot data files before touching anything
        $snapshot = snapshotDataFiles($data_files, $uploads_dir);

        list($ok, $err) = extractBackupZip($target_backup, __DIR__, $root_json_files, $lock_file);

        if ($ok) {
            // H2: only declare success after full validation
            list($val_ok, $val_err) = validateRestoredFiles($data_files, $games_file);
            if (!$val_ok) {
                restoreSnapshot($snapshot, $data_files, $uploads_dir);
                $response["message"] = "復元を中止しました（検証エラー: " . $val_err . "）";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                exit;
            }
            restoreSnapshot($snapshot, $data_files, $uploads_dir); // cleanup snapshot
            @chmod($games_file, 0666);
            @chmod($share_file, 0666);
            @chmod($chat_file, 0666);
            @chmod($stamps_file, 0666);
            @chmod($config_file, 0666);
            $response["status"] = "success";
            $response["message"] = "ZIPバックアップから復元しました";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
        } else {
            // H2: restore snapshot on failure
            restoreSnapshot($snapshot, $data_files, $uploads_dir);
            $response["message"] = "ZIPファイルの展開に失敗しました: " . $err;
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
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
        // M2: strict filename whitelist - only server-side backup zips may be deleted
        if (!preg_match($backup_filename_regex, $filename)) {
            $response["message"] = "ファイル名が不正です（backup_YYYYMMDD_HHMMSS_<hex>.zip の形式のみ許可）";
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
