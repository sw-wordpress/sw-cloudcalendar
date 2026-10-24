<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Unit\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SW\Calendar\Application\Render\Template\CompiledTemplate;
use SW\Calendar\Application\Render\Template\TemplateCompiler;
use SW\Calendar\Application\Render\Template\TemplateRenderer;
use SW\Calendar\Application\Render\Template\TemplateValidationException;
use SW\Calendar\Application\Render\Template\TrustedHtml;
use SW\Calendar\Domain\Template\TemplateKind;

final class TemplateCompilerTest extends TestCase
{
    private function render(string $template, array $values, string $kind = 'event'): string
    {
        $compiled = (new TemplateCompiler())->compile($template, $kind);
        return (new TemplateRenderer())->render($compiled, $values);
    }

    /**
     * @return list<string>
     */
    private function errors(string $template, TemplateKind $kind = TemplateKind::Event): array
    {
        return array_map(static fn ($e) => $e->code, (new TemplateCompiler())->validate($template, $kind));
    }

    public function testSpecificationExampleRenders(): void
    {
        $template = <<<'HTML'
<article class="swc-event">
  <h3>{{event.title}}</h3>
  <time>{{event.start_date}}</time>
  {{#event.location}}<p>{{event.location}}</p>{{/event.location}}
  {{^event.location}}<p>Ort wird bekanntgegeben</p>{{/event.location}}
  {{event.attachments.gallery}}
  {{event.attachments.file_list}}
</article>
HTML;
        $html = $this->render($template, [
            'event.title' => 'Gottesdienst <b>',
            'event.start_date' => '1. November',
            'event.location' => '',
            'event.attachments.gallery' => new TrustedHtml('<div class="g"></div>'),
            'event.attachments.file_list' => '<script>x</script>',
        ]);
        self::assertStringContainsString('<h3>Gottesdienst &lt;b&gt;</h3>', $html);
        self::assertStringContainsString('<p>Ort wird bekanntgegeben</p>', $html);
        self::assertStringContainsString('<div class="g"></div>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html, 'Untrusted strings in HTML slots are escaped');
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testSectionTruthiness(): void
    {
        $tpl = '<p>{{#event.location}}at {{event.location}}{{/event.location}}{{^event.all_day}} timed{{/event.all_day}}</p>';
        self::assertSame('<p>at Kirche timed</p>', $this->render($tpl, ['event.location' => 'Kirche', 'event.all_day' => false]));
        self::assertSame('<p></p>', $this->render($tpl, ['event.location' => '  ', 'event.all_day' => true]));
    }

    public function testAttributeContextIsEscaped(): void
    {
        $html = $this->render('<span title="{{event.title}}" class="e {{#event.all_day}}allday{{/event.all_day}}">x</span>', [
            'event.title' => '" onmouseover="alert(1)',
            'event.all_day' => true,
        ]);
        self::assertSame('<span title="&quot; onmouseover=&quot;alert(1)" class="e allday">x</span>', $html);
    }

    public function testUrlPlaceholderSanitized(): void
    {
        $tpl = '<a href="{{event.url}}" target="_blank">x</a>';
        self::assertSame('<a href="" target="_blank" rel="noopener noreferrer">x</a>', $this->render($tpl, ['event.url' => 'javascript:alert(1)']));
        self::assertSame('<a href="https://ex.com/?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">x</a>', $this->render($tpl, ['event.url' => 'https://ex.com/?a=1&b=2']));
        self::assertSame('<a href="" target="_blank" rel="noopener noreferrer">x</a>', $this->render($tpl, ['event.url' => " java\tscript:alert(1)"]));
    }

    public function testColorPlaceholderOnlyAcceptsHex(): void
    {
        $tpl = '<span style="border-color: {{event.color}}">x</span>';
        self::assertSame('<span style="border-color: #aabbcc">x</span>', $this->render($tpl, ['event.color' => '#aabbcc']));
        self::assertSame('<span style="border-color: ">x</span>', $this->render($tpl, ['event.color' => 'red;background:url(x)']));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidTemplates(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>', 'disallowed_tag'],
            'style tag' => ['<style>p{}</style>', 'disallowed_tag'],
            'iframe' => ['<iframe src="x"></iframe>', 'disallowed_tag'],
            'svg' => ['<svg><circle/></svg>', 'disallowed_tag'],
            'event handler' => ['<p onclick="x()">a</p>', 'event_handler'],
            'event handler upper' => ['<p ONLOAD="x()">a</p>', 'event_handler'],
            'unknown attribute' => ['<p formaction="x">a</p>', 'disallowed_attribute'],
            'javascript url' => ['<a href="javascript:alert(1)">a</a>', 'unsafe_url'],
            'entity encoded javascript url' => ['<a href="&#106;avascript:alert(1)">a</a>', 'unsafe_url'],
            'data url' => ['<img src="data:image/svg+xml;base64,AAA">', 'unsafe_url'],
            'text placeholder in href' => ['<a href="{{event.title}}">a</a>', 'placeholder_not_url'],
            'mixed url' => ['<a href="https://x/{{event.id}}">a</a>', 'url_placeholder_only'],
            'placeholder in style' => ['<p style="color: {{event.title}}">a</p>', 'style_placeholder'],
            'css url in style' => ['<p style="background:url(https://evil/x)">a</p>', 'unsafe_css'],
            'html in attribute' => ['<p title="{{event.attachments.gallery}}">a</p>', 'html_in_attribute'],
            'placeholder as attribute name' => ['<p {{event.title}}>a</p>', 'placeholder_in_tag'],
            'unknown placeholder' => ['<p>{{event.password}}</p>', 'unknown_placeholder'],
            'triple mustache' => ['<p>{{{event.title}}}</p>', 'unsupported_syntax'],
            'partial' => ['<p>{{> other}}</p>', 'unsupported_syntax'],
            'function call' => ['<p>{{event.title(1)}}</p>', 'invalid_placeholder'],
            'unclosed section' => ['<p>{{#event.location}}x</p>', 'section_crosses_element'],
            'section crossing element' => ['{{#event.location}}<div>{{/event.location}}</div>', 'section_crosses_element'],
            'mismatched section' => ['{{#event.location}}x{{/event.title}}', 'unexpected_section_end'],
            'unclosed element' => ['<div><p>x</p>', 'unclosed_element'],
            'stray closing tag' => ['</div>', 'mismatched_end_tag'],
            'container placeholder in event' => ['{{calendar.events}}', 'unknown_placeholder'],
            'doctype' => ['<!DOCTYPE html><p>x</p>', 'disallowed_markup'],
            'unclosed placeholder' => ['<p>{{event.title</p>', 'unclosed_placeholder'],
            'target javascript' => ['<a href="/" target="javascript:x">a</a>', 'invalid_attribute_value'],
        ];
    }

    #[DataProvider('invalidTemplates')]
    public function testRejectsUnsafeOrInvalidTemplates(string $template, string $expectedCode): void
    {
        self::assertContains($expectedCode, $this->errors($template));
    }

    public function testCompileThrowsWithPosition(): void
    {
        try {
            (new TemplateCompiler())->compile("<p>ok</p>\n  <p>{{event.nope}}</p>", 'event');
            self::fail('Expected exception');
        } catch (TemplateValidationException $e) {
            self::assertSame(2, $e->errors[0]->line);
            self::assertSame(6, $e->errors[0]->column);
        }
    }

    public function testCommentsAreDroppedAndStrayLessThanEscaped(): void
    {
        self::assertSame('<p>a  &lt; b</p>', $this->render('<p>a <!-- secret --> < b</p>', []));
    }

    public function testContainerPlaceholders(): void
    {
        $html = $this->render('<div class="list">{{calendar.events}}</div>', ['calendar.events' => new TrustedHtml('<ul></ul>')], 'container');
        self::assertSame('<div class="list"><ul></ul></div>', $html);
    }

    public function testAstRoundTripAndCorruptionFallback(): void
    {
        $compiled = (new TemplateCompiler())->compile('<p class="{{#event.all_day}}a{{/event.all_day}}">{{event.title}}</p>', 'event');
        $restored = CompiledTemplate::fromJson($compiled->toJson());
        self::assertNotNull($restored);
        self::assertSame($compiled->nodes, $restored->nodes);
        self::assertNull(CompiledTemplate::fromJson('{"v":"1","k":"event","n":[["x","<script>"]]}'));
        self::assertNull(CompiledTemplate::fromJson('not json'));
        self::assertNull(CompiledTemplate::fromJson('{"v":"0","k":"event","n":[]}'));
    }

    public function testVoidAndBooleanAttributes(): void
    {
        self::assertSame(
            '<details open><summary>x</summary><img src="/a.png" alt=""><br></details>',
            $this->render('<details open><summary>x</summary><img src="/a.png" alt=""/><br></details>', [])
        );
    }
}
