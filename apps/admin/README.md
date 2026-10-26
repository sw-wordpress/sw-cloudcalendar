# SW CloudCalendar admin frontend

Vue 3 (Composition API, `<script setup lang="ts">`) + Quasar (via `@quasar/vite-plugin`, **no Quasar CLI**) + Vite + TypeScript `strict` + Pinia + vue-router (hash history) + vue-i18n. It runs inside the wp-admin page `admin.php?page=sw-cloudcalendar` (menu "Calendar"; the WordPress submenu entries link to the hash routes below). Technology, layout, design-system components and the CSS isolation are identical to the sibling plugin sw-backupit (`swbi` → `swc`).

REST contract: [`docs/rest-api.md`](../../docs/rest-api.md). The TypeScript types in `src/types/api.ts` mirror it 1:1 (field names as delivered, snake_case).

## Dev workflow

Node 18.18+ is enough (versions are pinned accordingly, see below).

```bash
cd apps/admin
npm install
npm run dev          # http://127.0.0.1:5173 (dev host page simulating wp-admin)
npm run typecheck    # vue-tsc --noEmit
npm run lint         # eslint (flat config, eslint-plugin-vue, typescript-eslint)
npm run format       # prettier --write
npm test             # vitest
npm run build        # type-check + production build -> ../../assets/admin (gitignored)
node scripts/check-css-scope.mjs   # asserts every selector in the built CSS is namespaced
```

There is no mock API. To develop against a real WordPress, proxy the REST API:
`SWC_WP_ORIGIN=https://my-site.test npm run dev`, log in to that site in the same browser profile and open
`http://127.0.0.1:5173/?nonce=<wp_rest nonce>` (copy `window.swcloudcalendarConfig.nonce` from the real admin page; cookies must be valid for the proxied origin). Dev URL switches of the dev host page `index.html`: `?lang=en` (English), `?caps=` (empty = no capability → NoAccess layout), `?nonce=…`.

## Production build and PHP integration

`npm run build` emits into `assets/admin/` (repo root):

| File                  | Purpose                                                   |
| --------------------- | --------------------------------------------------------- |
| `app.js`              | ES module entry, loaded with `<script type="module">`     |
| `app.css`             | the only stylesheet (`cssCodeSplit: false`)               |
| `chunks/*.js`         | lazy route chunks (relative imports, `base: './'`)        |
| `assets/*`            | fonts (Inter, JetBrains Mono, Material Icons Round woff2) |
| `.vite/manifest.json` | Vite manifest (entry key `src/main.ts`)                   |

`emptyOutDir` is on. No external resources are requested at runtime. The template editor chunk contains CodeMirror 6 (~580 KB, ~200 KB gzip) and is only loaded on `#/templates/:id`.

### Config injection contract

`AdminPage::render()` prints `<div id="sw-cloudcalendar-app">`; `AdminPage::enqueue()` adds before the module script:

```js
window.swcloudcalendarConfig = {
    restRoot: 'https://example.com/wp-json/sw-cloudcalendar/v1/', // ends with "/"
    nonce: '<wp_rest nonce>',
    locale: 'de_DE', // starts with "de" -> German, anything else -> English
    version: '1.0.0',
    adminUrl: 'https://example.com/wp-admin/admin.php?page=sw-cloudcalendar',
    capabilities: ['manage_sw_calendar'],
    siteUrl: 'https://example.com',
    pluginUrl: 'https://example.com/wp-content/plugins/sw-cloudcalendar/', // ends with "/"
};
```

Plain-permalink roots such as `https://x/?rest_route=/sw-cloudcalendar/v1/` work too. If WordPress refreshes the nonce (`X-WP-Nonce` response header, also on error responses) the client adopts it. The capability is UX only; the server stays authoritative. Without `manage_sw_calendar` the app shows the NoAccess layout.

## Structure

