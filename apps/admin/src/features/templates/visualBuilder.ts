/**
 * Visual template builder: a list of blocks that is turned into template markup with the
 * plugin's placeholder syntax (`{{event.title}}`, `{{#…}}…{{/…}}`). Pure functions only, so the
 * generation (incl. escaping of static text) is unit tested.
 *
 * Security: every static text (labels) is HTML-escaped and curly braces are encoded, so a label
 * can never open a placeholder or inject markup. CSS class fields are reduced to class tokens.
 * Only the "Custom HTML" block passes markup through; it uses the same restricted syntax and is
 * validated by the server like any HTML template.
 */
import type { TemplateKind } from '@/types/api';

export const SCHEMA_VERSION = 1;

export type BlockType =
  | 'title'
  | 'datetime'
  | 'description'
  | 'location'
  | 'link'
  | 'gallery'
  | 'attachments'
  | 'container'
  | 'divider'
  | 'custom_html'
  | 'event_list';

export interface BlockBase {
  id: string;
  type: BlockType;
  visible: boolean;
  cssClass: string;
}

export interface TitleBlock extends BlockBase {
  type: 'title';
  level: 2 | 3 | 4;
  /** Event kinds: link the title to the detail view. */
  linkDetail: boolean;
}
export interface DateTimeBlock extends BlockBase {
  type: 'datetime';
  /** range = full formatted range, start = start date + time, date = start date only */
  format: 'range' | 'start' | 'date';
}
export interface DescriptionBlock extends BlockBase {
  type: 'description';
  variant: 'excerpt' | 'text' | 'html';
}
export interface LocationBlock extends BlockBase {
  type: 'location';
  label: string;
}
export interface LinkBlock extends BlockBase {
  type: 'link';
  label: string;
  newTab: boolean;
}
export interface GalleryBlock extends BlockBase {
  type: 'gallery';
}
export interface AttachmentsBlock extends BlockBase {
  type: 'attachments';
  heading: string;
}
export interface ContainerBlock extends BlockBase {
  type: 'container';
  tag: 'div' | 'section' | 'header' | 'footer' | 'aside';
  /** One nesting level: children are never containers. */
  children: Block[];
}
export interface DividerBlock extends BlockBase {
  type: 'divider';
}
export interface CustomHtmlBlock extends BlockBase {
  type: 'custom_html';
  html: string;
}
export interface EventListBlock extends BlockBase {
  type: 'event_list';
  navigation: boolean;
  emptyText: string;
}

export type Block =
  | TitleBlock
  | DateTimeBlock
  | DescriptionBlock
  | LocationBlock
  | LinkBlock
  | GalleryBlock
  | AttachmentsBlock
  | ContainerBlock
  | DividerBlock
  | CustomHtmlBlock
  | EventListBlock;

export interface VisualSchema {
  version: number;
  kind: TemplateKind;
  root: { cssClass: string; colorAccent: boolean };
  blocks: Block[];
}

/** Blocks offered per template kind (placeholders differ: container has no event fields). */
export const BLOCKS_BY_KIND: Record<TemplateKind, BlockType[]> = {
  container: ['title', 'datetime', 'event_list', 'container', 'divider', 'custom_html'],
  event: [
    'title',
    'datetime',
    'description',
    'location',
    'link',
    'gallery',
    'attachments',
    'container',
    'divider',
    'custom_html',
  ],
  detail: [
    'title',
    'datetime',
    'description',
    'location',
    'link',
    'gallery',
    'attachments',
    'container',
    'divider',
    'custom_html',
  ],
};

/* ------------------------------------------------------------------ escaping */

/** Escapes static text for an HTML text node and neutralises placeholder braces. */
export function escapeText(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
    .replace(/\{/g, '&#123;')
    .replace(/\}/g, '&#125;');
}

/** Reduces a user supplied class list to safe class tokens (letters, digits, `-`, `_`). */
export function sanitizeClassList(value: string): string {
  return value
    .split(/\s+/)
    .map((token) => token.replace(/[^A-Za-z0-9_-]/g, ''))
    .filter((token) => token !== '' && /^[A-Za-z_-]/.test(token))
    .slice(0, 10)
    .join(' ');
}

function cls(base: string, extra: string): string {
  const custom = sanitizeClassList(extra);
  return custom === '' ? base : `${base} ${custom}`;
}

/* ------------------------------------------------------------------ factories */

let seq = 0;
export function newBlockId(): string {
  seq += 1;
  return `b${Date.now().toString(36)}${seq.toString(36)}`;
}

