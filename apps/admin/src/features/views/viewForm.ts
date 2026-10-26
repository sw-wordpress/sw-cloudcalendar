import type { CalendarView, DisplayMode, ViewDefaultFilters, ViewInput } from '@/types/api';

export type TriState = 'default' | 'yes' | 'no';

/** Editable representation of `default_filters` (empty string / 'default' = not set). */
export interface FilterForm {
  limit: string;
  days: string;
  start: string;
  end: string;
  past: TriState;
  search: string;
  location: string;
  sort: '' | 'asc' | 'desc';
  show_images: TriState;
  show_attachments: TriState;
}

function tri(value: unknown): TriState {
  if (value === true || value === 'true' || value === '1' || value === 1) return 'yes';
  if (value === false || value === 'false' || value === '0' || value === 0) return 'no';
  return 'default';
}
function text(value: unknown): string {
  return value === undefined || value === null ? '' : String(value);
}

export function toFilterForm(raw: ViewDefaultFilters | unknown[] | null | undefined): FilterForm {
  const f: ViewDefaultFilters = raw && !Array.isArray(raw) ? raw : {};
  return {
    limit: text(f.limit),
    days: text(f.days),
    start: text(f.start),
    end: text(f.end),
    past: tri(f.past),
    search: text(f.search),
    location: text(f.location),
    sort: f.sort === 'asc' || f.sort === 'desc' ? f.sort : '',
    show_images: tri(f.show_images),
    show_attachments: tri(f.show_attachments),
  };
}

/** Only set values are sent; the server validates them with the shortcode filter schema. */
export function fromFilterForm(form: FilterForm): ViewDefaultFilters {
  const out: ViewDefaultFilters = {};
  const int = (v: string): number | null => {
    const n = Number(v.trim());
    return v.trim() !== '' && Number.isFinite(n) ? n : null;
  };
  const limit = int(form.limit);
  if (limit !== null) out.limit = limit;
  const days = int(form.days);
  if (days !== null) out.days = days;
  if (form.start.trim() !== '') out.start = form.start.trim();
  if (form.end.trim() !== '') out.end = form.end.trim();
  if (form.past !== 'default') out.past = form.past === 'yes';
  if (form.search.trim() !== '') out.search = form.search.trim();
  if (form.location.trim() !== '') out.location = form.location.trim();
  if (form.sort !== '') out.sort = form.sort;
  if (form.show_images !== 'default') out.show_images = form.show_images === 'yes';
  if (form.show_attachments !== 'default') out.show_attachments = form.show_attachments === 'yes';
  return out;
}

export function blankView(): ViewInput {
  return {
    name: '',
    enabled: true,
    source_ids: [],
    default_filters: {},
    display_mode: 'list',
    allowed_modes: ['list'],
    container_template_id: null,
    event_template_id: null,
    detail_template_id: null,
    allowed_template_ids: [],
    timezone: '',
    language: '',
    color_rules: {},
    html_cache: true,
    date_format: '',
    time_format: '',
  };
}

/** Copies the editable fields of a stored view (drops id/revision/shortcode/dynamic). */
export function toViewInput(v: CalendarView): ViewInput {
  const rules = v.color_rules && !Array.isArray(v.color_rules) ? v.color_rules : {};
  return {
    name: v.name,
    enabled: v.enabled,
    source_ids: [...v.source_ids],
    default_filters:
      v.default_filters && !Array.isArray(v.default_filters) ? { ...v.default_filters } : {},
    display_mode: v.display_mode,
    allowed_modes: [...v.allowed_modes],
    container_template_id: v.container_template_id,
    event_template_id: v.event_template_id,
    detail_template_id: v.detail_template_id,
    allowed_template_ids: [...v.allowed_template_ids],
    timezone: v.timezone ?? '',
    language: v.language ?? '',
    color_rules: { ...rules },
    html_cache: v.html_cache,
    date_format: v.date_format ?? '',
    time_format: v.time_format ?? '',
  };
}

/** Color overrides may only reference selected sources (server: `color_rules: invalid`). */
export function cleanColorRules(
  rules: Record<string, string>,
  sourceIds: number[],
): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [id, color] of Object.entries(rules)) {
    if (sourceIds.includes(Number(id)) && /^#[0-9a-fA-F]{6}$/.test(color)) {
      out[id] = color.toLowerCase();
    }
  }
  return out;
}

export const DISPLAY_MODES: DisplayMode[] = ['list', 'cards', 'month'];
