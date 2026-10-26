import type { Diagnostics } from '@/types/api';
import { parseServerDate } from '@/utils/format';

export type HealthLevel = 'ok' | 'warning' | 'error';

export interface HealthIssue {
  level: Exclude<HealthLevel, 'ok'>;
  /** i18n key below `health.issues.` */
  key: string;
  params?: Record<string, string | number>;
  /** route to fix it */
  to?: string;
}

/** A WP-Cron tick older than this is considered stale. */
const STALE_TICK_SECONDS = 3 * 3600;

/** Derives the dashboard health summary from `GET /admin/diagnostics`. Pure (tested). */
export function healthIssues(d: Diagnostics, now: Date = new Date()): HealthIssue[] {
  const issues: HealthIssue[] = [];
  if (d.environment.missing_tables.length > 0) {
    issues.push({
      level: 'error',
      key: 'missingTables',
      params: { tables: d.environment.missing_tables.join(', ') },
      to: '/diagnostics',
    });
  }
  if (!d.storage.safe) {
    issues.push({ level: 'warning', key: 'storageUnsafe', to: '/diagnostics' });
  }
  if (d.security.encryption_key === 'wp_salts_fallback') {
    issues.push({ level: 'warning', key: 'encryptionFallback', to: '/diagnostics' });
  }
  if (d.cron.mode === 'wp' && d.cron.wp_cron_disabled) {
    issues.push({ level: 'error', key: 'wpCronDisabled', to: '/settings' });
  }
  const last = parseServerDate(d.cron.last_tick);
  if (
    d.sources.length > 0 &&
    (!last || (now.getTime() - last.getTime()) / 1000 > STALE_TICK_SECONDS)
  ) {
    issues.push({ level: 'warning', key: 'cronStale', to: '/diagnostics' });
  }
  const failing = d.sources.filter((s) => s.status === 'error' || s.failures > 0);
  if (failing.length > 0) {
    issues.push({
      level: 'warning',
      key: 'sourcesFailing',
      params: { n: failing.length },
      to: '/sources',
    });
  }
  const broken = d.connections.filter(
    (c) => c.status === 'failed' || c.status === 'reauth_required',
  );
  if (broken.length > 0) {
    issues.push({
      level: 'warning',
      key: 'connectionsBroken',
      params: { n: broken.length },
      to: '/connections',
    });
  }
  return issues;
}

export function overallHealth(issues: HealthIssue[]): HealthLevel {
  if (issues.some((i) => i.level === 'error')) return 'error';
  return issues.length > 0 ? 'warning' : 'ok';
}
