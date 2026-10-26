/** Config object injected by PHP as `window.swcloudcalendarConfig` (AdminPage::config()). */
export interface SwcConfig {
  /** REST root of the plugin namespace; always ends with `/`, e.g. `https://x/wp-json/sw-cloudcalendar/v1/`. */
  restRoot: string;
  /** `wp_rest` nonce, sent as `X-WP-Nonce`. */
  nonce: string;
  locale: string;
  version: string;
  /** `…/wp-admin/admin.php?page=sw-cloudcalendar` */
  adminUrl: string;
  capabilities: string[];
  siteUrl: string;
  /** Plugin base URL ending with `/` (used for the frontend stylesheet inside preview iframes). */
  pluginUrl: string;
}

export type Capability = 'manage_sw_calendar';
