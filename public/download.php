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

if ($passwordHash === '' || empty($_SESSION['settings_authenticated'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo $passwordHash === ''
        ? "Data downloads are disabled until a settings password is configured on the server.\n"
        : "Sign in on the collector settings page before downloading data.\n";
    exit;
}

if (!is_file($dbPath)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "The collector database is not available yet.\n";
    exit;
}

set_time_limit(0);
$temporaryFiles = [];

try {
    $suffix = bin2hex(random_bytes(12));
    $snapshotPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "dota-collector-$suffix.sqlite";
    $tarPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "dota-collector-$suffix.tar";
    $archivePath = $tarPath . '.gz';
    $temporaryFiles = [$snapshotPath, $tarPath, $archivePath];

    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $quotedSnapshotPath = str_replace("'", "''", $snapshotPath);
    $db->exec("VACUUM INTO '$quotedSnapshotPath'");
    unset($db);

    $archive = new PharData($tarPath);
    $archive->addFile($snapshotPath, 'data/collector.sqlite');
    foreach ([
        'live' => glob($dataDir . '/live/*.jsonl') ?: [],
        'final' => glob($dataDir . '/final/*.json') ?: [],
    ] as $folder => $files) {
        foreach ($files as $file) {
            if (!is_file($file) || is_link($file)) {
                continue;
            }
            $archive->addFile($file, 'data/' . $folder . '/' . basename($file));
        }
    }
    unset($archive);

    $archive = new PharData($tarPath);
    $archive->compress(Phar::GZ);
    unset($archive);

    if (!is_file($archivePath) || !is_readable($archivePath)) {
        throw new RuntimeException('Archive generation did not produce a readable file.');
    }

    $filename = 'dota-observer-data-' . gmdate('Y-m-d') . '.tar.gz';
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($archivePath));
    header('X-Content-Type-Options: nosniff');
    readfile($archivePath);
} catch (Throwable $e) {
    error_log('Could not create collector data download: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Could not create the data archive. Check the server logs and temporary disk space.\n";
    }
} finally {
    foreach ($temporaryFiles as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
