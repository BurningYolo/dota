<?php
declare(strict_types=1);

require __DIR__ . '/test_support.php';

function createLegacyCollectorRow(string $dataDir, int $matchId, string $status, int $attempts = 0): void
{
    testMakeDataDirectories($dataDir);
    $db = new PDO('sqlite:' . $dataDir . '/collector.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE matches (
        match_id INTEGER PRIMARY KEY,
        status TEXT NOT NULL,
        avg_mmr INTEGER,
        first_seen INTEGER,
        last_seen INTEGER,
        misses INTEGER NOT NULL DEFAULT 0,
        attempts INTEGER NOT NULL DEFAULT 0,
        next_at INTEGER NOT NULL DEFAULT 0
    )');
    $insert = $db->prepare('INSERT INTO matches
        (match_id, status, avg_mmr, first_seen, last_seen, misses, attempts, next_at)
        VALUES (?, ?, 7000, 0, 0, 0, ?, 0)');
    $insert->execute([$matchId, $status, $attempts]);
}

function startCollector(string $root, string $dataDir, string $apiUrl, array $extraEnvironment = []): array
{
    $logPath = $root . DIRECTORY_SEPARATOR . 'collector-' . bin2hex(random_bytes(3)) . '.log';
    $environment = array_merge([
        'DATA_DIR' => $dataDir,
        'OPENDOTA_BASE_URL' => $apiUrl,
        'OPENDOTA_API_KEY' => '',
        'MIN_MMR' => '6000',
        'POLL_SECONDS' => '10',
        'MIN_INTERVAL' => '0.02',
        'DAILY_BUDGET' => '20',
        'GAME_MODES' => '22',
        'LOBBY_TYPES' => '7',
        'INCLUDE_LEAGUE_GAMES' => '0',
        'STALE_SECONDS' => '600',
    ], $extraEnvironment);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $logPath, 'a'],
        2 => ['file', $logPath, 'a'],
    ];
    $process = proc_open([PHP_BINARY, __DIR__ . '/../script.php'], $descriptors, $pipes, dirname(__DIR__), testEnvironment($environment));
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the collector process.');
    }
    fclose($pipes[0]);
    return [$process, $logPath];
}

