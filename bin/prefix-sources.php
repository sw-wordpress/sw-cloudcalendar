<?php

/**
 * Build step after Mozart: rewrites references to isolated libraries in the plugin's own sources.
 *
 * Mozart only rewrites the dependency packages themselves. This script changes qualified names that start with one
 * of the isolated root namespaces (token based, so strings and comments are untouched) and fails if any reference
 * remains. Usage: php bin/prefix-sources.php <plugin-dir>
 */

declare(strict_types=1);

$root = rtrim($argv[1] ?? '', '/');
if ($root === '' || !is_dir($root . '/src')) {
    fwrite(STDERR, "usage: php bin/prefix-sources.php <plugin-dir>\n");
    exit(1);
}
$prefix = 'SW\\Calendar\\Dependencies\\';
$namespaces = ['Sabre'];

$changed = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $code = (string) file_get_contents($file->getPathname());
    $out = '';
    foreach (token_get_all($code) as $token) {
        if (is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $name = $token[1];
            $leading = str_starts_with($name, '\\') ? '\\' : '';
            $bare = ltrim($name, '\\');
            foreach ($namespaces as $namespace) {
                if (str_starts_with($bare, $namespace . '\\')) {
                    $name = $leading . $prefix . $bare;
                    break;
                }
            }
            $out .= $name;
            continue;
        }
        $out .= is_array($token) ? $token[1] : $token;
    }
    if ($out !== $code) {
        file_put_contents($file->getPathname(), $out);
        $changed++;
    }
}

// Verify: no unprefixed reference may remain.
$remaining = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
        if (is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            foreach ($namespaces as $namespace) {
                if (str_starts_with(ltrim($token[1], '\\'), $namespace . '\\')) {
                    $remaining[] = $file->getPathname() . ': ' . $token[1];
                }
            }
        }
    }
}
if ($remaining !== []) {
    fwrite(STDERR, "Unprefixed library references remain:\n" . implode("\n", $remaining) . "\n");
    exit(1);
}
echo "Prefixed library references in {$changed} file(s).\n";
