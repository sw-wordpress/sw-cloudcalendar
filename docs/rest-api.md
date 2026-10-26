# REST API – `sw-cloudcalendar/v1`

Base: `/wp-json/sw-cloudcalendar/v1/` (plain permalinks: `/?rest_route=/sw-cloudcalendar/v1/`).

- **Public routes** have an open `permission_callback`; authorization happens per calendar *source* inside the
  read services on every request. Unknown, disabled and unauthorized resources are indistinguishable (404 or empty).
- **Admin routes** (`/admin/...`) require the capability `manage_sw_calendar`. Cookie requests need the REST nonce
  (`X-WP-Nonce`). The nonce may be refreshed by WordPress (`X-WP-Nonce` response header).
- Collections: `{ "items": [...], "total": n, "pages": n }`.
- Errors: WordPress format `{ "code": "swc_…", "message": "translated text", "data": { "status": 400, "params": { "field": "error_code" } } }`.
  Field names in `params` use dots for nested fields (`settings.attachments.max_file_size_mb`).
- Times are ISO 8601 UTC strings (`2026-10-24T17:20:00Z`) everywhere in admin responses. Event times in public
  responses carry the offset of the view time zone (`2026-11-01T09:00:00+01:00`) or are dates (all-day).
- Maps are always JSON objects, also when empty (`{}`). DELETE responses: `{ "deleted": true }`.

## Public

| Method | Path | Notes |
|---|---|---|
| GET | `/views/{id}/events` | Filter params (below), `page`, `per_page` (≤100). Response see SPECIFICATIONS.md §13; `pagination` additionally contains `has_previous` and `has_next`. `Cache-Control: public, max-age=60` only if every source is public, otherwise `private, no-store` + `Vary: Cookie`. |
| GET | `/views/{id}/render` | Same filters + `page_url` (same host; detail/month links point there). `{ html, css: {scopeClass: css}, total }`. Invalid filters → 400 `swc_invalid_filter` with `params`. |
| GET | `/events/{id}?view={viewId}` | Rendered detail `{ html, css }`; 404 unless the occurrence belongs to an authorized source of the enabled view. |
| GET | `/attachments/{id}/content` | Streams the stored file after source ACL + policy + publication check. `nosniff`, `Content-Disposition` inline only for raster images, sandbox CSP. Public sources: `public, max-age=300`; others `private, no-store`. |
| GET | `/attachments/{id}/preview` | Preview image (falls back to the original). |
| GET | `/oauth/callback` | Provider redirect URI. Stores the code with the single-use state and redirects to `admin.php?page=sw-cloudcalendar#/connections?oauth_state=…` (or `?oauth=denied|invalid_state`). |
| GET | `/editor/views` | `edit_posts`: `[{id, name, enabled, display_mode}]` for the block editor. |

`admin-ajax.php?action=sw_calendar_rest_nonce` returns `{ nonce }` (empty for guests, never cached) – used by the frontend
script for dynamically loaded (protected) views on publicly cached pages.

### Filter parameters (shortcode, block, REST)

