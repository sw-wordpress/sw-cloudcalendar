<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render;

use SW\Calendar\Application\Port\TemplateRepositoryInterface;

/**
 * Presets are copied into editable templates on install, so a fixed preset never reaches existing copies. This
 * upgrader replaces the CSS of a stored template with the current preset CSS, but only when the stored CSS still
 * equals a known earlier shipped version of that preset. Customised templates are never touched.
 */
final class PresetUpgrader
{
    /** Raise when LEGACY_CSS gains entries, so installed sites run the upgrade once more. */
    public const REVISION = 1;

    /**
     * SHA-1 of the whitespace-normalised CSS of earlier preset versions: preset key => kind => hashes.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const LEGACY_CSS = [
        PresetLibrary::IMAGE_RIGHT_LIST => [
            'event' => [
                '93f53f93f499d2c6a7b36662fc01629da2277367',
                'e1cba2551ec4d66952981d650eb1087f1b4cbccc',
            ],
        ],
    ];

    public function __construct(
        private readonly TemplateRepositoryInterface $templates,
        private readonly PresetLibrary $presets,
    ) {
    }

    /**
     * @return int Number of upgraded templates.
     */
    public function upgrade(): int
    {
        $upgraded = 0;
        foreach ($this->templates->all() as $template) {
            $key = $template->presetKey;
            if ($key === null || !isset(self::LEGACY_CSS[$key][$template->kind->value])) {
                continue;
            }
            if (!in_array(self::fingerprint($template->css), self::LEGACY_CSS[$key][$template->kind->value], true)) {
                continue;
            }
            $css = $this->presets->css($key, $template->kind);
            if ($css === '' || self::fingerprint($css) === self::fingerprint($template->css)) {
                continue;
            }
            $this->templates->save($template->id, $template->name, $template->kind, $template->editorMode, $template->body, $css, $template->visualSchema, $key);
            $upgraded++;
        }
        return $upgraded;
    }

    public static function fingerprint(string $css): string
    {
        $normalised = trim((string) preg_replace('/[ \t]+(?=\n)/', '', str_replace("\r\n", "\n", $css)));
        return sha1($normalised);
    }
}
