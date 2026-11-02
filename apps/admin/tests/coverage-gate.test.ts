import { execFileSync } from 'node:child_process';
import { mkdtempSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, relative, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const plugin = resolve(import.meta.dirname, '../../..');
const gate = join(plugin, 'apps/admin/scripts/check-coverage.mjs');
const sources = ['apps/admin/src', 'apps/admin/tools', 'assets/js', 'blocks'].flatMap((dir) =>
  readdirSync(join(plugin, dir), { recursive: true, withFileTypes: true })
    .filter((entry) => entry.isFile() && /\.(js|ts|vue)$/.test(entry.name))
    .map((entry) => relative(plugin, join(entry.parentPath, entry.name)))
    .filter((path) => !path.includes('/src/types/') && !path.endsWith('.d.ts')),
);

function check(coverage: number, omitted?: string): string {
  const dir = mkdtempSync(join(tmpdir(), 'swc-coverage-'));
  const report = join(dir, 'coverage.info');
  writeFileSync(
    report,
    sources
      .filter((path) => path !== omitted)
      .map((path) => `SF:${path}\nLF:100\nLH:${coverage}\nend_of_record`)
      .join('\n'),
  );
  try {
    return execFileSync(process.execPath, [gate, report], { encoding: 'utf8', stdio: 'pipe' });
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

describe('coverage gate', () => {
  it('requires more than 80 percent', () => {
    expect(check(81)).toContain('81.00 %');
    expect(() => check(80)).toThrow();
  });
  it.each([
    'apps/admin/src/main.ts',
    'apps/admin/tools/postcssScope.ts',
    'assets/js/frontend.js',
    'blocks/calendar/index.js',
  ])('rejects a report missing %s even with 100 percent coverage', (path) => {
    expect(() => check(100, path)).toThrow();
  });
});
