import { getConfig } from '@/config';
import { ApiClient } from './client';
import type { Query } from './client';
import type {
  AuditEntry,
  CalendarView,
  Connection,
  ConnectionInput,
  ConnectionTestResult,
  DeletedResult,
  Diagnostics,
  DisplayMode,
  LogEntry,
  Meta,
  Paginated,
  ProviderCalendar,
  QueuedResult,
  RenderResult,
  Settings,
  Source,
  SourceCreateInput,
  SourceUpdateInput,
  StorageCheck,
  SyncRun,
  Template,
  TemplateInput,
  TemplateKind,
  TemplatePreset,
  TemplatePreviewInput,
  TemplatePreviewResult,
  TemplateReference,
  TemplateSummary,
  TemplateValidation,
  ViewInput,
} from '@/types/api';

export interface PageQuery {
  page?: number;
  per_page?: number;
}

/** Typed per-resource API. Build once from a client; `useApi()` provides the app singleton. */
export function createApi(client: ApiClient) {
  return {
    client,
    connections: {
      list: () => client.get<Paginated<Connection>>('admin/connections'),
      get: (id: number) => client.get<Connection>(`admin/connections/${id}`),
      create: (input: ConnectionInput) => client.post<Connection>('admin/connections', input),
      update: (id: number, input: Omit<ConnectionInput, 'provider' | 'auth_mode'>) =>
        client.put<Connection>(`admin/connections/${id}`, input),
      remove: (id: number) => client.delete<DeletedResult>(`admin/connections/${id}`),
      test: (id: number) => client.post<ConnectionTestResult>(`admin/connections/${id}/test`),
      calendars: (id: number) =>
        client.get<Paginated<ProviderCalendar>>(`admin/connections/${id}/calendars`),
      authorize: (id: number) => client.post<{ url: string }>(`admin/connections/${id}/authorize`),
      disconnect: (id: number) => client.post<Connection>(`admin/connections/${id}/disconnect`),
      completeOAuth: (state: string) => client.post<Connection>('admin/oauth/complete', { state }),
    },
    sources: {
      list: () => client.get<Paginated<Source>>('admin/sources'),
      get: (id: number) => client.get<Source>(`admin/sources/${id}`),
      create: (input: SourceCreateInput) => client.post<Source>('admin/sources', input),
      update: (id: number, input: SourceUpdateInput) =>
        client.put<Source>(`admin/sources/${id}`, input),
      remove: (id: number) => client.delete<DeletedResult>(`admin/sources/${id}`),
      sync: (id: number, full = false) =>
        client.post<QueuedResult>(`admin/sources/${id}/sync`, full ? { full: true } : {}),
      runs: (id: number, q: PageQuery = {}) =>
        client.get<Paginated<SyncRun>>(`admin/sources/${id}/runs`, q as Query),
    },
    views: {
      list: () => client.get<Paginated<CalendarView>>('admin/views'),
      get: (id: number) => client.get<CalendarView>(`admin/views/${id}`),
      create: (input: ViewInput) => client.post<CalendarView>('admin/views', input),
      update: (id: number, input: ViewInput) =>
        client.put<CalendarView>(`admin/views/${id}`, input),
      remove: (id: number) => client.delete<DeletedResult>(`admin/views/${id}`),
      /** Public render route (the nonce identifies the admin, so non-public sources render too). */
      render: (id: number, query: { view?: DisplayMode; page_url?: string } = {}) =>
        client.get<RenderResult>(`views/${id}/render`, query as Query),
      /** Admin preview of the unsaved form state (also for disabled views); never cached. */
      preview: (body: { id?: number; view: ViewInput; filters?: Record<string, string> }) =>
        client.post<RenderResult>('admin/views/preview', body),
    },
    templates: {
      list: (kind?: TemplateKind) =>
        client.get<Paginated<TemplateSummary>>('admin/templates', { kind }),
      get: (id: number) => client.get<Template>(`admin/templates/${id}`),
      create: (input: TemplateInput) => client.post<Template>('admin/templates', input),
      update: (id: number, input: Omit<TemplateInput, 'kind'>) =>
        client.put<Template>(`admin/templates/${id}`, input),
      remove: (id: number) => client.delete<DeletedResult>(`admin/templates/${id}`),
      validate: (input: { kind: TemplateKind; body: string; css: string }, signal?: AbortSignal) =>
        client.post<TemplateValidation>('admin/templates/validate', input, { signal }),
      preview: (input: TemplatePreviewInput, signal?: AbortSignal) =>
        client.post<TemplatePreviewResult>('admin/templates/preview', input, { signal }),
      presets: () => client.get<TemplatePreset[]>('admin/templates/presets'),
      installPreset: (key: string) =>
        client.post<TemplateSummary[]>(
          `admin/templates/presets/${encodeURIComponent(key)}/install`,
        ),
      reference: () => client.get<TemplateReference>('admin/templates/reference'),
    },
    system: {
      diagnostics: () => client.get<Diagnostics>('admin/diagnostics'),
      storageCheck: () => client.post<StorageCheck>('admin/diagnostics/storage-check'),
      clearCache: (scope = 'all') =>
        client.post<{ cleared: boolean }>('admin/cache/clear', { scope }),
      settings: () => client.get<Settings>('admin/settings'),
      updateSettings: (input: Partial<Settings>) => client.put<Settings>('admin/settings', input),
      logs: (q: PageQuery & { level?: string; source_id?: number } = {}) =>
        client.get<Paginated<LogEntry>>('admin/logs', q as Query),
      audit: (q: PageQuery = {}) => client.get<Paginated<AuditEntry>>('admin/audit', q as Query),
      runs: (q: PageQuery = {}) => client.get<Paginated<SyncRun>>('admin/sync/runs', q as Query),
      schedule: () => client.post<{ queued: number }>('admin/sync/schedule'),
      meta: () => client.get<Meta>('admin/meta'),
    },
  };
}

export type Api = ReturnType<typeof createApi>;

let instance: Api | null = null;

export function useApi(): Api {
  if (instance === null) {
    const cfg = getConfig();
    instance = createApi(new ApiClient({ restRoot: cfg.restRoot, nonce: cfg.nonce }));
  }
  return instance;
}
