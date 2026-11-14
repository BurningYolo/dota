<?php
declare(strict_types=1);

require __DIR__ . '/test_support.php';

function extractCsrf(string $html): string
{
    if (preg_match('/<input type="hidden" name="csrf" value="([^"]+)"/', $html, $matches) !== 1) {
        throw new RuntimeException('CSRF token was not rendered.');
    }
    return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$root = testCreateRoot();
$server = null;
try {
    $dataDir = $root . DIRECTORY_SEPARATOR . 'data';
    $now = time();
    $matches = [];
    for ($id = 101; $id <= 152; $id++) {
        $matches[] = [
            'match_id' => $id,
            'status' => $id === 101 ? 'parsed' : ($id === 102 ? 'live' : 'ended'),
            'avg_mmr' => 6000 + $id,
            'first_seen' => $now - 3600,
            'last_seen' => $now - ($id - 100),
        ];
    }
    $today = gmdate('Y-m-d');
    $yesterday = gmdate('Y-m-d', $now - 86400);
    $db = testCreateDatabase($dataDir, $matches, [
        'min_mmr' => '5000',
        'poll_seconds' => '30',
        'min_interval' => '1.1',
        'daily_budget' => '0',
    ], [$today => 7, $yesterday => 4]);

    $snapshots = [
        ['t' => $now - 60, 'game' => ['game_time' => 120, 'match_id' => 101, 'marker' => 'snapshot-one']],
        ['t' => $now, 'game' => ['game_time' => 600, 'match_id' => 101, 'marker' => 'snapshot-two']],
    ];
    file_put_contents($dataDir . '/live/101.jsonl', implode("\n", array_map(
        static fn(array $snapshot): string => json_encode($snapshot, JSON_THROW_ON_ERROR),
        $snapshots
    )) . "\n");

    $match = [
        'match_id' => 101,
        'duration' => 1200,
        'pre_game_duration' => 90,
        'radiant_win' => true,
        'radiant_team' => ['name' => 'Radiant Test'],
        'dire_team' => ['name' => 'Dire Test'],
        'radiant_gold_adv' => [0, 100, 200],
        'radiant_xp_adv' => [0, 50, 100],
        'players' => [
            [
                'player_slot' => 0, 'isRadiant' => true, 'hero_id' => 1, 'level' => 20,
                'kills' => 5, 'deaths' => 2, 'assists' => 3, 'gold_t' => [0, 100, 200],
                'xp_t' => [0, 50, 100], 'item_0' => 0, 'ability_upgrades_arr' => [],
            ],
            [
                'player_slot' => 128, 'isRadiant' => false, 'hero_id' => 2, 'level' => 19,
                'kills' => 2, 'deaths' => 5, 'assists' => 1, 'gold_t' => [0, 80, 140],
                'xp_t' => [0, 40, 90], 'item_0' => 0, 'ability_upgrades_arr' => [],
            ],
        ],
    ];
    file_put_contents($dataDir . '/final/101.json', json_encode($match, JSON_THROW_ON_ERROR));
    unset($db);

    $password = 'test-password-for-settings';
    [$server, $baseUrl] = testStartServer($root, [
        'DATA_DIR' => $dataDir,
        'SETTINGS_PASSWORD_HASH' => password_hash($password, PASSWORD_DEFAULT),
    ], dirname(__DIR__) . '/public');
    $cookieJar = $root . DIRECTORY_SEPARATOR . 'cookies.txt';

    [$status, $html] = testHttpRequest($baseUrl, 'index.php', $cookieJar);
    testAssert($status === 200, 'Dashboard should return HTTP 200.');
    testAssertContains('Match collector', $html, 'Dashboard heading should render.');
    testAssertContains('data-search="101 parsed"', $html, 'Dashboard should render match rows.');
    testAssertContains('52', $html, 'Dashboard should render the fixture match count.');
    testAssertContains('Page 1 of 3', $html, 'Dashboard should paginate its match rows.');
    testAssertContains(number_format(7), $html, 'Dashboard should render today’s API usage.');
    echo "PASS: dashboard summary and pagination\n";

    [, $html] = testHttpRequest($baseUrl, 'index.php?q=101', $cookieJar);
    testAssertContains('data-search="101 parsed"', $html, 'Dashboard search should include the matching ID.');
    testAssert(!str_contains($html, 'data-search="102 live"'), 'Dashboard search should exclude other IDs.');
    [, $html] = testHttpRequest($baseUrl, 'index.php?status=live', $cookieJar);
    testAssertContains('data-search="102 live"', $html, 'Dashboard status filter should include live matches.');
    testAssert(!str_contains($html, 'data-search="101 parsed"'), 'Dashboard status filter should exclude parsed matches.');
    echo "PASS: dashboard search and status filters\n";

    [$status, $html] = testHttpRequest($baseUrl, 'data.php?table=matches', $cookieJar);
    testAssert($status === 200, 'Data browser should return HTTP 200.');
    testAssertContains('SQLite browser', $html, 'Data browser should show the selected SQLite table.');
    testAssertContains('Page 1 of 2', $html, 'Data browser should paginate SQLite rows.');
    [, $html] = testHttpRequest($baseUrl, 'data.php?table=matches&q=101', $cookieJar);
    testAssertContains('href="data.php?match=101"', $html, 'Data browser match IDs should link to raw match files.');
    testAssert(!str_contains($html, 'href="data.php?match=102"'), 'Data browser filter should exclude unrelated matches.');
    [, $html] = testHttpRequest($baseUrl, 'data.php?match=101', $cookieJar);
    testAssertContains('snapshot-one', $html, 'Data browser should display live snapshot JSON.');
    testAssertContains('Radiant Test', $html, 'Data browser should display final match JSON.');
    [, $html] = testHttpRequest($baseUrl, 'data.php?match=9999', $cookieJar);
    testAssertContains('No file has been saved for this match yet.', $html, 'Data browser should explain missing match files.');
    echo "PASS: SQLite browser, filtering, pagination, and JSON files\n";

    [$status, $html] = testHttpRequest($baseUrl, 'match.php?id=101', $cookieJar);
    testAssert($status === 200, 'Match report should return HTTP 200.');
    testAssertContains('Radiant Test', $html, 'Match report should render the team names.');
    testAssertContains('Parsed replay data available', $html, 'Match report should recognize parsed time-series data.');
    testAssertContains('Radiant Player 1', $html, 'Match report should render player details.');
    [, $html] = testHttpRequest($baseUrl, 'match.php?id=9999', $cookieJar);
    testAssertContains('No saved final JSON exists for this match yet.', $html, 'Match report should handle missing match data.');
    [, $html] = testHttpRequest($baseUrl, 'match.php?id=invalid', $cookieJar);
    testAssertContains('Enter a valid match ID from the dashboard.', $html, 'Match report should reject invalid IDs.');
    echo "PASS: match report data and invalid-ID handling\n";

    [$status, $html] = testHttpRequest($baseUrl, 'settings.php', $cookieJar);
    testAssert($status === 200, 'Settings page should return HTTP 200.');
    testAssertContains('Sign in', $html, 'Settings page should require authentication.');
    $csrf = extractCsrf($html);
    [, $html] = testHttpRequest($baseUrl, 'settings.php', $cookieJar, 'POST', [
        'csrf' => 'invalid-token', 'action' => 'login', 'password' => $password,
    ]);
    testAssertContains('Your form session expired.', $html, 'Settings page should reject invalid CSRF tokens.');
    [, $html] = testHttpRequest($baseUrl, 'settings.php', $cookieJar, 'POST', [
        'csrf' => $csrf, 'action' => 'login', 'password' => 'wrong-password',
    ]);
    testAssertContains('Password did not match.', $html, 'Settings page should reject an incorrect password.');
    [, $html] = testHttpRequest($baseUrl, 'settings.php', $cookieJar, 'POST', [
        'csrf' => $csrf, 'action' => 'login', 'password' => $password,
    ]);
    testAssertContains('Live collector controls', $html, 'Correct settings password should unlock controls.');

    [, $html] = testHttpRequest($baseUrl, 'settings.php', $cookieJar);
    $csrf = extractCsrf($html);
    $invalidValues = [
        'csrf' => $csrf, 'action' => 'save', 'min_mmr' => '16000',
        'poll_seconds' => '45', 'min_interval' => '0.5', 'daily_budget' => '250',
    ];
    [, $html] = testHttpRequest($baseUrl, 'settings.php', $cookieJar, 'POST', $invalidValues);
    testAssertContains('Enter values within the ranges shown below.', $html, 'Settings should reject values outside their allowed ranges.');

    $validValues = [
        'csrf' => $csrf, 'action' => 'save', 'min_mmr' => '7000',
        'poll_seconds' => '45', 'min_interval' => '0.5', 'daily_budget' => '250',
    ];
    [, $html] = testHttpRequest($baseUrl, 'settings.php', $cookieJar, 'POST', $validValues);
    testAssertContains('Settings saved.', $html, 'Valid settings should be saved.');
    $db = new PDO('sqlite:' . $dataDir . '/collector.sqlite');
    $savedSettings = $db->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    testAssert($savedSettings['min_mmr'] === '7000', 'Minimum MMR should persist in SQLite.');
    testAssert($savedSettings['poll_seconds'] === '45', 'Polling interval should persist in SQLite.');
    testAssert($savedSettings['min_interval'] === '0.5', 'API interval should persist in SQLite.');
    testAssert($savedSettings['daily_budget'] === '250', 'Daily budget should persist in SQLite.');
    unset($db);

    $csrf = extractCsrf($html);
    [, $html] = testHttpRequest($baseUrl, 'settings.php', $cookieJar, 'POST', [
        'csrf' => $csrf, 'action' => 'logout',
    ]);
    testAssertContains('Signed out.', $html, 'Settings logout should end the authenticated session.');
    testAssertContains('Sign in', $html, 'Settings logout should return to the sign-in form.');
    echo "PASS: settings authentication, validation, persistence, and logout\n";

    $emptyServer = null;
    try {
        [$emptyServer, $emptyUrl] = testStartServer($root, [
            'DATA_DIR' => $root . DIRECTORY_SEPARATOR . 'empty-data',
            'SETTINGS_PASSWORD_HASH' => '',
        ], dirname(__DIR__) . '/public');
        $emptyCookieJar = $root . DIRECTORY_SEPARATOR . 'empty-cookies.txt';
        [, $html] = testHttpRequest($emptyUrl, 'index.php', $emptyCookieJar);
        testAssertContains('Collector database not found yet.', $html, 'Dashboard should explain that collector data is not ready.');
        [, $html] = testHttpRequest($emptyUrl, 'data.php', $emptyCookieJar);
        testAssertContains('Collector database not found yet.', $html, 'Data browser should explain that its database is missing.');
        [, $html] = testHttpRequest($emptyUrl, 'settings.php', $emptyCookieJar);
        $csrf = extractCsrf($html);
        [, $html] = testHttpRequest($emptyUrl, 'settings.php', $emptyCookieJar, 'POST', [
            'csrf' => $csrf, 'action' => 'login', 'password' => 'any-password',
        ]);
        testAssertContains('Settings password is not configured on the server.', $html, 'Settings should explain when no password is configured.');
        echo "PASS: web pages explain missing database and settings configuration\n";
    } finally {
        if ($emptyServer !== null) {
            testStopProcess($emptyServer);
        }
    }
} finally {
    if ($server !== null) {
        testStopProcess($server);
    }
    testRemoveRoot($root);
}
