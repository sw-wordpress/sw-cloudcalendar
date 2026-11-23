<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- loads the wpdb stand-in used by these tests.

/**
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use SW\Calendar\Domain\Template\EditorMode;
use SW\Calendar\Domain\Template\TemplateKind;
use SW\Calendar\Infrastructure\Database\TemplateRepository;

require_once dirname(__DIR__, 2) . '/Support/FakeWpdb.php';

final class TemplateRepositoryTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb          = new \wpdb();
        $GLOBALS['wpdb']     = $this->wpdb;
    }

    public function testInsertWithNullSchemaWritesSqlNull(): void
    {
        $repo = new TemplateRepository();
        $id   = $repo->save(null, 'T', TemplateKind::Event, EditorMode::Html, '<p></p>', '', null, null);
        self::assertSame(1, $id);
        $sql = $this->lastQuery();
        self::assertStringContainsString('`schema_json` = NULL', $sql);
        self::assertStringNotContainsString("`schema_json` = ''", $sql);
    }

    public function testUpdateWithNullSchemaWritesSqlNullNotEmptyString(): void
    {
        $repo = new TemplateRepository();
        $repo->save(7, 'T', TemplateKind::Event, EditorMode::Html, '<p></p>', '', null, null);
        $sql = $this->lastQuery();
        self::assertStringContainsString('schema_json = NULL', $sql);
        self::assertStringNotContainsString("schema_json = ''", $sql);
        self::assertStringContainsString('WHERE id = 7', $sql);
    }

    public function testUpdateWithSchemaWritesJson(): void
    {
        $repo = new TemplateRepository();
        $repo->save(7, 'T', TemplateKind::Event, EditorMode::Visual, '<p></p>', '', array( 'blocks' => array() ), null);
        $sql = $this->lastQuery();
        self::assertStringContainsString('schema_json =', $sql);
        self::assertStringNotContainsString('schema_json = NULL', $sql);
        self::assertStringContainsString('blocks', $sql);
    }

    private function lastQuery(): string
    {
        self::assertNotEmpty($this->wpdb->queries);
        return $this->wpdb->queries[ array_key_last($this->wpdb->queries) ];
    }
}
