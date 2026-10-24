<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render\Template;

/**
 * Renders a compiled AST with a flat value map. Escaping is decided by the context recorded at compile time.
 */
final class TemplateRenderer
{
    /**
     * @param array<string, string|int|bool|TrustedHtml|null> $values
     */
    public function render(CompiledTemplate $template, array $values): string
    {
        return $this->renderNodes($template->nodes, $values);
    }

    /**
     * @param list<array<int, mixed>>                         $nodes
     * @param array<string, string|int|bool|TrustedHtml|null> $values
     */
    private function renderNodes(array $nodes, array $values): string
    {
        $html = '';
        foreach ($nodes as $node) {
            switch ($node[0]) {
                case 't':
                    $html .= $node[1];
                    break;
                case 'v':
                    $html .= $this->renderValue($values[$node[1]] ?? null, (string) $node[2]);
                    break;
                case 's':
                    $truthy = self::isTruthy($values[$node[1]] ?? null);
                    if ($truthy !== (bool) $node[2]) {
                        $html .= $this->renderNodes($node[3], $values);
                    }
                    break;
            }
        }
        return $html;
    }

    private function renderValue(string|int|bool|TrustedHtml|null $value, string $context): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        if ($value === true) {
            $value = '1';
        }
        if ($context === 'html') {
            // Only plugin generated components are emitted unescaped; anything else is treated as text.
            return $value instanceof TrustedHtml ? (string) $value : TemplateCompiler::escape((string) $value);
        }
        $string = (string) $value;
        return match ($context) {
            'url' => TemplateCompiler::escape(UrlPolicy::sanitize($string)),
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $string) === 1 ? $string : '',
            default => TemplateCompiler::escape($string),
        };
    }

    public static function isTruthy(string|int|bool|TrustedHtml|null $value): bool
    {
        if ($value instanceof TrustedHtml) {
            return !$value->isEmpty();
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        return $value !== null && trim($value) !== '' && $value !== '0';
    }
}
