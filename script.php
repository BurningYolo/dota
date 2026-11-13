<?php
declare(strict_types=1);

/**
 * Dota 2 live high-MMR match collector (OpenDota API)
 *
 * What it does
 *  1. Polls GET /live every POLL_SECONDS and keeps games with average_mmr >= MIN_MMR.
 *  2. Saves every live snapshot (raw JSON) to data/live/<match_id>.jsonl
 *     -> gives you leakage-free, time-stamped gold lead / score data for free.
 *  3. Never sends per-match requests while a match is live.
 *  4. When a match has been missing from /live for MISSES_TO_END polls, it is
 *     marked "ended" and is NEVER polled via /live again. Only then is the
 *     final raw JSON fetched once from GET /matches/<id> (with backoff if
 *     OpenDota doesn't have it yet).
 *  5. Requests a replay parse (POST /request/<id>) once, then re-fetches later
 *     to replace the raw JSON with the parsed version (per-minute gold/xp arrays).
 *
 * Usage:   php dota_live_collector.php
 * Env:     OPENDOTA_API_KEY  optional key (higher limits)
 *          MIN_MMR           default 6000
 *          POLL_SECONDS      default 30
 *          MIN_INTERVAL      min seconds between API calls, default 1.1
 *          DAILY_BUDGET      max API calls per UTC day, default 2800
 *          DATA_DIR          default ./data
 *
 * Requires: PHP 8+, ext-curl, ext-pdo_sqlite
 * Verify field names (match_id, average_mmr) against a real /live response;
 * the first poll logs the keys it sees.
 */

const BASE_URL = 'https://api.opendota.com/api';
const MISSES_TO_END = 3;       // consecutive polls absent from /live => ended
const MAX_FETCH_ATTEMPTS = 8;  // for the first raw fetch
const MAX_PARSE_ATTEMPTS = 6;  // for the parsed re-fetch

$apiKey      = (string)(getenv('OPENDOTA_API_KEY') ?: '');
$minMmr      = (int)(getenv('MIN_MMR') ?: 6000);
$pollSeconds = (int)(getenv('POLL_SECONDS') ?: 30);
$minInterval = (float)(getenv('MIN_INTERVAL') ?: 1.1);
$dailyBudget = (int)(getenv('DAILY_BUDGET') ?: 2800);
$dataDir     = rtrim((string)(getenv('DATA_DIR') ?: __DIR__ . '/data'), '/');
// Optional filters, comma-separated ints (empty = no filter), e.g. GAME_MODES=22 LOBBY_TYPES=7
$gameModes   = array_values(array_filter(array_map('intval', explode(',', (string)(getenv('GAME_MODES') ?: '')))));
$lobbyTypes  = array_values(array_filter(array_map('intval', explode(',', (string)(getenv('LOBBY_TYPES') ?: '')))));
$skipLeague  = (getenv('INCLUDE_LEAGUE_GAMES') ?: '0') !== '1'; // skip pro/league games by default
// If a /live entry's last_update_time stops changing for this long, treat the match as ended.
$staleSeconds = (int)(getenv('STALE_SECONDS') ?: 600);

foreach ([$dataDir, "$dataDir/live", "$dataDir/final"] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Cannot create $dir\n");
        exit(1);
    }
}

$db = new PDO('sqlite:' . $dataDir . '/collector.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE IF NOT EXISTS matches (
    match_id   INTEGER PRIMARY KEY,
    status     TEXT NOT NULL,      -- live | ended | fetched | parsed | unparsed | failed
    avg_mmr    INTEGER,
    first_seen INTEGER,
    last_seen  INTEGER,
    misses     INTEGER NOT NULL DEFAULT 0,
    attempts   INTEGER NOT NULL DEFAULT 0,
    next_at    INTEGER NOT NULL DEFAULT 0
)');
$db->exec('CREATE TABLE IF NOT EXISTS usage (day TEXT PRIMARY KEY, calls INTEGER NOT NULL)');
// Added later: track the feed's last_update_time and when we last saw it change.
foreach (['last_update INTEGER NOT NULL DEFAULT 0', 'changed_at INTEGER NOT NULL DEFAULT 0'] as $col) {
    try {
        $db->exec("ALTER TABLE matches ADD COLUMN $col");
    } catch (PDOException $e) {
        // column already exists
    }
}

function logm(string $msg): void
{
    echo '[' . gmdate('H:i:s') . '] ' . $msg . PHP_EOL;
}

