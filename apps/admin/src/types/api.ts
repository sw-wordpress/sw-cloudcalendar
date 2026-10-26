/**
 * Mirror of the REST contract `sw-cloudcalendar/v1` (docs/rest-api.md). Field names are kept
 * exactly as delivered by the server (snake_case). Times are ISO 8601 UTC strings unless noted;
 * rows read directly from database tables (sync runs, logs, audit) use the MySQL format
 * `YYYY-MM-DD HH:MM:SS` (UTC) – see `parseServerDate()` in utils/format.ts.
 */

/** Collection envelope `{ items, total, pages }`. */
export interface Paginated<T> {
  items: T[];
  total: number;
  pages: number;
}

/** `{ code, message, data: { status, params } }` – normalised into `ApiError` by the client. */
export interface WpError {
  code: string;
  message: string;
  data?: { status?: number; params?: Record<string, string>; validation?: TemplateValidation };
}

/* ------------------------------------------------------------------ connections */

export type Provider = 'google' | 'microsoft' | 'ics';
export type AuthMode = 'oauth' | 'api_key' | 'none' | 'http_header';
export type ConnectionStatus =
  'disconnected' | 'connecting' | 'connected' | 'reauth_required' | 'failed' | 'disabled';
export type SecretKey = 'client_secret' | 'api_key' | 'auth_header';

export interface IcsConnectionSettings {
  url?: string;
  allow_http?: boolean;
  max_feed_mb?: number;
}
export interface GoogleConnectionSettings {
  client_id?: string;
  attachment_access?: boolean;
  test_calendar_id?: string;
}
export interface MicrosoftConnectionSettings {
  client_id?: string;
  tenant_id?: string;
  attachment_access?: boolean;
}
export type ConnectionSettings = IcsConnectionSettings &
  GoogleConnectionSettings &
  MicrosoftConnectionSettings;

export interface ProviderCapabilities {
  list_calendars: boolean;
  incremental_sync: boolean;
  etag: boolean;
  recurrence: boolean;
  file_attachments: boolean;
  cloud_reference_downloads: boolean;
  public_attachment_verification: boolean;
}

export interface Connection {
  id: number;
  provider: Provider;
  name: string;
  auth_mode: AuthMode;
  status: ConnectionStatus;
  account_ref: string | null;
  settings: ConnectionSettings;
  /** Presence of write-only secrets (never their values). */
  secrets_set: string[];
  authorized: boolean;
  capabilities: ProviderCapabilities;
  source_count: number;
  redirect_uri: string;
  updated_at: string | null;
}

/** POST body; on PUT `provider`/`auth_mode` are immutable. Empty secret values keep the stored one. */
export interface ConnectionInput {
  provider: Provider;
  auth_mode: AuthMode;
  name: string;
  settings: ConnectionSettings;
  secrets: Partial<Record<SecretKey, string>>;
}

export interface ConnectionTestResult {
  ok: boolean;
  message: string;
  error_code: string | null;
}

export interface ProviderCalendar {
  external_id: string;
  name: string;
  timezone: string | null;
  color: string | null;
  public_at_provider: boolean | null;
}

/* ------------------------------------------------------------------ sources */

export type Visibility = 'public' | 'authenticated' | 'roles';
export type SourceStatus = 'draft' | 'active' | 'paused' | 'error' | 'disabled';
/** Statuses an administrator may set (`error` is set by the sync engine only). */
export type EditableSourceStatus = Exclude<SourceStatus, 'error'>;

export interface Confirmation {
  user_id: number;
  confirmed_at: string;
  source_revision: number;
}

export interface AttachmentSettings {
  sync_attachments: boolean;
  public_only: boolean;
  max_file_size_mb: number;
  allowed_mime_types: string[];
  generate_image_previews: boolean;
  show_images: boolean;
  show_file_list: boolean;
  quota_mb: number;
  refresh_hours: number;
  /** read-only */
  publish_private_attachments_confirmed?: boolean;
  /** read-only, granted only via `confirm_private_attachments` */
  publish_private_attachments_confirmation?: Confirmation | null;
}

export interface SourceSettings {
  sync_enabled: boolean;
  sync_interval_minutes: number;
  past_days: number;
  future_days: number;
  exclude_private_events: boolean;
  excluded_event_types: string[];
  attachments: AttachmentSettings;
  /** read-only, granted only via `confirm_publication` */
  publication_confirmation?: Confirmation | null;
}

