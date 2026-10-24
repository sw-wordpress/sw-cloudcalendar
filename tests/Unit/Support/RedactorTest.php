<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SW\Calendar\Support\Redactor;

final class RedactorTest extends TestCase
{
    public function testRemovesTokensUrlsAndAuthHeaders(): void
    {
        $text = Redactor::text('{"error":"invalid_grant","refresh_token":"1//abc"} Authorization: Bearer ya29.a0AfH6SM~x/y= https://graph.microsoft.com/v1.0/me/events?$deltatoken=XYZ access_token=secret&x=1');
        self::assertStringNotContainsString('1//abc', $text);
        self::assertStringNotContainsString('ya29', $text);
        self::assertStringNotContainsString('XYZ', $text);
        self::assertStringNotContainsString('secret', $text);
        self::assertStringNotContainsString('graph.microsoft.com', $text);
        self::assertStringContainsString('invalid_grant', $text);
    }

    public function testTruncates(): void
    {
        self::assertSame(11, mb_strlen(Redactor::text(str_repeat('a', 50), 10)));
    }
}