export function createBlock(type: BlockType, kind: TemplateKind): Block {
  const base = { id: newBlockId(), visible: true, cssClass: '' };
  switch (type) {
    case 'title':
      return { ...base, type, level: kind === 'detail' ? 2 : 3, linkDetail: kind === 'event' };
    case 'datetime':
      return { ...base, type, format: 'range' };
    case 'description':
      return { ...base, type, variant: kind === 'detail' ? 'html' : 'excerpt' };
    case 'location':
      return { ...base, type, label: '' };
    case 'link':
      return { ...base, type, label: '', newTab: true };
    case 'gallery':
      return { ...base, type };
    case 'attachments':
      return { ...base, type, heading: '' };
    case 'container':
      return { ...base, type, tag: 'div', children: [] };
    case 'divider':
      return { ...base, type };
    case 'custom_html':
      return { ...base, type, html: '' };
    case 'event_list':
      return { ...base, type, navigation: true, emptyText: '' };
  }
}

/** Sensible starting layout per kind. */
export function defaultSchema(kind: TemplateKind): VisualSchema {
  const types: BlockType[] =
    kind === 'container'
      ? ['title', 'event_list']
      : kind === 'event'
        ? ['datetime', 'title', 'location', 'attachments']
        : ['title', 'datetime', 'location', 'gallery', 'description', 'attachments', 'link'];
  return {
    version: SCHEMA_VERSION,
    kind,
    root: { cssClass: '', colorAccent: kind !== 'container' },
    blocks: types.map((type) => createBlock(type, kind)),
  };
}

/* ------------------------------------------------------------------ list operations */

export function moveBlock<T>(list: readonly T[], index: number, delta: -1 | 1): T[] {
  const target = index + delta;
  if (index < 0 || index >= list.length || target < 0 || target >= list.length) return [...list];
  const copy = [...list];
  const [item] = copy.splice(index, 1);
  copy.splice(target, 0, item as T);
  return copy;
}

/* ------------------------------------------------------------------ generation */

function indent(lines: string[], depth: number): string[] {
  const pad = '  '.repeat(depth);
  return lines.map((line) => (line === '' ? line : pad + line));
}

function blockLines(block: Block, kind: TemplateKind): string[] {
  const isContainerKind = kind === 'container';
  switch (block.type) {
    case 'title': {
      const tag = `h${block.level}`;
      const value = isContainerKind ? '{{calendar.title}}' : '{{event.title}}';
      const inner =
        !isContainerKind && block.linkDetail
          ? `<a href="{{event.detail_url}}">${value}</a>`
          : value;
      return [`<${tag} class="${cls('swc-v-title', block.cssClass)}">${inner}</${tag}>`];
    }
    case 'datetime': {
      if (isContainerKind) {
        return [
          `{{#calendar.range_label}}<p class="${cls('swc-v-range', block.cssClass)}">{{calendar.range_label}}</p>{{/calendar.range_label}}`,
        ];
      }
      const text =
        block.format === 'range'
          ? '{{event.date_range}}'
          : block.format === 'date'
            ? '{{event.start_date}}'
            : '{{event.start_date}}{{^event.all_day}} {{event.start_time}}{{/event.all_day}}';
      return [
        `<p class="${cls('swc-v-when', block.cssClass)}"><time datetime="{{event.start_iso}}">${text}</time></p>`,
      ];
    }
    case 'description': {
      const value =
        block.variant === 'html'
          ? '{{event.description_html}}'
          : block.variant === 'text'
            ? '{{event.description}}'
            : '{{event.excerpt}}';
      return [
        `{{#event.description}}<div class="${cls('swc-v-description', block.cssClass)}">${value}</div>{{/event.description}}`,
      ];
    }
    case 'location': {
      const label =
        block.label.trim() === ''
          ? ''
          : `<span class="swc-v-label">${escapeText(block.label.trim())}</span> `;
      return [
        `{{#event.location}}<p class="${cls('swc-v-location', block.cssClass)}">${label}{{event.location}}</p>{{/event.location}}`,
      ];
    }
    case 'link': {
      const label =
        block.label.trim() === '' ? '{{event.calendar_name}}' : escapeText(block.label.trim());
      const target = block.newTab ? ' target="_blank"' : '';
      return [
        `{{#event.url}}<p class="${cls('swc-v-link', block.cssClass)}"><a href="{{event.url}}"${target}>${label}</a></p>{{/event.url}}`,
      ];
    }
    case 'gallery':
      return [
        `{{#event.has_images}}<div class="${cls('swc-v-gallery', block.cssClass)}">{{event.attachments.gallery}}</div>{{/event.has_images}}`,
      ];
    case 'attachments': {
      const heading =
        block.heading.trim() === ''
          ? ''
          : `<h4 class="swc-v-files__title">${escapeText(block.heading.trim())}</h4>`;
      return [
        `{{#event.has_files}}<div class="${cls('swc-v-files', block.cssClass)}">${heading}{{event.attachments.file_list}}</div>{{/event.has_files}}`,
      ];
    }
    case 'divider':
      return [`<hr class="${cls('swc-v-divider', block.cssClass)}">`];
    case 'custom_html':
      return block.html.trim() === '' ? [] : block.html.replace(/\r\n?/g, '\n').trim().split('\n');
    case 'event_list': {
      const lines: string[] = [];
      if (block.navigation) lines.push('{{calendar.navigation}}');
      lines.push(`<div class="${cls('swc-v-events', block.cssClass)}">{{calendar.events}}</div>`);
      if (block.emptyText.trim() !== '') {
        lines.push(
          `{{^calendar.has_events}}<p class="swc-v-empty">${escapeText(block.emptyText.trim())}</p>{{/calendar.has_events}}`,
        );
      }
      return lines;
    }
    case 'container': {
      const children = block.children
        .filter((child) => child.visible && child.type !== 'container')
        .flatMap((child) => blockLines(child, kind));
      return [
        `<${block.tag} class="${cls('swc-v-group', block.cssClass)}">`,
        ...indent(children, 1),
        `</${block.tag}>`,
      ];
    }
  }
}