export interface AttachmentCount {
  sync_state: string;
  publication_state: string;
  files: number;
}

export interface AttachmentStats {
  counts: AttachmentCount[];
  last_errors: string[];
  bytes: number;
}

export interface Source {
  id: number;
  connection_id: number;
  provider: Provider | null;
  external_id: string;
  name: string;
  color: string;
  timezone: string;
  visibility: Visibility;
  roles: string[];
  status: SourceStatus;
  revision: number;
  settings: SourceSettings;
  requires_confirmation: boolean;
  last_success_at: string | null;
  next_attempt_at: string | null;
  last_error_code: string | null;
  failures: number;
  syncing: boolean;
  sync_queued: boolean;
  occurrence_count: number;
  attachments: AttachmentStats;
  storage_safe: boolean;
}

export interface SourceCreateInput {
  connection_id: number;
  external_id: string;
  name: string;
  color: string;
  timezone: string;
  visibility: Visibility;
  roles: string[];
  settings: Partial<SourceSettings>;
}

export interface SourceUpdateInput {
  name: string;
  color: string;
  timezone: string;
  visibility: Visibility;
  roles: string[];
  settings: Partial<SourceSettings>;
  status?: EditableSourceStatus;
  confirm_publication?: true;
  confirm_private_attachments?: true;
}

export interface QueuedResult {
  queued: boolean;
}

/* ------------------------------------------------------------------ sync runs */

export type SyncRunStatus = 'running' | 'success' | 'failed';
export type SyncMode = 'full' | 'delta';

export interface SyncRun {
  id: number;
  source_id: number;
  /** MySQL format, UTC */
  started_at: string;
  finished_at: string | null;
  status: SyncRunStatus | string;
  mode: SyncMode | string;
  created: number;
  updated: number;
  deleted: number;
  attachments: number;
  error_code: string | null;
  message: string | null;
  metrics: Record<string, unknown> | unknown[];
}

/* ------------------------------------------------------------------ views */

export type DisplayMode = 'list' | 'cards' | 'month';

/** Default filters of a view (same keys as the shortcode/REST filter parameters). */
export interface ViewDefaultFilters {
  source?: string | number[];
  view?: DisplayMode;
  limit?: number | string;
  days?: number | string;
  start?: string;
  end?: string;
  past?: boolean | string;
  search?: string;
  location?: string;
  sort?: 'asc' | 'desc';
  template?: number | string;
  show_images?: boolean | string;
  show_attachments?: boolean | string;
  month?: string;
  per_page?: number | string;
}

export interface ViewInput {
  name: string;
  enabled: boolean;
  source_ids: number[];
  default_filters: ViewDefaultFilters;
  display_mode: DisplayMode;
  allowed_modes: DisplayMode[];
  container_template_id: number | null;
  event_template_id: number | null;
  detail_template_id: number | null;
  allowed_template_ids: number[];
  timezone: string;
  language: string;
  /** source id -> #rrggbb (serialised as an object; an empty one may arrive as `[]` or `{}`) */
  color_rules: Record<string, string>;
  html_cache: boolean;
  date_format: string;
  time_format: string;
}

export interface CalendarView extends ViewInput {
  id: number;
  revision: number;
  shortcode: string;
  /** Contains non-public sources: the frontend loads it via REST. */
  dynamic: boolean;
}

/** Public `GET /views/{id}/render`. `css` maps a scope class to its CSS. */
export interface RenderResult {
  html: string;
  css: Record<string, string> | string[] | string;
  total: number;
}

/* ------------------------------------------------------------------ templates */

export type TemplateKind = 'container' | 'event' | 'detail';
export type EditorMode = 'visual' | 'html';

export interface TemplateSummary {
  id: number;
  name: string;
  kind: TemplateKind;
  editor_mode: EditorMode;
  revision: number;
  preset_key: string | null;
  updated_at: string | null;
  /** ids of views using the template */
  used_by: number[];
}

export interface Template extends TemplateSummary {
  body: string;
  css: string;
  visual_schema: unknown;
}

export interface TemplateInput {
  name: string;
  kind: TemplateKind;
  editor_mode: EditorMode;
  body: string;
  css: string;
  visual_schema: unknown;
}

export interface TemplateSyntaxError {
  code: string;
  message: string;
  line: number;
  column: number;
  params?: Record<string, string>;
}

