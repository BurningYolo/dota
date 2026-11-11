<?php
declare(strict_types=1);

/**
 * Status report for dota_live_collector.php
 * Usage (run from the same folder as the collector): php status.php
 * Optional: set DATA_DIR if you changed it.
 */

$dataDir = rtrim((string)(getenv('DATA_DIR') ?: __DIR__ . '/data'), '/\\');
$dbPath  = $dataDir . '/collector.sqlite';
if (!is_file($dbPath)) {
    fwrite(STDERR, "No database at $dbPath. Has the collector run yet?\n");
    exit(1);
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== Matches by status ==\n";
foreach ($db->query('SELECT status, COUNT(*) AS n FROM matches GROUP BY status ORDER BY n DESC') as $r) {
    printf("  %-10s %d\n", $r['status'], $r['n']);
}

echo "\n== API calls per day ==\n";
foreach ($db->query('SELECT day, calls FROM usage ORDER BY day DESC LIMIT 7') as $r) {
    printf("  %s  %d\n", $r['day'], $r['calls']);
}

function dirSize(string $dir): int
{
    $bytes = 0;
    foreach (glob($dir . '/*') ?: [] as $f) {
        $bytes += is_file($f) ? (int)filesize($f) : 0;
    }
    return $bytes;
}
$liveFiles  = glob($dataDir . '/live/*.jsonl') ?: [];
$finalFiles = glob($dataDir . '/final/*.json') ?: [];
printf("\n== Disk ==\n  live:  %d files, %.1f MB\n  final: %d files, %.1f MB\n",
    count($liveFiles), dirSize($dataDir . '/live') / 1048576,
    count($finalFiles), dirSize($dataDir . '/final') / 1048576);

echo "\n== Snapshot coverage (last snapshot game_time vs match duration) ==\n";
$rows = $db->query("SELECT match_id FROM matches WHERE status IN ('parsed','fetched','unparsed')
                    ORDER BY last_seen DESC LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
if (!$rows) {
    echo "  No finished matches yet.\n";
}
foreach ($rows as $id) {
    $liveFile  = "$dataDir/live/$id.jsonl";
    $finalFile = "$dataDir/final/$id.json";
    if (!is_file($liveFile) || !is_file($finalFile)) {
        continue;
    }
    $lines = file($liveFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $first = json_decode($lines[0], true)['game']['game_time'] ?? null;
    $last  = json_decode(end($lines), true)['game']['game_time'] ?? null;
    $m     = json_decode((string)file_get_contents($finalFile), true);
    $dur   = $m['duration'] ?? null;
    $preGame = $m['pre_game_duration'] ?? 0;
    printf("  %s  snapshots=%d  first_t=%s  last_t=%s  duration=%s  parsed=%s\n",
        $id, count($lines), var_export($first, true), var_export($last, true), var_export($dur, true),
        isset($m['players'][0]['gold_t']) ? 'yes' : 'no');
}
echo "\nNote: if last_t is far below duration, matches are leaving /live before they end.\n";