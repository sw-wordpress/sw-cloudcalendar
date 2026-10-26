<?php

/**
 * Creates the JSON catalogs WordPress loads for the block editor script (wp_set_script_translations):
 * languages/sw-cloudcalendar-<locale>-<md5('blocks/calendar/index.js')>.json from the matching .po entries.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$script = 'blocks/calendar/index.js';
foreach (glob($root . '/languages/sw-cloudcalendar-*.po') ?: [] as $po) {
    $locale = substr(basename($po, '.po'), strlen('sw-cloudcalendar-'));
    $messages = [];
    $blocks = preg_split('/\n\s*\n/', (string) file_get_contents($po)) ?: [];
    foreach ($blocks as $block) {
        if (!str_contains($block, '#: ' . $script) && !preg_match('/^#:.*' . preg_quote($script, '/') . '/m', $block)) {
            continue;
        }
        $id = po_value($block, 'msgid');
        $str = po_value($block, 'msgstr');
        if ($id !== '' && $str !== '') {
            $messages[$id] = [$str];
        }
    }
    $json = [
        'translation-revision-date' => '',
        'generator' => 'sw-cloudcalendar bin/i18n-json.php',
        'domain' => 'messages',
        'locale_data' => ['messages' => ['' => ['domain' => 'messages', 'lang' => $locale]] + $messages],
    ];
    file_put_contents($root . '/languages/sw-cloudcalendar-' . $locale . '-' . md5($script) . '.json', json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
}

function po_value(string $block, string $key): string
{
    if (!preg_match('/^' . $key . ' ((?:"(?:[^"\\\\]|\\\\.)*"\s*)+)/m', $block, $m)) {
        return '';
    }
    preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $m[1], $parts);
    return stripcslashes(implode('', $parts[1]));
}
