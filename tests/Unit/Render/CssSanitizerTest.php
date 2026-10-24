<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Unit\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SW\Calendar\Application\Render\Template\CssSanitizer;

final class CssSanitizerTest extends TestCase
{
    public function testScopesSelectorsAndMediaQueries(): void
    {
        $css = "/* c */ .a, p > span { color: red; }\nbody{margin:0}\n@media (max-width: 600px) { .a { display:none } }";
        $scoped = CssSanitizer::scope($css, '.swc-tpl-3');
        self::assertStringContainsString('.swc-tpl-3 .a, .swc-tpl-3 p > span{color: red;}', $scoped);
        self::assertStringContainsString('.swc-tpl-3{margin:0}', $scoped);
        self::assertStringContainsString('@media (max-width: 600px){.swc-tpl-3 .a{display:none}}', $scoped);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function forbidden(): array
    {
        return [
            'url' => ['.a{background:url(https://evil.example/x)}'],
            'url uppercase' => ['.a{background:URL(x)}'],
            'import' => ['@import "x.css";'],
            'font-face' => ['@font-face{font-family:x;src:local(x)}'],
            'expression' => ['.a{width:expression(alert(1))}'],
            'escape' => ['.a{background:\75 rl(x)}'],
            'closing style' => ['.a{}</style><script>'],
            'declarations outside rule' => ['color:red;'],
            'unbalanced' => ['.a{color:red'],
            'page at-rule' => ['@page{margin:0}'],
        ];
    }

    #[DataProvider('forbidden')]
    public function testRejectsDangerousCss(string $css): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CssSanitizer::scope($css, '.swc-tpl-1');
    }
}
