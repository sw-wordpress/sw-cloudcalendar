<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use SW\Calendar\Application\Port\TemplateRepositoryInterface;
use SW\Calendar\Application\Render\PresetLibrary;
use SW\Calendar\Application\Render\PresetUpgrader;
use SW\Calendar\Domain\Template\EditorMode;
use SW\Calendar\Domain\Template\Template;
use SW\Calendar\Domain\Template\TemplateKind;

final class PresetUpgraderTest extends TestCase
{
    private const LEGACY_FIXTURE = __DIR__ . '/../../Fixtures/image-right-list-event-legacy.css';
    private const KEY = PresetLibrary::IMAGE_RIGHT_LIST;

    private PresetLibrary $presets;

    protected function setUp(): void
    {
        $this->presets = new PresetLibrary(dirname(__DIR__, 3) . '/templates/presets');
    }

    public function testReplacesUnmodifiedLegacyCss(): void
    {
        $repo = $this->repo([$this->template(1, $this->legacy())]);
        self::assertSame(1, (new PresetUpgrader($repo, $this->presets))->upgrade());
        self::assertSame($this->presets->css(self::KEY, TemplateKind::Event), $repo->items[1]->css);
        self::assertSame('<b>mine</b>', $repo->items[1]->body);
        self::assertSame('My name', $repo->items[1]->name);
        self::assertSame(self::KEY, $repo->items[1]->presetKey);
    }

    public function testLegacyCssWithoutFirstRuleAndCrLfIsRecognised(): void
    {
        $lines = explode("\n", $this->legacy());
        array_shift($lines);
        $old = str_replace("\n", "\r\n", implode("\n", $lines)) . "  \r\n";
        $repo = $this->repo([$this->template(1, $old)]);
        self::assertSame(1, (new PresetUpgrader($repo, $this->presets))->upgrade());
    }

    public function testCustomisedCssIsKept(): void
    {
        $custom = $this->legacy() . "\n.swc-right-row { color: red; }";
        $repo = $this->repo([$this->template(1, $custom)]);
        self::assertSame(0, (new PresetUpgrader($repo, $this->presets))->upgrade());
        self::assertSame($custom, $repo->items[1]->css);
    }

    public function testCurrentCssTemplatesWithoutPresetAndOtherKindsAreSkipped(): void
    {
        $repo = $this->repo([
            $this->template(1, $this->presets->css(self::KEY, TemplateKind::Event)),
            $this->template(2, $this->legacy(), null),
            $this->template(3, $this->legacy(), self::KEY, TemplateKind::Container),
            $this->template(4, $this->legacy(), PresetLibrary::CLASSIC_LIST),
        ]);
        self::assertSame(0, (new PresetUpgrader($repo, $this->presets))->upgrade());
        self::assertSame($this->legacy(), $repo->items[2]->css);
    }

    public function testSecondRunChangesNothing(): void
    {
        $repo = $this->repo([$this->template(1, $this->legacy())]);
        $upgrader = new PresetUpgrader($repo, $this->presets);
        self::assertSame(1, $upgrader->upgrade());
        self::assertSame(0, $upgrader->upgrade());
    }

    public function testCurrentPresetCssDiffersFromLegacyAndKeepsBaselineAlignment(): void
    {
        $current = $this->presets->css(self::KEY, TemplateKind::Event);
        self::assertNotSame(PresetUpgrader::fingerprint($this->legacy()), PresetUpgrader::fingerprint($current));
        self::assertStringContainsString('align-items: baseline', $current);
    }

    private function legacy(): string
    {
        return (string) file_get_contents(self::LEGACY_FIXTURE);
    }

    private function template(int $id, string $css, ?string $key = self::KEY, TemplateKind $kind = TemplateKind::Event): Template
    {
        return new Template($id, 'My name', $kind, EditorMode::Html, '<b>mine</b>', $css, null, 1, null, $key);
    }

    /**
     * @param list<Template> $templates
     */
    private function repo(array $templates): object
    {
        return new class ($templates) implements TemplateRepositoryInterface {
            /** @var array<int, Template> */
            public array $items = [];

            /** @param list<Template> $templates */
            public function __construct(array $templates)
            {
                foreach ($templates as $t) {
                    $this->items[$t->id] = $t;
                }
            }

            public function find(int $id): ?Template
            {
                return $this->items[$id] ?? null;
            }

            public function all(?TemplateKind $kind = null): array
            {
                return array_values($this->items);
            }

            public function save(?int $id, string $name, TemplateKind $kind, EditorMode $mode, string $body, string $css, ?array $schema, ?string $preset_key = null): int
            {
                $this->items[(int) $id] = new Template((int) $id, $name, $kind, $mode, $body, $css, $schema, 2, null, $preset_key);
                return (int) $id;
            }
        };
    }
}
