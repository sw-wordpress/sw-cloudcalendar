# Setup and operation

Details moved from the README. Decisions and limits: [decisions.md](decisions.md);
template language: [templates.md](templates.md).

## Connections (Calendar → Connections)

- **ICS**: feed URL (HTTPS; `webcal://`/`webcals://` is converted to `https://`; plain HTTP only with the
  explicit connection option), optionally an Authorization header (e.g. basic auth).
- **Google**: in the Google Cloud Console create an OAuth client of type "Web application", enable the
  Calendar API (and the Drive API for attachments), enter the redirect URI shown in the plugin; store client ID and
  secret in the plugin and choose **Authorise**. Alternatively an API key for calendars that are public at Google.
  `drive.readonly` is a restricted scope and may require app verification. With audience "External" in status
  "Testing" the authorisation expires after 7 days.
- **Microsoft 365**: in Entra ID create an app registration (platform "Web") with the redirect URI, delegated
  permissions `Calendars.Read`, `User.Read`, `offline_access`, and a client secret; store tenant, client ID and
  secret in the plugin and choose **Authorise**. Shared mailboxes, group calendars and app-only access are not
  supported in V1 (see D-16 in [decisions.md](decisions.md)).

The redirect URI is fixed: `/wp-json/sw-cloudcalendar/v1/oauth/callback` (D-18).

## Sources and visibility

New sources are drafts; only **Status → Active** synchronises and displays them. Visibility always applies to a
whole source (public, logged-in users or selected roles). Showing a calendar that is private at the provider
publicly requires an explicit confirmation, which is logged in the audit log.

## Shortcode

```text
[sw_calendar id="12"]
[sw_calendar id="12" view="list" limit="5" days="30"]
[sw_calendar id="12" source="2,3" search="Gottesdienst"]
[sw_calendar id="12" start="2026-11-01" end="2026-12-31" location="Kirche"]
[sw_calendar id="12" template="7" show_images="true" show_attachments="false"]
[sw_calendar id="12" navigation="false"]
```

All filter parameters: `source`, `view`, `limit`, `days`, `start`, `end`, `past`, `search`, `location`,
`exclude`, `sort`, `template`, `show_images`, `show_attachments`, `month`, `page`, `per_page`, `navigation`
(`category` is rejected as not supported, D-12). Full schema: [rest-api.md](rest-api.md).

- Filters can only narrow a view, never extend it.
- Navigation: month view "Previous month"/"Next month", list and cards "Earlier events"/"More events" (earlier =
  events that already ended before the display range). Can be disabled per view and overridden with `navigation`.
- Views with non-public sources are loaded via JavaScript and never written into (possibly publicly cached) page
  HTML (D-04).

## `wp-config.php` details

- Without `SW_CALENDAR_ENCRYPTION_KEY` a key is derived from the WordPress salts (warning in **Diagnostics**).
  A base64 value of exactly 32 bytes is used as is; any other string is hashed into a key. For key rotation move the
  old key to `SW_CALENDAR_ENCRYPTION_KEY_PREVIOUS`; secrets are re-encrypted when read (D-19). Both can also be
  set as environment variables.
- Without `SW_CALENDAR_PRIVATE_STORAGE_PATH` the plugin creates a random directory below `uploads/` and accepts it
  only if an HTTP probe proves that files in it cannot be fetched (on Nginx this usually needs its own `location`
  rule); otherwise attachment sync stays blocked (D-21).

## Operation

- Each source is synchronised every 6 hours by default (1–48 hours, **Synchronise now** at any time). The queue
  checks every 5 minutes via WP-Cron whether something is due; a daily job rotates logs and prunes storage.
- WP-CLI: `wp sw-cloudcalendar sources list | connections test --id=1 | sync --source=3 | sync --all --full |
  sync --due | attachments sync --source=3 | attachments prune --dry-run | cache clear | diagnostics |
  queue run [--seconds=50]`.