/** Returns [httpCode, body|null]. code 0 = not sent (budget) or network error. */
function api(string $method, string $path, array $query = []): array
{
    global $apiKey, $minInterval, $dailyBudget, $db;
    static $last = 0.0;

    $day = gmdate('Y-m-d');
    $used = (int)$db->query("SELECT calls FROM usage WHERE day = '$day'")->fetchColumn();
    if ($used >= $dailyBudget) {
        return [0, null];
    }

    $wait = $minInterval - (microtime(true) - $last);
    if ($wait > 0) {
        usleep((int)($wait * 1_000_000));
    }

    if ($apiKey !== '') {
        $query['api_key'] = $apiKey;
    }
    $url = BASE_URL . $path . ($query ? '?' . http_build_query($query) : '');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_USERAGENT      => 'dota-research-collector/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $last = microtime(true);

    $db->exec("INSERT INTO usage (day, calls) VALUES ('$day', 1)
               ON CONFLICT(day) DO UPDATE SET calls = calls + 1");

    if ($code === 429) {
        logm('429 rate limited, sleeping 60s');
        sleep(60);
    }
    return [$code, $body === false ? null : (string)$body];
}

function saveAtomic(string $path, string $content): void
{
    $tmp = $path . '.tmp';
    file_put_contents($tmp, $content, LOCK_EX);
    rename($tmp, $path);
}

function pollLive(): void
{
    global $db, $dataDir, $minMmr, $gameModes, $lobbyTypes, $skipLeague, $staleSeconds;
    static $loggedKeys = false;

    [$code, $body] = api('GET', '/live');
    if ($code !== 200 || $body === null) {
        logm("/live failed (code $code)");
        return;
    }
    $games = json_decode($body, true);
    if (!is_array($games)) {
        logm('/live returned unexpected JSON');
        return;
    }
    if (!$loggedKeys && isset($games[0]) && is_array($games[0])) {
        logm('First /live game keys: ' . implode(', ', array_keys($games[0])));
        $loggedKeys = true;
        $dist = [];
        foreach ($games as $g) {
            $k = 'lobby_type=' . ($g['lobby_type'] ?? '?') . ' game_mode=' . ($g['game_mode'] ?? '?');
            $dist[$k] = ($dist[$k] ?? 0) + 1;
        }
        arsort($dist);
        logm('Mode mix in first /live poll: ' . json_encode($dist));
        $mm = array_map(fn($g) => (int)($g['average_mmr'] ?? 0), $games);
        sort($mm);
        logm('average_mmr min/median/max: ' . $mm[0] . '/' . $mm[intdiv(count($mm), 2)] . '/' . end($mm));
    }

    $now = time();
    $seen = [];
    $get = $db->prepare('SELECT status, last_update, changed_at FROM matches WHERE match_id = ?');
    $ins = $db->prepare('INSERT INTO matches (match_id, status, avg_mmr, first_seen, last_seen, last_update, changed_at)
                         VALUES (?, \'live\', ?, ?, ?, ?, ?)');
    $insEnded = $db->prepare('INSERT INTO matches (match_id, status, avg_mmr, first_seen, last_seen, last_update, changed_at, next_at)
                              VALUES (?, \'ended\', ?, ?, ?, ?, ?, ?)');
    $upd = $db->prepare('UPDATE matches SET last_seen = ?, misses = 0, avg_mmr = ?, last_update = ?, changed_at = ? WHERE match_id = ?');
    $toEnded = $db->prepare("UPDATE matches SET status = 'ended', attempts = 0, next_at = ? WHERE match_id = ?");

    foreach ($games as $g) {
        $id  = (int)($g['match_id'] ?? 0);
        $mmr = (int)($g['average_mmr'] ?? 0);
        if ($id <= 0 || $mmr < $minMmr) {
            continue;
        }
        if ($skipLeague && (int)($g['league_id'] ?? 0) > 0) {
            continue;
        }
        if ($gameModes && !in_array((int)($g['game_mode'] ?? -1), $gameModes, true)) {
            continue;
        }
        if ($lobbyTypes && !in_array((int)($g['lobby_type'] ?? -1), $lobbyTypes, true)) {
            continue;
        }
        $get->execute([$id]);
        $row = $get->fetch(PDO::FETCH_ASSOC);
        $status = $row['status'] ?? false;
        if ($status !== false && $status !== 'live') {
            continue; // already ended/fetched: never track it again
        }
        $seen[$id] = true;
        $lu = (int)($g['last_update_time'] ?? 0);
        $save = false;

        if ($status === false) {
            if ($lu > 0 && ($now - $lu) > $staleSeconds) {
                // Feed entry is already stale (the game stopped updating long ago):
                // queue the final fetch now instead of polling a dead entry.
                $insEnded->execute([$id, $mmr, $now, $now, $lu, $now, $now]);
                logm("Match $id already stale in /live (" . intdiv($now - $lu, 60) . ' min); queued for final fetch');
                continue;
            }
            $ins->execute([$id, $mmr, $now, $now, $lu, $now]);
            logm("New live match $id (avg MMR $mmr)");
            $save = true;
        } else {
            $changed   = $lu !== (int)$row['last_update'];
            $changedAt = $changed ? $now : (int)$row['changed_at'];
            $upd->execute([$now, $mmr, $lu, $changedAt, $id]);
            $save = $changed; // only keep snapshots that carry new information
            if (!$changed && ($now - $changedAt) > $staleSeconds) {
                $toEnded->execute([$now + 60, $id]);
                logm("Match $id stopped updating for " . intdiv($now - $changedAt, 60) . ' min; queued for final fetch');
            }
        }

        if ($save) {
            file_put_contents(
                "$dataDir/live/$id.jsonl",
                json_encode(['t' => $now, 'game' => $g]) . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        }
    }

    // Live matches that disappeared from the list
    $liveIds = $db->query("SELECT match_id FROM matches WHERE status = 'live'")->fetchAll(PDO::FETCH_COLUMN);
    $miss = $db->prepare('UPDATE matches SET misses = misses + 1 WHERE match_id = ?');
    $end  = $db->prepare("UPDATE matches SET status = 'ended', attempts = 0, next_at = ? WHERE match_id = ?");
    $chk  = $db->prepare('SELECT misses FROM matches WHERE match_id = ?');
    foreach ($liveIds as $id) {
        if (isset($seen[(int)$id])) {
            continue;
        }
        $miss->execute([$id]);
        $chk->execute([$id]);
        if ((int)$chk->fetchColumn() >= MISSES_TO_END) {
            $end->execute([$now + 180, $id]); // give OpenDota a few minutes
            logm("Match $id ended; will fetch final JSON");
        }
    }
}

function fetchEnded(): void
{
    global $db, $dataDir;
    $now  = time();
    $rows = $db->query("SELECT match_id, attempts FROM matches
                        WHERE status = 'ended' AND next_at <= $now LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $id = (int)$r['match_id'];
        [$code, $body] = api('GET', "/matches/$id");
        if ($code === 0 && $body === null) {
            return; // budget or network; try later
        }
        $m = ($code === 200 && $body !== null) ? json_decode($body, true) : null;

        if (is_array($m) && isset($m['players'])) {
            saveAtomic("$dataDir/final/$id.json", $body);
            api('POST', "/request/$id"); // ask for a replay parse once
            $db->prepare("UPDATE matches SET status = 'fetched', attempts = 0, next_at = ? WHERE match_id = ?")
               ->execute([$now + 600, $id]);
            logm("Saved raw JSON for $id, parse requested");
            continue;
        }

        $attempts = (int)$r['attempts'] + 1;
        if ($attempts >= MAX_FETCH_ATTEMPTS) {
            $db->prepare("UPDATE matches SET status = 'failed', attempts = ? WHERE match_id = ?")
               ->execute([$attempts, $id]);
            logm("Giving up on $id (code $code)");
        } else {
            $delay = min(3600, 120 * (2 ** $attempts));
            $db->prepare('UPDATE matches SET attempts = ?, next_at = ? WHERE match_id = ?')
               ->execute([$attempts, $now + $delay, $id]);
            logm("Match $id not ready (code $code), retry in {$delay}s");
        }
    }
}

function refetchParsed(): void
{
    global $db, $dataDir;
    $now  = time();
    $rows = $db->query("SELECT match_id, attempts FROM matches
                        WHERE status = 'fetched' AND next_at <= $now LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $id = (int)$r['match_id'];
        [$code, $body] = api('GET', "/matches/$id");
        if ($code === 0 && $body === null) {
            return;
        }
        $m = ($code === 200 && $body !== null) ? json_decode($body, true) : null;

        if (is_array($m) && isset($m['players'][0]['gold_t'])) {
            saveAtomic("$dataDir/final/$id.json", $body);
            $db->prepare("UPDATE matches SET status = 'parsed' WHERE match_id = ?")->execute([$id]);
            logm("Parsed data saved for $id");
            continue;
        }

        $attempts = (int)$r['attempts'] + 1;
        if ($attempts >= MAX_PARSE_ATTEMPTS) {
            $db->prepare("UPDATE matches SET status = 'unparsed', attempts = ? WHERE match_id = ?")
               ->execute([$attempts, $id]);
            logm("Match $id never got parsed; keeping unparsed raw JSON");
        } else {
            $db->prepare('UPDATE matches SET attempts = ?, next_at = ? WHERE match_id = ?')
               ->execute([$attempts, $now + 600, $id]);
        }
    }
}

logm("Collector started (MIN_MMR=$minMmr, poll={$pollSeconds}s, budget=$dailyBudget/day)");
while (true) {
    pollLive();
    fetchEnded();
    refetchParsed();
    sleep($pollSeconds);
}