```
src/
  main.ts                 bootstrap (Pinia, router, i18n, Quasar with scoped portals)
  config.ts               reads/validates window.swcloudcalendarConfig, resolveUiLocale()
  api/client.ts           the ONLY fetch wrapper (nonce, JSON, ApiError{code,message,status,params,extra})
  api/index.ts            typed per-resource modules (connections, sources, views, templates, system)
  types/                  api.ts (contract mirror), config.ts
  composables/            useAsyncData, useNotify, useFormat, useTheme, useCapabilities, useFormError, useFieldErrors
  stores/                 app (theme, rail, live region), lookups (meta, connections, sources, templates, reference)
  utils/                  format.ts (Intl helpers, parseServerDate), previewDoc.ts (sandboxed iframe document), ids.ts
  i18n/                   de.ts (schema), en.ts (typed against de.ts), index.ts, quasarLang.ts
  components/ui/          design-system layer from sw-backupit: SwcCard, StatusChip, StatTile, PageHeader,
                          EmptyState, ErrorState, DataState, ConfirmDialog, ProgressRing, FormSection, SideSheet,
                          FormSheet, PaginationBar, TagInput, SecretInput, SegmentedControl
  components/             CopyButton, ProviderIcon, TimezoneSelect, preview/PreviewFrame, editor/CodeEditor (+ completion)
  features/
    dashboard/            health overview (health.ts derives issues from /admin/diagnostics)
    connections/          list + create/edit side sheet, OAuth authorise/complete, test, disconnect, delete
    sources/              list, editor page (connection → calendar → form), confirmations, run history
    views/                list with shortcode copy, editor with sandboxed live preview
    templates/            list grouped by kind, preset gallery, editor (visual builder / CodeMirror HTML)
    sync/                 runs of all sources, queue due sources, job counts, auto refresh
    settings/             settings form, cache clear, audit log, logs
    diagnostics/          all diagnostics sections, storage re-check, JSON export
  layouts/                ShellLayout (left rail / top tabs on mobile), NoAccess
  router/                 hash routes = WP submenu entries
  styles/                 fonts.css, app.css (tokens, isolation reset, utilities, editor colours)
tools/postcssScope.ts     build-time scoping of Quasar CSS
scripts/                  check-css-scope.mjs, i18n-keys.mjs
tests/                    vitest specs
```

Routes: `#/`, `#/connections`, `#/sources`, `#/sources/new`, `#/sources/:id`, `#/views`, `#/views/new`, `#/views/:id`, `#/templates`, `#/templates/new?kind=&mode=`, `#/templates/:id`, `#/sync`, `#/settings`, `#/diagnostics`.

## i18n

All user-visible strings live in `src/i18n/{de,en}.ts` (only these two). `de.ts` defines the schema; `en.ts` is typed `typeof de`, so a missing or extra key is a compile error; `tests/i18n-parity.test.ts` also checks placeholders, plural forms and unescaped `@`. German is used for locales starting with `de`, English otherwise. Dates, numbers, bytes and relative times use `Intl` with the active locale. Server-provided messages (errors, validation messages, placeholder descriptions) are shown as delivered; validation codes in `data.params` are translated under `validation.*` (unknown codes shown raw).

## Style isolation inside wp-admin

1. **Namespace.** Everything mounts into `#sw-cloudcalendar-app`, which gets the classes `swc-root` and `swc-fullbleed`. Our CSS only uses `swc-` classes, `.swc-root` descendants or Vue scoped styles. Design tokens are CSS custom properties on `.swc-root` / `.swc-root.swc-dark`.
2. **Quasar CSS is rewritten at build time** by `tools/postcssScope.ts` (only files from `node_modules/quasar` and `@quasar/extras`): `html/body/:root` → `.swc-root`, `body.body--dark` → `.swc-root.swc-dark`, scroll-lock classes neutralised, everything else prefixed with `.swc-root `. `node scripts/check-css-scope.mjs` verifies the built `app.css`.
3. **Portals.** `app.use(Quasar, { config: { globalNodes: { class: 'swc-root' } } })` gives dialogs/menus/notifications the root class; `useThemeSync()` copies `swc-dark` to them. Z-indexes are raised above the admin bar.
4. **Reset against wp-admin bleed.** `.swc-root` resets headings, lists, links, tables, `code`, `label` etc.
5. **Layout.** `.swc-fullbleed` cancels the content padding; sticky offsets use `--wp-admin--admin-bar--height`. Global side effects: body class `swc-admin-page`, Quasar's body classes, `body.q-body--prevent-scroll` while a dialog is open.
6. **CodeMirror** injects its own base styles at runtime (generated `ͼ…` class names, scoped to `.cm-editor`); its colours come from `--swc-cm-*` tokens so light and dark both work. These rules never match wp-admin markup.

