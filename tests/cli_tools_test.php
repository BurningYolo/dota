<?php
declare(strict_types=1);

require __DIR__ . '/test_support.php';

$root = testCreateRoot();
try {
    $dataDir = $root . DIRECTORY_SEPARATOR . 'cli-data';
    $now = time();
    $matches = [
        ['match_id' => 501, 'status' => 'parsed', 'avg_mmr' => 7000, 'last_seen' => $now],
        ['match_id' => 502, 'status' => 'live', 'avg_mmr' => 6500, 'last_seen' => $now - 1],
        ['match_id' => 503, 'status' => 'failed', 'avg_mmr' => 6000, 'last_seen' => $now - 2],
        ['match_id' => 504, 'status' => 'unparsed', 'avg_mmr' => 5500, 'last_seen' => $now - 3],
        ['match_id' => 505, 'status' => 'failed', 'avg_mmr' => 5000, 'last_seen' => $now - 4],
    ];
    $today = gmdate('Y-m-d');
    $yesterday = gmdate('Y-m-d', $now - 86400);
    $db = testCreateDatabase($dataDir, $matches, [], [$today => 9, $yesterday => 4]);
    unset($db);

    $validSnapshot = json_encode([
        't' => $now,
        'game' => ['game_time' => 300, 'match_id' => 501],
    ], JSON_THROW_ON_ERROR);
    file_put_contents($dataDir . '/live/501.jsonl', $validSnapshot . "\n");
    file_put_contents($dataDir . '/live/502.jsonl', $validSnapshot . "\n");
    file_put_contents($dataDir . '/live/504.jsonl', "{\"invalid\":true}\n");
    file_put_contents($dataDir . '/final/501.json', json_encode([
        'duration' => 1200,
        'pre_game_duration' => 90,
        'players' => [['hero_id' => 1, 'gold_t' => [0, 100]],
            ['hero_id' => 2, 'gold_t' => [0, 80]]],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($dataDir . '/final/505.json', '{"players":[]}');

    $repo = dirname(__DIR__);
    [$exitCode, $statusOutput, $statusError] = testRunPhp(
        [$repo . '/status.php'],
        $repo,
        ['DATA_DIR' => $dataDir]
    );
    testAssert($exitCode === 0, 'Status command should succeed for a valid collector database. ' . $statusError);
    testAssertContains('== Matches by status ==', $statusOutput, 'Status command should summarize match stages.');
    testAssertContains('parsed', $statusOutput, 'Status command should list parsed matches.');
    testAssertContains("$today  9", $statusOutput, 'Status command should list API usage by day.');
    testAssertContains('live:  3 files', $statusOutput, 'Status command should report live files.');
    testAssertContains('final: 2 files', $statusOutput, 'Status command should report final files.');
    testAssertContains('snapshots=1', $statusOutput, 'Status command should report snapshot coverage.');
    testAssertContains('parsed=yes', $statusOutput, 'Status command should identify parsed replay data.');
    echo "PASS: status command reports match stages, API usage, storage, and coverage\n";

    [$exitCode, $dryRunOutput] = testRunPhp(
        [$repo . '/cleanup.php'],
        $repo,
        ['DATA_DIR' => $dataDir]
    );
    testAssert($exitCode === 0, 'Cleanup dry run should succeed.');
    testAssertContains('Dry run: would delete 3 match record(s)', $dryRunOutput, 'Cleanup should identify unusable records.');
    testAssertContains('504', $dryRunOutput, 'Malformed live snapshots should be cleanup candidates.');
    testAssertContains('505', $dryRunOutput, 'Final JSON without players should be a cleanup candidate.');
    testAssertContains('Nothing was changed.', $dryRunOutput, 'Cleanup dry run should not delete data.');
    $db = new PDO('sqlite:' . $dataDir . '/collector.sqlite');
    testAssert((int)$db->query('SELECT COUNT(*) FROM matches')->fetchColumn() === 5, 'Cleanup dry run should preserve all database rows.');
    testAssert(is_file($dataDir . '/live/504.jsonl'), 'Cleanup dry run should preserve files.');
    echo "PASS: cleanup dry run preserves data and reports invalid records\n";

    [$exitCode, $applyOutput, $applyError] = testRunPhp(
        [$repo . '/cleanup.php', '--apply'],
        $repo,
        ['DATA_DIR' => $dataDir]
    );
    testAssert($exitCode === 0, 'Cleanup apply should succeed. ' . $applyError);
    testAssertContains('Removed 3 database record(s) and 2 associated file(s).', $applyOutput, 'Cleanup should remove only unusable rows and files.');
    $remainingIds = $db->query('SELECT match_id FROM matches ORDER BY match_id')->fetchAll(PDO::FETCH_COLUMN);
    testAssert($remainingIds === [501, 502], 'Cleanup should retain matches with valid snapshots or final players.');
    testAssert(is_file($dataDir . '/live/501.jsonl') && is_file($dataDir . '/final/501.json'), 'Cleanup should retain valid match data.');
    testAssert(!is_file($dataDir . '/live/504.jsonl') && !is_file($dataDir . '/final/505.json'), 'Cleanup should remove invalid per-match files.');
    unset($db);
    echo "PASS: cleanup apply removes unusable records while preserving valid data\n";

    $missingDataDir = $root . DIRECTORY_SEPARATOR . 'missing-data';
    [$exitCode, , $error] = testRunPhp(
        [$repo . '/status.php'],
        $repo,
        ['DATA_DIR' => $missingDataDir]
    );
    testAssert($exitCode !== 0 && str_contains($error, 'No database at'), 'Status command should report a missing database.');
    [$exitCode, , $error] = testRunPhp(
        [$repo . '/cleanup.php'],
        $repo,
        ['DATA_DIR' => $missingDataDir]
    );
    testAssert($exitCode !== 0 && str_contains($error, 'No collector database'), 'Cleanup should report a missing database.');
    unset($db);
    echo "PASS: CLI tools report missing databases clearly\n";
} finally {
    testRemoveRoot($root);
}
