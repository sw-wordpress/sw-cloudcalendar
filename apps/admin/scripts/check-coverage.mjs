// Strict JS/TS line-coverage gate (AGENTS.md: > 80 %, exactly 80 % fails) on coverage/lcov.info.
// Also rewrites LCOV paths relative to the plugin root (for SonarQube) and fails when a hand-written
// production source is missing from the report, including the admin app and external scripts.
import { existsSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const MIN_EXCLUSIVE = 80;
const app = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const plugin = resolve(app, '../..');
const report = resolve(app, process.argv[2] ?? 'coverage/lcov.info');

function walk(dir, pattern = /\.js$/, out = []) {
    if (!existsSync(dir)) return out;
    for (const name of readdirSync(dir)) {
        const path = join(dir, name);
        if (path === join(app, 'src/types') || name.endsWith('.d.ts')) continue;
        if (statSync(path).isDirectory()) walk(path, pattern, out);
        else if (pattern.test(name)) out.push(relative(plugin, path));
    }
    return out;
}

if (!existsSync(report)) {
    console.error(`Coverage report missing: ${report} (run "npm run test:coverage")`);
    process.exit(1);
}

let found = 0;
let hit = 0;
const files = new Set();
const lines = readFileSync(report, 'utf8')
    .split('\n')
    .map((line) => {
        if (line.startsWith('SF:')) {
            const path = line.slice(3);
            // Idempotent: paths already relative to the plugin root stay as they are.
            const absolute =
                existsSync(resolve(plugin, path)) && !existsSync(resolve(app, path))
                    ? resolve(plugin, path)
                    : resolve(app, path);
            const rel = relative(plugin, absolute);
            files.add(rel);
            return `SF:${rel}`;
        }
        if (line.startsWith('LF:')) found += Number(line.slice(3));
        if (line.startsWith('LH:')) hit += Number(line.slice(3));
        return line;
    });
writeFileSync(report, lines.join('\n'));

const missing = [
    ...walk(join(app, 'src'), /\.(ts|js|vue)$/),
    ...walk(join(app, 'tools'), /\.ts$/),
    ...walk(join(plugin, 'assets/js')),
    ...walk(join(plugin, 'blocks')),
].filter((file) => !files.has(file));
if (missing.length > 0) {
    console.error(
        `Production sources without coverage data: ${missing.join(', ')}`,
    );
    process.exit(1);
}

const pct = found === 0 ? 0 : (hit / found) * 100;
// Integer comparison avoids rounding: hit/found > 80/100 <=> 100*hit > 80*found.
const ok = found > 0 && hit * 100 > found * MIN_EXCLUSIVE;
console.log(
    `JS/TS line coverage: ${pct.toFixed(2)} % (${hit}/${found}, ${files.size} files), required > ${MIN_EXCLUSIVE} %`,
);
if (!ok) {
    console.error('JS/TS line coverage gate failed.');
    process.exit(1);
}
