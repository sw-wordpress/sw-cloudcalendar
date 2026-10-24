<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render\Template;

/**
 * Validates and scopes template CSS (SPECIFICATIONS.md §11.2). External resources (url(), @import, fonts) are
 * rejected to prevent data exfiltration; every selector is prefixed with the template's scope class.
 */
final class CssSanitizer
{
    public const MAX_LENGTH = 51200;

    private const FORBIDDEN = [
        'url(' => 'url()',
        'image-set(' => 'image-set()',
        'expression(' => 'expression()',
        'javascript:' => 'javascript:',
        'vbscript:' => 'vbscript:',
        'behavior' => 'behavior',
        '-moz-binding' => '-moz-binding',
        '@import' => '@import',
        '@font-face' => '@font-face',
        '@charset' => '@charset',
        '@namespace' => '@namespace',
        '<' => '<',
        '\\' => 'escape sequences',
    ];

    private const NESTING_AT_RULES = ['@media', '@supports', '@container'];

    /**
     * @return string|null Reason if forbidden content was found.
     */
    public static function findForbidden(string $css): ?string
    {
        $lower = strtolower(self::stripComments($css));
        foreach (self::FORBIDDEN as $needle => $label) {
            if (str_contains($lower, $needle)) {
                return $label;
            }
        }
        return null;
    }

    /**
     * Validates the content of a style attribute (declarations only).
     */
    public static function validateDeclarations(string $css): ?string
    {
        $forbidden = self::findForbidden($css);
        if ($forbidden !== null) {
            return $forbidden;
        }
        if (str_contains($css, '{') || str_contains($css, '}') || str_contains($css, '@')) {
            return 'rules in style attribute';
        }
        return null;
    }

    /**
     * Scopes a stylesheet below `$scope` (a class selector such as `.swc-tpl-7`).
     *
     * @throws \InvalidArgumentException With a safe reason if the CSS is not acceptable.
     */
    public static function scope(string $css, string $scope): string
    {
        if (strlen($css) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('stylesheet too long');
        }
        $forbidden = self::findForbidden($css);
        if ($forbidden !== null) {
            throw new \InvalidArgumentException($forbidden);
        }
        return trim(self::scopeBlock(self::stripComments($css), $scope, 0));
    }

    private static function scopeBlock(string $css, string $scope, int $depth): string
    {
        if ($depth > 4) {
            throw new \InvalidArgumentException('nesting too deep');
        }
        $out = '';
        $pos = 0;
        $len = strlen($css);
        while ($pos < $len) {
            $open = self::findOutsideStrings($css, '{', $pos);
            if ($open === null) {
                if (trim(substr($css, $pos)) !== '') {
                    throw new \InvalidArgumentException('declarations outside of a rule');
                }
                break;
            }
            $prelude = trim(substr($css, $pos, $open - $pos));
            $close = self::matchingBrace($css, $open);
            $body = substr($css, $open + 1, $close - $open - 1);
            $pos = $close + 1;
            if ($prelude === '') {
                throw new \InvalidArgumentException('empty selector');
            }
            if ($prelude[0] === '@') {
                $atName = strtolower((string) preg_replace('/[\s(].*$/s', '', $prelude));
                if (in_array($atName, self::NESTING_AT_RULES, true)) {
                    $out .= $prelude . '{' . trim(self::scopeBlock($body, $scope, $depth + 1)) . "}\n";
                    continue;
                }
                if ($atName === '@keyframes' || $atName === '@-webkit-keyframes') {
                    $out .= $prelude . '{' . $body . "}\n";
                    continue;
                }
                throw new \InvalidArgumentException('unsupported at-rule ' . $atName);
            }
            if (str_contains($body, '{')) {
                throw new \InvalidArgumentException('nested rules');
            }
            $out .= self::scopeSelectors($prelude, $scope) . '{' . trim($body) . "}\n";
        }
        return $out;
    }

    private static function scopeSelectors(string $prelude, string $scope): string
    {
        $selectors = [];
        foreach (self::splitSelectors($prelude) as $selector) {
            $selector = trim($selector);
            if ($selector === '') {
                continue;
            }
            $replaced = preg_replace('/^(:root|html|body)\b/i', $scope, $selector, 1, $count);
            $selectors[] = $count > 0 ? (string) $replaced : $scope . ' ' . $selector;
        }
        if ($selectors === []) {
            throw new \InvalidArgumentException('empty selector');
        }
        return implode(', ', $selectors);
    }

    /**
     * @return list<string>
     */
    private static function splitSelectors(string $prelude): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        $length = strlen($prelude);
        for ($i = 0; $i < $length; $i++) {
            $char = $prelude[$i];
            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;
        return $parts;
    }

    private static function matchingBrace(string $css, int $open): int
    {
        $depth = 0;
        $length = strlen($css);
        $quote = null;
        for ($i = $open; $i < $length; $i++) {
            $char = $css[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        throw new \InvalidArgumentException('unbalanced braces');
    }

    private static function findOutsideStrings(string $css, string $needle, int $from): ?int
    {
        $quote = null;
        $length = strlen($css);
        for ($i = $from; $i < $length; $i++) {
            $char = $css[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === $needle) {
                return $i;
            } elseif ($char === '}') {
                throw new \InvalidArgumentException('unbalanced braces');
            }
        }
        return null;
    }

    private static function stripComments(string $css): string
    {
        return (string) preg_replace('~/\*.*?(\*/|$)~s', '', $css);
    }
}
