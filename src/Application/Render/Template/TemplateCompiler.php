<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render\Template;

use SW\Calendar\Domain\Template\TemplateKind;

/**
 * Context aware compiler for the restricted Mustache-like template language (SPECIFICATIONS.md §11).
 *
 * The compiler tokenizes the HTML itself and therefore knows whether a placeholder appears in a text node, a plain
 * attribute, a URL attribute or a style attribute. Markup is validated against a tag/attribute allowlist and
 * re-serialized in a normalized form; the output is a data-only AST, never PHP code.
 *
 * Rules (docs/templates.md):
 * - `{{name}}`, `{{#name}}…{{/name}}`, `{{^name}}…{{/name}}` with registered names only.
 * - Sections must not cross element boundaries and must be closed inside the same attribute value.
 * - href/src: a static safe URL or exactly one URL placeholder.
 * - style: static, sanitized declarations; placeholders only of type colour.
 * - Trusted HTML placeholders only in text context.
 * - No comments in the output, no scripts, no event handlers, no unknown tags or attributes.
 */
final class TemplateCompiler implements TemplateCompilerInterface
{
    public const ENGINE_VERSION = '1';
    public const ALLOWLIST_VERSION = '1';
    public const MAX_LENGTH = 102400;
    private const MAX_DEPTH = 64;

    private const VOID = ['br', 'hr', 'img', 'wbr'];

    private const TAGS = [
        'div', 'span', 'p', 'a', 'strong', 'em', 'b', 'i', 'u', 's', 'small', 'br', 'hr', 'wbr',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'article', 'section', 'header', 'footer', 'aside', 'nav', 'main', 'figure', 'figcaption',
        'img', 'time', 'address', 'blockquote', 'q', 'cite', 'abbr', 'mark', 'sup', 'sub', 'code',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'details', 'summary',
    ];

    private const GLOBAL_ATTRIBUTES = ['class', 'id', 'title', 'lang', 'dir', 'role', 'hidden', 'tabindex', 'style'];

    private const TAG_ATTRIBUTES = [
        'a' => ['href', 'target', 'rel', 'hreflang'],
        'img' => ['src', 'alt', 'width', 'height', 'loading', 'decoding'],
        'time' => ['datetime'],
        'td' => ['colspan', 'rowspan', 'headers'],
        'th' => ['colspan', 'rowspan', 'headers', 'scope'],
        'ol' => ['start', 'reversed', 'type'],
        'details' => ['open'],
        'blockquote' => ['cite'],
        'q' => ['cite'],
    ];

    private const URL_ATTRIBUTES = ['href', 'src', 'cite'];
    private const BOOLEAN_ATTRIBUTES = ['hidden', 'open', 'reversed'];

    private string $src = '';
    private int $pos = 0;
    private int $len = 0;
    private TemplateKind $kind = TemplateKind::Event;
    /** @var list<TemplateSyntaxError> */
    private array $errors = [];
    /** @var list<array{name: string, offset: int}> */
    private array $elements = [];
    /** @var list<array{name: string, inverted: bool, depth: int, offset: int}> */
    private array $sections = [];
    /** @var list<list<array<int, mixed>>> */
    private array $out = [];

    public function __construct(private readonly PlaceholderRegistry $registry = new PlaceholderRegistry())
    {
    }

    /**
     * @throws TemplateValidationException
     */
    public function compile(string $template, string $kind): CompiledTemplate
    {
        $templateKind = TemplateKind::tryFrom($kind);
        if ($templateKind === null) {
            throw new TemplateValidationException([new TemplateSyntaxError('invalid_kind', 'Unknown template kind.', 1, 1)]);
        }
        $nodes = $this->run($template, $templateKind);
        if ($this->errors !== []) {
            throw new TemplateValidationException($this->errors);
        }
        return new CompiledTemplate($templateKind, $nodes);
    }

