<?php
/**
 * Tool Launcher Pro - Game Data Save Endpoint (Fixed)
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

$lock_file = __DIR__ . '/games.lock';
$fp_lock = fopen($lock_file, 'w');

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
    foreach ($decoded as $index => $game) {
        if (!is_array($game)) {
            $response["message"] = "インデックス {$index} のデータが不正です";
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            flock($fp_lock, LOCK_UN);
            fclose($fp_lock);
            exit;
        }

        foreach ($required_fields as $field) {
            if (!isset($game[$field]) || (is_string($game[$field]) && trim($game[$field]) === '')) {
                $response["message"] = "インデックス {$index} の {$field} が不正です";
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                flock($fp_lock, LOCK_UN);
                fclose($fp_lock);
                exit;
            }
        }

        $game_id = is_int($game['id']) ? $game['id'] : intval($game['id']);
        
        // 既存のサーバー側データを取得
        $server_game = isset($existing_active_map[$game_id]) ? null : null; // マップからはactiveUsersしか取れないので再検索
        foreach ($existing_games as $ex_g) {
            if ($ex_g['id'] == $game_id) {
                $server_game = $ex_g;
                break;
            }
        }

        // タイムスタンプの比較（ミリ秒）
        $client_updated_at = isset($game['updatedAt']) ? intval($game['updatedAt']) : 0;
        $server_updated_at = $server_game && isset($server_game['updatedAt']) ? intval($server_game['updatedAt']) : 0;
        
        // クライアントが古い場合はサーバーのメタデータを優先する
        $is_outdated = ($server_game && $client_updated_at < $server_updated_at);

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

        // playCountの採用（新しい方を採用するか、足し合わせるか？基本的にサーバー側の数値をベースにするのが安全だが、今回は新しい方を採用）
        $client_play_count = isset($game['playCount']) ? intval($game['playCount']) : 0;
        $server_play_count = $server_game && isset($server_game['playCount']) ? intval($server_game['playCount']) : 0;
        $merged_play_count = max($client_play_count, $server_play_count);

        $validated[] = [
            'id' => $game_id,
            'name' => $is_outdated ? $server_game['name'] : htmlspecialchars(mb_substr(trim($game['name']), 0, 100), ENT_QUOTES, 'UTF-8'),
            'url' => $is_outdated ? $server_game['url'] : (filter_var(trim($game['url']), FILTER_VALIDATE_URL) ?: ''),
            'image' => $is_outdated ? $server_game['image'] : (isset($game['image']) ? htmlspecialchars(trim($game['image']), ENT_QUOTES, 'UTF-8') : ''),    
            'category' => $is_outdated ? $server_game['category'] : (isset($game['category']) ? htmlspecialchars(mb_substr(trim($game['category']), 0, 50), ENT_QUOTES, 'UTF-8') : ''),
            'playersMin' => $is_outdated ? $server_game['playersMin'] : (isset($game['playersMin']) ? max(0, intval($game['playersMin'])) : 0),
            'playersMax' => $is_outdated ? $server_game['playersMax'] : (isset($game['playersMax']) ? max(0, intval($game['playersMax'])) : 0),
            'createdAt' => $is_outdated ? $server_game['createdAt'] : (isset($game['createdAt']) ? intval($game['createdAt']) : time() * 1000),
            'updatedAt' => ($client_updated_at > 0 || $server_updated_at > 0) ? max($client_updated_at, $server_updated_at) : (time() * 1000),
            'lastPlayed' => $merged_last_played,
            'hasUpdate' => $is_outdated ? $server_game['hasUpdate'] : (isset($game['hasUpdate']) ? filter_var($game['hasUpdate'], FILTER_VALIDATE_BOOLEAN) : false),
            'remoteUpdatedAt' => $is_outdated ? $server_game['remoteUpdatedAt'] : (isset($game['remoteUpdatedAt']) ? intval($game['remoteUpdatedAt']) : 0),
            'lastHash' => $is_outdated ? $server_game['lastHash'] : (isset($game['lastHash']) ? htmlspecialchars(trim($game['lastHash']), ENT_QUOTES, 'UTF-8') : ''),
            'isPinned' => $is_outdated ? $server_game['isPinned'] : (isset($game['isPinned']) ? filter_var($game['isPinned'], FILTER_VALIDATE_BOOLEAN) : false),
            'playCount' => $merged_play_count,
            'activeUsers' => $merged_active
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

    $result = @file_put_contents($file_path, $json_output);
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

