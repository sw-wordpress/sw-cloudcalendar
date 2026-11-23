# Sources and attachments

A source selects one calendar from a connection. Open **Calendar → Sources → Create source**, choose the connection and calendar, then set the name, **Colour**, **Time zone** and **Visibility**.

New sources start as drafts and default to signed-in users. Choose **Create as draft**, inspect the settings, then change **Status** to **Active** and save. Only active sources synchronise and display. Pausing is useful when a calendar should temporarily stop being delivered.

When calendar listing is available, **Add multiple calendars → Create selected as drafts** preserves their names/colours, creates signed-in-only drafts, and requires individual activation. The source list also has bulk activation, pause and visibility actions; review every selected calendar before applying a visibility change.

## Visibility

Visibility applies to the entire source, not individual view layouts:

- **Public**: all visitors, including those not logged in.
- Signed-in users: only visitors with a WordPress login.
- Selected **Roles**: only signed-in visitors with an allowed role.

Publishing an OAuth/authenticated calendar publicly requires a logged confirmation. Titles, dates, locations and descriptions can then be indexed and copied by public caches. A calendar private at its provider is not automatically safe to publish on your site. Use **Exclude events marked as private** if appropriate; this is off by default, so do not assume private-marked events are filtered automatically.

Local adapters can enforce additional visitor-specific restrictions. Always test anonymously and with a permitted/non-permitted account. [Preloading](./views.md#protected-calendars-and-preloading) changes shared-cache responsibilities.

## Synchronisation

| Setting | Initial value / meaning |
| --- | --- |
| **Synchronise automatically** | On for remote sources; active status is also required. |
| **Interval in hours (1–48)** | 6 hours. |
| **Days in the past** | 90. |
| **Days in the future** | 365. |
| **Exclude events marked as private** | Off. |
| **Exclude event types** | Google's Working location, Focus time and Out of office are initially excluded. |

The maximum window is 1095 days in each direction unless changed by the server administrator. An embed cannot display events that were never synchronised. The source time zone is used for all-day events and recurrence processing; the view controls display time zone.

Choose **Synchronise now** for a queued refresh; inspect **Last synchronisation**, **Last error**, **Next run**, available events and history. **Full resynchronisation** discards stored sync state and fetches the window again in the background; use it for recovery, not on every page view. If the queue does not run, check [background processing](./configuration.md#background-processing).

Local-source events are live from the source plugin, not copied by this queue. Its own availability and permissions still apply.

## Attachments

Attachments are optional and **Synchronise attachments** is initially off. Before enabling it, check **Calendar → Diagnostics → Private file storage**. If storage cannot be proven protected against web access, attachment synchronisation stays blocked.

1. Enable **Synchronise attachments** on the source.
2. Keep **Only verified public files (recommended)** enabled unless you have reviewed and approved private-file publication.
3. Set permitted file types, size/quota and check interval; save and run synchronisation.
4. Check **Stored / known**, storage use and recent attachment errors. Enable image/file display in the source, view and chosen template as needed.

Initial attachment limits are 20 MB per file, 500 MB per source and a 24-hour check interval. JPEG, PNG, WebP, GIF, PDF, plain text and ZIP are initially allowed. Image previews, **Show images** and **Show file list** are initially on, but they do not enable attachment sync themselves. The global quota is an additional limit.

“Verified public” means the provider's sharing state could be verified, for example a Google Drive file shared with anyone with the link. An unknown state counts as private. Microsoft mailbox and ICS attachments cannot be verified public and are not delivered with this option enabled. Downloading Google Drive attachments requires the connection's additional Drive access and authorisation.

Disabling the public-only policy on a public source requires a separate logged confirmation: private files can then become downloadable by anyone. Protected files are stored locally and delivered through WordPress access checks; do not link directly to storage paths or treat quotas as backups.

## Delete a source

Deleting stops delivery and deletes stored events, attachments and synchronisation state for that source. Existing views may then have fewer or no events. This does not delete appointments at the external provider. Deleting a view instead only removes that presentation.