    /**
     * @return list<TemplateSyntaxError>
     */
    public function validate(string $template, TemplateKind $kind): array
    {
        $this->run($template, $kind);
        return $this->errors;
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function run(string $template, TemplateKind $kind): array
    {
        $this->src = str_replace(["\r\n", "\r"], "\n", $template);
        $this->pos = 0;
        $this->len = strlen($this->src);
        $this->kind = $kind;
        $this->errors = [];
        $this->elements = [];
        $this->sections = [];
        $this->out = [[]];

        if ($this->len > self::MAX_LENGTH) {
            $this->error('too_long', 'The template is too long.', 0);
            return [];
        }
        if (!mb_check_encoding($this->src, 'UTF-8')) {
            $this->error('invalid_encoding', 'The template is not valid UTF-8.', 0);
            return [];
        }

        while ($this->pos < $this->len) {
            $lt = strpos($this->src, '<', $this->pos);
            $mu = strpos($this->src, '{{', $this->pos);
            $next = min($lt === false ? $this->len : $lt, $mu === false ? $this->len : $mu);
            if ($next > $this->pos) {
                $this->appendStatic(substr($this->src, $this->pos, $next - $this->pos));
                $this->pos = $next;
            }
            if ($this->pos >= $this->len) {
                break;
            }
            if ($mu !== false && $mu === $this->pos) {
                $this->textMustache();
            } else {
                $this->tag();
            }
        }

        foreach ($this->elements as $element) {
            $this->error('unclosed_element', 'Element <' . $element['name'] . '> is not closed.', $element['offset'], ['tag' => $element['name']]);
        }
        foreach ($this->sections as $section) {
            $this->error('unclosed_section', 'Section {{#' . $section['name'] . '}} is not closed.', $section['offset'], ['name' => $section['name']]);
        }
        return $this->out[0];
    }

    private function textMustache(): void
    {
        $start = $this->pos;
        $tag = $this->readMustache();
        if ($tag === null) {
            return;
        }
        [$sigil, $name] = $tag;
        $type = $this->registry->find($name, $this->kind);
        if ($type === null) {
            $this->error('unknown_placeholder', 'Unknown placeholder "' . $name . '".', $start, ['name' => $name]);
            return;
        }
        if ($sigil === '') {
            $this->append(['v', $name, $type === PlaceholderType::Html ? 'html' : 'text']);
            return;
        }
        if ($sigil === '#' || $sigil === '^') {
            $this->sections[] = ['name' => $name, 'inverted' => $sigil === '^', 'depth' => count($this->elements), 'offset' => $start];
            $this->out[] = [];
            return;
        }
        $this->closeSection($name, $start);
    }

    private function closeSection(string $name, int $offset): void
    {
        $open = end($this->sections);
        if ($open === false || $open['name'] !== $name) {
            $this->error('unexpected_section_end', 'Closing {{/' . $name . '}} does not match an open section.', $offset, ['name' => $name]);
            return;
        }
        if ($open['depth'] !== count($this->elements)) {
            $this->error('section_crosses_element', 'Section {{#' . $name . '}} must be closed inside the same element.', $offset, ['name' => $name]);
            return;
        }
        array_pop($this->sections);
        $children = array_pop($this->out);
        $this->append(['s', $name, $open['inverted'], $children ?? []]);
    }

    /**
     * Reads `{{…}}` at the current position.
     *
     * @return array{0: string, 1: string}|null [sigil, name]
     */
    private function readMustache(): ?array
    {
        $start = $this->pos;
        $end = strpos($this->src, '}}', $start + 2);
        if ($end === false) {
            $this->error('unclosed_placeholder', 'Placeholder is not closed with "}}".', $start);
            $this->pos = $this->len;
            return null;
        }
        $this->pos = $end + 2;
        $inner = trim(substr($this->src, $start + 2, $end - $start - 2));
        return $this->parseMustacheInner($inner, $start);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parseMustacheInner(string $inner, int $offset): ?array
    {
        if ($inner !== '' && in_array($inner[0], ['{', '!', '>', '&', '=', '<', '$'], true)) {
            $this->error('unsupported_syntax', 'Unsupported template syntax "{{' . $inner[0] . '".', $offset);
            return null;
        }
        $sigil = '';
        if ($inner !== '' && in_array($inner[0], ['#', '^', '/'], true)) {
            $sigil = $inner[0];
            $inner = trim(substr($inner, 1));
        }
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $inner) !== 1) {
            $this->error('invalid_placeholder', 'Invalid placeholder name "' . mb_substr($inner, 0, 60) . '".', $offset);
            return null;
        }
        return [$sigil, $inner];
    }