## Security notes

- No `v-html` anywhere (ESLint `vue/no-v-html: error`); no `innerHTML`. Server strings are text-interpolated.
- **Server rendered previews** (template preview, view preview, preset preview) are shown only in `<iframe sandbox="" srcdoc="…">` (`components/preview/PreviewFrame.vue`, `utils/previewDoc.ts`): opaque origin, no scripts, no forms, no popups, no top navigation. The document contains the returned HTML, the returned CSS in a `<style>` (`</style` is neutralised) and `{pluginUrl}assets/css/frontend.css`, plus a CSP meta (`default-src 'none'`, styles/images/fonts only from the plugin origin, `script-src 'none'`). Nothing is injected into the admin DOM.
- **Secrets** (`client_secret`, `api_key`, `auth_header`) are write-only inputs (`autocomplete="new-password"`), never prefilled, sent only when non-empty, wiped after saving and when the sheet opens/closes; a "set" badge comes from `secrets_set`.
- Nothing sensitive in localStorage/URL/console. The only localStorage key is `swc-theme` (try/catch). The OAuth `oauth_state` is removed from the URL (`router.replace`) before it is submitted; the authorise URL is only followed if it is `https:`.
- Visual builder: static texts are HTML-escaped and `{`/`}` encoded (cannot open placeholders), CSS class fields are reduced to class tokens; only the Custom-HTML block passes markup, which the server validates like any template.
- Destructive actions use `ConfirmDialog` naming target and consequences (typed name for deleting sources / connections with sources; checkbox + typed source name for publication confirmations; checkbox for "delete data on uninstall").

## Contract-specific behaviour

- **OAuth.** "Authorise" → `POST /admin/connections/{id}/authorize` → `window.location.assign(url)`. On return (`#/connections?oauth_state=…`) the app cleans the URL and calls `POST /admin/oauth/complete`; `?oauth=denied|invalid_state` shows an error.
- **Source confirmations.** A 400 whose `params` contain only `confirm_publication: required` and/or `confirm_private_attachments: required` opens one confirmation dialog per flag (checkbox "I confirm…" + typed source name) and resends with `confirm_publication: true` / `confirm_private_attachments: true`. Mixed with other errors, the errors are shown first. The stored confirmation structures are never sent (only the writable settings).
- **ICS sources** use the external id `feed`; connections without `list_calendars` (Google API-key mode) or failing calendar listings fall back to manual ID entry (prefilled with `test_calendar_id`).
- **Sync** is never synchronous: "Sync now"/"Full resync" → `202 {queued}` → notice. The sync page auto-refreshes every 10 s while the tab is visible.
- **Templates.** Visual mode generates the body client side (`features/templates/visualBuilder.ts`) and stores the block list in `visual_schema` (`{version:1, kind, root, blocks}`). HTML → Visual is only possible while the body still equals the generated markup; otherwise a dialog offers a new visual template. Visual → HTML offers "switch" or "duplicate as HTML". An HTML body that still equals the generated markup is saved as `editor_mode: visual`. Validation (`/validate`) and preview (`/preview`) are debounced (600 ms) and aborted when superseded; errors carry line/column markers in the editor and a clickable list. Save errors `swc_invalid_template` use `data.validation`; delete 409 is shown in the dialog.
- **Views.** Template selects are filtered by kind with "Preset (default)" = `null`; colour overrides are limited to selected sources; default filters only send set values. The live preview renders the **saved** view via `GET /views/{id}/render` (nonce sent).
- **Dates.** Sync runs, logs and audit rows arrive as `YYYY-MM-DD HH:MM:SS` (UTC, no zone); `parseServerDate()` reads them as UTC.

## Versions (Node 18 compatible)

Same pins as sw-backupit: vue 3.5, quasar 2.18, @quasar/vite-plugin 1.9.0 (1.10+ requires Node 20), vite 6, vitest 3.2, vue-i18n 10.0, pinia 3, vue-router 4, eslint 9.39, typescript 5.9, vue-tsc 3, prettier 3. Additionally CodeMirror 6 (`@codemirror/state|view|language|commands|autocomplete|lang-html|lang-css|lint`, `@lezer/highlight`), which has no Node engine restriction.
`npm audit` reports advisories in dev tooling (vitest/vite transitive dependencies, never shipped); the fixed versions need Node 20+.
