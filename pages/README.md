# Documentation maintenance (not published)

## Page plan recorded before implementation

Audience: WordPress site administrators and editors who publish read-only calendars. The existing README and operational guide are predominantly English; the user guide follows that language and the English UI labels.

Evidence reviewed: root README and AGENTS, `docs/setup.md`, `docs/templates.md`, release notes, `Settings.php`, source/view form defaults, provider setup helpers, Vue editors and English UI translations. The release builder uses rsync exclusions rather than a runtime-only allowlist.

| Page | Reason based on implemented behaviour |
| --- | --- |
| `index.md` | Factual overview linking connections, sources, views, templates and operation. |
| `getting-started.md` | Requirements, installable release ZIP and the first connection → active source → embedded view. |
| `connections.md` | Distinct ICS, Google OAuth/API-key, Microsoft delegated OAuth and local-provider setup and limits. |
| `sources.md` | Draft/active sources, source-wide visibility, sync windows, bulk creation and attachment policies. |
| `views.md` | Shortcode/block embedding, modes, filters, templates, navigation and the security-sensitive preload option. |
| `templates.md` | Preset selection, visual/HTML editing and links to the existing technical language reference. |
| `configuration.md` | Global defaults, encryption/storage, cron, diagnostics, updates and removal. |
| `troubleshooting.md` | Actionable checks for sync, authorisation, empty output, attachments and caches. |
| `Changelog.md` | Unreleased documentation entry plus only attributable release-note history. |

No separate API, developer architecture or provider-console reference is copied: existing technical docs remain canonical. GitHub releases 1.0.0 and 0.8.0 have no release-note body, so no historical changes are assigned to them. Pages configuration could not be confirmed (API returned 404 during parent preflight); no repository settings are changed. Default branch is `master`; no custom domain was identified. The project Pages base is `/sw-cloudcalendar/`.

## Updating

Edit the relevant guide with the same change as the UI and update `pages/Changelog.md` as required by AGENTS. Keep only nonempty categories Feature, Bugs and Other topics. At release time move actual Unreleased changes to the actual version/date; do not infer release history from the current feature set. The exact shared footer is rendered once through the theme layout-bottom slot, including sidebar and 404 pages, with responsive CSS. Navigation and local search include Changelog; this maintenance file is excluded using `srcExclude`.

Real application screenshot: `pages/public/screenshots/sw-cloudcalendar-views.png`, captured read-only from the supplied WordPress demo at `wp-demo.stefanwarnat.de`. It shows the initial **No views yet / Create view** state, not a configured connection/source or successful sync. The parent captured and visually checked it; the toolbar/user menu were cropped. No calendar content or credentials are shown. It is embedded with an explicit initial-state caption and descriptive alt text in Views. The Sources route hung during the capture session; no screenshot of that route or a configured calendar is claimed. Future screenshots must use synthetic data and meaningful alt text; never include accounts, secrets or private calendar/feed URLs.

## Isolated production build

Use Node.js 22 and npm. From the repository root:

```sh
DOCS_BUILD="$(mktemp -d "${TMPDIR:-/tmp}/sw-cloudcalendar-docs.XXXXXX")"
cp -R pages "$DOCS_BUILD/pages"
npm install --prefix "$DOCS_BUILD" --no-save --package-lock=false --ignore-scripts --no-audit --no-fund vitepress@1.6.4
"$DOCS_BUILD/node_modules/.bin/vitepress" build "$DOCS_BUILD/pages"
# Optional local preview (keep running while viewing):
"$DOCS_BUILD/node_modules/.bin/vitepress" preview "$DOCS_BUILD/pages"
# Remove the temporary directory after use:
# rm -rf "$DOCS_BUILD"
```

VitePress 1.6.4 is pinned; transitive dependencies are resolved at build time (there is deliberately no docs package/lockfile). Toolchain, copied sources, cache and output stay outside the checkout. Do not run npm install for docs in the root or `apps/admin`. Dead-link validation must remain enabled. Check `/sw-cloudcalendar/`, each sidebar link, local search, `Changelog.html` and the footer on narrow and wide layouts.

## GitHub Pages

The `documentation.yml` workflow builds relevant pull requests; it does not deploy them. Pushes to `master` and manual runs selected on `master` can deploy using the official Pages artifact/deployment actions. Manual runs on other refs only validate. Build permissions are read-only; Pages/OIDC permissions and serial deployment concurrency are confined to the deploy job.

One-time maintainer action: **Settings → Pages → Source → GitHub Actions**. Confirm repository access and Pages environment restrictions if the API returns 404; that response alone does not establish whether Pages is disabled or inaccessible. A successful build is not proof of publication. After merge, inspect the workflow deployment and open its actual environment URL before reporting publication. No PAT or `gh-pages` branch is needed.

## Release packaging

`bin/build-zip.sh` explicitly excludes `/pages`, including screenshots, cache, dist and any accidentally created docs dependencies. The signed-release workflow additionally rejects a ZIP containing `pages/`. Existing `/node_modules`, `/apps`, `/dist` and `/build` exclusions remain in place. The plugin's existing dependencies and runtime sources are unchanged. A full ZIP build still requires the existing Composer, PHP, admin toolchain and Docker setup.
