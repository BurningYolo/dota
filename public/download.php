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

function tarOctal(int $value, int $width): string
{
    $octal = sprintf('%o', $value);
    if (strlen($octal) > $width - 1) {
        throw new RuntimeException('A file is too large for the tar archive format.');
    }
    return str_pad($octal, $width - 1, '0', STR_PAD_LEFT) . "\0";
}

function tarFileHeader(string $name, int $size, int $modified): string
{
    if (strlen($name) > 100) {
        throw new RuntimeException('A data filename is too long for the tar archive format.');
    }

    $header = str_pad($name, 100, "\0")
        . tarOctal(0644, 8)
        . tarOctal(0, 8)
        . tarOctal(0, 8)
        . tarOctal($size, 12)
        . tarOctal($modified, 12)
        . str_repeat(' ', 8)
        . '0'
        . str_repeat("\0", 100)
        . "ustar\0"
        . '00'
        . str_repeat("\0", 32)
        . str_repeat("\0", 32)
        . tarOctal(0, 8)
        . tarOctal(0, 8)
        . str_repeat("\0", 155)
        . str_repeat("\0", 12);

    $checksum = array_sum(array_map('ord', str_split($header)));
    $header = substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
    if (strlen($header) !== 512) {
        throw new RuntimeException('Could not create a valid tar archive header.');
    }
    return $header;
}

function writeGzipChunk($context, string $data, int $flush = ZLIB_NO_FLUSH): void
{
    $compressed = deflate_add($context, $data, $flush);
    if ($compressed === false) {
        throw new RuntimeException('Could not compress the data archive.');
    }
    echo $compressed;
    flush();
}

set_time_limit(0);
$temporaryFiles = [];

try {
    $suffix = bin2hex(random_bytes(12));
    $snapshotPath = $dataDir . DIRECTORY_SEPARATOR . ".dota-download-$suffix.sqlite";
    $temporaryFiles = [$snapshotPath];

    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $quotedSnapshotPath = str_replace("'", "''", $snapshotPath);
    $db->exec("VACUUM INTO '$quotedSnapshotPath'");
    unset($db);

    $entries = [['data/collector.sqlite', $snapshotPath]];
    foreach ([
        'live' => glob($dataDir . '/live/*.jsonl') ?: [],
        'final' => glob($dataDir . '/final/*.json') ?: [],
    ] as $folder => $folderFiles) {
        foreach ($folderFiles as $file) {
            if (!is_file($file) || is_link($file)) {
                continue;
            }
            $entries[] = ['data/' . $folder . '/' . basename($file), $file];
        }
    }
    foreach ($entries as [$archiveName]) {
        if (strlen($archiveName) > 100) {
            throw new RuntimeException('A data filename is too long for the tar archive format.');
        }
    }

    $filename = 'dota-observer-data-' . gmdate('Y-m-d') . '.tar.gz';
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    $context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
    if ($context === false) {
        throw new RuntimeException('Could not initialize archive compression.');
    }

    foreach ($entries as [$archiveName, $path]) {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Could not read data file: $path");
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException("Could not lock data file: $path");
            }
            $stat = fstat($handle);
            if ($stat === false) {
                throw new RuntimeException("Could not inspect data file: $path");
            }

            $size = (int)$stat['size'];
            writeGzipChunk($context, tarFileHeader($archiveName, $size, (int)$stat['mtime']));
            $remaining = $size;
            while ($remaining > 0) {
                $chunk = fread($handle, min(65536, $remaining));
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException("Could not finish reading data file: $path");
                }
                writeGzipChunk($context, $chunk);
                $remaining -= strlen($chunk);
            }
            $padding = (512 - ($size % 512)) % 512;
            if ($padding > 0) {
                writeGzipChunk($context, str_repeat("\0", $padding));
            }
        } finally {
            fclose($handle);
        }
    }

    writeGzipChunk($context, str_repeat("\0", 1024), ZLIB_FINISH);
} catch (Throwable $e) {
    error_log('Could not create collector data download: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Could not create the data archive. Check the server logs and free disk space.\n";
    }
} finally {
    foreach ($temporaryFiles as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
