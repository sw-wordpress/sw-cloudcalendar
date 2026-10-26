import postcss from 'postcss';
import { describe, expect, it } from 'vitest';
import { rewriteSelector, swcScope } from '../tools/postcssScope';

const r = (s: string): string => rewriteSelector(s, '.swc-root', 'swc-dark');

describe('rewriteSelector', () => {
  it('prefixes ordinary selectors', () => {
    expect(r('.q-btn')).toBe('.swc-root .q-btn');
    expect(r('*')).toBe('.swc-root *');
    expect(r('a:hover')).toBe('.swc-root a:hover');
  });
  it('maps html/body/:root to the root', () => {
    expect(r('html')).toBe('.swc-root');
    expect(r('body')).toBe('.swc-root');
    expect(r(':root')).toBe('.swc-root');
    expect(r('body.desktop .q-x')).toBe('.swc-root.desktop .q-x');
  });
  it('maps Quasar dark/light body classes onto our root classes', () => {
    expect(r('body.body--dark .q-card')).toBe('.swc-root.swc-dark .q-card');
    expect(r('.body--dark .q-card')).toBe('.swc-root.swc-dark .q-card');
    expect(r('body.body--light .q-card')).toBe('.swc-root:not(.swc-dark) .q-card');
  });
  it('neutralises body scroll-lock classes', () => {
    expect(r('body.q-body--prevent-scroll')).toBe('.swc-root.q-body--prevent-scroll');
  });
  it('is idempotent', () => {
    expect(r('.swc-root .q-btn')).toBe('.swc-root .q-btn');
  });
});

describe('swcScope plugin', () => {
  const run = (css: string, from: string): Promise<string> =>
    postcss([swcScope()])
      .process(css, { from })
      .then((res) => res.css);

  it('rewrites only Quasar files and leaves keyframes alone', async () => {
    const q = await run(
      '.a, body { x: 1 } @keyframes k { from { a: b } }',
      '/app/node_modules/quasar/dist/q.css',
    );
    expect(q).toContain('.swc-root .a');
    expect(q).toContain('.swc-root {');
    expect(q).toContain('from { a: b }');
    const own = await run('.swc-card { x: 1 }', '/app/src/styles/app.css');
    expect(own).toBe('.swc-card { x: 1 }');
  });
});
