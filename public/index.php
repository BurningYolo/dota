<?php
declare(strict_types=1);

/** Read-only web dashboard for the Dota live match collector. */
$dataDir = rtrim((string)(getenv('DATA_DIR') ?: dirname(__DIR__) . '/data'), '/\\');
$dbPath = $dataDir . '/collector.sqlite';
$error = null;
$summary = [];
$recent = [];
$usage = [];
$disk = ['live_files' => 0, 'live_bytes' => 0, 'final_files' => 0, 'final_bytes' => 0];

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function folderStats(string $path, string $pattern): array
{
    $files = glob($path . '/' . $pattern) ?: [];
    $bytes = 0;
    foreach ($files as $file) {
        if (is_file($file)) $bytes += (int)filesize($file);
    }
    return [count($files), $bytes];
}

function gameClock(mixed $seconds): string
{
    if (!is_numeric($seconds)) return '—';
    $seconds = (int)$seconds;
    $sign = $seconds < 0 ? '-' : '';
    $seconds = abs($seconds);
    return $sign . intdiv($seconds, 60) . ':' . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT);
}

function snapshotStats(string $path): array
{
    $stats = ['count' => 0, 'first_game' => null, 'last_game' => null];
    if (!is_file($path) || ($handle = fopen($path, 'rb')) === false) return $stats;
    try {
        while (($line = fgets($handle)) !== false) {
            $snapshot = json_decode($line, true);
            if (!is_array($snapshot) || !isset($snapshot['game']) || !is_array($snapshot['game'])) continue;
            $gameTime = $snapshot['game']['game_time'] ?? null;
            if ($stats['count'] === 0) $stats['first_game'] = $gameTime;
            $stats['last_game'] = $gameTime;
            $stats['count']++;
        }
    } finally {
        fclose($handle);
    }
    return $stats;
}

