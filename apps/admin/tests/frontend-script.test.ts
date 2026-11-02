/**
 * Unbundled frontend script assets/js/frontend.js (progressive enhancement of shortcode/block output).
 * The IIFE runs on import, so every test sets globals and the DOM first and imports a fresh copy.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

type Listener = [string, EventListenerOrEventListenerObject];
let docListeners: Listener[] = [];
let fetchMock: ReturnType<typeof vi.fn>;

interface Route {
  ok?: boolean;
  status?: number;
  body?: unknown;
  reject?: boolean;
}

function respond(route: Route): Promise<Response> {
  if (route.reject) return Promise.reject(new TypeError('network'));
  const ok = route.ok ?? true;
  return Promise.resolve({
    ok,
    status: route.status ?? (ok ? 200 : 500),
    json: () => Promise.resolve(route.body),
  } as Response);
}

/** Routes fetch by URL substring; the first matching key wins. */
function routeFetch(routes: Record<string, Route>): void {
  fetchMock = vi.fn((input: string) => {
    const key = Object.keys(routes).find((k) => input.includes(k));
    return key ? respond(routes[key] as Route) : Promise.reject(new Error('unrouted ' + input));
  });
  vi.stubGlobal('fetch', fetchMock);
}

async function loadScript(config: Record<string, unknown> | undefined = {}): Promise<void> {
  (window as unknown as { swCloudCalendar?: unknown }).swCloudCalendar = config;
  vi.resetModules();
  // @ts-expect-error -- unbundled browser script (IIFE) without type declarations.
  await import('../../../assets/js/frontend.js');
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

function calls(): string[] {
  return fetchMock.mock.calls.map((c) => String(c[0]));
}

function click(target: Element, init: MouseEventInit = {}): MouseEvent {
  const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
  target.dispatchEvent(event);
  return event;
}

const CONFIG = {
  ajaxUrl: 'https://site.test/wp-admin/admin-ajax.php',
  restRoot: 'https://site.test/wp-json/sw-cloudcalendar/v1/',
  i18n: { error: 'Fehler', details: 'Details', close: 'Schließen' },
};

beforeEach(() => {
  docListeners = [];
  const original = document.addEventListener.bind(document);
  vi.spyOn(document, 'addEventListener').mockImplementation(((
    type: string,
    listener: EventListenerOrEventListenerObject,
    options?: boolean | AddEventListenerOptions,
  ) => {
    docListeners.push([type, listener]);
    original(type, listener, options);
  }) as typeof document.addEventListener);
  const proto = HTMLDialogElement.prototype as HTMLDialogElement & Record<string, unknown>;
  proto.showModal = function (this: HTMLDialogElement) {
    this.setAttribute('open', '');
  };
  proto.close = function (this: HTMLDialogElement) {
    this.removeAttribute('open');
    this.dispatchEvent(new Event('close'));
  };
  window.history.replaceState(null, '', '/page/?swc_event=4&swc_view=2&swc_month=2026-10&keep=1');
});

afterEach(() => {
  for (const [type, listener] of docListeners) document.removeEventListener(type, listener);
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  document.head.innerHTML = '';
  document.body.innerHTML = '';
});

describe('frontend.js dynamic placeholders', () => {
  it('renders protected views with a fresh nonce, scoped CSS and a clean page URL', async () => {
    document.body.innerHTML =
      '<div class="swc-calendar swc-dynamic" data-swc-view="7" data-swc-filters=\'{"limit":5,"search":"","x":null}\'></div>';
    routeFetch({
      sw_calendar_rest_nonce: { body: { nonce: 'n1' } },
      'views/7/render': {
        body: {
          html: '<div class="swc-calendar" id="rendered">ok</div>',
          css: { 'swc-v7': '.a{}', 'bad scope': 'x{}' },
        },
      },
    });
    await loadScript(CONFIG);
    await flush();

    expect(document.getElementById('rendered')?.textContent).toBe('ok');
    expect(document.getElementById('swc-v7-css')?.textContent).toBe('.a{}');
    expect(document.head.querySelectorAll('style')).toHaveLength(1);
    const [nonceUrl, renderUrl] = calls();
    expect(nonceUrl).toBe(CONFIG.ajaxUrl + '?action=sw_calendar_rest_nonce');
    const url = new URL(renderUrl as string);
    expect(url.searchParams.get('limit')).toBe('5');
    expect(url.searchParams.get('search')).toBe('');
    expect(url.searchParams.has('x')).toBe(false);
    expect(url.searchParams.get('page_url')).toBe('http://localhost:3000/page/?keep=1');
    const init = fetchMock.mock.calls[1]?.[1] as RequestInit;
    expect((init.headers as Record<string, string>)['X-WP-Nonce']).toBe('n1');
  });

  it('shows an error without nonce header when nonce and render fail', async () => {
    document.body.innerHTML =
      '<div class="swc-calendar swc-dynamic" data-swc-view="3" data-swc-filters="{broken"></div>';
    routeFetch({
      sw_calendar_rest_nonce: { ok: false },
      'views/3/render': { ok: false, status: 403 },
    });
    await loadScript(CONFIG);
    await flush();

    const placeholder = document.querySelector('.swc-calendar') as HTMLElement;
    expect(placeholder.getAttribute('aria-busy')).toBeNull();
    expect(placeholder.querySelector('p.swc-error')?.textContent).toBe('Fehler');
    const init = fetchMock.mock.calls[1]?.[1] as RequestInit;
    expect((init.headers as Record<string, string>)['X-WP-Nonce']).toBeUndefined();
  });

  it('falls back to the default error text and removes empty renders', async () => {
    document.body.innerHTML =
      '<div class="swc-calendar swc-dynamic" data-swc-view="1"></div>' +
      '<div class="swc-calendar swc-dynamic" data-swc-view="2"></div>';
    routeFetch({
      sw_calendar_rest_nonce: { reject: true },
      'views/1/render': { reject: true },
      'views/2/render': { body: { html: '   ' } },
    });
    await loadScript(undefined);
    await flush();

    const remaining = document.querySelectorAll('.swc-calendar');
    expect(remaining).toHaveLength(1);
    expect(remaining[0]?.textContent).toBe('Error');
  });

  it('loads a deep-linked event and falls back to the view when it fails', async () => {
    document.body.innerHTML =
      '<div class="swc-calendar swc-dynamic" data-swc-view="5" data-swc-event="9"></div>' +
      '<div class="swc-calendar swc-dynamic" data-swc-view="6" data-swc-event="10"></div>';
    routeFetch({
      sw_calendar_rest_nonce: { body: { nonce: 42 } },
      'events/9': { body: { html: '<article id="ev9">E</article>', css: {} } },
      'events/10': { ok: false, status: 404 },
      'views/6/render': { body: { html: '<div id="view6">V</div>' } },
    });
    await loadScript(CONFIG);
    await flush();

    expect(document.getElementById('ev9')).not.toBeNull();
    expect(document.getElementById('view6')).not.toBeNull();
    expect(
      new URL(calls().find((u) => u.includes('events/9')) as string).searchParams.get('view'),
    ).toBe('5');
  });

  it('waits for DOMContentLoaded while the document is loading', async () => {
    const state = vi.spyOn(document, 'readyState', 'get').mockReturnValue('loading');
    document.body.innerHTML = '<div class="swc-calendar swc-dynamic" data-swc-view="8"></div>';
    routeFetch({
      sw_calendar_rest_nonce: { body: {} },
      'views/8/render': { body: { html: '<div id="late">L</div>' } },
    });
    await loadScript(CONFIG);
    await flush();
    expect(fetchMock).not.toHaveBeenCalled();

    state.mockRestore();
    document.dispatchEvent(new Event('DOMContentLoaded'));
    await flush();
    expect(document.getElementById('late')).not.toBeNull();
  });
});

describe('frontend.js navigation and details', () => {
  const calendar = (inner: string) =>
    `<div class="swc-calendar" data-swc-view="4" data-swc-mode="month" data-swc-state='{"limit":3}'>${inner}</div>`;

  it('replaces the calendar on month navigation and focuses the label', async () => {
    document.body.innerHTML = calendar(
      '<a id="next" href="?swc_month=2026-11" data-swc-month="2026-11">›</a>',
    );
    routeFetch({
      sw_calendar_rest_nonce: { body: { nonce: 'n' } },
      'views/4/render': {
        body: { html: '<div class="swc-calendar"><span class="swc-nav__label">Nov</span></div>' },
      },
    });
    await loadScript(CONFIG);

    const event = click(document.getElementById('next') as Element);
    expect(event.defaultPrevented).toBe(true);
    await flush();

    const label = document.querySelector('.swc-nav__label') as HTMLElement;
    expect(label.getAttribute('tabindex')).toBe('-1');
    expect(document.activeElement).toBe(label);
    const url = new URL(calls().find((u) => u.includes('render')) as string);
    expect(url.searchParams.get('month')).toBe('2026-11');
    expect(url.searchParams.get('view')).toBe('month');
    expect(url.searchParams.get('limit')).toBe('3');
  });

  it('keeps month navigation working when the render has no label', async () => {
    document.body.innerHTML = calendar('<a id="next" href="#" data-swc-month="2026-12">›</a>');
    routeFetch({
      sw_calendar_rest_nonce: { body: {} },
      'views/4/render': { body: { html: '<div class="swc-calendar" id="plain"></div>' } },
    });
    await loadScript(CONFIG);
    click(document.getElementById('next') as Element);
    await flush();
    expect(document.getElementById('plain')).not.toBeNull();
  });

  it('paginates lists, focuses the new root and scrolls it into view when above the viewport', async () => {
    document.body.innerHTML = calendar('<a id="more" href="#" data-swc-page="2">more</a>').replace(
      `data-swc-state='{"limit":3}'`,
      `data-swc-state='not json'`,
    );
    routeFetch({
      sw_calendar_rest_nonce: { body: {} },
      'views/4/render': { body: { html: '<section class="swc-calendar" id="p2"></section>' } },
    });
    const scroll = vi.fn();
    Element.prototype.scrollIntoView = scroll;
    vi.spyOn(Element.prototype, 'getBoundingClientRect').mockReturnValue({ top: -20 } as DOMRect);
    await loadScript(CONFIG);

    click(document.getElementById('more') as Element);
    await flush();

    const root = document.getElementById('p2') as HTMLElement;
    expect(root.getAttribute('tabindex')).toBe('-1');
    expect(document.activeElement).toBe(root);
    expect(scroll).toHaveBeenCalledWith({ block: 'start' });
    expect(new URL(calls()[1] as string).searchParams.get('page')).toBe('2');
  });

  it('does not scroll when the new page is visible or the render failed', async () => {
    document.body.innerHTML = calendar('<a id="more" href="#" data-swc-page="3">more</a>');
    routeFetch({
      sw_calendar_rest_nonce: { body: {} },
      'views/4/render': { body: { html: '<section class="swc-calendar" id="p3"></section>' } },
    });
    const scroll = vi.fn();
    Element.prototype.scrollIntoView = scroll;
    await loadScript(CONFIG);
    click(document.getElementById('more') as Element);
    await flush();
    expect(document.getElementById('p3')).not.toBeNull();
    expect(scroll).not.toHaveBeenCalled();

    document.body.innerHTML = calendar('<a id="more" href="#" data-swc-page="4">more</a>');
    routeFetch({ 'views/4/render': { ok: false } });
    click(document.getElementById('more') as Element);
    await flush();
    expect(document.querySelector('.swc-error')?.textContent).toBe('Fehler');
  });

  it('opens event details in a reusable modal dialog and restores focus on close', async () => {
    document.body.innerHTML = calendar(
      '<div data-swc-event="11"><a id="detail" href="/event/11" data-swc-detail>Event</a></div>',
    );
    routeFetch({
      sw_calendar_rest_nonce: { body: { nonce: 'n' } },
      'events/11': { body: { html: '<p id="body11">Details</p>', css: { 'swc-d': 'p{}' } } },
    });
    await loadScript(CONFIG);
    const link = document.getElementById('detail') as HTMLAnchorElement;

    expect(click(link).defaultPrevented).toBe(true);
    await flush();
    const dialog = document.querySelector('dialog.swc-dialog') as HTMLDialogElement;
    expect(dialog.hasAttribute('open')).toBe(true);
    expect(dialog.getAttribute('aria-label')).toBe('Details');
    expect(dialog.querySelector('#body11')).not.toBeNull();
    expect(document.getElementById('swc-d-css')).not.toBeNull();

    (dialog.querySelector('.swc-dialog__close') as HTMLButtonElement).click();
    expect(dialog.hasAttribute('open')).toBe(false);
    expect(document.activeElement).toBe(link);

    // Second open reuses the dialog; a backdrop click closes it, a click inside does not.
    click(link);
    await flush();
    expect(document.querySelectorAll('dialog')).toHaveLength(1);
    expect(dialog.querySelector('.swc-dialog__header .swc-dialog__title')?.textContent).toBe(
      'Details',
    );
    expect(dialog.querySelector('.swc-dialog__header .swc-dialog__close')).not.toBeNull();
    (dialog.querySelector('#body11') as HTMLElement).click();
    expect(dialog.hasAttribute('open')).toBe(true);
    dialog.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    expect(dialog.hasAttribute('open')).toBe(false);
  });

  it('navigates to the event page when details cannot be loaded', async () => {
    document.body.innerHTML = calendar(
      '<div data-swc-event="12"><a id="detail" href="#event-12" data-swc-detail>Event</a></div>',
    );
    routeFetch({ sw_calendar_rest_nonce: { body: {} }, 'events/12': { ok: false } });
    await loadScript({ ...CONFIG, i18n: undefined });
    click(document.getElementById('detail') as Element);
    await flush();
    expect(window.location.hash).toBe('#event-12');
  });

  it('leaves the link alone without native dialog support', async () => {
    document.body.innerHTML = calendar(
      '<div data-swc-event="13"><a id="detail" href="#e13" data-swc-detail>Event</a></div>',
    );
    routeFetch({});
    vi.stubGlobal('HTMLDialogElement', undefined);
    await loadScript(CONFIG);
    const event = click(document.getElementById('detail') as Element);
    expect(event.defaultPrevented).toBe(false);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('ignores modified clicks, foreign links and non-element targets', async () => {
    document.body.innerHTML =
      calendar(
        '<a id="month" href="#" data-swc-month="2027-01">m</a><div data-swc-event="1"><a id="plain" href="#">p</a></div>',
      ) + '<a id="outside" href="#" data-swc-month="2027-01">o</a>';
    routeFetch({});
    await loadScript(CONFIG);

    expect(
      click(document.getElementById('month') as Element, { ctrlKey: true }).defaultPrevented,
    ).toBe(false);
    expect(click(document.getElementById('month') as Element, { button: 1 }).defaultPrevented).toBe(
      false,
    );
    expect(click(document.getElementById('outside') as Element).defaultPrevented).toBe(false);
    expect(click(document.getElementById('plain') as Element).defaultPrevented).toBe(false);
    document.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    const prevented = new MouseEvent('click', { bubbles: true, cancelable: true });
    prevented.preventDefault();
    document.getElementById('month')?.dispatchEvent(prevented);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

it('synchronizes only explicitly grouped month views and resets pagination', async () => {
  document.body.innerHTML = `
    <div class="swc-calendar" data-swc-view="1" data-swc-mode="month" data-swc-group="mgwh" data-swc-state='{}'><a href="/?swc_month=2026-11" data-swc-month="2026-11">Next</a></div>
    <div class="swc-calendar" data-swc-view="2" data-swc-mode="list" data-swc-group="mgwh" data-swc-state='{"page":4,"source":[7]}'></div>
    <div class="swc-calendar" data-swc-view="3" data-swc-mode="list" data-swc-group="other"></div>`;
  routeFetch({
    'admin-ajax.php': { body: { nonce: '' } },
    '/render': { body: { html: '<div class="swc-calendar"></div>', css: {} } },
  });
  await loadScript(CONFIG);
  click(document.querySelector('a')!);
  await flush();
  await flush();
  expect(
    calls().some(
      (u) =>
        u.includes('views/2/render') &&
        u.includes('month=2026-11') &&
        u.includes('page=1') &&
        u.includes('view=list'),
    ),
  ).toBe(true);
  expect(calls().some((u) => u.includes('views/1/render'))).toBe(true);
  expect(calls().some((u) => u.includes('views/3/render'))).toBe(false);
});

it('loads opaque external event references in dynamic detail placeholders', async () => {
  document.body.innerHTML =
    '<div class="swc-calendar swc-dynamic" data-swc-view="1" data-swc-event="local.2.NDI"></div>';
  routeFetch({
    'admin-ajax.php': { body: { nonce: '' } },
    'events/local.2.NDI': { body: { html: '<div>External detail</div>', css: {} } },
  });
  await loadScript(CONFIG);
  document.dispatchEvent(new Event('DOMContentLoaded'));
  await flush();
  await flush();
  expect(calls().some((u) => u.includes('events/local.2.NDI'))).toBe(true);
  expect(document.body.textContent).toContain('External detail');
});

it('keeps the selected month when paging a month-filtered list', async () => {
  document.body.innerHTML = `<div class="swc-calendar" data-swc-view="4" data-swc-mode="list" data-swc-state='{"limit":50,"month":"2026-11","page":1}'><a id="more" href="?swc_month=2026-11&swc_page=2" data-swc-page="2">more</a></div>`;
  routeFetch({
    sw_calendar_rest_nonce: { body: {} },
    'views/4/render': {
      body: { html: '<section class="swc-calendar" id="month-page-two"></section>' },
    },
  });
  await loadScript(CONFIG);
  click(document.getElementById('more')!);
  await flush();
  const url = new URL(calls()[1]!);
  expect(url.searchParams.get('page')).toBe('2');
  expect(url.searchParams.get('month')).toBe('2026-11');
  expect(document.getElementById('month-page-two')).not.toBeNull();
});

describe('visitor display tabs and event search', () => {
  it('switches only the current calendar and preserves its search and selected month', async () => {
    document.body.innerHTML = `<div class="swc-calendar" data-swc-view="4" data-swc-mode="list" data-swc-state='{"search":"Choir","month":"2026-11","page":3}'><nav><a href="?swc_mode=month" data-swc-switch="month">Month</a></nav></div><div class="swc-calendar" id="unrelated" data-swc-view="5"></div>`;
    routeFetch({
      sw_calendar_rest_nonce: { body: {} },
      'views/4/render': {
        body: {
          html: '<div class="swc-calendar"><a class="swc-mode-tabs__tab" aria-current="page" href="#">Month</a></div>',
        },
      },
    });
    await loadScript(CONFIG);
    const link = document.querySelector('a')!;
    expect(click(link).defaultPrevented).toBe(true);
    // A second click during the same request must not start another render.
    click(link);
    await flush();
    await flush();
    const url = new URL(calls().find((u) => u.includes('/render'))!);
    expect(url.searchParams.get('view')).toBe('month');
    expect(url.searchParams.get('page')).toBe('1');
    expect(url.searchParams.get('search')).toBe('Choir');
    expect(url.searchParams.get('month')).toBe('2026-11');
    expect(calls().filter((u) => u.includes('/render'))).toHaveLength(1);
    expect(document.getElementById('unrelated')).not.toBeNull();
    expect(document.activeElement?.getAttribute('aria-current')).toBe('page');
  });

  it('searches via REST, resets paging, preserves restrictions and can clear the term', async () => {
    document.body.innerHTML = `<div class="swc-calendar" data-swc-view="4" data-swc-mode="cards" data-swc-state='{"source":[2],"page":5,"month":"2026-11"}'><form class="swc-search"><input type="search" name="swc_search" value=" Choir "><button>Search</button></form></div>`;
    routeFetch({
      sw_calendar_rest_nonce: { body: {} },
      'views/4/render': {
        body: {
          html: `<div class="swc-calendar" data-swc-view="4" data-swc-mode="cards" data-swc-state='{"search":"Choir","month":"2026-11"}'><form class="swc-search"><input type="search" name="swc_search"></form></div>`,
        },
      },
    });
    await loadScript(CONFIG);
    const form = document.querySelector('form')!;
    const submit = new Event('submit', { bubbles: true, cancelable: true });
    form.dispatchEvent(submit);
    expect(submit.defaultPrevented).toBe(true);
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flush();
    await flush();
    const url = new URL(calls().find((u) => u.includes('/render'))!);
    expect(url.searchParams.get('search')).toBe('Choir');
    expect(url.searchParams.get('page')).toBe('1');
    expect(url.searchParams.get('source')).toBe('2');
    expect(url.searchParams.get('view')).toBe('cards');
    expect(document.activeElement?.getAttribute('type')).toBe('search');
    document
      .querySelector('form')!
      .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flush();
    await flush();
    const last = new URL(
      calls()
        .filter((u) => u.includes('/render'))
        .at(-1)!,
    );
    expect(last.searchParams.has('search')).toBe(true);
    expect(last.searchParams.get('search')).toBe('');
  });

  it('submits month searches while keeping the month view and date range', async () => {
    document.body.innerHTML = `<div class="swc-calendar" data-swc-view="4" data-swc-mode="month" data-swc-state='{"month":"2026-11"}'><form class="swc-search"><input type="search" value="Music"></form></div>`;
    routeFetch({
      sw_calendar_rest_nonce: { body: {} },
      'views/4/render': {
        body: { html: '<div class="swc-calendar" data-swc-mode="month">Filtered grid</div>' },
      },
    });
    await loadScript(CONFIG);
    document
      .querySelector('form')!
      .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flush();
    await flush();
    const url = new URL(calls().find((u) => u.includes('/render'))!);
    expect(url.searchParams.get('view')).toBe('month');
    expect(url.searchParams.get('month')).toBe('2026-11');
    expect(url.searchParams.get('search')).toBe('Music');
    expect(document.body.textContent).toContain('Filtered grid');
  });

  it('leaves other forms alone and ignores malformed or already-handled search submissions', async () => {
    document.body.innerHTML =
      '<form id="other"></form><form class="swc-search" id="outside"></form>' +
      '<div class="swc-calendar" data-swc-view="1"><form class="swc-search" id="missing"></form></div>';
    routeFetch({});
    await loadScript(CONFIG);
    for (const id of ['other', 'outside', 'missing'])
      document
        .getElementById(id)!
        .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    const prevented = new Event('submit', { bubbles: true, cancelable: true });
    prevented.preventDefault();
    document.getElementById('missing')!.dispatchEvent(prevented);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
