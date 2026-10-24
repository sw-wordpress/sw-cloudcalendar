<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render\Template;

use SW\Calendar\Domain\Template\TemplateKind;

/**
 * Serializable AST. Nodes are plain arrays so the cache never needs PHP object deserialization:
 * - ['t', string]                     static, already normalized and safe markup
 * - ['v', name, context]              variable; context: text|attr|url|color|html
 * - ['s', name, inverted, children]   (inverted) section
 */
final class CompiledTemplate
{
    /**
     * @param list<array<int, mixed>> $nodes
     */
    public function __construct(
        public readonly TemplateKind $kind,
        public readonly array $nodes,
        public readonly string $css = '',
    ) {
    }

    public function toJson(): string
    {
        return (string) json_encode(['v' => TemplateCompiler::ENGINE_VERSION, 'k' => $this->kind->value, 'n' => $this->nodes, 'c' => $this->css]);
    }

    /**
     * Restores a cached AST. Anything unexpected returns null and the caller recompiles (cache corruption is safe).
     */
    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['v'] ?? null) !== TemplateCompiler::ENGINE_VERSION || !is_array($data['n'] ?? null)) {
            return null;
        }
        $kind = TemplateKind::tryFrom((string) ($data['k'] ?? ''));
        if ($kind === null || !self::validNodes($data['n'])) {
            return null;
        }
        /** @var list<array<int, mixed>> $nodes */
        $nodes = $data['n'];
        return new self($kind, $nodes, is_string($data['c'] ?? null) ? $data['c'] : '');
    }

    /**
     * @param array<mixed> $nodes
     */
    private static function validNodes(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node[0])) {
                return false;
            }
            $ok = match ($node[0]) {
                't' => is_string($node[1] ?? null),
                'v' => is_string($node[1] ?? null) && in_array($node[2] ?? null, ['text', 'attr', 'url', 'color', 'html'], true),
                's' => is_string($node[1] ?? null) && is_bool($node[2] ?? null) && is_array($node[3] ?? null) && self::validNodes($node[3]),
                default => false,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }
}
