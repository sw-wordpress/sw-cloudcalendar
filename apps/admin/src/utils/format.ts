/** Locale-aware formatting helpers. All functions are pure; pass the locale explicitly. */

const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'] as const;

export function formatNumber(value: number, locale: string, maximumFractionDigits = 0): string {
  return new Intl.NumberFormat(locale, { maximumFractionDigits }).format(value);
}

/** Binary units (1024) with a short label, e.g. `1.5 MB`. */
export function formatBytes(bytes: number | null | undefined, locale: string): string {
  if (bytes === null || bytes === undefined || !Number.isFinite(bytes) || bytes < 0) return '–';
  if (bytes < 1024) return `${formatNumber(bytes, locale)} B`;
  const exp = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), UNITS.length - 1);
  const value = bytes / 1024 ** exp;
  const digits = value >= 100 ? 0 : value >= 10 ? 1 : 2;
  return `${formatNumber(value, locale, digits)} ${UNITS[exp]}`;
}

/** `value` is a fraction (0..1) or already percent when `isPercent`. */
export function formatPercent(value: number, locale: string, isPercent = false): string {
  const fraction = isPercent ? value / 100 : value;
  return new Intl.NumberFormat(locale, {
    style: 'percent',
    maximumFractionDigits: fraction < 0.1 ? 1 : 0,
  }).format(Number.isFinite(fraction) ? fraction : 0);
}

/** `3725` -> `1:02:05`, `65` -> `1:05`. */
export function formatDuration(totalSeconds: number | null | undefined): string {
  if (totalSeconds === null || totalSeconds === undefined || !Number.isFinite(totalSeconds)) {
    return '–';
  }
  const s = Math.max(0, Math.round(totalSeconds));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  const pad = (n: number): string => String(n).padStart(2, '0');
  return h > 0 ? `${h}:${pad(m)}:${pad(sec)}` : `${m}:${pad(sec)}`;
}

/**
 * Parses server times. ISO 8601 strings are used as is; database rows (sync runs, logs, audit)
 * arrive as `YYYY-MM-DD HH:MM:SS` in UTC without a zone designator and are read as UTC here
 * (`new Date()` would otherwise interpret them as local time). Unix seconds are accepted too.
 */
export function parseServerDate(value: string | number | null | undefined): Date | null {
  if (value === null || value === undefined || value === '') return null;
  let date: Date;
  if (typeof value === 'number') date = new Date(value * 1000);
  else if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/.test(value))
    date = new Date(`${value.replace(' ', 'T')}Z`);
  else date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

export function formatDateTime(iso: string | number | null | undefined, locale: string): string {
  const date = parseServerDate(iso);
  if (!date) return '–';
  return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}

export function formatTime(iso: string | null | undefined, locale: string): string {
  const date = parseServerDate(iso);
  if (!date) return '–';
  return new Intl.DateTimeFormat(locale, { timeStyle: 'medium' }).format(date);
}

const RELATIVE_STEPS: Array<[Intl.RelativeTimeFormatUnit, number]> = [
  ['year', 365 * 86400],
  ['month', 30 * 86400],
  ['week', 7 * 86400],
  ['day', 86400],
  ['hour', 3600],
  ['minute', 60],
];

/** `in 2 hours` / `3 days ago` via Intl.RelativeTimeFormat. */
export function formatRelative(
  iso: string | number | null | undefined,
  locale: string,
  now: Date = new Date(),
): string {
  const date = parseServerDate(iso);
  if (!date) return '–';
  const diffSeconds = Math.round((date.getTime() - now.getTime()) / 1000);
  const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
  const abs = Math.abs(diffSeconds);
  for (const [unit, seconds] of RELATIVE_STEPS) {
    if (abs >= seconds) return rtf.format(Math.round(diffSeconds / seconds), unit);
  }
  return rtf.format(diffSeconds, 'second');
}

/** Duration between two server times in seconds (null while running / unknown). */
export function secondsBetween(
  start: string | null | undefined,
  end: string | null | undefined,
): number | null {
  const a = parseServerDate(start);
  const b = parseServerDate(end);
  if (!a || !b) return null;
  return Math.max(0, (b.getTime() - a.getTime()) / 1000);
}