    private function tag(): void
    {
        $start = $this->pos;
        if (substr_compare($this->src, '<!--', $start, 4) === 0) {
            $end = strpos($this->src, '-->', $start + 4);
            if ($end === false) {
                $this->error('unclosed_comment', 'HTML comment is not closed.', $start);
                $this->pos = $this->len;
                return;
            }
            // Comments are dropped: they could hide conditional comments or confuse later parsers.
            $this->pos = $end + 3;
            return;
        }
        $nextChar = $this->src[$start + 1] ?? '';
        if ($nextChar === '/') {
            $this->endTag();
            return;
        }
        if (ctype_alpha($nextChar)) {
            $this->startTag();
            return;
        }
        if ($nextChar === '!' || $nextChar === '?') {
            $this->error('disallowed_markup', 'Doctype, CDATA and processing instructions are not allowed.', $start);
            $close = strpos($this->src, '>', $start);
            $this->pos = $close === false ? $this->len : $close + 1;
            return;
        }
        $this->appendStatic('&lt;');
        $this->pos++;
    }

    private function endTag(): void
    {
        $start = $this->pos;
        if (preg_match('/\G<\/([a-zA-Z][a-zA-Z0-9-]*)\s*>/', $this->src, $m, 0, $start) !== 1) {
            $this->error('malformed_tag', 'Malformed closing tag.', $start);
            $close = strpos($this->src, '>', $start);
            $this->pos = $close === false ? $this->len : $close + 1;
            return;
        }
        $this->pos = $start + strlen($m[0]);
        $name = strtolower($m[1]);
        if (!in_array($name, self::TAGS, true)) {
            $this->error('disallowed_tag', 'The element <' . $name . '> is not allowed.', $start, ['tag' => $name]);
            return;
        }
        if (in_array($name, self::VOID, true)) {
            $this->error('void_end_tag', '<' . $name . '> must not have a closing tag.', $start, ['tag' => $name]);
            return;
        }
        $top = end($this->elements);
        if ($top === false || $top['name'] !== $name) {
            $this->error('mismatched_end_tag', 'Closing </' . $name . '> does not match the open element.', $start, ['tag' => $name]);
            return;
        }
        $section = end($this->sections);
        if ($section !== false && $section['depth'] === count($this->elements)) {
            $this->error('section_crosses_element', 'Section {{#' . $section['name'] . '}} must be closed inside the same element.', $start, ['name' => $section['name']]);
            return;
        }
        array_pop($this->elements);
        $this->appendStatic('</' . $name . '>');
    }

