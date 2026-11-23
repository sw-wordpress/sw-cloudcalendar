# Connections

A connection stores access to a provider. A [source](./sources.md) chooses one of its calendars; a [view](./views.md) controls how selected sources appear. Several sources can share a connection.

Open **Calendar → Connections → Add connection**. Provider and **Sign-in method** are fixed after creation. Secrets are stored encrypted and not shown again; do not put them in screenshots or support requests.

## ICS feeds

1. Choose ICS and **Public feed** or **Feed with credentials**.
2. Enter **Feed URL**. Prefer HTTPS. `webcal://` and `webcals://` addresses are converted to HTTPS.
3. For an authenticated feed, enter the complete **Authorization header** value supplied by its operator, not just a password.
4. Save and choose **Test**. Create a source using this connection; its single feed is selected automatically.

Private/internal network addresses are rejected. **Allow unencrypted HTTP** is an explicit exception: feed data and credentials then travel unencrypted. Do not enable it for sensitive content. **Maximum feed size (MB, 1–50)** limits downloads. Treat private feed URLs as credentials even if no additional header is needed.

## Google Calendar with OAuth

Use OAuth for calendars accessible to a Google account. The plugin's **Guide: create client ID and client secret** shows the exact callback and requested scopes for your installation. Provider console labels may change; copy the values shown by the plugin.

1. Select Google and **OAuth (account)**. Open the setup guide and copy **Redirect URI**.
2. In your Google Cloud project enable the Calendar API. Enable the Drive API only if requesting file attachments.
3. Configure the consent screen/audience and its authorised domain. Create an OAuth client of type **Web application** with the exact redirect URI from the plugin.
4. Grant the read-only calendar scope `https://www.googleapis.com/auth/calendar.readonly`. When **Request access to file attachments** is enabled, the plugin additionally requests `https://www.googleapis.com/auth/drive.readonly`.
5. Enter **Client ID** and **Client secret** in WordPress, save and choose **Authorise**. Sign in to the calendar account and grant the requested access.
6. Choose **Test**, then create a source and select its calendar.

The callback path is `/wp-json/sw-cloudcalendar/v1/oauth/callback`; use the full URI shown by your site, including its actual origin/path. External apps in Google's Testing status need re-authorisation after seven days. Review Google's publishing/verification requirements before using this setup permanently, especially for the restricted Drive scope. The [existing setup guide](https://github.com/sw-wordpress/sw-cloudcalendar/blob/master/docs/setup.md#connections-calendar--connections) records the integration constraints.

## Google Calendar with an API key

Choose **API key** only for a calendar already public at Google. Enter the key and **Calendar ID for the connection test**, save, then **Test**. Calendar listing is not supported in this mode: enter the calendar ID manually when creating the source. An API key does not grant access to a private calendar.

## Microsoft 365

Use your own Entra ID app registration and delegated OAuth, not an app-only service account.

1. Open the connection's setup guide and copy its **Redirect URI**.
2. Create a single-tenant app registration in Microsoft Entra ID with platform **Web** and that exact redirect URI.
3. Enter the registration's application/client ID and directory/tenant ID in **Client ID** and **Tenant ID**.
4. Create a client secret and enter its **Value**, not its Secret ID, in **Client secret**. Store its expiry date securely for renewal.
5. Configure delegated Microsoft Graph permissions `Calendars.Read`, `User.Read`, `offline_access` and `openid`, as shown in the plugin. Obtain organisational administrator consent if your tenant requires it.
6. Save and choose **Authorise**; sign in as the account whose calendars should be read. Choose **Test** and create a source.

Shared mailboxes, group calendars and app-only access are not supported by this integration. Mailbox attachments cannot be verified as publicly shared; see [attachment policy](./sources.md#attachments).

## Local source plugins

An active plugin can register a **Source plugin** provider. Select an available provider/calendar and create a source, then include it in a view. Events are read directly from that plugin without another event copy or provider synchronisation. Availability and visitor permissions depend on the supplying plugin. CloudCalendar does not include the ChurchTools adapter; ChurchTools Suite supplies it externally. Integrators should use the [local sources API](https://github.com/sw-wordpress/sw-cloudcalendar/blob/master/docs/local-sources.md).

## Re-authorise, disconnect or delete

Changing client ID, tenant or file access resets authorisation; grant it again. Use **Re-authorise** after expired/revoked consent is corrected.

**Disconnect** removes local tokens and revokes Google tokens, but previously synchronised events remain visible according to source visibility and stop updating. Pause or delete the source if those events must no longer be delivered. **Delete connection** also deletes its sources and their stored events and attachments; read the confirmation before proceeding.
