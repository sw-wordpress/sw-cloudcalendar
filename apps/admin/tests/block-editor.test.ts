/**
 * Block editor script blocks/calendar/index.js against minimal stand-ins for the WordPress globals.
 * createElement returns a plain tree; hooks keep state between renders so Edit can be re-rendered.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';

interface Node {
  type: unknown;
  props: Record<string, unknown> | null;
  children: unknown[];
}
type EditFn = (props: {
  attributes: Record<string, unknown>;
  setAttributes: (a: object) => void;
}) => Node;
interface BlockSettings {
  edit: EditFn;
  save: () => unknown;
}

const components = {
  PanelBody: 'PanelBody',
  SelectControl: 'SelectControl',
  TextControl: 'TextControl',
  ToggleControl: 'ToggleControl',
  Placeholder: 'Placeholder',
  Spinner: 'Spinner',
  Notice: 'Notice',
};

let hookState: unknown;
let cleanup: (() => void) | undefined;
let registered: { name: string; settings: BlockSettings } | undefined;

async function loadBlock(apiFetch: () => Promise<unknown>): Promise<BlockSettings> {
  hookState = undefined;
  cleanup = undefined;
  registered = undefined;
  vi.stubGlobal('wp', {
    blocks: {
      registerBlockType: (name: string, settings: BlockSettings) => {
        registered = { name, settings };
      },
    },
    element: {
      createElement: (
        type: unknown,
        props: Record<string, unknown> | null,
        ...children: unknown[]
      ): Node => ({
        type,
        props,
        children,
      }),
      Fragment: 'Fragment',
      useState: (initial: unknown) => [
        hookState ?? initial,
        (next: unknown) => {
          hookState = next;
        },
      ],
      useEffect: (effect: () => () => void) => {
        cleanup ??= effect();
      },
    },
    blockEditor: {
      InspectorControls: 'InspectorControls',
      useBlockProps: () => ({ className: 'wp-block' }),
    },
    components,
    i18n: { __: (text: string) => text },
    serverSideRender: 'ServerSideRender',
    apiFetch,
  });
  vi.resetModules();
  // @ts-expect-error -- unbundled browser script (IIFE) without type declarations.
  await import('../../../blocks/calendar/index.js');
  // Assigned inside the registerBlockType callback, which control-flow analysis cannot see.
  const block = registered as { name: string; settings: BlockSettings } | undefined;
  if (!block) throw new Error('block not registered');
  return block.settings;
}

function all(node: unknown, out: Node[] = []): Node[] {
  if (node && typeof node === 'object' && 'type' in node) {
    out.push(node as Node);
    (node as Node).children.forEach((child) => all(child, out));
  }
  return out;
}

function control(tree: Node, label: string): Node {
  const found = all(tree).find((n) => n.props?.label === label);
  if (!found) throw new Error('missing control ' + label);
  return found;
}

function change(tree: Node, label: string, value: unknown): void {
  (control(tree, label).props?.onChange as (v: unknown) => void)(value);
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('calendar block editor', () => {
  it('registers a dynamic block without saved markup', async () => {
    const settings = await loadBlock(() => new Promise(() => undefined));
    expect(registered?.name).toBe('sw-cloudcalendar/calendar');
    expect(settings.save()).toBeNull();
  });

  it('shows a spinner and placeholder until views are loaded, then lists them', async () => {
    const apiFetch = vi.fn(() =>
      Promise.resolve([
        { id: 3, name: 'Public', enabled: true },
        { id: 4, name: 'Old', enabled: false },
      ]),
    );
    const { edit } = await loadBlock(apiFetch);
    const setAttributes = vi.fn();

    const loading = edit({ attributes: {}, setAttributes });
    expect(all(loading).some((n) => n.type === components.Spinner)).toBe(true);
    const placeholder = all(loading).find((n) => n.type === components.Placeholder) as Node;
    expect(placeholder.children).toContain('Select a calendar view in the block settings.');
    expect(apiFetch).toHaveBeenCalledWith({ path: '/sw-cloudcalendar/v1/editor/views' });

    await flush();
    const loaded = edit({ attributes: {}, setAttributes });
    const select = control(loaded, 'Calendar view');
    expect(select.props?.value).toBe('0');
    expect(select.props?.options).toEqual([
      { label: '— Select a calendar view —', value: '0' },
      { label: 'Public', value: '3' },
      { label: 'Old (disabled)', value: '4' },
    ]);
    change(loaded, 'Calendar view', '4');
    change(loaded, 'Calendar view', 'none');
    expect(setAttributes.mock.calls).toEqual([[{ viewId: 4 }], [{ viewId: 0 }]]);
  });

  it('reports load errors and treats non-array responses as empty', async () => {
    const failing = await loadBlock(() => Promise.reject(new Error('403')));
    failing.edit({ attributes: {}, setAttributes: vi.fn() });
    await flush();
    const tree = failing.edit({ attributes: {}, setAttributes: vi.fn() });
    const notice = all(tree).find((n) => n.type === components.Notice) as Node;
    expect(notice.props).toMatchObject({ status: 'error', isDismissible: false });

    const odd = await loadBlock(() => Promise.resolve({ not: 'a list' }));
    odd.edit({ attributes: {}, setAttributes: vi.fn() });
    await flush();
    const options = control(odd.edit({ attributes: {}, setAttributes: vi.fn() }), 'Calendar view')
      .props?.options as unknown[];
    expect(options).toHaveLength(1);
  });

  it('ignores responses after unmount', async () => {
    let resolve: (v: unknown) => void = () => undefined;
    let reject: (e: unknown) => void = () => undefined;
    const first = await loadBlock(() => new Promise((res) => (resolve = res)));
    first.edit({ attributes: {}, setAttributes: vi.fn() });
    cleanup?.();
    resolve([{ id: 1, name: 'A', enabled: true }]);
    await flush();
    expect(hookState).toBeUndefined();

    const second = await loadBlock(() => new Promise((_, rej) => (reject = rej)));
    second.edit({ attributes: {}, setAttributes: vi.fn() });
    cleanup?.();
    reject(new Error('late'));
    await flush();
    expect(hookState).toBeUndefined();
  });

  it('renders the server side preview for a selected view with current attribute values', async () => {
    const { edit } = await loadBlock(() => Promise.resolve([]));
    const attributes = {
      viewId: 2,
      displayMode: 'list',
      limit: 5,
      days: 30,
      start: '2026-01-01',
      end: '2026-02-01',
      past: true,
      search: 'x',
      location: 'y',
      source: '1,2',
      sort: 'desc',
      template: 9,
      navigation: 'true',
      showImages: false,
      showAttachments: false,
    };
    const tree = edit({ attributes, setAttributes: vi.fn() });
    const preview = all(tree).find((n) => n.type === 'ServerSideRender') as Node;
    expect(preview.props).toEqual({ block: 'sw-cloudcalendar/calendar', attributes });
    expect(all(tree).find((n) => n.type === 'div')?.props).toEqual({ className: 'wp-block' });
    expect(control(tree, 'Layout').props?.value).toBe('list');
    expect(control(tree, 'Include past events').props?.checked).toBe(true);
    expect(control(tree, 'Show images').props?.checked).toBe(false);
    expect(control(tree, 'Show file list').props?.checked).toBe(false);
    expect(control(tree, 'Limit (1–100)').props?.value).toBe(5);

    const empty = edit({ attributes: {}, setAttributes: vi.fn() });
    expect(control(empty, 'Layout').props?.value).toBe('');
    expect(control(empty, 'Show images').props?.checked).toBe(true);
    expect(control(empty, 'Limit (1–100)').props?.value).toBe('');
  });

  it('normalizes edited attributes so empty values fall back to the view defaults', async () => {
    const { edit } = await loadBlock(() => Promise.resolve([]));
    const setAttributes = vi.fn();
    const tree = edit({ attributes: { viewId: 1 }, setAttributes });
    const steps: Array<[string, unknown, object]> = [
      ['Layout', 'month', { displayMode: 'month' }],
      ['Limit (1–100)', '12', { limit: 12 }],
      ['Limit (1–100)', '0', { limit: undefined }],
      ['Next days (1–1095)', 'abc', { days: undefined }],
      ['Next days (1–1095)', '14', { days: 14 }],
      ['Start date', '2026-03-01', { start: '2026-03-01' }],
      ['End date', '', { end: undefined }],
      ['Include past events', true, { past: true }],
      ['Include past events', false, { past: undefined }],
      ['Search text', '', { search: undefined }],
      ['Location contains', 'Hall', { location: 'Hall' }],
      ['Source IDs (comma separated)', '1, 2;x3', { source: '1,23' }],
      ['Source IDs (comma separated)', 'abc', { source: undefined }],
      ['Sort order', 'asc', { sort: 'asc' }],
      ['Event template ID', '7', { template: 7 }],
      ['Navigation', 'false', { navigation: 'false' }],
      ['Show images', false, { showImages: false }],
      ['Show images', true, { showImages: undefined }],
      ['Show file list', false, { showAttachments: false }],
      ['Show file list', true, { showAttachments: undefined }],
    ];
    for (const [label, value] of steps) change(tree, label, value);
    expect(setAttributes.mock.calls.map((c) => c[0])).toEqual(steps.map((s) => s[2]));
  });
});