    private function startTag(): void
    {
        $start = $this->pos;
        if (preg_match('/\G<([a-zA-Z][a-zA-Z0-9-]*)/', $this->src, $m, 0, $start) !== 1) {
            $this->appendStatic('&lt;');
            $this->pos++;
            return;
        }
        $name = strtolower($m[1]);
        $this->pos = $start + strlen($m[0]);
        $allowed = in_array($name, self::TAGS, true);
        if (!$allowed) {
            $this->error('disallowed_tag', 'The element <' . $name . '> is not allowed.', $start, ['tag' => $name]);
        }

        /** @var list<array<int, mixed>> $attributeNodes */
        $attributeNodes = [];
        $seen = [];
        $selfClosing = false;
        $target = null;
        $rel = null;
        while (true) {
            $this->skipWhitespace();
            if ($this->pos >= $this->len) {
                $this->error('malformed_tag', 'Tag <' . $name . '> is not closed.', $start);
                return;
            }
            $char = $this->src[$this->pos];
            if ($char === '>') {
                $this->pos++;
                break;
            }
            if ($char === '/' && ($this->src[$this->pos + 1] ?? '') === '>') {
                $selfClosing = true;
                $this->pos += 2;
                break;
            }
            if (substr_compare($this->src, '{{', $this->pos, 2) === 0) {
                $this->error('placeholder_in_tag', 'Placeholders are only allowed inside attribute values.', $this->pos);
                $end = strpos($this->src, '}}', $this->pos);
                $this->pos = $end === false ? $this->len : $end + 2;
                continue;
            }
            $attrStart = $this->pos;
            if (preg_match('/\G[^\s"\'>\/=]+/', $this->src, $am, 0, $this->pos) !== 1) {
                $this->error('malformed_tag', 'Malformed attribute in <' . $name . '>.', $this->pos);
                $close = strpos($this->src, '>', $this->pos);
                $this->pos = $close === false ? $this->len : $close + 1;
                return;
            }
            $attr = strtolower($am[0]);
            $this->pos += strlen($am[0]);
            $this->skipWhitespace();
            $value = null;
            $valueOffset = $this->pos;
            if (($this->src[$this->pos] ?? '') === '=') {
                $this->pos++;
                $this->skipWhitespace();
                [$value, $valueOffset] = $this->readAttributeValue();
                if ($value === null) {
                    return;
                }
            }
            if (isset($seen[$attr])) {
                $this->error('duplicate_attribute', 'Duplicate attribute "' . $attr . '".', $attrStart, ['attribute' => $attr]);
                continue;
            }
            $seen[$attr] = true;
            if (!$allowed) {
                continue;
            }
            if (!$this->attributeAllowed($name, $attr, $attrStart)) {
                continue;
            }
            if ($attr === 'target') {
                $target = $value;
                if (!in_array($value, ['_blank', '_self', '_parent', '_top'], true)) {
                    $this->error('invalid_attribute_value', 'Invalid target value.', $valueOffset, ['attribute' => $attr]);
                }
                continue;
            }
            if ($attr === 'rel') {
                $rel = $value;
                if ($value === null || str_contains($value, '{{')) {
                    $this->error('invalid_attribute_value', 'The rel attribute must be static.', $valueOffset, ['attribute' => $attr]);
                }
                continue;
            }
            $nodes = $this->compileAttribute($attr, $value, $valueOffset);
            if ($nodes !== null) {
                array_push($attributeNodes, ...$nodes);
            }
        }

        if (!$allowed) {
            return;
        }
        if ($target !== null && in_array($target, ['_blank', '_self', '_parent', '_top'], true)) {
            $attributeNodes[] = ['t', ' target="' . $target . '"'];
            if ($target === '_blank') {
                $relParts = preg_split('/\s+/', strtolower(trim((string) $rel))) ?: [];
                $rel = implode(' ', array_values(array_unique(array_filter(array_merge($relParts, ['noopener', 'noreferrer'])))));
            }
        }
        if ($rel !== null && !str_contains($rel, '{{')) {
            $attributeNodes[] = ['t', ' rel="' . self::escape(self::decode($rel)) . '"'];
        }

        $void = in_array($name, self::VOID, true);
        if ($selfClosing && !$void) {
            $this->error('self_closing_element', '<' . $name . ' /> must be written with a closing tag.', $start, ['tag' => $name]);
            return;
        }
        $this->appendStatic('<' . $name);
        foreach ($attributeNodes as $node) {
            $this->append($node);
        }
        $this->appendStatic('>');
        if (!$void) {
            if (count($this->elements) >= self::MAX_DEPTH) {
                $this->error('too_deep', 'Elements are nested too deeply.', $start);
            }
            $this->elements[] = ['name' => $name, 'offset' => $start];
        }
    }

