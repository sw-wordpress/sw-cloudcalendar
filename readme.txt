=== SW CloudCalendar ===
Contributors: swcloudcalendar
Tags: calendar, google calendar, microsoft 365, ics, events
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Read-only integration of Google Calendar, Microsoft 365 and ICS feeds with local synchronisation, flexible templates and protected attachments.

== Description ==

* Google Calendar (OAuth or API key), Microsoft 365 (delegated OAuth) and ICS feeds; several connections and sources.
* Background synchronisation (WP-Cron or system cron), incremental where the provider allows it, local recurrence expansion.
* Month grid, list and card layouts; shortcode `[sw_calendar id="…"]` and the block "Calendar (SW CloudCalendar)".
* Template editor (visual and HTML) with a safe placeholder language, presets and live preview.
* Visibility per calendar source: public, logged-in users or selected roles. Protected calendars are never written into cacheable page HTML.
* Optional local attachments (images as gallery, other files as download list), delivered only by WordPress after an access check.

No provider requests are made during regular page views.

== Installation ==

1. Upload the plugin ZIP and activate it.
2. Recommended: define `SW_CALENDAR_ENCRYPTION_KEY` and `SW_CALENDAR_PRIVATE_STORAGE_PATH` in `wp-config.php`.
3. Calendar → Connections: add a connection, then sources and a view.

== Privacy ==

Appointment data and (optionally) attachments of the configured calendars are copied to this site and removed when they leave the synchronisation window, when the source is deleted or when the plugin data is removed. The plugin does not track visitors. A privacy policy text suggestion is added to the WordPress privacy guide.

== Changelog ==

= 1.0.0 =
* First release.
