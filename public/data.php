<?php
declare(strict_types=1);

/** Read-only browser for collector JSON files and SQLite records. */
$dataDir = rtrim((string)(getenv('DATA_DIR') ?: dirname(__DIR__) . '/data'), '/\\');
$dbPath = $dataDir . '/collector.sqlite';
$error = null;
$tables = [];
$rows = [];
$columns = [];
$selectedTable = '';
$page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
$pageSize = 100;
$matchId = filter_var($_GET['match'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$tableParam = (string)($_GET['table'] ?? 'matches');

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function readJsonFile(string $path): array
{
    if (!is_file($path)) return [null, 'No file has been saved for this match yet.'];
    $content = file_get_contents($path);
    if ($content === false) return [null, 'Could not read this file.'];
    return [$content, null];
}

function gameClock(mixed $seconds): string
{
    if (!is_numeric($seconds)) return 'unknown';
    $seconds = (int)$seconds;
    $sign = $seconds < 0 ? '-' : '';
    $seconds = abs($seconds);
    return $sign . intdiv($seconds, 60) . ':' . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT);
}

if (is_file($dbPath)) {
    try {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
                     ->fetchAll(PDO::FETCH_COLUMN);
        if (in_array($tableParam, $tables, true)) $selectedTable = $tableParam;
        elseif ($tables) $selectedTable = (string)$tables[0];
        if ($selectedTable !== '') {
            $quoted = '"' . str_replace('"', '""', $selectedTable) . '"';
            $columns = $db->query('PRAGMA table_info(' . $quoted . ')')->fetchAll(PDO::FETCH_ASSOC);
            $totalRows = (int)$db->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
            $offset = ($page - 1) * $pageSize;
            $rows = $db->query('SELECT * FROM ' . $quoted . ' LIMIT ' . $pageSize . ' OFFSET ' . $offset)->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $error = 'Could not read the SQLite database. Check that PHP has PDO SQLite enabled and the data directory is readable.';
    }
} else {
    $totalRows = 0;
    $error = 'Collector database not found yet. Start the collector to populate this browser.';
}
$totalRows ??= 0;
$pages = max(1, (int)ceil($totalRows / $pageSize));
$liveContent = $finalContent = null;
$liveError = $finalError = null;
if ($matchId !== false && $matchId !== null) {
    [$liveContent, $liveError] = readJsonFile($dataDir . '/live/' . $matchId . '.jsonl');
    [$finalContent, $finalError] = readJsonFile($dataDir . '/final/' . $matchId . '.json');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dota Observer · Data browser</title>
<style>
:root{color-scheme:dark;--bg:#101214;--panel:#171a1d;--line:#292e32;--muted:#929ba2;--text:#edf0f2;--accent:#c43d35}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 80% -15%,#39211f 0,transparent 38%),var(--bg);color:var(--text);font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.shell{max-width:1250px;margin:auto;padding:30px 22px 55px}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:25px}.brand{display:flex;gap:12px;align-items:center}.mark{width:40px;height:40px;border-radius:12px;background:linear-gradient(145deg,#e8564b,#872a27);display:grid;place-items:center;font-weight:900;font-size:18px}.eyebrow{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#cf827b;font-weight:700}.title{font-size:21px;font-weight:700}.link{color:#f0b0a7;text-decoration:none;border:1px solid #55332f;border-radius:8px;padding:8px 11px}.link:hover{background:#281c1b}h1{font-size:29px;letter-spacing:-.04em;margin:0 0 4px}.intro{color:var(--muted);margin:0 0 22px}.panel{background:linear-gradient(145deg,#1a1d20,#151719);border:1px solid var(--line);border-radius:14px;overflow:hidden;margin:14px 0}.head{padding:15px 18px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:12px}.head strong{font-size:15px}.hint{color:var(--muted);font-size:12px}select,input{background:#101214;border:1px solid var(--line);border-radius:8px;padding:9px 11px;color:var(--text)}.tools{display:flex;align-items:center;gap:8px}.body{padding:17px 18px}pre{margin:0;white-space:pre;overflow:auto;max-height:70vh;background:#101214;border:1px solid #24282b;border-radius:9px;padding:16px;color:#d8e0e4;font:12px/1.55 ui-monospace,SFMono-Regular,Consolas,monospace}.json-entry{margin:0 0 14px}.json-entry:last-child{margin-bottom:0}.entry-label{font-size:11px;color:#cfaaa5;margin:0 0 7px}.alert{padding:14px 16px;border:1px solid #55482c;border-radius:12px;background:#241f15;color:#e5c985;margin-bottom:15px}.table-wrap{overflow:auto}table{border-collapse:collapse;width:100%;white-space:nowrap}th,td{text-align:left;padding:11px 14px;border-bottom:1px solid #25292c;vertical-align:top}th{font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:var(--muted)}td{font-size:12px;max-width:360px;overflow:hidden;text-overflow:ellipsis}.pager{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;color:var(--muted);font-size:12px}.pager a{color:#f0b0a7;text-decoration:none}.badge{font:11px ui-monospace,monospace;color:#c7d0d4;background:#22272a;padding:3px 7px;border-radius:5px}.empty{color:var(--muted);padding:15px 0}@media(max-width:600px){.shell{padding:20px 13px}.head{align-items:flex-start;flex-direction:column}.tools{width:100%}.tools select{flex:1;min-width:0}.top{align-items:flex-start;gap:12px}}
</style>
</head>
<body><main class="shell">
<header class="top"><div class="brand"><div class="mark">D</div><div><div class="eyebrow">Dota 2 · Research tools</div><div class="title">Dota Observer</div></div></div><a class="link" href="index.php">← Dashboard</a></header>
<h1>Data browser</h1><p class="intro">Inspect saved live snapshots, final match responses, and read-only SQLite tables.</p>
<?php if ($error): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>
<?php if ($matchId !== false && $matchId !== null): ?>
<section class="panel"><div class="head"><div><strong>Match <?= h($matchId) ?> · JSON files</strong><div class="hint">Live snapshots are JSON Lines: one timestamped snapshot per line.</div></div><div class="tools"><a class="link" href="match.php?id=<?= h($matchId) ?>">Research report →</a><a class="link" href="data.php?table=matches">Back to database</a></div></div><div class="body">
  <h2 style="font-size:14px">Live snapshots <span class="badge">live/<?= h($matchId) ?>.jsonl</span></h2>
  <?php if ($liveError): ?><div class="empty"><?= h($liveError) ?></div><?php else: $liveLines = preg_split('/\r?\n/', trim((string)$liveContent), -1, PREG_SPLIT_NO_EMPTY) ?: []; foreach ($liveLines as $i => $line): $decoded = json_decode($line, true); $pretty = is_array($decoded) ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $line; $capturedAt = is_array($decoded) && isset($decoded['t']) ? gmdate('Y-m-d H:i:s', (int)$decoded['t']) . ' UTC' : 'time unknown'; $inGameTime = is_array($decoded) ? gameClock($decoded['game']['game_time'] ?? null) : 'unknown'; ?>
    <div class="json-entry"><div class="entry-label">Snapshot <?= number_format($i + 1) ?> · Game time <?= h($inGameTime) ?> · Captured <?= h($capturedAt) ?></div><pre><?= h($pretty ?: $line) ?></pre></div>
  <?php endforeach; if (!$liveLines): ?><div class="empty">No snapshots in this file.</div><?php endif; endif; ?>
  <h2 style="font-size:14px;margin-top:25px">Final match response <span class="badge">final/<?= h($matchId) ?>.json</span></h2>
  <?php if ($finalError): ?><div class="empty"><?= h($finalError) ?></div><?php else: $decodedFinal = json_decode((string)$finalContent, true); $prettyFinal = is_array($decodedFinal) ? json_encode($decodedFinal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $finalContent; ?><pre><?= h($prettyFinal ?: $finalContent) ?></pre><?php endif; ?>
</div></section>
<?php else: ?>
<section class="panel"><div class="head"><div><strong>SQLite browser</strong><div class="hint">Read-only · <?= number_format($totalRows) ?> rows in this table</div></div>
  <?php if ($tables): ?><form class="tools" method="get"><select name="table" aria-label="SQLite table"><?php foreach ($tables as $table): ?><option value="<?= h($table) ?>" <?= $table === $selectedTable ? 'selected' : '' ?>><?= h($table) ?></option><?php endforeach; ?></select><button class="link" type="submit" style="cursor:pointer">Open table</button></form><?php endif; ?>
</div>
<?php if ($selectedTable): ?><div class="table-wrap"><table><thead><tr><?php foreach ($columns as $column): ?><th><?= h($column['name']) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><?php foreach ($columns as $column): $value = $row[$column['name']] ?? null; ?><td title="<?= h(is_scalar($value) || $value === null ? (string)$value : json_encode($value)) ?>"><?php if ($selectedTable === 'matches' && $column['name'] === 'match_id' && $value !== null): ?><a class="link" style="padding:3px 7px" href="data.php?match=<?= h($value) ?>"><?= h($value) ?> ↗</a><?php elseif ($value === null): ?><span class="hint">NULL</span><?php else: ?><?= h((string)$value) ?><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="<?= max(1, count($columns)) ?>" class="empty">This table has no rows.</td></tr><?php endif; ?></tbody></table></div>
<div class="pager"><span>Page <?= number_format($page) ?> of <?= number_format($pages) ?> · <?= number_format($pageSize) ?> rows per page</span><span><?php if ($page > 1): ?><a href="?table=<?= rawurlencode($selectedTable) ?>&amp;page=<?= $page - 1 ?>">← Previous</a><?php endif; ?><?php if ($page < $pages): ?> <a href="?table=<?= rawurlencode($selectedTable) ?>&amp;page=<?= $page + 1 ?>">Next →</a><?php endif; ?></span></div>
<?php else: ?><div class="body empty">No SQLite tables found.</div><?php endif; ?></section>
<?php endif; ?>
</main></body></html>