/** Generates the template body for a schema. Deterministic (used for "unchanged" checks). */
export function generateHtml(schema: VisualSchema): string {
  const kind = schema.kind;
  const tag = kind === 'container' ? 'section' : 'article';
  const rootClass = cls(`swc-v swc-v-${kind}`, schema.root.cssClass);
  const style =
    kind !== 'container' && schema.root.colorAccent
      ? ' style="border-left: 4px solid {{event.color}}"'
      : '';
  const body = schema.blocks
    .filter((block) => block.visible)
    .flatMap((block) => blockLines(block, kind));
  return (
    [`<${tag} class="${rootClass}"${style}>`, ...indent(body, 1), `</${tag}>`].join('\n') + '\n'
  );
}

/* ------------------------------------------------------------------ schema parsing */

function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function str(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : fallback;
}

function parseBlock(raw: unknown, kind: TemplateKind, depth: number): Block | null {
  if (!isObject(raw)) return null;
  const type = raw.type as BlockType;
  if (!BLOCKS_BY_KIND[kind].includes(type) && !(type === 'event_list' && kind === 'container')) {
    return null;
  }
  if (type === 'container' && depth > 0) return null;
  const block = createBlock(type, kind) as Block & Record<string, unknown>;
  block.id = str(raw.id, block.id) || block.id;
  block.visible = raw.visible !== false;
  block.cssClass = sanitizeClassList(str(raw.cssClass));
  switch (block.type) {
    case 'title':
      block.level = raw.level === 2 || raw.level === 4 ? raw.level : 3;
      block.linkDetail = raw.linkDetail === true;
      break;
    case 'datetime':
      block.format = raw.format === 'start' || raw.format === 'date' ? raw.format : 'range';
      break;
    case 'description':
      block.variant = raw.variant === 'text' || raw.variant === 'html' ? raw.variant : 'excerpt';
      break;
    case 'location':
      block.label = str(raw.label);
      break;
    case 'link':
      block.label = str(raw.label);
      block.newTab = raw.newTab !== false;
      break;
    case 'attachments':
      block.heading = str(raw.heading);
      break;
    case 'custom_html':
      block.html = str(raw.html);
      break;
    case 'event_list':
      block.navigation = raw.navigation !== false;
      block.emptyText = str(raw.emptyText);
      break;
    case 'container':
      block.tag = (['div', 'section', 'header', 'footer', 'aside'] as const).includes(
        raw.tag as ContainerBlock['tag'],
      )
        ? (raw.tag as ContainerBlock['tag'])
        : 'div';
      block.children = Array.isArray(raw.children)
        ? raw.children
            .map((child) => parseBlock(child, kind, depth + 1))
            .filter((child): child is Block => child !== null)
        : [];
      break;
    default:
      break;
  }
  return block;
}

/** Reads a stored `visual_schema`; returns null if it is missing or not a builder schema. */
export function parseSchema(raw: unknown, kind: TemplateKind): VisualSchema | null {
  if (!isObject(raw) || raw.version !== SCHEMA_VERSION || !Array.isArray(raw.blocks)) return null;
  const root = isObject(raw.root) ? raw.root : {};
  return {
    version: SCHEMA_VERSION,
    kind,
    root: {
      cssClass: sanitizeClassList(str(root.cssClass)),
      colorAccent: root.colorAccent === true,
    },
    blocks: raw.blocks
      .map((block) => parseBlock(block, kind, 0))
      .filter((block): block is Block => block !== null),
  };
}

/** Whether the body can be edited visually again: schema present and body untouched. */
export function canEditVisually(schema: VisualSchema | null, body: string): boolean {
  if (!schema) return false;
  return normaliseBody(generateHtml(schema)) === normaliseBody(body);
}

function normaliseBody(body: string): string {
  return body.replace(/\r\n?/g, '\n').trim();
}
