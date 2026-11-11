<?php
declare(strict_types=1);

/**
 * Find collector records that contain no usable match data.
 * Dry run: php cleanup.php
 * Delete candidates: php cleanup.php --apply
 * Optional data path: set DATA_DIR before running.
 *
 * A match is considered useful if it has at least one valid live JSONL
 * snapshot OR a final JSON object with player data. This conservative rule
 * keeps partially collected live matches that may still be useful research.
 */

$apply = in_array('--apply', $argv ?? [], true);
$dataDir = rtrim((string)(getenv('DATA_DIR') ?: __DIR__ . '/data'), '/\\');
$dbPath = $dataDir . '/collector.sqlite';

function fileHasLiveSnapshot(string $path): bool
{
    if (!is_file($path) || (int)filesize($path) === 0) return false;
    $handle = fopen($path, 'rb');
    if ($handle === false) return false;
    try {
        while (($line = fgets($handle)) !== false) {
            $snapshot = json_decode($line, true);
            if (is_array($snapshot) && isset($snapshot['game']) && is_array($snapshot['game'])) return true;
        }
    } finally {
        fclose($handle);
    }
    return false;
}

function fileHasFinalPlayers(string $path): bool
{
    if (!is_file($path) || (int)filesize($path) === 0) return false;
    $json = json_decode((string)file_get_contents($path), true);
    return is_array($json) && isset($json['players']) && is_array($json['players']) && count($json['players']) > 0;
}

if (!is_file($dbPath)) {
    fwrite(STDERR, "No collector database at $dbPath\n");
    exit(1);
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $matches = $db->query('SELECT match_id, status, avg_mmr FROM matches ORDER BY match_id')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fwrite(STDERR, "Could not read the collector database: " . $e->getMessage() . "\n");
    exit(1);
}

$candidates = [];
foreach ($matches as $match) {
    $id = (int)$match['match_id'];
    $hasLive = fileHasLiveSnapshot($dataDir . '/live/' . $id . '.jsonl');
    $hasFinal = fileHasFinalPlayers($dataDir . '/final/' . $id . '.json');
    if (!$hasLive && !$hasFinal) $candidates[] = $match;
}

if (!$candidates) {
    echo "No matches without usable data found.\n";
    exit(0);
}

echo ($apply ? 'Deleting' : 'Dry run: would delete') . ' ' . count($candidates) . " match record(s) with no usable live snapshots or final player data:\n";
foreach ($candidates as $match) {
    printf("  %s  status=%s  avg_mmr=%s\n", $match['match_id'], $match['status'], $match['avg_mmr'] ?? 'unknown');
}

if (!$apply) {
    echo "\nNothing was changed. Review the list, then run php cleanup.php --apply to delete these rows and their per-match files.\n";
    exit(0);
}

// Keep the DB changes atomic. File deletion is restricted to names derived
// from numeric match IDs and to the collector's live/final directories.
try {
    $db->beginTransaction();
    $delete = $db->prepare('DELETE FROM matches WHERE match_id = ?');
    foreach ($candidates as $match) $delete->execute([(int)$match['match_id']]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "Database deletion failed; no files were removed: " . $e->getMessage() . "\n");
    exit(1);
}

$removedFiles = 0;
foreach ($candidates as $match) {
    $id = (int)$match['match_id'];
    foreach ([$dataDir . '/live/' . $id . '.jsonl', $dataDir . '/final/' . $id . '.json'] as $path) {
        if (is_file($path) && unlink($path)) $removedFiles++;
    }
}
echo "Removed " . count($candidates) . " database record(s) and $removedFiles associated file(s).\n";