function waitForMatchState(
    $process,
    string $dataDir,
    int $matchId,
    string $expectedStatus,
    ?int $expectedAttempts = null,
    ?string $collectorLog = null,
    int $timeoutSeconds = 8
): array
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $dbPath = $dataDir . '/collector.sqlite';
        if (is_file($dbPath)) {
            $db = new PDO('sqlite:' . $dbPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->exec('PRAGMA busy_timeout = 5000');
            $columns = $db->query('PRAGMA table_info(matches)')->fetchAll(PDO::FETCH_COLUMN, 1);
            if (in_array('changed_at', $columns, true)) {
                $statement = $db->prepare('SELECT status, attempts, next_at FROM matches WHERE match_id = ?');
                $statement->execute([$matchId]);
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                unset($statement);
                if ($row && $row['status'] === $expectedStatus
                    && ($expectedAttempts === null || (int)$row['attempts'] === $expectedAttempts)) {
                    unset($db);
                    return $row;
                }
            }
            unset($db);
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            $log = $collectorLog !== null ? (string)@file_get_contents($collectorLog) : '';
            throw new RuntimeException("Collector exited before match $matchId reached $expectedStatus. $log");
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("Collector did not move match $matchId to $expectedStatus.");
}

function startMockApi(string $root, string $scenario): array
{
    $logPath = $root . DIRECTORY_SEPARATOR . "api-$scenario.log";
    [$process, $url] = testStartServer($root, [
        'MOCK_API_SCENARIO' => $scenario,
        'MOCK_API_LOG' => $logPath,
    ], null, __DIR__ . '/mock_opendota.php');
    return [$process, $url, $logPath];
}

$root = testCreateRoot();
$mockServer = null;
$collector = null;
try {
    $liveData = $root . DIRECTORY_SEPARATOR . 'collector-live';
    $db = testCreateDatabase($liveData, [[
        'match_id' => 9107,
        'status' => 'live',
        'avg_mmr' => 7100,
        'misses' => 2,
    ]], [
        'min_mmr' => '6000',
        'poll_seconds' => '10',
        'min_interval' => '0.02',
        'daily_budget' => '20',
    ]);
    unset($db);
    [$mockServer, $mockUrl, $apiLog] = startMockApi($root, 'live');
    [$collector, $collectorLog] = startCollector($root, $liveData, $mockUrl, ['MIN_MMR' => '6000']);
    waitForMatchState($collector, $liveData, 9101, 'live', null, $collectorLog);
    $db = new PDO('sqlite:' . $liveData . '/collector.sqlite');
    $db->prepare("UPDATE settings SET value = '5000' WHERE name = 'min_mmr'")->execute();
    unset($db);
    waitForMatchState($collector, $liveData, 9102, 'live', null, $collectorLog, 20);
    waitForMatchState($collector, $liveData, 9108, 'live', null, $collectorLog, 20);
    waitForMatchState($collector, $liveData, 9109, 'live', null, $collectorLog, 20);
    $stale = waitForMatchState($collector, $liveData, 9106, 'ended', 1, $collectorLog);
    waitForMatchState($collector, $liveData, 9107, 'ended', null, $collectorLog);
    testStopProcess($collector);
    $collector = null;
    testStopProcess($mockServer);
    $mockServer = null;

    $db = new PDO('sqlite:' . $liveData . '/collector.sqlite');
    $rows = $db->query('SELECT match_id, status FROM matches ORDER BY match_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    testAssert($rows === [9101 => 'live', 9102 => 'live', 9106 => 'ended', 9107 => 'ended', 9108 => 'live', 9109 => 'live'], 'A saved 5000 MMR threshold should accept matches from 5000 up even when the environment default is 6000, while applying other live-feed filters.');
    testAssertContains('Minimum MMR updated to 5000', (string)file_get_contents($collectorLog), 'Collector should log when the minimum MMR changes while it is running.');
    testAssert((int)$stale['next_at'] > time(), 'Stale matches should be queued for a delayed final fetch.');
    $missing = $db->query('SELECT misses, next_at FROM matches WHERE match_id = 9107')->fetch(PDO::FETCH_ASSOC);
    testAssert((int)$missing['misses'] === 3 && (int)$missing['next_at'] > time(), 'Matches missing for three polls should transition to ended with a final-fetch delay.');
    $snapshotPath = $liveData . '/live/9101.jsonl';
    testAssert(is_file($snapshotPath), 'Collector should save a new live snapshot.');
    testAssert(is_file($liveData . '/live/9109.jsonl'), 'Collector should save a snapshot for a match exactly at the minimum MMR.');
    $snapshot = json_decode(explode(PHP_EOL, trim((string)file_get_contents($snapshotPath)))[0], true);
    testAssert(($snapshot['game']['match_id'] ?? null) === 9101, 'Live snapshot should contain the accepted match.');
    testAssert(!is_file($liveData . '/live/9106.jsonl'), 'Stale matches should not receive a live snapshot.');
    testAssertContains('GET /matches/9106', (string)file_get_contents($apiLog), 'Stale match should be queued for final fetch.');
    unset($db);
    echo "PASS: collector filters live matches, snapshots accepted games, and queues stale games\n";

    $fetchData = $root . DIRECTORY_SEPARATOR . 'collector-fetch';
    createLegacyCollectorRow($fetchData, 9201, 'ended');
    [$mockServer, $mockUrl, $apiLog] = startMockApi($root, 'fetch');
    [$collector, $collectorLog] = startCollector($root, $fetchData, $mockUrl);
    waitForMatchState($collector, $fetchData, 9201, 'fetched', null, $collectorLog);
    testStopProcess($collector);
    $collector = null;
    testStopProcess($mockServer);
    $mockServer = null;

    $final = json_decode((string)file_get_contents($fetchData . '/final/9201.json'), true);
    testAssert(is_array($final) && count($final['players'] ?? []) === 2, 'Collector should save the fetched final match response.');
    testAssertContains('POST /request/9201', (string)file_get_contents($apiLog), 'Fetched matches should request a replay parse.');
    $db = new PDO('sqlite:' . $fetchData . '/collector.sqlite');
    $columns = $db->query('PRAGMA table_info(matches)')->fetchAll(PDO::FETCH_COLUMN, 1);
    testAssert(in_array('last_update', $columns, true) && in_array('changed_at', $columns, true), 'Collector should migrate older match databases.');
    unset($db);
    echo "PASS: collector fetches ended matches, saves JSON, requests parsing, and migrates schemas\n";

    $parsedData = $root . DIRECTORY_SEPARATOR . 'collector-parsed';
    createLegacyCollectorRow($parsedData, 9301, 'fetched');
    [$mockServer, $mockUrl] = startMockApi($root, 'parsed');
    [$collector, $collectorLog] = startCollector($root, $parsedData, $mockUrl);
    waitForMatchState($collector, $parsedData, 9301, 'parsed', null, $collectorLog);
    testStopProcess($collector);
    $collector = null;
    testStopProcess($mockServer);
    $mockServer = null;
    $parsed = json_decode((string)file_get_contents($parsedData . '/final/9301.json'), true);
    testAssert(isset($parsed['players'][0]['gold_t']), 'Collector should replace raw match data with parsed replay data.');
    echo "PASS: collector stores parsed replay data\n";

    $retryData = $root . DIRECTORY_SEPARATOR . 'collector-retry';
    createLegacyCollectorRow($retryData, 9401, 'ended', 7);
    $db = new PDO('sqlite:' . $retryData . '/collector.sqlite');
    $db->exec("INSERT INTO matches (match_id, status, avg_mmr, attempts, next_at) VALUES (9402, 'ended', 7000, 0, 0)");
    $db->exec("INSERT INTO matches (match_id, status, avg_mmr, attempts, next_at) VALUES (9403, 'fetched', 7000, 5, 0)");
    file_put_contents($retryData . '/final/9403.json', '{"players":[{"hero_id":1}]}');
    unset($db);
    [$mockServer, $mockUrl] = startMockApi($root, 'retry');
    [$collector, $collectorLog] = startCollector($root, $retryData, $mockUrl);
    $failedFetch = waitForMatchState($collector, $retryData, 9401, 'failed', 8, $collectorLog);
    $retry = waitForMatchState($collector, $retryData, 9402, 'ended', 1, $collectorLog);
    waitForMatchState($collector, $retryData, 9403, 'unparsed', 6, $collectorLog);
    testStopProcess($collector);
    $collector = null;
    testStopProcess($mockServer);
    $mockServer = null;
    testAssert((int)$retry['next_at'] > time(), 'Failed final fetch should be scheduled for a retry.');
    testAssert((int)$failedFetch['attempts'] === 8, 'Collector should stop retrying after its maximum final-fetch attempts.');
    testAssert(is_file($retryData . '/final/9403.json'), 'Collector should retain raw final JSON when parsing never becomes available.');
    echo "PASS: collector backs off, gives up at retry limits, and preserves unparsed JSON\n";

    $budgetData = $root . DIRECTORY_SEPARATOR . 'collector-budget';
    $today = gmdate('Y-m-d');
    $db = testCreateDatabase($budgetData, [], ['daily_budget' => '1'], [$today => 1]);
    unset($db);
    [$mockServer, $mockUrl, $apiLog] = startMockApi($root, 'empty');
    [$collector, $collectorLog] = startCollector($root, $budgetData, $mockUrl, ['DAILY_BUDGET' => '20']);
    usleep(500000);
    testStopProcess($collector);
    $collector = null;
    testStopProcess($mockServer);
    $mockServer = null;
    testAssert(!is_file($apiLog) || filesize($apiLog) === 0, 'Exhausted daily API budget should prevent network requests.');
    echo "PASS: collector enforces the stored daily API budget\n";
} finally {
    if ($collector !== null) {
        testStopProcess($collector);
    }
    if ($mockServer !== null) {
        testStopProcess($mockServer);
    }
    testRemoveRoot($root);
}
