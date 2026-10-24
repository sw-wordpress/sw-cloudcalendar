<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render;

final class RenderResult
{
    public const OK = 'ok';
    public const NOT_FOUND = 'not_found';
    public const INVALID = 'invalid';

    /**
     * @param array<string, string> $css    Scope class => scoped CSS of the templates used.
     * @param array<string, string> $errors Filter errors (parameter => code).
     */
    public function __construct(
        public readonly string $status,
        public readonly string $html = '',
        public readonly array $css = [],
        public readonly bool $cacheHit = false,
        /** Output contains only data of public sources and may be stored in shared caches. */
        public readonly bool $public = false,
        public readonly array $errors = [],
        public readonly int $total = 0,
    ) {
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    public function toJson(): string
    {
        return (string) json_encode(['h' => $this->html, 'c' => $this->css, 't' => $this->total]);
    }

    public static function fromCache(string $json): ?self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_string($data['h'] ?? null) || !is_array($data['c'] ?? null)) {
            return null;
        }
        $css = [];
        foreach ($data['c'] as $scope => $value) {
            if (is_string($scope) && is_string($value) && preg_match('/^swc-[a-z0-9-]+$/', $scope) === 1) {
                $css[$scope] = $value;
            }
        }
        return new self(self::OK, $data['h'], $css, true, true, [], (int) ($data['t'] ?? 0));
    }
}
