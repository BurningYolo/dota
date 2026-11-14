<?php
declare(strict_types=1);

function testAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function testAssertContains(string $needle, string $haystack, string $message): void
{
    testAssert(str_contains($haystack, $needle), $message);
}

function testCreateRoot(): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dota-observer-tests-' . bin2hex(random_bytes(8));
    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException("Could not create test directory: $path");
    }
    return $path;
}

function testRemoveRoot(string $path): void
{
    $prefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dota-observer-tests-';
    if (strpos($path, $prefix) !== 0 || !is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

function testMakeDataDirectories(string $dataDir): void
{
    foreach ([$dataDir, "$dataDir/live", "$dataDir/final"] as $path) {
        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException("Could not create test data directory: $path");
        }
    }
}

function testCreateDatabase(string $dataDir, array $matches, array $settings = [], array $usage = []): PDO
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
        next_at INTEGER NOT NULL DEFAULT 0,
        last_update INTEGER NOT NULL DEFAULT 0,
        changed_at INTEGER NOT NULL DEFAULT 0
    )');
    $db->exec('CREATE TABLE usage (day TEXT PRIMARY KEY, calls INTEGER NOT NULL)');
    $db->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');

    $insertMatch = $db->prepare('INSERT INTO matches
        (match_id, status, avg_mmr, first_seen, last_seen, misses, attempts, next_at, last_update, changed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($matches as $match) {
        $insertMatch->execute([
            $match['match_id'],
            $match['status'],
            $match['avg_mmr'] ?? null,
            $match['first_seen'] ?? 0,
            $match['last_seen'] ?? 0,
            $match['misses'] ?? 0,
            $match['attempts'] ?? 0,
            $match['next_at'] ?? 0,
            $match['last_update'] ?? 0,
            $match['changed_at'] ?? 0,
        ]);
    }

    $insertSetting = $db->prepare('INSERT INTO settings (name, value) VALUES (?, ?)');
    foreach ($settings as $name => $value) {
        $insertSetting->execute([$name, (string)$value]);
    }
    $insertUsage = $db->prepare('INSERT INTO usage (day, calls) VALUES (?, ?)');
    foreach ($usage as $day => $calls) {
        $insertUsage->execute([$day, $calls]);
    }
    return $db;
}

function testEnvironment(array $overrides = []): array
{
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    foreach ($overrides as $name => $value) {
        $environment[$name] = (string)$value;
    }
    return $environment;
}

function testStartServer(string $root, array $environment, ?string $docRoot = null, ?string $router = null): array
{
    $sessionDir = $root . DIRECTORY_SEPARATOR . 'sessions';
    if (!is_dir($sessionDir) && !mkdir($sessionDir, 0777, true) && !is_dir($sessionDir)) {
        throw new RuntimeException("Could not create session directory: $sessionDir");
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException("Could not reserve a test server port: $errorMessage");
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($address)) {
        throw new RuntimeException('Could not read the reserved test server port.');
    }
    $port = (int)substr($address, strrpos($address, ':') + 1);
    $url = "http://127.0.0.1:$port";

    $command = [
        PHP_BINARY,
        '-d',
        'session.save_path=' . $root . DIRECTORY_SEPARATOR . 'sessions',
        '-S',
        "127.0.0.1:$port",
    ];
    if ($router !== null) {
        $command[] = $router;
    } elseif ($docRoot !== null) {
        $command[] = '-t';
        $command[] = $docRoot;
    } else {
        throw new InvalidArgumentException('A document root or router script is required.');
    }

    $logPrefix = $root . DIRECTORY_SEPARATOR . 'server-' . $port;
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $logPrefix . '.out.log', 'a'],
        2 => ['file', $logPrefix . '.err.log', 'a'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $root, testEnvironment($environment));
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the PHP test server.');
    }
    fclose($pipes[0]);

    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $response = @file_get_contents($url . '/');
        if ($response !== false) {
            return [$process, $url];
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(50000);
    }

    testStopProcess($process);
    $errorLog = @file_get_contents($logPrefix . '.err.log');
    throw new RuntimeException('PHP test server did not start. ' . (string)$errorLog);
}

function testStopProcess($process): void
{
    if (!is_resource($process)) {
        return;
    }
    $status = proc_get_status($process);
    if ($status['running']) {
        proc_terminate($process);
    }
    proc_close($process);
}

function testHttpRequest(string $baseUrl, string $path, string $cookieJar, string $method = 'GET', array $form = []): array
{
    $handle = curl_init($baseUrl . '/' . ltrim($path, '/'));
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($method === 'POST') {
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($form),
        ]);
    }
    $body = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($body === false) {
        throw new RuntimeException("HTTP request failed: $error");
    }
    return [$status, (string)$body];
}

function testRunPhp(array $arguments, string $cwd, array $environment = []): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        array_merge([PHP_BINARY], $arguments),
        $descriptors,
        $pipes,
        $cwd,
        testEnvironment($environment)
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start PHP command.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return [$exitCode, (string)$stdout, (string)$stderr];
}
