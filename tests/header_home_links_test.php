<?php
declare(strict_types=1);

$pages = ['index.php', 'settings.php', 'data.php', 'match.php'];
$pattern = '/<a\b(?=[^>]*\bclass="[^"]*\bbrand-home\b[^"]*")(?=[^>]*\bhref="index\.php")(?=[^>]*\baria-label="Dota Observer home")[^>]*>.*?<div class="mark">D<\/div>.*?<\/a>/s';
$failed = false;

foreach ($pages as $page) {
    $path = __DIR__ . '/../public/' . $page;
    $source = file_get_contents($path);
    if ($source === false || preg_match($pattern, $source) !== 1) {
        fwrite(STDERR, "FAIL: public/$page header logo should link to index.php\n");
        $failed = true;
        continue;
    }

    echo "PASS: public/$page header logo links to index.php\n";
}

exit($failed ? 1 : 0);