    /**
     * @return array{0: string|null, 1: int} Raw value and its offset; null value on error.
     */
    private function readAttributeValue(): array
    {
        $quote = $this->src[$this->pos] ?? '';
        if ($quote === '"' || $quote === "'") {
            $end = strpos($this->src, $quote, $this->pos + 1);
            if ($end === false) {
                $this->error('malformed_tag', 'Attribute value is not closed.', $this->pos);
                $this->pos = $this->len;
                return [null, $this->pos];
            }
            $offset = $this->pos + 1;
            $value = substr($this->src, $offset, $end - $offset);
            $this->pos = $end + 1;
            return [$value, $offset];
        }
        if (preg_match('/\G[^\s"\'=<>`]+/', $this->src, $m, 0, $this->pos) !== 1) {
            $this->error('malformed_tag', 'Missing attribute value.', $this->pos);
            $close = strpos($this->src, '>', $this->pos);
            $this->pos = $close === false ? $this->len : $close + 1;
            return [null, $this->pos];
        }
        $offset = $this->pos;
        $this->pos += strlen($m[0]);
        return [$m[0], $offset];
    }

    private function attributeAllowed(string $tag, string $attr, int $offset): bool
    {
        if (str_starts_with($attr, 'on')) {
            $this->error('event_handler', 'Event handler attributes such as "' . $attr . '" are not allowed.', $offset, ['attribute' => $attr]);
            return false;
        }
        if (
            in_array($attr, self::GLOBAL_ATTRIBUTES, true)
            || in_array($attr, self::TAG_ATTRIBUTES[$tag] ?? [], true)
            || preg_match('/^(aria|data)-[a-z0-9-]+$/', $attr) === 1
        ) {
            return true;
        }
        $this->error('disallowed_attribute', 'The attribute "' . $attr . '" is not allowed on <' . $tag . '>.', $offset, ['attribute' => $attr, 'tag' => $tag]);
        return false;
    }

    /**
     * @return list<array<int, mixed>>|null
     */
    private function compileAttribute(string $attr, ?string $value, int $offset): ?array
    {
        if ($value === null) {
            if (!in_array($attr, self::BOOLEAN_ATTRIBUTES, true)) {
                return [['t', ' ' . $attr . '=""']];
            }
            return [['t', ' ' . $attr]];
        }
        if (in_array($attr, self::URL_ATTRIBUTES, true)) {
            return $this->compileUrlAttribute($attr, $value, $offset);
        }
        if ($attr === 'style') {
            return $this->compileStyleAttribute($value, $offset);
        }
        $nodes = $this->compileAttributeValue($attr, $value, $offset);
        if ($nodes === null) {
            return null;
        }
        return array_merge([['t', ' ' . $attr . '="']], $nodes, [['t', '"']]);
    }

    /**
     * @return list<array<int, mixed>>|null
     */
    private function compileUrlAttribute(string $attr, string $value, int $offset): ?array
    {
        if (str_contains($value, '{{')) {
            if (preg_match('/^\s*\{\{\s*([a-z][a-z0-9_.]*)\s*\}\}\s*$/', $value, $m) !== 1) {
                $this->error('url_placeholder_only', 'A URL attribute must contain a static URL or exactly one URL placeholder.', $offset, ['attribute' => $attr]);
                return null;
            }
            $type = $this->registry->find($m[1], $this->kind);
            if ($type !== PlaceholderType::Url) {
                $this->error('placeholder_not_url', 'Only URL placeholders are allowed in "' . $attr . '".', $offset, ['attribute' => $attr, 'name' => $m[1]]);
                return null;
            }
            return [['t', ' ' . $attr . '="'], ['v', $m[1], 'url'], ['t', '"']];
        }
        $decoded = self::decode($value);
        if (!UrlPolicy::isSafe($decoded)) {
            $this->error('unsafe_url', 'The URL scheme is not allowed.', $offset, ['attribute' => $attr]);
            return null;
        }
        return [['t', ' ' . $attr . '="' . self::escape($decoded) . '"']];
    }

