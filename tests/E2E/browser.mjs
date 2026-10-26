import { createRequire } from 'module'; const { chromium } = createRequire(process.env.PW_DIR + '/x.js')('playwright-core');
const base = 'http://127.0.0.1:8089';
const out = process.argv[2];
const browser = await chromium.launch({ executablePath: process.env.CHROME });
const results = [];
const check = (name, ok, info = '') => { results.push(`${ok ? 'PASS' : 'FAIL'} ${name}${info ? ' – ' + info : ''}`); };
async function ctx() {
  const c = await browser.newContext({ viewport: { width: 1280, height: 900 }, locale: 'de-DE' });
  const p = await c.newPage();
  p.errors = [];
  p.on('pageerror', (e) => p.errors.push(String(e)));
  // Aborted core requests of the previous page (ERR_NETWORK_CHANGED/ERR_ABORTED) and the missing favicon are not plugin errors.
  p.on('console', (m) => {
    const text = m.text() + ' ' + m.location().url;
    if (m.type() === 'error' && !/favicon|ERR_NETWORK_CHANGED|ERR_ABORTED/.test(text)) p.errors.push(text);
  });
  return [c, p];
}
async function login(p, user, pass) {
  await p.goto(base + '/wp-login.php');
  await p.fill('#user_login', user); await p.fill('#user_pass', pass);
  await p.click('#wp-submit'); await p.waitForLoadState('networkidle');
}

// Guest
let [c, p] = await ctx();
await p.goto(base + '/liste/'); await p.waitForLoadState('networkidle');
check('guest list renders events', (await p.locator('.swc-events__item').count()) > 0);
await p.screenshot({ path: `${out}/guest-list.png`, fullPage: true });
await p.locator('a[data-swc-detail]').first().click();
await p.waitForTimeout(1500); check('detail dialog opens', (await p.locator('dialog.swc-dialog[open]').count()) === 1);
await p.screenshot({ path: `${out}/guest-dialog.png` });
await p.keyboard.press('Escape');
await p.goto(base + '/monat/'); await p.waitForLoadState('networkidle');
check('month grid renders', (await p.locator('table.swc-month td').count()) >= 28);
const label1 = await p.locator('.swc-nav__label').textContent();
await p.locator('a.swc-nav__next').click(); await p.waitForTimeout(1200);
const label2 = await p.locator('.swc-nav__label').textContent();
check('month navigation via REST', label1 !== label2, `${label1} -> ${label2}`);
await p.screenshot({ path: `${out}/guest-month.png`, fullPage: true });
await p.setViewportSize({ width: 390, height: 844 });
await p.screenshot({ path: `${out}/guest-month-mobile.png`, fullPage: true });
await p.setViewportSize({ width: 1280, height: 900 });
await p.goto(base + '/karten/'); await p.waitForLoadState('networkidle');
await p.screenshot({ path: `${out}/guest-cards.png`, fullPage: true });
await p.goto(base + '/intern/'); await p.waitForLoadState('networkidle');
const html = await p.content();
check('protected view: no event markup in page source as guest', !(await (await fetch(base + '/intern/')).text()).includes('swc-events__item'));
const guestSources = await p.locator('[data-swc-source="2"]').count();
check('guest does not see restricted source after dynamic load', guestSources === 0, `items: ${await p.locator('.swc-events__item').count()}`);
check('guest page without JS errors', p.errors.length === 0, p.errors.join(' | '));
await c.close();

// Editor (role allowed for restricted source)
[c, p] = await ctx();
await login(p, 'editor1', 'e2e-editor-pass');
await p.goto(base + '/intern/'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(800);
check('editor sees restricted source', (await p.locator('[data-swc-source="2"]').count()) > 0);
const adminStatus = (await p.goto(base + '/wp-admin/admin.php?page=sw-cloudcalendar')).status();
check('editor has no calendar admin', adminStatus === 403 || (await p.content()).includes('not allowed') || (await p.content()).includes('nicht'), String(adminStatus));
await c.close();

// Admin SPA
[c, p] = await ctx();
await login(p, 'admin', 'e2e-admin-pass');
for (const route of ['', 'connections', 'sources', 'views', 'templates', 'sync', 'settings', 'diagnostics']) {
  p.errors = [];
  await p.goto(`${base}/wp-admin/admin.php?page=sw-cloudcalendar#/${route}`);
  await p.waitForLoadState('networkidle'); await p.waitForTimeout(1500);
  const mounted = (await p.locator('#sw-cloudcalendar-app .swc-root, #sw-cloudcalendar-app.swc-root, .swc-root').count()) > 0;
  check(`admin #/${route} mounts without errors`, mounted && p.errors.length === 0, p.errors.slice(0, 3).join(' | '));
  await p.screenshot({ path: `${out}/admin-${route || 'dashboard'}.png`, fullPage: true });
}
// Template editor and view editor
p.errors = [];
await p.goto(`${base}/wp-admin/admin.php?page=sw-cloudcalendar#/views/1`); await p.waitForLoadState('networkidle'); await p.waitForTimeout(2500);
check('view editor preview iframe', (await p.locator('iframe[sandbox]').count()) > 0, p.errors.slice(0, 2).join(' | '));
await p.screenshot({ path: `${out}/admin-view-editor.png`, fullPage: true });
p.errors = [];
await p.goto(`${base}/wp-admin/admin.php?page=sw-cloudcalendar#/templates/2`); await p.waitForLoadState('networkidle'); await p.waitForTimeout(2500);
check('template editor loads', (await p.locator('.cm-editor, iframe[sandbox]').count()) > 0, p.errors.slice(0, 2).join(' | '));
await p.screenshot({ path: `${out}/admin-template-editor.png`, fullPage: true });
await c.close();
await browser.close();
console.log(results.join('\n'));
