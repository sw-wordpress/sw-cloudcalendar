/**
 * Builds the `srcdoc` of a sandboxed preview iframe (`<iframe sandbox="">`) for server rendered
 * HTML (template preview, view preview). The markup is never inserted into the admin DOM:
 * the iframe has an opaque origin, no scripts, no forms, no popups and no top navigation.
 * A restrictive CSP (meta) additionally limits loads to the site origin.
 */

export interface PreviewDocInput {
  html: string;
  /** CSS returned by the server: a string, or `{ scopeClass: css }` / list from the render route. */
  css?: string | Record<string, string> | string[] | null;
  /** Plugin base URL (ends with `/`); `assets/css/frontend.css` is linked from there. */
  pluginUrl: string;
  lang?: string;
  dark?: boolean;
}

/** Flattens the various CSS shapes of the API into one stylesheet string. */
export function flattenCss(css: PreviewDocInput['css']): string {
  if (!css) return '';
  if (typeof css === 'string') return css;
  const parts = Array.isArray(css) ? css : Object.values(css);
  return parts.filter((part): part is string => typeof part === 'string').join('\n');
}

/** Prevents `</style>` inside CSS from terminating the style element. */
export function escapeStyleContent(css: string): string {
  return css.replace(/<\/(style)/gi, '<\\/$1').replace(/<!--/g, '<\\!--');
}

function escapeAttr(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

function originOf(url: string): string | null {
  try {
    const parsed = new URL(url);
    return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.origin : null;
  } catch {
    return null;
  }
}

export function buildPreviewDocument(input: PreviewDocInput): string {
  const origin = originOf(input.pluginUrl);
  const allowed = origin ?? "'none'";
  const csp = [
    "default-src 'none'",
    `style-src 'unsafe-inline' ${allowed}`,
    `img-src ${allowed} data:`,
    `font-src ${allowed} data:`,
    "script-src 'none'",
    "form-action 'none'",
    "base-uri 'none'",
  ].join('; ');
  const stylesheet = origin
    ? `<link rel="stylesheet" href="${escapeAttr(`${input.pluginUrl}assets/css/frontend.css`)}">`
    : '';
  const scheme = input.dark ? 'dark' : 'light';
  return [
    '<!doctype html>',
    `<html lang="${escapeAttr(input.lang ?? 'en')}">`,
    '<head>',
    '<meta charset="utf-8">',
    `<meta http-equiv="Content-Security-Policy" content="${escapeAttr(csp)}">`,
    '<meta name="viewport" content="width=device-width, initial-scale=1">',
    // Links cannot open anything: popups and top navigation are not allowed by the sandbox.
    '<base target="_blank">',
    stylesheet,
    `<style>:root{color-scheme:${scheme}}body{margin:16px;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;font-size:15px;line-height:1.5;background:${input.dark ? '#131a2e' : '#ffffff'};color:${input.dark ? '#e8ecf8' : '#0f172a'}}</style>`,
    `<style>${escapeStyleContent(flattenCss(input.css))}</style>`,
    '</head>',
    `<body>${input.html}</body>`,
    '</html>',
  ].join('\n');
}
