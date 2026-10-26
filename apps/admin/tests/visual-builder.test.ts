import { describe, expect, it } from 'vitest';
import {
  BLOCKS_BY_KIND,
  canEditVisually,
  createBlock,
  defaultSchema,
  escapeText,
  generateHtml,
  moveBlock,
  parseSchema,
  sanitizeClassList,
} from '../src/features/templates/visualBuilder';
import type { Block, VisualSchema } from '../src/features/templates/visualBuilder';

function schema(kind: VisualSchema['kind'], blocks: Block[], colorAccent = false): VisualSchema {
  return { version: 1, kind, root: { cssClass: '', colorAccent }, blocks };
}

describe('escaping', () => {
  it('escapes markup and neutralises placeholder braces in static text', () => {
    expect(escapeText('<b>"A" & \'B\'</b>')).toBe(
      '&lt;b&gt;&quot;A&quot; &amp; &#39;B&#39;&lt;/b&gt;',
    );
    expect(escapeText('{{event.title}}')).toBe('&#123;&#123;event.title&#125;&#125;');
  });
  it('reduces class lists to safe tokens', () => {
    expect(sanitizeClassList(' a-b  c_d "x" <y> 9z onclick=1')).toBe('a-b c_d x y onclick1');
    expect(sanitizeClassList('" onmouseover="alert(1)')).toBe('onmouseoveralert1');
  });
});

describe('generateHtml', () => {
  it('generates event markup with placeholders and sections', () => {
    const title = createBlock('title', 'event');
    const location = { ...createBlock('location', 'event'), label: 'Ort:' } as Block;
    const html = generateHtml(schema('event', [title, location], true));
    expect(html).toContain(
      '<article class="swc-v swc-v-event" style="border-left: 4px solid {{event.color}}">',
    );
    expect(html).toContain(
      '<h3 class="swc-v-title"><a href="{{event.detail_url}}">{{event.title}}</a></h3>',
    );
    expect(html).toContain(
      '{{#event.location}}<p class="swc-v-location"><span class="swc-v-label">Ort:</span> {{event.location}}</p>{{/event.location}}',
    );
    expect(html.trim().endsWith('</article>')).toBe(true);
  });

  it('escapes user labels so they cannot inject markup or placeholders', () => {
    const link = {
      ...createBlock('link', 'detail'),
      label: '<script>x</script>{{event.url}}',
    } as Block;
    const files = {
      ...createBlock('attachments', 'detail'),
      heading: '"><img src=x onerror=1>',
    } as Block;
    const html = generateHtml(schema('detail', [link, files]));
    expect(html).not.toContain('<script>');
    expect(html).not.toContain('<img');
    expect(html).toContain('&lt;script&gt;x&lt;/script&gt;&#123;&#123;event.url&#125;&#125;');
    expect(html).toContain('&quot;&gt;&lt;img src=x onerror=1&gt;');
    // the only placeholders left are the generated ones
    expect(html.match(/\{\{[^}]*\}\}/g)).toEqual([
      '{{#event.url}}',
      '{{event.url}}',
      '{{/event.url}}',
      '{{#event.has_files}}',
      '{{event.attachments.file_list}}',
      '{{/event.has_files}}',
    ]);
  });

  it('sanitises css classes in attributes', () => {
    const title = { ...createBlock('title', 'event'), cssClass: 'big" onclick="x' } as Block;
    const html = generateHtml(schema('event', [title]));
    expect(html).toContain('class="swc-v-title big onclickx"');
    expect(html).not.toContain('onclick="');
  });

  it('omits hidden blocks and nests container children one level', () => {
    const hidden = { ...createBlock('location', 'event'), visible: false } as Block;
    const container = {
      ...createBlock('container', 'event'),
      tag: 'header',
      children: [createBlock('datetime', 'event'), createBlock('container', 'event')],
    } as Block;
    const html = generateHtml(schema('event', [hidden, container]));
    expect(html).not.toContain('event.location');
    expect(html).toContain('<header class="swc-v-group">');
    expect(html).toContain('<time datetime="{{event.start_iso}}">{{event.date_range}}</time>');
    expect(html.match(/swc-v-group/g)?.length).toBe(1);
  });

  it('uses calendar placeholders for container templates', () => {
    const list = {
      ...createBlock('event_list', 'container'),
      emptyText: 'Keine Termine <3',
    } as Block;
    const html = generateHtml(schema('container', [createBlock('title', 'container'), list], true));
    expect(html.startsWith('<section class="swc-v swc-v-container">')).toBe(true);
    expect(html).toContain('{{calendar.title}}');
    expect(html).toContain('{{calendar.navigation}}');
    expect(html).toContain('{{calendar.events}}');
    expect(html).toContain(
      '{{^calendar.has_events}}<p class="swc-v-empty">Keine Termine &lt;3</p>{{/calendar.has_events}}',
    );
    expect(html).not.toContain('event.');
  });

  it('passes custom HTML through unchanged (validated by the server)', () => {
    const custom = {
      ...createBlock('custom_html', 'event'),
      html: '<em>{{event.calendar_name}}</em>',
    } as Block;
    expect(generateHtml(schema('event', [custom]))).toContain('  <em>{{event.calendar_name}}</em>');
  });

  it('offers only kind-compatible blocks', () => {
    expect(BLOCKS_BY_KIND.container).toContain('event_list');
    expect(BLOCKS_BY_KIND.event).not.toContain('event_list');
    expect(BLOCKS_BY_KIND.container).not.toContain('location');
  });
});

describe('schema round trip and mode switching', () => {
  it('parses a stored schema and regenerates the same body', () => {
    const original = defaultSchema('detail');
    const body = generateHtml(original);
    const parsed = parseSchema(JSON.parse(JSON.stringify(original)), 'detail');
    expect(parsed).not.toBeNull();
    expect(generateHtml(parsed as VisualSchema)).toBe(body);
    expect(canEditVisually(parsed, body)).toBe(true);
    expect(canEditVisually(parsed, `${body}<p>edited</p>`)).toBe(false);
    expect(canEditVisually(null, body)).toBe(false);
  });

  it('rejects foreign schemas and drops unknown or nested containers', () => {
    expect(parseSchema(null, 'event')).toBeNull();
    expect(parseSchema({ version: 2, blocks: [] }, 'event')).toBeNull();
    const parsed = parseSchema(
      {
        version: 1,
        root: { cssClass: 'x"y', colorAccent: true },
        blocks: [
          { type: 'script', id: 'a' },
          { type: 'event_list', id: 'b' },
          {
            type: 'container',
            id: 'c',
            children: [
              { type: 'container', id: 'd' },
              { type: 'divider', id: 'e' },
            ],
          },
        ],
      },
      'event',
    );
    expect(parsed?.root.cssClass).toBe('xy');
    expect(parsed?.blocks.map((b) => b.type)).toEqual(['container']);
    const container = parsed?.blocks[0];
    expect(container?.type === 'container' && container.children.map((c) => c.type)).toEqual([
      'divider',
    ]);
  });

  it('moves blocks within bounds', () => {
    expect(moveBlock(['a', 'b', 'c'], 0, 1)).toEqual(['b', 'a', 'c']);
    expect(moveBlock(['a', 'b', 'c'], 2, -1)).toEqual(['a', 'c', 'b']);
    expect(moveBlock(['a', 'b'], 0, -1)).toEqual(['a', 'b']);
    expect(moveBlock(['a', 'b'], 1, 1)).toEqual(['a', 'b']);
  });
});
