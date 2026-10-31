<?php

/**
 * Strict PHP line-coverage gate (AGENTS.md: > 80 %, exactly 80 % fails) on the merged Clover report.
 * Fails as well when a production PHP file is missing from the report, so the scope cannot shrink silently.
 * Usage: php tests/coverage/check.php [coverage/clover.xml]
 */

declare(strict_types=1);

const MIN_EXCLUSIVE = 80;

$root = dirname(__DIR__, 2) . '/';
$report = $argv[1] ?? $root . 'coverage/clover.xml';
$xml = new DOMDocument();
if (!is_file($report) || !$xml->load($report, LIBXML_NONET)) {
    fwrite(STDERR, "Coverage report missing or invalid: $report\n");
    exit(1);
}

$statements = 0;
$covered = 0;
$reported = [];
foreach ($xml->getElementsByTagName('file') as $file) {
    $reported[$file->getAttribute('name')] = true;
    foreach ($file->getElementsByTagName('metrics') as $metrics) {
        $statements += (int) $metrics->getAttribute('statements');
        $covered += (int) $metrics->getAttribute('coveredstatements');
    }
}

$production = ['sw-cloudcalendar.php', 'uninstall.php'];
foreach (['src', 'templates', 'blocks'] as $dir) {
    if (!is_dir($root . $dir)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $path) {
        if ($path->isFile() && $path->getExtension() === 'php') {
            $production[] = substr($path->getPathname(), strlen($root));
        }
    }
}
$missing = array_values(array_filter($production, static fn (string $name): bool => !isset($reported[$name])));
if ($missing) {
    sort($missing);
    fwrite(STDERR, "Production PHP files missing from coverage report:\n  " . implode("\n  ", $missing) . "\n");
    exit(1);
}

// Integer comparison avoids rounding: covered/statements > 80/100 <=> 100*covered > 80*statements.
$passed = $statements > 0 && $covered * 100 > $statements * MIN_EXCLUSIVE;
printf(
    "PHP line coverage: %.2f %% (%d/%d, %d files), required > %d %%\n",
    $statements > 0 ? $covered / $statements * 100 : 0,
    $covered,
    $statements,
    count($reported),
    MIN_EXCLUSIVE
);
if (!$passed) {
    fwrite(STDERR, "PHP line coverage gate failed.\n");
    exit(1);
}
