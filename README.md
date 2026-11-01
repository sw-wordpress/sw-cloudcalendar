# SW CloudCalendar

[![Release](https://img.shields.io/github/v/release/sw-wordpress/sw-cloudcalendar?sort=semver)](https://github.com/sw-wordpress/sw-cloudcalendar/releases)
[![CI](https://github.com/sw-wordpress/sw-cloudcalendar/actions/workflows/tests.yml/badge.svg)](https://github.com/sw-wordpress/sw-cloudcalendar/actions/workflows/tests.yml)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=sw-wordpress_sw-cloudcalendar&metric=coverage)](https://sonarcloud.io/summary/overall?id=sw-wordpress_sw-cloudcalendar)
[![SonarQube Issues](https://sonarcloud.io/api/project_badges/measure?project=sw-wordpress_sw-cloudcalendar&metric=vulnerabilities)](https://sonarcloud.io/project/issues?id=sw-wordpress_sw-cloudcalendar&impactSoftwareQualities=SECURITY&resolved=false)
![PHPStan level 8](https://img.shields.io/badge/phpstan%20level-8%20of%209-brightgreen?style=flat-square&logo=php)
![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?style=flat-square&logo=php&logoColor=white)
![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b?style=flat-square&logo=wordpress&logoColor=white)
[![License GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)

Shows appointments from Google Calendar, Microsoft 365 and [ICS](https://en.wikipedia.org/wiki/ICalendar) feeds
on your WordPress site, read-only. Events are copied to your site in the background and displayed as a list,
cards or a month grid via shortcode or block.

## Features

- **Three calendar sources** — Google Calendar, Microsoft 365 (Exchange Online) and any ICS feed.
- **Background synchronisation** — Page views never contact the calendar providers.
- **Your own layout** — Visual and HTML template editor with presets and live preview.
- **Visibility per calendar** — Public, logged-in users or selected roles; protected calendars never end up in cached pages.
- **Protected attachments** — Optionally stored locally and delivered only by WordPress after an access check.

## Languages

English, German.

## Usage

1. Open **Calendar → Connections**, click **Add connection** and choose ICS, Google or Microsoft 365.
   For Google and Microsoft 365 click **Authorise** after saving ([provider setup](docs/setup.md#connections-calendar--connections)).
2. Open **Calendar → Sources**, click **Create source**, choose the calendar and **Visibility**, then **Create as draft**.
3. Edit the source, set **Status** to **Active** and save. The first synchronisation runs in the background.
4. Open **Calendar → Views**, click **Create view**, pick sources and **Display mode**, then **Copy shortcode**
   (e.g. `[sw_calendar id="12"]`) or add the block **Calendar (SW CloudCalendar)**. More examples: [docs/setup.md](docs/setup.md#shortcode).

## Configuration

### Requirements

PHP 8.2+ with sodium, mbstring, JSON and XML (fileinfo recommended, GD or Imagick for image previews), WordPress 6.5+, MySQL 8.0+ or MariaDB 10.6+ (InnoDB), single site or multisite.

### Settings

Global options (HTML cache, default time zone, API rate limit, cron mode, storage quota, logging, data removal)
are under **Calendar → Settings**; health checks under **Calendar → Diagnostics**.

Recommended `wp-config.php` constants (details: [docs/setup.md](docs/setup.md#wp-configphp-details)):

```php
// Key for stored credentials. Default: derived from the WordPress salts (warning in Diagnostics).
define( 'SW_CALENDAR_ENCRYPTION_KEY', '…' );
// Previous key during key rotation. Default: none.
define( 'SW_CALENDAR_ENCRYPTION_KEY_PREVIOUS', '…' );
// Attachment storage OUTSIDE the web root. Default: random folder below uploads/,
// used only if it is proven not to be publicly reachable; otherwise attachment sync stays blocked.
define( 'SW_CALENDAR_PRIVATE_STORAGE_PATH', '/var/lib/wordpress-private/sw-cloudcalendar' );
// Maximum synchronisation window in days per direction. Default: 1095.
define( 'SW_CALENDAR_MAX_WINDOW_DAYS', 1095 );
```

`SW_CALENDAR_AUTOLOADER` and `SW_CALENDAR_TEST_SAVEPOINTS` are for the test environment only.

### Background tasks

Every 5 minutes WP-Cron checks which sources are due (default: every 6 hours per source); a daily job cleans up
logs and files. On sites with little traffic or with `DISABLE_WP_CRON`, set **Cron mode** to
**System cron (WP-CLI)** and add `* * * * * cd /path/to/wordpress && wp sw-cloudcalendar queue run`.
All WP-CLI commands: [docs/setup.md](docs/setup.md#operation).

### Integrations

- **Google Calendar** — OAuth or API key; Drive files as attachments.
- **Microsoft 365** — Delegated OAuth with your own Entra ID app registration.
- **ICS feeds** — Any iCalendar URL, optionally with an Authorization header.

### Deactivation and removal

Deactivating keeps all data and only stops the scheduled tasks. Deleting the plugin also keeps everything unless
**Delete all data on uninstall** is enabled under **Calendar → Settings**; then all tables, options, the plugin's
own folder below `uploads/` and Google tokens (revoked) are removed. A folder set via
`SW_CALENDAR_PRIVATE_STORAGE_PATH` is never deleted. On multisite, removal only covers the main site.

## Troubleshooting

| Problem | Solution |
|---|---|
| Events are not updated. | With `DISABLE_WP_CRON`, switch to **System cron (WP-CLI)** and set up the cron job; see **Calendar → Diagnostics**. |
| Attachments are not synchronised. | The fallback folder below `uploads/` is publicly reachable (often Nginx). Set `SW_CALENDAR_PRIVATE_STORAGE_PATH`. |
| Google asks to **Re-authorise** every 7 days. | The Google app is in "Testing" mode; publish it or use "Internal" with Google Workspace. |
| A protected calendar only shows "Loading calendar…". | Protected views are loaded with JavaScript; make sure scripts are not blocked. |

## Built with AI

This plugin was implemented with AI assistance. Why it exists and how AI is used:
[sw-wordpress manifest](https://github.com/sw-wordpress/.github/blob/main/profile/README.md).

AI agents must follow the project rules before changing code:

- Rules and mandatory coverage: [AGENTS.md](AGENTS.md), [docs/CODE-STYLE.md](docs/CODE-STYLE.md)
- Plan and specification: [PLAN.md](PLAN.md), [docs/SPECIFICATIONS.md](docs/SPECIFICATIONS.md), [docs/CALENDAR_PLUGIN_CONCEPT.md](docs/CALENDAR_PLUGIN_CONCEPT.md)
- Decisions and open points: [docs/decisions.md](docs/decisions.md), further docs in [docs/](docs/)
- README structure: [README-STANDARD.md](https://github.com/sw-wordpress/sw-securelogin/blob/master/docs/README-STANDARD.md)

## Development

Requirements: PHP 8.2+, Composer, Node.js 18.18+ with npm, Make, MariaDB (test database), GNU gettext (translations)
and Docker for the release build.

```sh
composer install
make build                                   # admin SPA (apps/admin) -> assets/admin/
bin/test-db.sh start                         # private MariaDB on 127.0.0.1:33078
(cd tests/wp && composer install)            # WordPress test environment

composer check                               # cs, cs:wp, PHPStan and PHPUnit (unit + integration)
composer test:wp                             # WordPress integration tests
bin/i18n.sh check                            # translations complete and up to date
(cd apps/admin && npm run typecheck && npm run lint && npm test)

bin/build-zip.sh                             # release ZIP in dist/
```

Every change needs tests; line coverage must stay above 80 % for PHP and JavaScript/TypeScript.
Details: [docs/development.md](docs/development.md), [docs/testing.md](docs/testing.md) (incl. browser tests),
[docs/sonarqube.md](docs/sonarqube.md). GitHub Actions run all checks on every push.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) — see [LICENSE](LICENSE). Provided by Stefan Warnat.

Bundled third-party components (license texts in the linked repositories; the release package does not include copies):

- PHP, isolated in `vendor-prefixed/` of the release build:
  - [sabre/vobject](https://github.com/sabre-io/vobject) — BSD-3-Clause
  - [sabre/xml](https://github.com/sabre-io/xml) — BSD-3-Clause
  - [sabre/uri](https://github.com/sabre-io/uri) — BSD-3-Clause
- JavaScript, bundled in `assets/admin/`:
  - [Vue](https://github.com/vuejs/core) — MIT
  - [Vue Router](https://github.com/vuejs/router) — MIT
  - [Pinia](https://github.com/vuejs/pinia) — MIT
  - [Vue I18n](https://github.com/intlify/vue-i18n) (incl. @intlify packages) — MIT
  - [Quasar](https://github.com/quasarframework/quasar) — MIT
  - [CodeMirror 6](https://github.com/codemirror/dev) (incl. @lezer, style-mod, w3c-keyname, crelt, find-cluster-break) — MIT
- Fonts, bundled in `assets/admin/assets/`:
  - [Inter](https://github.com/rsms/inter) — OFL-1.1
  - [JetBrains Mono](https://github.com/JetBrains/JetBrainsMono) — OFL-1.1
  - [Material Icons Round](https://github.com/google/material-design-icons) (via @quasar/extras) — Apache-2.0
