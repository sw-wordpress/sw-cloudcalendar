const { test } = require('node:test');
const assert = require('node:assert/strict');
const { format, run } = require('../../.github/scripts/performance-comment.cjs');
const path = require('node:path');
const reportPath = path.join(__dirname, 'baseline.json');
const report = require('./baseline.json');

test('formats complete benchmark and documents truncation', () => {
  const body = format(report, 'https://example.org/run', 'abc123');
  assert.match(body, /render_month_warm/);
  assert.match(body, /1000.00 von 2.000/);
  assert.match(body, /abc123/);
  assert.match(body, /kein belastbares P95/);
  assert.throws(() => format({ ...report, events: 0 }, '', ''), /Missing/);
  assert.throws(() => format({ ...report, summary: { '<script>': {} } }, '', ''), /Invalid/);
  assert.throws(() => format({ ...report, summary: { import: { samples: NaN } } }, '', ''), /Invalid/);
});

for (const existing of [false, true, 'stale']) {
  test(existing === 'stale' ? 'ignores superseded commits' : existing ? 'updates existing bot comment across pages' : 'creates first comment', async () => {
    const calls = [];
    const api = {
      listComments() {},
      async updateComment(args) { calls.push(['update', args]); },
      async createComment(args) { calls.push(['create', args]); },
    };
    const deps = {
      context: { serverUrl: 'https://github.com', repo: { owner: 'a', repo: 'b' }, runId: 1,
        payload: { pull_request: { number: 2, head: { sha: 'head-sha' } } } },
      core: { summary: { addRaw() { return this; }, async write() {} } },
      github: { rest: { issues: api, pulls: { async get() { return { data: { head: { sha: existing === 'stale' ? 'newer-sha' : 'head-sha' } } }; } } }, async paginate() { return [
        { id: 10, user: { type: 'User' }, body: '<!-- swc-performance -->' },
        ...(existing ? [{ id: 11, user: { type: 'Bot' }, body: '<!-- swc-performance -->old' }] : []),
      ]; } },
    };
    await run(deps, reportPath, false);
    assert.equal(calls.length, 0, 'summary-only mode never calls write APIs');
    await run(deps, reportPath, true);
    if (existing === 'stale') {
      assert.equal(calls.length, 0);
      return;
    }
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], existing ? 'update' : 'create');
    assert.match(calls[0][1].body, /head-sha/);
    assert.equal(calls[0][1][existing ? 'comment_id' : 'issue_number'], existing ? 11 : 2);
  });
}