if (is_file($dbPath)) {
    try {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $summary = $db->query('SELECT status, COUNT(*) AS n FROM matches GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
        $recent = $db->query("SELECT match_id, status, avg_mmr, first_seen, last_seen, misses, attempts
                              FROM matches ORDER BY last_seen DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($recent as &$match) {
            $match['snapshots'] = snapshotStats($dataDir . '/live/' . (int)$match['match_id'] . '.jsonl');
        }
        unset($match);
        $usage = $db->query('SELECT day, calls FROM usage ORDER BY day DESC LIMIT 7')->fetchAll(PDO::FETCH_ASSOC);
        [$disk['live_files'], $disk['live_bytes']] = folderStats($dataDir . '/live', '*.jsonl');
        [$disk['final_files'], $disk['final_bytes']] = folderStats($dataDir . '/final', '*.json');
    } catch (Throwable $e) {
        $error = 'Could not read collector data. Check that PHP has PDO SQLite enabled and the data directory is readable.';
    }
} else {
    $error = 'Collector database not found yet. Start the collector to populate this dashboard.';
}

$total = array_sum(array_map('intval', $summary));
$live = (int)($summary['live'] ?? 0);
$today = gmdate('Y-m-d');
$callsToday = 0;
foreach ($usage as $day) if ($day['day'] === $today) $callsToday = (int)$day['calls'];
$statusColors = ['live' => 'green', 'ended' => 'amber', 'fetched' => 'blue', 'parsed' => 'violet', 'unparsed' => 'amber', 'failed' => 'red'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="60">
  <title>Dota Observer · Collector</title>
  <style>
    :root{color-scheme:dark;--bg:#101214;--panel:#171a1d;--line:#292e32;--muted:#929ba2;--text:#edf0f2;--accent:#c43d35;--green:#74d6a2;--amber:#e7bd70;--blue:#83b9ed;--violet:#bea4f2;--red:#ee8179}
    *{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 80% -15%,#39211f 0,transparent 38%),var(--bg);color:var(--text);font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}
    .shell{max-width:1180px;margin:auto;padding:34px 24px 60px}.top{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:30px}.brand{display:flex;gap:13px;align-items:center}.mark{width:42px;height:42px;border-radius:12px;background:linear-gradient(145deg,#e8564b,#872a27);display:grid;place-items:center;font-weight:900;font-size:18px;box-shadow:0 8px 28px #c43d3533}.eyebrow{font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:#cf827b;font-weight:750}.title{font-size:22px;font-weight:720;letter-spacing:-.03em;margin-top:1px}.refresh{color:var(--muted);font-size:12px}
    .hero{display:flex;justify-content:space-between;align-items:flex-end;margin:0 0 20px}.hero h1{font-size:30px;letter-spacing:-.045em;margin:0 0 4px}.hero p{margin:0;color:var(--muted)}.live-pill,.badge{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--line);background:#1b1f22;border-radius:99px;padding:6px 10px;color:#c8d0d4;font-size:12px}.dot{width:7px;height:7px;background:var(--green);border-radius:50%;box-shadow:0 0 10px #74d6a288}
    .cards{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-bottom:18px}.card,.panel{background:linear-gradient(145deg,#1a1d20,#151719);border:1px solid var(--line);border-radius:14px}.card{padding:17px 18px}.label{font-size:12px;color:var(--muted)}.value{font-size:27px;font-weight:720;letter-spacing:-.04em;margin-top:7px}.value small{font-size:12px;font-weight:500;color:var(--muted);letter-spacing:0}.sub{font-size:11px;color:var(--muted);margin-top:1px}
    .grid{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(260px,.8fr);gap:15px}.panel{overflow:hidden}.panel-head{padding:17px 19px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:15px}.panel-title{font-weight:650}.panel-hint{font-size:11px;color:var(--muted);margin-top:2px}.search{background:#101214;border:1px solid var(--line);border-radius:8px;color:var(--text);padding:8px 10px;outline:none;width:190px}.search:focus{border-color:#8d4d48}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;white-space:nowrap}th{text-align:left;font-size:10px;color:var(--muted);letter-spacing:.11em;text-transform:uppercase;font-weight:650;padding:12px 18px}td{padding:13px 18px;border-top:1px solid #24282b;font-size:13px}tbody tr:hover{background:#ffffff06}.id{font-weight:650;font-variant-numeric:tabular-nums}.mmr{color:#d6dce0;font-variant-numeric:tabular-nums}.badge{padding:4px 9px;font-size:11px;text-transform:capitalize}.badge.green{color:var(--green);border-color:#315543}.badge.amber{color:var(--amber);border-color:#57492c}.badge.blue{color:var(--blue);border-color:#304c64}.badge.violet{color:var(--violet);border-color:#4a3e65}.badge.red{color:var(--red);border-color:#633b39}.empty{text-align:center;color:var(--muted);padding:36px 15px}.side{display:flex;flex-direction:column;gap:15px}.inner{padding:16px 19px}.status-row,.usage-row,.metric-row{display:flex;align-items:center;justify-content:space-between;padding:8px 0}.status-row+.status-row,.usage-row+.usage-row,.metric-row+.metric-row{border-top:1px solid #25292c}.status-name{display:flex;align-items:center;gap:9px;color:#cdd2d5}.count{font-variant-numeric:tabular-nums;font-weight:650}.metric-row span:first-child,.usage-row span:first-child{color:var(--muted)}.metric-row strong,.usage-row strong{font-weight:630;font-variant-numeric:tabular-nums}.callbar{height:4px;background:#292e32;border-radius:9px;margin-top:12px;overflow:hidden}.callbar span{display:block;height:100%;background:linear-gradient(90deg,#c43d35,#e69a67);border-radius:9px}.foot{margin-top:17px;color:var(--muted);font-size:11px;text-align:right}.alert{padding:15px 17px;border:1px solid #55482c;border-radius:12px;background:#241f15;color:#e5c985;margin-bottom:17px}
    @media(max-width:820px){.grid{grid-template-columns:1fr}.cards{grid-template-columns:repeat(2,1fr)}}@media(max-width:520px){.shell{padding:22px 14px 40px}.hero{align-items:flex-start;gap:12px;flex-direction:column}.hero h1{font-size:25px}.cards{gap:9px}.card{padding:14px}.value{font-size:23px}.panel-head{align-items:flex-start;flex-direction:column}.search{width:100%}.top{margin-bottom:24px}}
  </style>
</head>
<body>
<main class="shell">
  <header class="top"><div class="brand"><div class="mark">D</div><div><div class="eyebrow">Dota 2 · Research tools</div><div class="title">Dota Observer</div></div></div><a href="data.php" style="color:#f0b0a7;text-decoration:none;border:1px solid #55332f;border-radius:8px;padding:8px 11px;font-size:12px">Browse raw data &amp; SQLite →</a></header>
  <section class="hero"><div><h1>Match collector</h1><p>Live high MMR tracking and parse status at a glance.</p></div><div class="live-pill"><span class="dot"></span> Collector data</div></section>
  <?php if ($error): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>
  <section class="cards">
    <div class="card"><div class="label">Tracked matches</div><div class="value"><?= number_format($total) ?></div><div class="sub">all recorded matches</div></div>
    <div class="card"><div class="label">Live now</div><div class="value" style="color:var(--green)"><?= number_format($live) ?></div><div class="sub">currently being monitored</div></div>
    <div class="card"><div class="label">API calls today</div><div class="value"><?= number_format($callsToday) ?></div><div class="sub">UTC · collector request count</div></div>
    <div class="card"><div class="label">Saved data</div><div class="value"><?= number_format(($disk['live_bytes'] + $disk['final_bytes']) / 1048576, 1) ?><small> MB</small></div><div class="sub"><?= number_format($disk['live_files'] + $disk['final_files']) ?> match files</div></div>
  </section>
  <div class="grid">
    <section class="panel"><div class="panel-head"><div><div class="panel-title">Recent matches</div><div class="panel-hint">Latest activity · <?= count($recent) ?> shown</div></div><input id="search" class="search" type="search" placeholder="Search match ID or status" aria-label="Search matches"></div>
      <div class="table-wrap"><table><thead><tr><th>Match ID</th><th>Status</th><th>Avg MMR</th><th>Snapshots</th><th>Game-time coverage</th><th>Last seen</th></tr></thead><tbody id="matches">
      <?php foreach ($recent as $row): $status = (string)$row['status']; $color = $statusColors[$status] ?? ''; ?>
        <tr data-search="<?= h(strtolower($row['match_id'] . ' ' . $status)) ?>"><td class="id"><a href="match.php?id=<?= h($row['match_id']) ?>" style="color:inherit;text-decoration:none"><?= h($row['match_id']) ?> ↗</a></td><td><span class="badge <?= h($color) ?>"><?= h($status) ?></span></td><td class="mmr"><?= $row['avg_mmr'] === null ? '—' : number_format((int)$row['avg_mmr']) ?></td><td><?= number_format($row['snapshots']['count']) ?></td><td><?php if ($row['snapshots']['count']): ?><?= h(gameClock($row['snapshots']['first_game'])) ?> → <?= h(gameClock($row['snapshots']['last_game'])) ?><?php else: ?><span style="color:var(--muted)">No snapshots</span><?php endif; ?></td><td><?= $row['last_seen'] ? h(gmdate('M j, H:i', (int)$row['last_seen'])) . ' UTC' : '—' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$recent): ?><tr class="empty-row"><td class="empty" colspan="6"><?= $error ? 'Waiting for collector data.' : 'No matches recorded yet.' ?></td></tr><?php endif; ?>
      </tbody></table></div>
    </section>
    <aside class="side">
      <section class="panel"><div class="panel-head"><div><div class="panel-title">Pipeline status</div><div class="panel-hint">Matches grouped by stage</div></div></div><div class="inner">
        <?php if (!$summary): ?><div class="empty">No status data available.</div><?php else: foreach ($summary as $status => $count): $color = $statusColors[$status] ?? ''; ?>
          <div class="status-row"><span class="status-name"><span class="badge <?= h($color) ?>"><?= h($status) ?></span></span><span class="count"><?= number_format((int)$count) ?></span></div>
        <?php endforeach; endif; ?>
      </div></section>
      <section class="panel"><div class="panel-head"><div><div class="panel-title">API usage</div><div class="panel-hint">Recent daily request totals · UTC</div></div></div><div class="inner">
        <?php if (!$usage): ?><div class="empty">No API calls recorded.</div><?php else: $maxCalls = max(1, ...array_map(static fn($r) => (int)$r['calls'], $usage)); foreach ($usage as $u): ?>
          <div class="usage-row"><span><?= h($u['day']) ?></span><strong><?= number_format((int)$u['calls']) ?></strong></div><div class="callbar"><span style="width:<?= min(100, (int)round(100 * (int)$u['calls'] / $maxCalls)) ?>%"></span></div>
        <?php endforeach; endif; ?>
      </div></section>
      <section class="panel"><div class="panel-head"><div><div class="panel-title">Local storage</div><div class="panel-hint">Data files on this server</div></div></div><div class="inner">
        <div class="metric-row"><span>Live snapshots</span><strong><?= number_format($disk['live_files']) ?></strong></div><div class="metric-row"><span>Final match files</span><strong><?= number_format($disk['final_files']) ?></strong></div><div class="metric-row"><span>Total size</span><strong><?= number_format(($disk['live_bytes'] + $disk['final_bytes']) / 1048576, 1) ?> MB</strong></div>
      </div></section>
    </aside>
  </div>
  <div class="foot">Data refreshes when this page loads · Times shown in UTC</div>
</main>
<script>
  const search = document.getElementById('search');
  search?.addEventListener('input', () => {
    const query = search.value.trim().toLowerCase();
    document.querySelectorAll('#matches tr[data-search]').forEach(row => {
      row.hidden = !row.dataset.search.includes(query);
    });
  });
</script>
</body>
</html>
