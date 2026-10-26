import type { ApiError } from '@/api/client';
import type { AttachmentStats, Connection, Meta, SourceSettings } from '@/types/api';

export type ConfirmationFlag = 'confirm_publication' | 'confirm_private_attachments';

const DEFAULT_MIMES = [
  'image/jpeg',
  'image/png',
  'image/webp',
  'image/gif',
  'application/pdf',
  'text/plain',
  'application/zip',
];

/** Defaults equal to the server's SourceSettings/AttachmentPolicy defaults. */
export function defaultSourceSettings(meta: Meta | null): SourceSettings {
  return {
    sync_enabled: true,
    sync_interval_minutes: 15,
    past_days: 90,
    future_days: 365,
    exclude_private_events: false,
    excluded_event_types: ['workingLocation', 'focusTime', 'outOfOffice'],
    attachments: {
      sync_attachments: false,
      public_only: true,
      max_file_size_mb: 20,
      allowed_mime_types: [...(meta?.default_mime_types ?? DEFAULT_MIMES)],
      generate_image_previews: true,
      show_images: true,
      show_file_list: true,
      quota_mb: 500,
      refresh_hours: 24,
    },
  };
}

/** Only the writable part of the settings (confirmations can only be granted via flags). */
export function writableSettings(s: SourceSettings): SourceSettings {
  const a = s.attachments;
  return {
    sync_enabled: s.sync_enabled,
    sync_interval_minutes: Number(s.sync_interval_minutes),
    past_days: Number(s.past_days),
    future_days: Number(s.future_days),
    exclude_private_events: s.exclude_private_events,
    excluded_event_types: [...s.excluded_event_types],
    attachments: {
      sync_attachments: a.sync_attachments,
      public_only: a.public_only,
      max_file_size_mb: Number(a.max_file_size_mb),
      allowed_mime_types: [...a.allowed_mime_types],
      generate_image_previews: a.generate_image_previews,
      show_images: a.show_images,
      show_file_list: a.show_file_list,
      quota_mb: Number(a.quota_mb),
      refresh_hours: Number(a.refresh_hours),
    },
  };
}

/**
 * Confirmation flags the server asks for (`params.confirm_*: required`). Returns null if the
 * error also contains other validation problems: those must be fixed first.
 */
export function requiredConfirmations(error: ApiError): ConfirmationFlag[] | null {
  if (error.status !== 400) return null;
  const keys = Object.keys(error.params);
  const flags = keys.filter(
    (k): k is ConfirmationFlag =>
      (k === 'confirm_publication' || k === 'confirm_private_attachments') &&
      error.params[k] === 'required',
  );
  if (flags.length === 0 || flags.length !== keys.length) return null;
  return flags.sort((a, b) => (a === b ? 0 : a === 'confirm_publication' ? -1 : 1));
}

/**
 * Whether a calendar of this connection is private at the provider (mirrors
 * SourceManager::provider_private: OAuth and authenticated ICS feeds).
 */
export function connectionIsPrivate(c: Pick<Connection, 'auth_mode'> | null | undefined): boolean {
  return c?.auth_mode === 'oauth' || c?.auth_mode === 'http_header';
}

/** Fixed external id used by ICS connections (one feed per connection). */
export const ICS_EXTERNAL_ID = 'feed';

/** Stored / total attachment files of a source. */
export function attachmentTotals(stats: AttachmentStats | null | undefined): {
  stored: number;
  total: number;
} {
  let stored = 0;
  let total = 0;
  for (const row of stats?.counts ?? []) {
    total += row.files;
    if (row.sync_state === 'stored') stored += row.files;
  }
  return { stored, total };
}
