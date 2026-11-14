<?php
declare(strict_types=1);

$path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$scenario = (string)(getenv('MOCK_API_SCENARIO') ?: 'empty');
$logPath = (string)(getenv('MOCK_API_LOG') ?: '');
if ($logPath !== '' && $path !== '/') {
    file_put_contents($logPath, "$method $path\n", FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json');
if ($path === '/') {
    echo '{}';
    return;
}

if ($path === '/live' && $scenario === 'live') {
    $now = time();
    echo json_encode([
        ['match_id' => 9101, 'average_mmr' => 7200, 'game_mode' => 22, 'lobby_type' => 7, 'league_id' => 0, 'last_update_time' => $now - 20, 'game_time' => 100],
        ['match_id' => 9102, 'average_mmr' => 6500, 'game_mode' => 22, 'lobby_type' => 7, 'league_id' => 0, 'last_update_time' => $now - 20, 'game_time' => 100],
        ['match_id' => 9103, 'average_mmr' => 7500, 'game_mode' => 2, 'lobby_type' => 7, 'league_id' => 0, 'last_update_time' => $now - 20, 'game_time' => 100],
        ['match_id' => 9104, 'average_mmr' => 7500, 'game_mode' => 22, 'lobby_type' => 9, 'league_id' => 0, 'last_update_time' => $now - 20, 'game_time' => 100],
        ['match_id' => 9105, 'average_mmr' => 7500, 'game_mode' => 22, 'lobby_type' => 7, 'league_id' => 42, 'last_update_time' => $now - 20, 'game_time' => 100],
        ['match_id' => 9106, 'average_mmr' => 7200, 'game_mode' => 22, 'lobby_type' => 7, 'league_id' => 0, 'last_update_time' => $now - 1200, 'game_time' => 100],
    ], JSON_THROW_ON_ERROR);
    return;
}

if (preg_match('~^/matches/(\d+)$~', $path, $matches) === 1) {
    $matchId = (int)$matches[1];
    if ($scenario === 'fetch' && $matchId === 9201) {
        echo json_encode([
            'match_id' => $matchId,
            'duration' => 1800,
            'players' => [['hero_id' => 1], ['hero_id' => 2]],
        ], JSON_THROW_ON_ERROR);
        return;
    }
    if ($scenario === 'parsed' && $matchId === 9301) {
        echo json_encode([
            'match_id' => $matchId,
            'duration' => 1800,
            'players' => [['hero_id' => 1, 'gold_t' => [0, 100], 'xp_t' => [0, 50]]],
        ], JSON_THROW_ON_ERROR);
        return;
    }
    if ($scenario === 'retry' && $matchId === 9401) {
        http_response_code(503);
        echo '{}';
        return;
    }
    http_response_code(404);
    echo '{}';
    return;
}

if ($method === 'POST' && preg_match('~^/request/\d+$~', $path) === 1) {
    echo '{"success":true}';
    return;
}

if ($path === '/live') {
    echo '[]';
    return;
}

http_response_code(404);
echo '{}';