export interface TemplateValidation {
  valid: boolean;
  errors: TemplateSyntaxError[];
  css_error: string | null;
}

export interface TemplatePart {
  body: string;
  css: string;
}

export interface TemplatePreviewInput {
  mode: DisplayMode;
  container?: TemplatePart;
  event?: TemplatePart;
  detail?: TemplatePart;
}

export interface TemplatePreviewResult {
  html: string;
  css: string;
  /** per kind; empty array/object when everything compiled */
  errors: Partial<Record<TemplateKind, Array<Partial<TemplateSyntaxError>>>> | unknown[];
}

export interface TemplatePreset {
  key: string;
  name: string;
  mode: DisplayMode | string;
  description: string;
  templates: Record<TemplateKind, TemplatePart>;
}

export type PlaceholderType = 'text' | 'html' | 'url' | 'flag' | 'color' | string;

export interface PlaceholderInfo {
  name: string;
  type: PlaceholderType;
  description: string;
}

export type TemplateReference = Record<TemplateKind, PlaceholderInfo[]>;

/* ------------------------------------------------------------------ system */

export interface StorageCheck {
  root?: string;
  checked_at: string | number | null;
  safe: boolean;
  method: string;
  reason: string;
}

export interface DiagnosticsSource {
  id: number;
  name: string;
  status: SourceStatus;
  visibility: Visibility;
  last_success_at: string | null;
  next_attempt_at: string | null;
  last_error_code: string | null;
  failures: number;
  cursor: 'none' | 'stored';
  occurrences: number;
  last_run: SyncRun | null;
  attachments: AttachmentStats;
}

export interface DiagnosticsConnection {
  id: number;
  name: string;
  provider: Provider;
  auth_mode: AuthMode;
  status: ConnectionStatus;
}

export interface Diagnostics {
  generated_at: string;
  versions: { plugin: string; db_schema: number; wordpress: string; php: string };
  environment: {
    sodium: boolean;
    fileinfo: boolean;
    gd: boolean;
    imagick: boolean;
    intl: boolean;
    curl: boolean;
    missing_tables: string[];
  };
  security: {
    encryption_key: 'wp_salts_fallback' | 'dedicated_key';
    capability_holders: string[];
  };
  storage: {
    configured: 'constant' | 'uploads_fallback';
    safe: boolean;
    method: string;
    reason: string;
    checked_at: string | null;
    bytes: number;
    quota_mb: number;
  };
  cron: {
    mode: CronMode;
    wp_cron_disabled: boolean;
    next_tick: string | null;
    next_daily: string | null;
    last_tick: string | null;
    last_maintenance: string | null;
    /** job status -> count */
    jobs: Record<string, number> | unknown[];
  };
  cache: {
    html_cache_enabled: boolean;
    generation: number;
    hits: number;
    misses: number;
    since: string | number | null;
    persistent_object_cache: boolean;
  };
  connections: DiagnosticsConnection[];
  sources: DiagnosticsSource[];
  recent_errors: LogEntry[];
}

export type CronMode = 'wp' | 'system';
export type LogLevel = 'debug' | 'info' | 'warning' | 'error';

export interface Settings {
  html_cache: boolean;
  delete_data_on_uninstall: boolean;
  cron_mode: CronMode;
  log_level: LogLevel;
  log_retention_days: number;
  run_retention_days: number;
  global_quota_mb: number;
  revoked_file_grace_hours: number;
  public_rate_limit: number;
  default_view_timezone: string;
}

export interface LogEntry {
  id: number;
  /** MySQL format, UTC */
  created_at: string;
  level: LogLevel | string;
  code: string;
  source_id: number | null;
  message: string;
  context: Record<string, unknown> | unknown[];
}

export interface AuditEntry {
  id: number;
  /** MySQL format, UTC */
  created_at: string;
  user_id: number;
  user_name: string | null;
  action: string;
  object_type: string;
  object_id: number;
  context: Record<string, unknown> | unknown[];
}

export interface Meta {
  /** role slug -> translated name */
  roles: Record<string, string>;
  auth_modes: Record<Provider, AuthMode[]>;
  supported_mime_types: string[];
  default_mime_types: string[];
  event_types: string[];
  max_window_days: number;
  redirect_uri: string;
  storage_safe: boolean;
  site_timezone: string;
  timezones: string[];
  date_format: string;
  time_format: string;
  encryption_fallback: boolean;
}

export interface DeletedResult {
  deleted: boolean;
}
