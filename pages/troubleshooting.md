# Troubleshooting

Start with **Calendar → Diagnostics**, the source's **Last error** and synchronisation history. Change one setting at a time. Do not publish private data, disable storage protection or bypass signature checks to fix an unrelated error.

## No events or stale events

1. Confirm the source is **Active** and the view has **View enabled** on with that source selected.
2. Check the source's available events. The event must fall inside the source sync window and the view's date/month/search/location filters. A wider embed cannot recover events outside the stored window.
3. Test the connection; check **Last synchronisation**, **Next run** and errors. Choose **Synchronise now** and wait for the queue.
4. In Diagnostics check cron. If `DISABLE_WP_CRON` is set, configure [System cron and its server job](./configuration.md#background-processing).
5. For local sources, confirm the supplying plugin is active, its calendar exists, and the current visitor may read it. Remote resynchronisation does not repair an unavailable local adapter.
6. Clear the plugin HTML cache after relevant changes. Also check a full-page/CDN cache and the [preload duration](./views.md#protected-calendars-and-preloading); local data may remain cached until expiry.

Use **Full resynchronisation** only after ordinary connection/queue checks; it resets sync state and fetches the complete configured window in the background.

## OAuth fails or needs repeated authorisation

- Copy the full **Redirect URI** shown by the plugin into the provider registration exactly, including scheme and path.
- Confirm client ID, client secret, tenant and requested read permissions match that registration. Renew an expired Microsoft client secret using its Value, not Secret ID.
- After changing client ID, tenant or file access, choose **Authorise** again.
- External Google apps in Testing require re-authorisation after seven days; review production publishing or an Internal Workspace audience with the provider administrator.
- If consent was cancelled or state expired, start **Authorise** again rather than reusing an old callback URL.

Google API-key connections cannot list calendars and only read public calendars. Enter the actual calendar ID manually. Microsoft shared mailboxes, group calendars and app-only access are not supported.

## Only “Loading calendar…” appears

Protected/local views normally load through JavaScript and WordPress REST. Allow their scripts and requests in browser/privacy tools, check browser errors and ensure a cache/security layer does not block authenticated REST requests. Compare a permitted and an anonymous visitor. Do not enable preloading as a shortcut without reviewing its shared-cache warning.

## Files or images do not appear

1. Check **Diagnostics → Private file storage** and correct an unprotected directory; run **Check again**. Prefer storage outside the public web root.
2. Enable **Synchronise attachments** on the source. For Google Drive, also request file access on the connection and re-authorise.
3. Review **Only verified public files (recommended)**: Microsoft mailbox and ICS files are never verifiably public. Do not disable it on a public source unless private files may genuinely be published.
4. Check maximum file size, allowed MIME types, source/global quotas, attachment errors and check interval.
5. Check **Generate image previews**, image processing support, source/view image-file settings and whether the template actually includes that content.

Stored/known attachment counts are not a promise that every provider file is deliverable. Permissions and policy still apply on delivery.

## Layout, search or mode switching differs from expectations

Allow the requested mode in **Display modes allowed for embeds**. Enable **Display tabs on the website** separately for visible tabs. Search is limited to the chosen date range. Check explicit month/date filters when changing between Month and List/Cards.

If the same custom layout follows every mode, check view-wide and mode-specific templates. Approving **Alternative event templates** does not change the normal template. Resolve editor validation errors before saving; arbitrary HTML cannot be converted automatically to visual blocks.

## Missing vendor directory or update failure

Install the prepared release ZIP rather than GitHub's source archive. A checkout needs the existing developer build. For signed-update errors, preserve the error and verify the trusted key/release setup with the operator; do not install an unverified replacement as a workaround.

## Request support

Use [GitHub Issues](https://github.com/sw-wordpress/sw-cloudcalendar/issues). Include plugin/WordPress/PHP versions, provider type, steps, expected/actual behaviour, whether the issue occurs anonymously, and a reviewed Diagnostics export. Remove feed URLs with tokens, credentials, account names, event contents and private server details from logs and screenshots.
