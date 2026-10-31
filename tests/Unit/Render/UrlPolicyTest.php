<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Unit\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SW\Calendar\Application\Render\Template\UrlPolicy;

final class UrlPolicyTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function urlCases(): array
    {
        return [
            'https' => ['https://calendar.example.org/event', true],
            'uppercase https' => ['HTTPS://calendar.example.org', true],
            'http' => ['http://calendar.example.org', true],
            'mailto' => ['mailto:user@example.org', true],
            'telephone' => ['tel:+493712345', true],
            'root relative' => ['/events/1', true],
            'path relative' => ['events/1?month=10', true],
            'anchor' => ['#event-1', true],
            'empty' => ['', true],
            'javascript' => ['javascript:alert(1)', false],
            'obfuscated javascript' => ["java\tscript:alert(1)", false],
            'data URI' => ['data:text/html,evil', false],
            'file URI' => ['file:///etc/passwd', false],
            'unknown scheme' => ['custom+proto:x', false],
            'colon before slash' => ['invalid:scheme/path', false],
            'backslash prefix' => ['\\\\host\\path', false],
        ];
    }

    #[DataProvider('urlCases')]
    public function testUrlAllowlist(string $url, bool $expected): void
    {
        self::assertSame($expected, UrlPolicy::isSafe($url));
        self::assertSame($expected ? trim($url) : '', UrlPolicy::sanitize($url));
    }

    public function testSanitizeTrimsWhitespace(): void
    {
        self::assertSame('https://example.org/path', UrlPolicy::sanitize(" \thttps://example.org/path\n"));
        self::assertSame('', UrlPolicy::sanitize(" \tjavascript:alert(1)\n"));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function absoluteHttpCases(): array
    {
        return [
            'secure' => ['https://calendar.example.org/feed.ics', true],
            'insecure' => ['http://calendar.example.org/feed.ics', true],
            'mixed-case scheme' => ['HTTPS://calendar.example.org/feed.ics', true],
            'relative path' => ['/feed.ics', false],
            'mailto' => ['mailto:user@example.org', false],
            'missing host' => ['https:///feed.ics', false],
            'whitespace in host' => ['https://example.org /feed.ics', false],
            'javascript' => ['javascript:alert(1)', false],
        ];
    }

    #[DataProvider('absoluteHttpCases')]
    public function testAbsoluteHttpValidation(string $url, bool $expected): void
    {
        self::assertSame($expected, UrlPolicy::isHttp($url));
    }
}
