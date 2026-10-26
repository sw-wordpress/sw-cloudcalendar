<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SW\Calendar\Application\Security\UrlGuard;
use SW\Calendar\Domain\CalendarException;

final class UrlGuardTest extends TestCase
{
    private function guard(array $dns = []): UrlGuard
    {
        return new UrlGuard(static fn (string $host): array => $dns[$host] ?? ['93.184.216.34']);
    }

    /**
     * @return array<string, array{0: string, 1?: array<string, list<string>>}>
     */
    public static function blocked(): array
    {
        return [
            'http without opt-in' => ['http://calendar.example.org/x.ics'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher' => ['gopher://example.org/'],
            'localhost' => ['https://localhost/feed.ics'],
            'loopback ip' => ['https://127.0.0.1/feed.ics'],
            'private ip' => ['https://10.1.2.3/feed.ics'],
            'link local metadata' => ['https://169.254.169.254/latest/meta-data'],
            'cgnat' => ['https://100.64.0.1/x'],
            'ipv6 loopback' => ['https://[::1]/x'],
            'ipv6 unique local' => ['https://[fd00::1]/x'],
            'mapped ipv4' => ['https://[::ffff:10.0.0.1]/x'],
            'dns to private' => ['https://intranet.example.org/x', ['intranet.example.org' => ['192.168.1.5']]],
            'dns mixed' => ['https://mixed.example.org/x', ['mixed.example.org' => ['93.184.216.34', '10.0.0.1']]],
            'unresolvable' => ['https://nothing.example.org/x', ['nothing.example.org' => []]],
            'odd port' => ['https://calendar.example.org:22/x'],
            'credentials in url' => ['https://user:pass@calendar.example.org/x'],
            'internal tld' => ['https://db.internal/x'],
        ];
    }

    #[DataProvider('blocked')]
    public function testBlocksUnsafeTargets(string $url, array $dns = []): void
    {
        $this->expectException(CalendarException::class);
        $this->guard($dns)->check($url);
    }

    public function testAllowsPublicHttps(): void
    {
        self::assertSame(['93.184.216.34'], $this->guard()->check('https://calendar.example.org/feed.ics'));
        self::assertSame(['93.184.216.34'], $this->guard()->check('http://calendar.example.org/feed.ics', true));
    }

    public function testProviderHostAllowlist(): void
    {
        $hosts = ['www.googleapis.com', '.googleusercontent.com'];
        self::assertNotEmpty($this->guard()->check('https://doc-0s.googleusercontent.com/x', false, $hosts));
        $this->expectException(CalendarException::class);
        $this->guard()->check('https://googleusercontent.com.evil.example/x', false, $hosts);
    }
}