`source` (ids, comma separated; intersection with the view), `view` (`month|list|cards`), `limit` (1–100), `days`
(1–1095, from now to the end of the N-th local day), `start`/`end` (`YYYY-MM-DD`, inclusive dates), `past` (bool),
`search`/`location` (≤150 chars), `exclude` (occurrence ids), `sort` (`asc|desc`), `template` (event template id
allowed by the view), `show_images`/`show_attachments` (bool), `month` (`YYYY-MM`, month mode), `page` (1…10000 forward,
-1…-10000 backward = events that ended before the window start; 0 is invalid), `per_page`, `navigation` (bool, overrides
the view's `show_navigation`).
`category` is rejected (`not_supported`, docs/decisions.md D-12). Precedence: `start/end` > `days` > view default.

## Admin

### Connections

| Method | Path | Body / result |
|---|---|---|
| GET | `/admin/connections` | collection of Connection |
| POST | `/admin/connections` | `{ provider, auth_mode, name, settings, secrets }` → 201 Connection |
| GET/PUT/DELETE | `/admin/connections/{id}` | PUT body like POST (provider/auth_mode immutable). DELETE removes the connection, its secrets and all its sources. |
| POST | `/admin/connections/{id}/test` | `{ ok, message, error_code }` |
| GET | `/admin/connections/{id}/calendars` | collection of `{ external_id, name, timezone, color, public_at_provider }` |
| POST | `/admin/connections/{id}/authorize` | `{ url }` – open in the same window; the provider returns to `/oauth/callback`. |
| POST | `/admin/oauth/complete` | `{ state }` → Connection (exchanges the stored code; state bound to the current user, single use, 10 min). |
| POST | `/admin/connections/{id}/disconnect` | revokes tokens (Google) and deletes them locally → Connection |

Connection:

```json
{
  "id": 1, "provider": "google|microsoft|ics", "name": "…",
  "auth_mode": "oauth|api_key|none|http_header",
  "status": "disconnected|connecting|connected|reauth_required|failed|disabled",
  "account_ref": "user@example.org", "settings": { … }, "secrets_set": ["client_secret"],
  "authorized": true, "capabilities": { "list_calendars": true, "incremental_sync": true, "etag": false,
  "recurrence": true, "file_attachments": false, "cloud_reference_downloads": false, "public_attachment_verification": false },
  "source_count": 2, "redirect_uri": "https://…/wp-json/sw-cloudcalendar/v1/oauth/callback", "updated_at": "…"
}
```

Settings per provider: ICS `{ url, allow_http, max_feed_mb }`; Google `{ client_id, attachment_access, test_calendar_id }`;
Microsoft `{ client_id, tenant_id (required, e.g. contoso.onmicrosoft.com, a GUID or "organizations"), attachment_access }`. Secrets (write only, never returned): `client_secret` (oauth),
`api_key` (Google api_key mode), `auth_header` (ICS http_header mode, full header value e.g. `Basic …`). Empty secret
values keep the stored value. Auth modes per provider: `GET /admin/meta` → `auth_modes`.

### Sources

| Method | Path | Body / result |
|---|---|---|
| GET/POST | `/admin/sources` | POST `{ connection_id, external_id, name, color, timezone, visibility, roles, settings }` → 201 (status `draft`) |
| GET/PUT/DELETE | `/admin/sources/{id}` | PUT may also contain `status` (`draft|active|paused|disabled`), `confirm_publication: true`, `confirm_private_attachments: true` |
| POST | `/admin/sources/{id}/sync` | `{ full?: bool }` → 202 `{ queued: true }` (background job, never synchronous) |
| GET | `/admin/sources/{id}/runs` | paginated sync runs |

Source:

```json
{
  "id": 3, "connection_id": 1, "provider": "google", "external_id": "…", "name": "…", "color": "#3366cc",
  "timezone": "Europe/Berlin", "visibility": "public|authenticated|roles", "roles": ["editor"],
  "status": "draft|active|paused|error|disabled", "revision": 7,
  "settings": {
    "sync_enabled": true, "sync_interval_hours": 6, "past_days": 90, "future_days": 365,
    "exclude_private_events": false, "excluded_event_types": ["workingLocation","focusTime","outOfOffice"],
    "attachments": { "sync_attachments": false, "public_only": true, "max_file_size_mb": 20,
      "allowed_mime_types": ["image/jpeg", "…"], "generate_image_previews": true, "show_images": true,
      "show_file_list": true, "quota_mb": 500, "refresh_hours": 24,
      "publish_private_attachments_confirmed": false, "publish_private_attachments_confirmation": null },
    "publication_confirmation": { "user_id": 1, "confirmed_at": "…", "source_revision": 4 } | null
  },
  "requires_confirmation": true, "last_success_at": "…", "next_attempt_at": "…", "last_error_code": "RATE_LIMITED",
  "failures": 0, "syncing": false, "sync_queued": false, "occurrence_count": 120,
  "attachments": { "counts": [{ "sync_state": "stored", "publication_state": "PUBLIC_VERIFIED", "files": 3 }], "last_errors": ["PUBLIC_STATUS_UNKNOWN: …"], "bytes": 123 },
  "storage_safe": true
}
```

Validation codes worth handling in the UI: `confirm_publication: required` (public source from a private provider
calendar → show a confirmation dialog, resend with `confirm_publication: true`), `confirm_private_attachments:
required` (public source with `public_only=false`), `settings.attachments.sync_attachments: storage_unsafe`,
`roles: required|unknown_role`, `status: invalid`. Limits: interval 1–48 hours (default 6), window days 0/1–`max_window_days`.

### Views

| Method | Path | Body / result |
|---|---|---|
| GET/POST | `/admin/views` | View body: `{ name, enabled, source_ids, default_filters, display_mode, allowed_modes, container_template_id, event_template_id, detail_template_id, allowed_template_ids, timezone, language, color_rules, html_cache, date_format, time_format, show_navigation }` |
| GET/PUT/DELETE | `/admin/views/{id}` | result adds `id, revision, shortcode, dynamic` (dynamic = contains non-public sources → loaded via REST) |
| POST | `/admin/views/preview` | `{ id?, view: <View body>, filters?: {…} }` → `{ html, css: {scope: css}, total }` – renders the unsaved (or disabled) form state with the ACL of the current admin; validation errors like PUT; never cached |

Template ids `null` mean the built-in preset of the display mode. `language` is empty (site/user locale) or a
WordPress locale such as `de_DE`; it is switched to while the view is rendered (month/weekday names, labels). Live preview of a saved view: public
`GET /views/{id}/render` (the admin has access to all sources it may see).

### Templates

| Method | Path | Body / result |
|---|---|---|
| GET | `/admin/templates?kind=` | collection of summaries `{ id, name, kind, editor_mode, revision, preset_key, updated_at, used_by: [viewIds] }` |
| POST | `/admin/templates` | `{ name, kind: container|event|detail, editor_mode: visual|html, body, css, visual_schema }` |
| GET/PUT/DELETE | `/admin/templates/{id}` | kind immutable; DELETE → 409 `swc_conflict` if used by a view |
| POST | `/admin/templates/validate` | `{ kind, body, css }` → `{ valid, errors: [{code, message, line, column, params}], css_error }` |
| POST | `/admin/templates/preview` | `{ mode: list|cards|month, container?: {body, css}, event?: {…}, detail?: {…} }` → `{ html, css: {scope: css}, errors: {kind: [error]} }` (missing parts use the preset of the mode). Render inside a sandboxed iframe. |
| GET | `/admin/templates/presets` | `[{ key, name, mode, description, templates: { container: {body, css}, event: …, detail: … } }]` |
| POST | `/admin/templates/presets/{key}/install` | creates editable copies → 201 list |
| GET | `/admin/templates/reference` | placeholder reference per kind: `{ container: [{name, type, description}], event: …, detail: … }` |

Save errors: 400 `swc_invalid_template` with `data.params.body = "invalid_template"` and `data.validation` (same
shape as `/validate`) when body or CSS are invalid; other field errors (e.g. `name`) are a plain `swc_invalid`.

### System

| Method | Path | Result |
|---|---|---|
| GET | `/admin/diagnostics` | see `Diagnostics::collect()` (versions, environment, security, storage, cron, cache, connections, sources, recent_errors) |
| POST | `/admin/diagnostics/storage-check` | re-verifies the private storage `{ checked_at, safe, method, reason }` (`method`: `outside_document_root|http_probe`, `reason`: `not_writable|unverifiable_location|probe_failed|publicly_accessible|probe_inconclusive_<status>`) |
| POST | `/admin/cache/clear` | `{ scope }` → template AST and HTML caches are cleared together |
| GET/PUT | `/admin/settings` | `{ html_cache, delete_data_on_uninstall, cron_mode: wp|system, log_level, log_retention_days, run_retention_days, global_quota_mb, revoked_file_grace_hours, public_rate_limit, default_view_timezone }` |
| GET | `/admin/logs?level=&source_id=` | paginated log entries `{ id, created_at, level, code, source_id, message, context }` |
| GET | `/admin/audit` | paginated audit entries `{ id, created_at, user_id, user_name, action, object_type, object_id, context }` |
| GET | `/admin/sync/runs` | paginated runs of all sources |
| POST | `/admin/sync/schedule` | queues all due sources → 202 `{ queued }` |
| GET | `/admin/meta` | `{ roles, auth_modes, supported_mime_types, default_mime_types, event_types, max_window_days, redirect_uri, storage_safe, site_timezone, timezones, date_format, time_format, encryption_fallback }` |

Sync run: `{ id, source_id, started_at, finished_at, status: running|success|failed, mode: full|delta, created, updated, deleted, attachments, error_code, message, metrics }`.
