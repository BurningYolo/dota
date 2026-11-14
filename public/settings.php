<?php
declare(strict_types=1);

$dataDir = rtrim((string)(getenv('DATA_DIR') ?: dirname(__DIR__) . '/data'), '/\\');
$dbPath = $dataDir . '/collector.sqlite';
$passwordHash = (string)(getenv('SETTINGS_PASSWORD_HASH') ?: '');
session_name('dota_observer_settings');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfToken(): string
{
    return (string)($_SESSION['csrf'] ??= bin2hex(random_bytes(32)));
}

$error = null;
$notice = null;
$authenticated = !empty($_SESSION['settings_authenticated']);
$db = null;
$settings = [
    'min_mmr' => '5000',
    'poll_seconds' => '30',
    'min_interval' => '1.1',
    'daily_budget' => '0',
];

if ($passwordHash !== '' && is_file($dbPath)) {
    try {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $settings = array_merge($settings, $db->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR));
    } catch (Throwable $e) {
        $error = 'Could not access collector settings. Check that the collector database is available.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['csrf'] ?? '');
    if (!hash_equals(csrfToken(), $submittedToken)) {
        $error = 'Your form session expired. Reload the page and try again.';
    } elseif ((string)($_POST['action'] ?? '') === 'login') {
        $password = (string)($_POST['password'] ?? '');
        if ($passwordHash !== '' && password_verify($password, $passwordHash)) {
            session_regenerate_id(true);
            $_SESSION['settings_authenticated'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $authenticated = true;
            $notice = 'Signed in.';
        } else {
            $error = $passwordHash === '' ? 'Settings password is not configured on the server.' : 'Password did not match.';
        }
    } elseif ((string)($_POST['action'] ?? '') === 'logout' && $authenticated) {
        $_SESSION = [];
        session_regenerate_id(true);
        $authenticated = false;
        $notice = 'Signed out.';
    } elseif ((string)($_POST['action'] ?? '') === 'save' && $authenticated && $db instanceof PDO) {
        $values = [
            'min_mmr' => filter_var($_POST['min_mmr'] ?? null, FILTER_VALIDATE_INT),
            'poll_seconds' => filter_var($_POST['poll_seconds'] ?? null, FILTER_VALIDATE_INT),
            'min_interval' => filter_var($_POST['min_interval'] ?? null, FILTER_VALIDATE_FLOAT),
            'daily_budget' => filter_var($_POST['daily_budget'] ?? null, FILTER_VALIDATE_INT),
        ];
        $valid = $values['min_mmr'] !== false && $values['min_mmr'] >= 0 && $values['min_mmr'] <= 15000
            && $values['poll_seconds'] !== false && $values['poll_seconds'] >= 10 && $values['poll_seconds'] <= 3600
            && $values['min_interval'] !== false && $values['min_interval'] >= 0.02 && $values['min_interval'] <= 60
            && $values['daily_budget'] !== false && $values['daily_budget'] >= 0 && $values['daily_budget'] <= 1000000;

        if (!$valid) {
            $error = 'Enter values within the ranges shown below.';
        } else {
            try {
                $upsert = $db->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value');
                foreach ($values as $name => $value) {
                    $upsert->execute([$name, (string)$value]);
                    $settings[$name] = (string)$value;
                }
                $notice = 'Settings saved. The collector applies them on its next polling cycle.';
            } catch (Throwable $e) {
                $error = 'Could not save settings. Check that the collector database is writable.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Collector settings · Dota Observer</title>
  <link rel="stylesheet" href="assets/theme.css">
  <style>
    body{min-height:100vh}.shell{width:min(760px,calc(100% - 32px));margin:42px auto}.top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:26px}.brand{display:flex;align-items:center;gap:13px}.mark{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;font-weight:900;color:#031109;background:linear-gradient(145deg,#70f2aa,#12a95e)}.eyebrow{font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:#72e6a2}.title{font-size:21px;font-weight:720}.nav{display:flex;gap:8px}.nav a,.nav button{color:#a4e3b9;text-decoration:none;border:1px solid #254632;border-radius:8px;padding:8px 11px;background:transparent;font-size:12px}.panel{background:#0e1711;border:1px solid #1b3424;border-radius:14px;overflow:hidden}.panelhead{padding:20px 22px;border-bottom:1px solid #1b3424}.panelhead h1{font-size:24px;margin:0 0 5px}.panelhead p,.hint{color:#91a398;font-size:13px;margin:0}.inner{padding:22px}.fields{display:grid;grid-template-columns:1fr 1fr;gap:17px}.field{display:flex;flex-direction:column;gap:6px}.field label{font-weight:650}.field input{width:100%;padding:11px 12px}.hint{font-size:11px;line-height:1.45}.actions{display:flex;align-items:center;justify-content:flex-end;margin-top:22px}.button{border:1px solid #1d9b5c;border-radius:8px;padding:10px 14px;background:#0e6b3d;color:#f0fff5;font-weight:650;cursor:pointer}.alert{padding:12px 14px;margin-bottom:16px;border:1px solid #395332;border-radius:10px;background:#111b13;color:#d5e8ae}.alert.error{border-color:#66302e;background:#241110;color:#ffaaa1}.login{max-width:420px;margin:auto}.login .field{margin:18px 0}.cost{margin-top:18px;padding:13px 15px;background:#101a13;border:1px solid #294331;border-radius:10px;color:#b7c9bd;font-size:12px;line-height:1.5}.foot{margin-top:14px;color:#91a398;font-size:11px;text-align:center}@media(max-width:560px){.shell{margin:22px auto}.top{align-items:flex-start;flex-direction:column}.fields{grid-template-columns:1fr}.panelhead,.inner{padding:17px}}
  </style>
</head>
<body>
<main class="shell">
  <header class="top">
    <div class="brand"><div class="mark">D</div><div><div class="eyebrow">Dota Observer</div><div class="title">Collector settings</div></div></div>
    <nav class="nav"><a href="index.php">Dashboard</a><?php if ($authenticated): ?><form method="post"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="logout"><button type="submit">Sign out</button></form><?php endif; ?></nav>
  </header>
  <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php elseif ($notice): ?><div class="alert"><?= h($notice) ?></div><?php endif; ?>
  <?php if (!$authenticated): ?>
    <section class="panel login"><div class="panelhead"><h1>Sign in</h1><p>Enter the settings password configured on the server.</p></div><div class="inner">
      <form method="post"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="login"><div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div><button class="button" type="submit">Open settings</button></form>
    </div></section>
  <?php elseif (!$db instanceof PDO): ?>
    <section class="panel"><div class="panelhead"><h1>Settings unavailable</h1><p><?= h($error ?? 'The collector database is not ready yet.') ?></p></div></section>
  <?php else: ?>
    <section class="panel"><div class="panelhead"><h1>Live collector controls</h1><p>Saved values are picked up automatically on the next collector cycle.</p></div><div class="inner">
      <form method="post"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="save">
        <div class="fields">
          <div class="field"><label for="min_mmr">Minimum average MMR</label><input id="min_mmr" name="min_mmr" type="number" min="0" max="15000" step="100" value="<?= h($settings['min_mmr']) ?>" required><div class="hint">0–15,000. Matches below this average are skipped.</div></div>
          <div class="field"><label for="poll_seconds">Live polling interval</label><input id="poll_seconds" name="poll_seconds" type="number" min="10" max="3600" step="5" value="<?= h($settings['poll_seconds']) ?>" required><div class="hint">10–3,600 seconds between checks of the live feed.</div></div>
          <div class="field"><label for="min_interval">Minimum spacing between API calls</label><input id="min_interval" name="min_interval" type="number" min="0.02" max="60" step="0.1" value="<?= h($settings['min_interval']) ?>" required><div class="hint">0.02–60 seconds. The paid tier allows up to 3,000 calls per minute.</div></div>
          <div class="field"><label for="daily_budget">Daily call cap</label><input id="daily_budget" name="daily_budget" type="number" min="0" max="1000000" step="100" value="<?= h($settings['daily_budget']) ?>" required><div class="hint">Set 0 for no daily cap, or choose 1–1,000,000 calls.</div></div>
        </div>
        <div class="cost">API calls are billed at the rate on your account. At the current 30-second poll interval, live-feed checks alone make about 2,880 calls per day.</div>
        <div class="actions"><button class="button" type="submit">Save settings</button></div>
      </form>
    </div></section>
  <?php endif; ?>
  <div class="foot">The API key is kept server-side and is not shown here.</div>
</main>
</body>
</html>
