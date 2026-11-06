const { test } = require('node:test');
const assert = require('node:assert/strict');
const { format, run, loadReference, readArchive } = require('../../.github/scripts/performance-comment.cjs');
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
        payload: { repository: { default_branch: 'master' }, pull_request: { number: 2, head: { sha: 'head-sha' } } } },
      core: { summary: { addRaw() { return this; }, async write() {} } },
      github: { rest: { actions: { async listWorkflowRuns() { return { data: { workflow_runs: [] } }; } }, issues: api, pulls: { async get() { return { data: { head: { sha: existing === 'stale' ? 'newer-sha' : 'head-sha' } } }; } } }, async paginate() { return [
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

// GitHub requires PR write access when the issue comment belongs to a pull request.
test('comment job grants PR write permission without granting it to test jobs', () => {
  const workflow = require('node:fs').readFileSync(path.join(__dirname, '../../.github/workflows/tests.yml'), 'utf8');
  const commentJob = workflow.split('  performance-comment:\n')[1].split('  frontend:\n')[0];
  assert.match(commentJob, /      pull-requests: write\n/);
  assert.match(commentJob, /      issues: write\n/);
  assert.doesNotMatch(workflow.split('  performance-comment:\n')[0], /pull-requests: write|issues: write/);
});

function referenceDeps(mode = 'ok') {
  const calls = [];
  const deps = {
    context: { repo: { owner: 'a', repo: 'b' }, payload: { repository: { default_branch: 'master' } } },
    core: { warning(message) { calls.push(message); } },
    github: {
      rest: { actions: {
        listWorkflowRunArtifacts() {},
        async listWorkflowRuns(args) {
          assert.equal(args.branch, 'master');
          assert.equal(args.status, 'success');
          assert.equal(args.event, 'push');
          assert.equal(args.workflow_id, 'tests.yml');
          assert.equal(args.per_page, 1);
          return { data: { workflow_runs: mode === 'no-run' ? [] : [{ id: 12, html_url: 'https://example.org/run', head_sha: 'master-sha' }] } };
        },
        async downloadArtifact(args) {
          assert.equal(args.artifact_id, 23);
          if (mode === 'expired-download') throw Object.assign(new Error('Gone'), { status: 410 });
          if (mode === 'forbidden') throw Object.assign(new Error('Forbidden'), { status: 403 });
          return { data: 'archive' };
        },
      } },
      async paginate(method, args) {
        assert.equal(args.run_id, 12);
        return mode === 'missing' ? [] : [{ id: 23, name: 'calendar-performance', expired: mode === 'expired' }];
      },
    },
  };
  return { deps, calls };
}

test('loads artifact from latest successful push on default branch', async () => {
  const { deps } = referenceDeps();
  const reference = await loadReference(deps, report, data => { assert.equal(data, 'archive'); return report; });
  assert.equal(reference.sha, 'master-sha');
  assert.equal(reference.branch, 'master');
  const body = format(report, '', '', reference);
  assert.match(body, /Ref. Median/);
  assert.match(body, /\+0.00 %/);
  assert.match(body, /master-sha/);
});

for (const mode of ['no-run', 'missing', 'expired', 'expired-download']) {
  test(`missing reference: ${mode}`, async () => {
    const { deps } = referenceDeps(mode);
    assert.equal(await loadReference(deps, report, () => report), null);
  });
}

test('invalid or incompatible references are skipped, permission failures stay visible', async () => {
  const { deps } = referenceDeps();
  assert.equal(await loadReference(deps, report, () => ({ ...report, month: 'other' })), null);
  assert.equal(await loadReference(deps, report, () => ({})), null);
  await assert.rejects(loadReference(referenceDeps('forbidden').deps, report), /Forbidden/);
});

test('compares signed times and SQL; skips missing, zero and changed month workloads', () => {
  const changed = structuredClone(report);
  changed.summary.import.median_ms = report.summary.import.median_ms * 1.1;
  changed.summary.import.max_queries = report.summary.import.max_queries / 2;
  changed.summary.search_warm.median_ms = 0;
  const previous = structuredClone(report);
  previous.summary.search_warm.median_ms = 0;
  delete previous.summary.detail_warm;
  const reference = { report: previous, url: 'url', sha: 'sha', branch: 'master' };
  let body = format(changed, '', '', reference);
  assert.match(body, /\+10.00 %/);
  assert.match(body, /-50.00 %/);
  assert.match(body, /detail_warm[^\n]+— \| — \| — \| —/);
  assert.match(body, /search_warm[^\n]+—/);
  changed.rendered_unique_events.month = 2000;
  body = format(changed, '', '', reference);
  assert.match(body, /render_month_warm[^\n]+— \| — \| — \| —/);
});

test('reads a real GitHub artifact ZIP without extracting files', () => {
  const fs = require('node:fs');
  const os = require('node:os');
  const { execFileSync } = require('node:child_process');
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'swc-zip-test-'));
  try {
    fs.copyFileSync(reportPath, path.join(dir, 'swc-performance.json'));
    execFileSync('python3', ['-c', "import zipfile; z = zipfile.ZipFile('report.zip', 'w'); z.write('swc-performance.json'); z.close()"], { cwd: dir });
    assert.deepEqual(readArchive(fs.readFileSync(path.join(dir, 'report.zip'))), report);
    assert.throws(() => readArchive(Buffer.from('invalid zip')));
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
