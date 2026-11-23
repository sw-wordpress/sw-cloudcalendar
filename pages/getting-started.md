# Getting started

## Requirements

You need administrator access to WordPress and an account/feed whose events you may publish.

- WordPress 6.5 or later; PHP 8.2 or later with sodium, mbstring, JSON and XML.
- MySQL 8.0+ or MariaDB 10.6+ with InnoDB. Single-site and multisite installations are supported.
- Outbound network access to the calendar provider and working background tasks.
- For attachments: fileinfo is recommended; GD or Imagick is needed for image previews, and storage must be protected against direct web access.

The administration UI is available in English and German. This guide uses its English labels.

## Install

1. Back up an existing site before installing or updating plugins.
2. Download the installable `sw-cloudcalendar-<version>.zip` asset from [GitHub Releases](https://github.com/sw-wordpress/sw-cloudcalendar/releases), not GitHub's automatically generated source archive. Release packages include PHP dependencies and the built administration interface.
3. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, select the ZIP and install it.
4. Activate **SW CloudCalendar**. Open **Calendar → Diagnostics** and resolve missing extensions, tables or security warnings.
5. Ask the server administrator to configure a dedicated encryption key and private file storage as described in [configuration](./configuration.md#encryption-and-storage). Attachments are optional and off by default.

A source checkout is not an installable release package without its build dependencies. Developers should use the existing [development guide](https://github.com/sw-wordpress/sw-cloudcalendar/blob/master/docs/development.md).

## Publish a first calendar

For a first test, use a synthetic, publicly shareable event within the next few days. Keep attachments disabled initially.

1. Open **Calendar → Connections → Add connection**. Choose the provider and sign-in method, enter a name and the required details, then **Save**. For OAuth choose **Authorise** after saving. See [provider-specific instructions](./connections.md).
2. Choose **Test** for that connection and resolve errors before continuing.
3. Open **Calendar → Sources → Create source**. Choose the connection and calendar. ICS has exactly one feed; API-key connections require a calendar ID instead of a calendar picker.
4. Check the source name, time zone and **Visibility**. New sources default to signed-in users. Use public visibility only if all selected calendar content may be public.
5. Choose **Create as draft**. Reopen the source, set **Status** to **Active** and save. Review any publication confirmation carefully.
6. Choose **Synchronise now** and allow the background queue to run. Check the source's events and synchronisation history; local sources instead read from the supplying plugin.
7. Open **Calendar → Views → Create view**. Enter a name, select the source, leave **View enabled** on and choose **List** as the default display mode. Keep **Preset (default)** templates for the initial check, then save.
8. Use **Copy shortcode**. Paste it into a WordPress Shortcode block on a test page:

```text
[sw_calendar id="12"]
```

Replace `12` with your actual view ID. Alternatively add **Calendar (SW CloudCalendar)** and select the view. Publish or preview the page.

## Check the result

You should see the test event's title and dates. Open its details, try search and navigation, and verify that only the chosen sources appear. Check once while signed in and once in a private browser window: a signed-in-only source must not expose its events anonymously. An administrator's preview alone is not a permissions test.

If events are missing, check [troubleshooting](./troubleshooting.md) rather than changing source visibility merely to make the calendar appear.
