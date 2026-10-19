<?php
/**
 * Web Game Launcher Pro - Game Data Save Endpoint (Fixed)
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
$file_path = __DIR__ . '/games.json';
$max_file_size = 5 * 1024 * 1024; // 5MB
$max_games = 500;

// Rate limiting
$rate_limit_file = __DIR__ . '/.rate_limit_games';
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

$json_data = file_get_contents('php://input');

if (empty($json_data)) {
    $response["message"] = "データが空です";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

if (strlen($json_data) > $max_file_size) {
    $response["message"] = "データサイズが大きすぎます（最大5MB）";
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
    $response["message"] = "ゲームデータは配列である必要があります";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

if (count($decoded) > $max_games) {
    $response["message"] = "ゲーム数が上限（{$max_games}件）を超えています";
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// M16: use 'c' mode so opening the lock file does not truncate it
$lock_file = __DIR__ . '/games.lock';
$fp_lock = fopen($lock_file, 'c');

if ($fp_lock && flock($fp_lock, LOCK_EX)) {
    // --- Start of Active User Merge Logic ---
    $existing_games = [];
    if (file_exists($file_path)) {
        $existing_raw = file_get_contents($file_path);
        $existing_games = json_decode($existing_raw, true) ?: [];
    }
    // 既存のアクティブユーザー情報をIDをキーにしたマップにする
    $existing_active_map = [];
    foreach ($existing_games as $ex_game) {
        if (isset($ex_game['id']) && isset($ex_game['activeUsers']) && is_array($ex_game['activeUsers'])) {
            $existing_active_map[$ex_game['id']] = $ex_game['activeUsers'];
        }
    }
    $one_hour_ago_ms = (time() - 3600) * 1000;
    // --- End of Active User Merge Logic ---

    // Validate each game entry
    $required_fields = ['id', 'name', 'url'];
    $validated = [];
    $seen_ids = []; // duplicate id detection
    foreach ($decoded as $index => $game) {
        if (!is_array($game)) {
            $response["message"] = "インデックス {$index} のデータが不正です";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            flock($fp_lock, LOCK_UN);
            fclose($fp_lock);
            exit;
        }

        foreach ($required_fields as $field) {
            if (!isset($game[$field]) || (is_scalar($game[$field]) && trim((string)$game[$field]) === '')) {
                $response["message"] = "インデックス {$index} の {$field} が不正です";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                flock($fp_lock, LOCK_UN);
                fclose($fp_lock);
                exit;
            }
        }

        // Reject non-numeric ids: string ids must fully match /^\d+$/ before
        // being cast to int (prevents e.g. "abc12" being silently coerced).
        if (is_string($game['id']) && !preg_match('/^\d+$/', $game['id'])) {
            $response["message"] = "インデックス {$index} の id が不正です（数字のみ許可）";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            flock($fp_lock, LOCK_UN);
            fclose($fp_lock);
            exit;
        }
        $game_id = is_int($game['id']) ? $game['id'] : intval($game['id']);

        // Duplicate id detection: reject the entire payload if an id appears twice
        if (isset($seen_ids[$game_id])) {
            $response["message"] = "重複するゲームIDが検出されました（ID: {$game_id}）。保存を拒否しました。";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            flock($fp_lock, LOCK_UN);
            fclose($fp_lock);
            exit;
        }
        $seen_ids[$game_id] = true;

        // URL sanity check: reject non-URL schemes such as javascript:/data:/vbscript:/file:
        // (FILTER_VALIDATE_URL allows "javascript:alert(1)" which has no "://")
        $raw_url = is_string($game['url']) ? trim($game['url']) : '';
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $raw_url)) {
            $scheme = strtolower((string)preg_replace('/^([a-z][a-z0-9+.-]*):.*$/i', '$1', $raw_url));
            if (in_array($scheme, ['javascript', 'data', 'vbscript', 'file'], true)) {
                $response["message"] = "インデックス {$index} の url が許可されていないスキームです";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                flock($fp_lock, LOCK_UN);
                fclose($fp_lock);
                exit;
            }
        }

        // Image sanity check: same scheme rejection as url (javascript:/data:/vbscript:/file:)
        $raw_image = isset($game['image']) ? trim((string)$game['image']) : '';
        if ($raw_image !== '' && preg_match('/^[a-z][a-z0-9+.-]*:/i', $raw_image)) {
            $img_scheme = strtolower((string)preg_replace('/^([a-z][a-z0-9+.-]*):.*$/i', '$1', $raw_image));
            if (in_array($img_scheme, ['javascript', 'data', 'vbscript', 'file'], true)) {
                $response["message"] = "インデックス {$index} の image が許可されていないスキームです";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                flock($fp_lock, LOCK_UN);
                fclose($fp_lock);
                exit;
            }
        }

        // 既存のサーバー側データを取得
        $server_game = null;
        foreach ($existing_games as $ex_g) {
            if (isset($ex_g['id']) && $ex_g['id'] === $game_id) {
                $server_game = $ex_g;
                break;
            }
        }

        // タイムスタンプの比較（ミリ秒）
        $client_updated_at = isset($game['updatedAt']) ? intval($game['updatedAt']) : 0;
        $server_updated_at = $server_game && isset($server_game['updatedAt']) ? intval($server_game['updatedAt']) : 0;

        // クライアントが古い場合はサーバーのメタデータを優先する。
        // ただし client_updated_at が 0（未送信）の新しいゲームは
        // 古いデータとみなさず、クライアントの値を使う。
        $is_outdated = ($server_game && $client_updated_at > 0 && $client_updated_at < $server_updated_at);

        // アクティブユーザーのマージ処理
        $new_active = isset($game['activeUsers']) && is_array($game['activeUsers']) ? $game['activeUsers'] : [];
        $merged_active = $new_active;

        if ($server_game && isset($server_game['activeUsers']) && is_array($server_game['activeUsers'])) {
            foreach ($server_game['activeUsers'] as $uid => $ts) {
                if (!isset($merged_active[$uid]) || $ts > $merged_active[$uid]) {
                    $merged_active[$uid] = $ts;
                }
            }
        }

        // ついでにサーバー側でも1時間以上前の古いデータを掃除
        $merged_active = array_filter($merged_active, function($ts) use ($one_hour_ago_ms) {
            return $ts > $one_hour_ago_ms;
        });

        // lastPlayedは常に新しい方を採用
        $client_last_played = isset($game['lastPlayed']) ? intval($game['lastPlayed']) : 0;
        $server_last_played = $server_game && isset($server_game['lastPlayed']) ? intval($server_game['lastPlayed']) : 0;
        $merged_last_played = max($client_last_played, $server_last_played);

        // playCountの採用（新しい方を採用）
        $client_play_count = isset($game['playCount']) ? intval($game['playCount']) : 0;
        $server_play_count = $server_game && isset($server_game['playCount']) ? intval($server_game['playCount']) : 0;
        $merged_play_count = max($client_play_count, $server_play_count);

        // M7: read every server-side field with ?? defaults so a missing
        // key never throws a PHP warning/notice on this path.
        $s_name = $server_game['name'] ?? '';
        $s_url = $server_game['url'] ?? '';
        $s_image = $server_game['image'] ?? '';
        $s_category = $server_game['category'] ?? '';
        $s_players_min = $server_game['playersMin'] ?? 0;
        $s_players_max = $server_game['playersMax'] ?? 0;
        $s_created_at = $server_game['createdAt'] ?? (time() * 1000);
        $s_has_update = $server_game['hasUpdate'] ?? false;
        $s_remote_updated_at = $server_game['remoteUpdatedAt'] ?? 0;
        $s_last_hash = $server_game['lastHash'] ?? '';
        $s_is_pinned = $server_game['isPinned'] ?? false;
        $s_creator = $server_game['creator'] ?? '';

        // クライアントが古い（$is_outdated）場合はユーザー編集可能フィールドを
        // すべてサーバー側の値で置き換える。マージするのは lastPlayed /
        // playCount / activeUsers のみ。
        if ($is_outdated) {
            $out_name = mb_substr((string)$s_name, 0, 100, 'UTF-8');
            $out_url = (filter_var((string)$s_url, FILTER_VALIDATE_URL) ?: '');
            $out_image = (string)$s_image;
            $out_category = mb_substr((string)$s_category, 0, 50, 'UTF-8');
            $out_players_min = max(0, intval($s_players_min));
            $out_players_max = max(0, intval($s_players_max));
            $out_created_at = intval($s_created_at);
            $out_has_update = filter_var($s_has_update, FILTER_VALIDATE_BOOLEAN);
            $out_remote_updated_at = intval($s_remote_updated_at);
            $out_last_hash = (string)$s_last_hash;
            $out_is_pinned = filter_var($s_is_pinned, FILTER_VALIDATE_BOOLEAN);
            $out_creator = mb_substr((string)$s_creator, 0, 10, 'UTF-8');
        } else {
            $out_name = mb_substr(trim((string)($game['name'] ?? '')), 0, 100, 'UTF-8');
            $out_url = (filter_var(trim((string)($game['url'] ?? '')), FILTER_VALIDATE_URL) ?: '');
            $out_image = (isset($game['image']) ? trim((string)$game['image']) : '');
            $out_category = (isset($game['category']) ? mb_substr(trim((string)$game['category']), 0, 50, 'UTF-8') : '');
            $out_players_min = (isset($game['playersMin']) ? max(0, intval($game['playersMin'])) : 0);
            $out_players_max = (isset($game['playersMax']) ? max(0, intval($game['playersMax'])) : 0);
            $out_created_at = (isset($game['createdAt']) ? intval($game['createdAt']) : (time() * 1000));
            $out_has_update = (isset($game['hasUpdate']) ? filter_var($game['hasUpdate'], FILTER_VALIDATE_BOOLEAN) : false);
            $out_remote_updated_at = (isset($game['remoteUpdatedAt']) ? intval($game['remoteUpdatedAt']) : 0);
            $out_last_hash = (isset($game['lastHash']) ? trim((string)$game['lastHash']) : '');
            $out_is_pinned = (isset($game['isPinned']) ? filter_var($game['isPinned'], FILTER_VALIDATE_BOOLEAN) : false);
            $out_creator = (isset($game['creator']) ? mb_substr(trim((string)$game['creator']), 0, 10, 'UTF-8') : '');
        }

        $validated[] = [
            'id' => $game_id,
            'name' => $out_name,
            'url' => $out_url,
            'image' => $out_image,
            'category' => $out_category,
            'playersMin' => $out_players_min,
            'playersMax' => $out_players_max,
            'createdAt' => $out_created_at,
            'updatedAt' => ($client_updated_at > 0 || $server_updated_at > 0) ? max($client_updated_at, $server_updated_at) : (time() * 1000),
            'lastPlayed' => $merged_last_played,
            'hasUpdate' => $out_has_update,
            'remoteUpdatedAt' => $out_remote_updated_at,
            'lastHash' => $out_last_hash,
            'isPinned' => $out_is_pinned,
            'playCount' => $merged_play_count,
            'activeUsers' => $merged_active,
            'creator' => $out_creator
        ];
    }

    $backup_success = false;
    $backup_name = null;

    // Write data (main operation)
    $json_output = json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json_output === false) {
        $response["message"] = "JSONエンコードに失敗しました";
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        flock($fp_lock, LOCK_UN);
        fclose($fp_lock);
        exit;
    }

    // Atomic write: temp file + rename
    $tmp_path = $file_path . '.' . getmypid() . '.tmp';
    $result = @file_put_contents($tmp_path, $json_output, LOCK_EX);
    if ($result !== false) {
        if (!@rename($tmp_path, $file_path)) {
            @unlink($tmp_path);
            $result = false;
        }
    }
    if ($result !== false) {
        @chmod($file_path, 0666);

        $response["status"] = "success";
        $response["message"] = "保存成功！";
        $response["count"] = count($validated);
        $response["backup"] = $backup_success ? ($backup_name ? basename($backup_name) : 'created') : 'skipped';
        http_response_code(200);
    } else {
        $response["message"] = "games.json への書き込みに失敗しました。パーミッションを確認してください（chmod 666 games.json）";
        http_response_code(500);
    }

    flock($fp_lock, LOCK_UN);
} else {
    $response["message"] = "サーバーが混雑しています。もう一度お試しください。";
    http_response_code(503);
}

if ($fp_lock) {
    fclose($fp_lock);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
