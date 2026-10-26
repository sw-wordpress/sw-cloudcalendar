<?php

/**
 * Writes a POT fragment with the translatable block.json fields (WordPress contexts "block title",
 * "block description", "block keyword"). Used by bin/i18n.sh.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$block = json_decode((string) file_get_contents($root . '/blocks/calendar/block.json'), true);
$entries = [
    ['block title', $block['title']],
    ['block description', $block['description']],
];
foreach ($block['keywords'] as $keyword) {
    $entries[] = ['block keyword', $keyword];
}
$out = "msgid \"\"\nmsgstr \"\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\n";
foreach ($entries as [$context, $text]) {
    $out .= "#: blocks/calendar/block.json\nmsgctxt " . json_encode($context, JSON_UNESCAPED_UNICODE) . "\nmsgid " . json_encode($text, JSON_UNESCAPED_UNICODE) . "\nmsgstr \"\"\n\n";
}
file_put_contents($argv[1], $out);