    /**
     * @return list<array<int, mixed>>|null
     */
    private function compileStyleAttribute(string $value, int $offset): ?array
    {
        $parts = preg_split('/(\{\{[^}]*\}\})/', $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $probe = '';
        $nodes = [['t', ' style="']];
        foreach ($parts as $part) {
            if (str_starts_with($part, '{{')) {
                $tag = $this->parseMustacheInner(trim(substr($part, 2, -2)), $offset);
                if ($tag === null) {
                    return null;
                }
                if ($tag[0] !== '' || $this->registry->find($tag[1], $this->kind) !== PlaceholderType::Color) {
                    $this->error('style_placeholder', 'Only colour placeholders are allowed in style attributes.', $offset, ['name' => $tag[1]]);
                    return null;
                }
                $probe .= '#000000';
                $nodes[] = ['v', $tag[1], 'color'];
                continue;
            }
            $probe .= self::decode($part);
            $nodes[] = ['t', self::escape(self::decode($part))];
        }
        $cssError = CssSanitizer::validateDeclarations($probe);
        if ($cssError !== null) {
            $this->error('unsafe_css', 'The style attribute contains forbidden CSS (' . $cssError . ').', $offset);
            return null;
        }
        $nodes[] = ['t', '"'];
        return $nodes;
    }

    /**
     * Compiles a plain attribute value with text placeholders and balanced sections.
     *
     * @return list<array<int, mixed>>|null
     */
    private function compileAttributeValue(string $attr, string $value, int $offset): ?array
    {
        /** @var list<list<array<int, mixed>>> $stack */
        $stack = [[]];
        /** @var list<array{0: string, 1: bool}> $open */
        $open = [];
        $parts = preg_split('/(\{\{.*?\}\})/s', $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $part) {
            $level = count($stack) - 1;
            if (!str_starts_with($part, '{{')) {
                if (str_contains($part, '{{') || str_contains($part, '}}')) {
                    $this->error('unclosed_placeholder', 'Placeholder in attribute "' . $attr . '" is not closed.', $offset);
                    return null;
                }
                $stack[$level][] = ['t', self::escape(self::decode($part))];
                continue;
            }
            $tag = $this->parseMustacheInner(trim(substr($part, 2, -2)), $offset);
            if ($tag === null) {
                return null;
            }
            [$sigil, $name] = $tag;
            $type = $this->registry->find($name, $this->kind);
            if ($type === null) {
                $this->error('unknown_placeholder', 'Unknown placeholder "' . $name . '".', $offset, ['name' => $name]);
                return null;
            }
            if ($sigil === '') {
                if ($type === PlaceholderType::Html) {
                    $this->error('html_in_attribute', 'HTML components cannot be used in attributes.', $offset, ['name' => $name]);
                    return null;
                }
                $stack[$level][] = ['v', $name, 'attr'];
            } elseif ($sigil === '/') {
                $last = end($open);
                if ($last === false || $last[0] !== $name) {
                    $this->error('unexpected_section_end', 'Closing {{/' . $name . '}} does not match an open section.', $offset, ['name' => $name]);
                    return null;
                }
                array_pop($open);
                $children = array_pop($stack);
                $stack[count($stack) - 1][] = ['s', $name, $last[1], $children ?? []];
            } else {
                $open[] = [$name, $sigil === '^'];
                $stack[] = [];
            }
        }
        if ($open !== []) {
            $this->error('unclosed_section', 'Section in attribute "' . $attr . '" is not closed.', $offset, ['name' => $open[0][0]]);
            return null;
        }
        return $stack[0];
    }

    private function skipWhitespace(): void
    {
        while ($this->pos < $this->len && ctype_space($this->src[$this->pos])) {
            $this->pos++;
        }
    }

    private function appendStatic(string $html): void
    {
        $this->append(['t', $html]);
    }

    /**
     * @param array<int, mixed> $node
     */
    private function append(array $node): void
    {
        $level = count($this->out) - 1;
        $last = array_key_last($this->out[$level]);
        if ($node[0] === 't' && $last !== null && $this->out[$level][$last][0] === 't') {
            $this->out[$level][$last][1] .= $node[1];
            return;
        }
        $this->out[$level][] = $node;
    }

    /**
     * @param array<string, string> $params
     */
    private function error(string $code, string $message, int $offset, array $params = []): void
    {
        $before = substr($this->src, 0, max(0, $offset));
        $line = substr_count($before, "\n") + 1;
        $lineStart = strrpos($before, "\n");
        $column = $offset - ($lineStart === false ? -1 : $lineStart);
        $this->errors[] = new TemplateSyntaxError($code, $message, $line, $column, $params);
    }

    private static function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
