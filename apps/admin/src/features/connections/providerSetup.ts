import type { Provider } from '@/types/api';

/** One value the administrator has to enter at the provider (shown with a copy button). */
export interface SetupValue {
  /** i18n key below `connections.guide.values`. */
  key: string;
  value: string;
  /** Monospace + copy button (false for labels of radio options/menus). */
  copy: boolean;
}

/** Scopes requested by the plugin (mirror of OAuthEndpoints.php; least privilege). */
export function oauthScopes(
  provider: Exclude<Provider, 'ics'>,
  attachmentAccess: boolean,
): string[] {
  if (provider === 'google') {
    const scopes = ['https://www.googleapis.com/auth/calendar.readonly'];
    if (attachmentAccess) scopes.push('https://www.googleapis.com/auth/drive.readonly');
    return scopes;
  }
  // Graph file attachments are readable with Calendars.Read; no file scope is requested.
  return ['Calendars.Read', 'User.Read', 'offline_access', 'openid'];
}

function originOf(url: string): string {
  try {
    return new URL(url).origin;
  } catch {
    return '';
  }
}

/** Google expects the top private domain for "Authorised domains" (example.org instead of www.example.org). */
function hostOf(url: string): string {
  try {
    return new URL(url).hostname.replace(/^www\./, '');
  } catch {
    return '';
  }
}

export function setupValues(
  provider: Exclude<Provider, 'ics'>,
  redirectUri: string,
  siteUrl: string,
  attachmentAccess: boolean,
): SetupValue[] {
  const scopes = oauthScopes(provider, attachmentAccess);
  if (provider === 'google') {
    return [
      { key: 'applicationType', value: 'Webanwendung / Web application', copy: false },
      { key: 'redirectUri', value: redirectUri, copy: true },
      { key: 'jsOrigin', value: originOf(siteUrl), copy: true },
      { key: 'authorizedDomain', value: hostOf(siteUrl), copy: true },
      {
        key: 'apis',
        value: attachmentAccess ? 'Google Calendar API, Google Drive API' : 'Google Calendar API',
        copy: false,
      },
      { key: 'scopes', value: scopes.join('\n'), copy: true },
    ];
  }
  return [
    {
      key: 'accountTypes',
      value: 'Single tenant / Nur Konten in diesem Organisationsverzeichnis',
      copy: false,
    },
    { key: 'platform', value: 'Web', copy: false },
    { key: 'redirectUri', value: redirectUri, copy: true },
    { key: 'graphPermissions', value: scopes.join(', '), copy: true },
  ];
}

/** Direct entry points at the provider consoles (open in a new tab). */
export interface SetupLink {
  /** i18n key below `connections.guide.links`. */
  key: string;
  href: string;
}

export function setupLinks(
  provider: Exclude<Provider, 'ics'>,
  attachmentAccess: boolean,
): SetupLink[] {
  if (provider === 'google') {
    const links: SetupLink[] = [
      { key: 'googleConsole', href: 'https://console.cloud.google.com/' },
      {
        key: 'googleCalendarApi',
        href: 'https://console.cloud.google.com/apis/library/calendar-json.googleapis.com',
      },
    ];
    if (attachmentAccess) {
      links.push({
        key: 'googleDriveApi',
        href: 'https://console.cloud.google.com/apis/library/drive.googleapis.com',
      });
    }
    links.push(
      { key: 'googleConsent', href: 'https://console.cloud.google.com/apis/credentials/consent' },
      { key: 'googleCredentials', href: 'https://console.cloud.google.com/apis/credentials' },
    );
    return links;
  }
  return [
    { key: 'entraCenter', href: 'https://entra.microsoft.com/' },
    {
      key: 'entraAppRegistrations',
      href: 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade',
    },
  ];
}
