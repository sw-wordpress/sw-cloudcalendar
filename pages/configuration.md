# Configuration and operation

## Global settings

Open **Calendar → Settings**, adjust values and **Save**. These are the implemented defaults:

| Setting | Default | Notes |
| --- | --- | --- |
| **HTML cache** | On | View-level caching must also be enabled. **Clear cache** clears template and HTML caches. |
| **Default time zone of views** | WordPress time zone | A view can override it; invalid/manual-offset site zones fall back to UTC. |
| **Requests per minute and IP (public API)** | 120 | 0 disables the limit; administrators are exempt. |
| **Cron mode** | WP-Cron | System cron requires an external scheduled command. |
| **Global storage quota** | 2048 MB | Additional to source quotas. |
| **Grace period of revoked files** | 24 hours | Retention before cleanup, not a continued permission to download. |
| **Log level** | Info | Debug should be temporary; review logs before sharing. |
| **Keep logs** | 30 days | Daily maintenance handles cleanup. |
| **Keep runs** | 90 days | Synchronisation history retention. |
| **Delete all data on uninstall** | Off | Destructive opt-in; see removal below. |

## Encryption and storage

Ask the server administrator to set a dedicated secret encryption key in `wp-config.php`, keep it secret and include it in secure backups. **Calendar → Diagnostics → Key for secrets → Show solution** generates a key snippet in the browser. Do not copy a sample key from public documentation.

Without `SW_CALENDAR_ENCRYPTION_KEY`, credentials use a key derived from WordPress salts and Diagnostics warns. Changing salts can make those credentials unreadable. When rotating a dedicated key, retain the old value as `SW_CALENDAR_ENCRYPTION_KEY_PREVIOUS`; secrets are re-encrypted on read. Both keys may also be supplied through environment variables. Follow the [existing operational details](https://github.com/sw-wordpress/sw-cloudcalendar/blob/master/docs/setup.md#wp-configphp-details).

For attachments, configure a writable directory outside the public web root, for example:

```php
define( 'SW_CALENDAR_PRIVATE_STORAGE_PATH', '/var/lib/wordpress-private/sw-cloudcalendar' );
```

The example is a server path, not a URL; adapt ownership/permissions to your host. Without this constant the plugin creates a random uploads subdirectory and accepts it only if an HTTP probe proves files cannot be fetched. Nginx usually requires its own server rule. If protection cannot be established, attachment sync remains blocked. Use **Diagnostics → Private file storage → Check again** after correcting the setup.

`SW_CALENDAR_MAX_WINDOW_DAYS` defaults to 1095 days per direction and caps source synchronisation windows. Do not use the development-only `SW_CALENDAR_AUTOLOADER` or `SW_CALENDAR_TEST_SAVEPOINTS` constants on a live site.

## Background processing

The queue checks due sources every five minutes via WP-Cron; a source's normal sync interval is six hours. A daily maintenance job cleans logs and files. Low site traffic can delay WP-Cron.

If WP-Cron is disabled or unreliable, set **Cron mode → System cron (WP-CLI)** and ask the server administrator to run the queue every minute as the appropriate WordPress user:

```text
* * * * * cd /path/to/wordpress && wp sw-cloudcalendar queue run
```

Replace the path and ensure `wp` is available to cron. Merely selecting System cron does not create a server job. Use **Calendar → Diagnostics** to check last/next queue ticks and maintenance.

For operators, useful checks include:

```sh
wp sw-cloudcalendar diagnostics
wp sw-cloudcalendar sources list
wp sw-cloudcalendar connections test --id=1
wp sw-cloudcalendar sync --source=3
wp sw-cloudcalendar cache clear
```

Use your actual IDs. The [operation reference](https://github.com/sw-wordpress/sw-cloudcalendar/blob/master/docs/setup.md#operation) includes full/due sync, attachment cleanup and queue options.

## Diagnostics, logs and privacy

**Calendar → Diagnostics** shows environment versions, schema, encryption/storage health, cron, cache and connection/source state. **Export as JSON** excludes secrets and event contents; still inspect exports for operational details before sending them to support. Settings includes logs and an audit log. Publication confirmations record time, user and revision.

Remote calendar events and optional attachments are copied to this WordPress site, so include them in your data-protection, backup and retention policies. Local providers supply live data instead. Restrict administration access and review which roles hold `manage_sw_calendar`. Public output can be indexed; changing visibility cannot recall copies already taken by others.

## Updates

Signed GitHub updates are manually installed through **Plugins → Installed Plugins**. They require the locally trusted public release key and correctly signed releases; a source checkout alone does not establish trust for existing installations. Do not bypass signature failures. Maintainers should use the [signed-update operations guide](https://github.com/sw-wordpress/sw-cloudcalendar/blob/master/docs/GITHUB-UPDATES-BETRIEB.md) for trust distribution, release configuration and key rotation.

## Deactivation and removal

Deactivation keeps calendar data, stops scheduled tasks and removes executable cached update offers. Deleting the plugin also keeps data by default.

Enabling **Delete all data on uninstall** requires confirmation: deletion then removes the plugin's tables/options, stored data and its own uploads folder, and revokes Google tokens. Back up required configuration/data first. A directory configured through `SW_CALENDAR_PRIVATE_STORAGE_PATH` is never deleted automatically. On multisite removal covers only the main site. Embedded blocks/shortcodes cannot display a removed plugin's calendars. External provider events are not deleted.
