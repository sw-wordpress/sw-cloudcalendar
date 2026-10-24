<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render;

use SW\Calendar\Domain\Template\TemplateKind;
use SW\Calendar\Domain\View\DisplayMode;

/**
 * Built-in design presets (SPECIFICATIONS.md §11.4) stored as plain files in `templates/presets/<key>/`:
 * `preset.json` (name, mode), `{container,event,detail}.html` and optional `{kind}.css`.
 */
final class PresetLibrary
{
    public const CLASSIC_LIST = 'classic-list';
    public const EVENT_CARDS = 'event-cards';
    public const MONTH_GRID = 'month-grid';
    public const TIMELINE_LIST = 'timeline-list';

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return [self::CLASSIC_LIST, self::EVENT_CARDS, self::MONTH_GRID, self::TIMELINE_LIST];
    }

    public static function defaultFor(DisplayMode $mode): string
    {
        return match ($mode) {
            DisplayMode::Month => self::MONTH_GRID,
            DisplayMode::Cards => self::EVENT_CARDS,
            DisplayMode::List => self::CLASSIC_LIST,
        };
    }

    /**
     * @return array{name: string, mode: string, description: string}
     */
    public function meta(string $key): array
    {
        $json = $this->read($key, 'preset.json');
        $data = $json === null ? null : json_decode($json, true);
        return [
            'name' => is_array($data) ? (string) ($data['name'] ?? $key) : $key,
            'mode' => is_array($data) ? (string) ($data['mode'] ?? 'list') : 'list',
            'description' => is_array($data) ? (string) ($data['description'] ?? '') : '',
        ];
    }

    public function body(string $key, TemplateKind $kind): ?string
    {
        return $this->read($key, $kind->value . '.html');
    }

    public function css(string $key, TemplateKind $kind): string
    {
        return $this->read($key, $kind->value . '.css') ?? '';
    }

    private function read(string $key, string $file): ?string
    {
        if (!in_array($key, $this->keys(), true)) {
            return null;
        }
        $path = rtrim($this->directory, '/') . '/' . $key . '/' . $file;
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $content = file_get_contents($path);
        return $content === false ? null : $content;
    }
}
