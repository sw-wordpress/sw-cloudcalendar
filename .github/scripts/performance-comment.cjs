const fs = require('node:fs');

const marker = '<!-- swc-performance -->';

function format(report, runUrl, sha, reference = null) {
  const number = value => {
    if (!Number.isFinite(value) || value < 0) throw new Error('Invalid benchmark metric');
    return value.toFixed(2);
  };
  const rows = Object.entries(report.summary).map(([name, value]) => {
    if (!/^[a-z0-9_]+$/.test(name)) throw new Error('Invalid scenario name');
    const previous = reference?.report.summary[name];
    const comparable = previous && (!name.startsWith('render_month_') ||
      report.rendered_unique_events.month === reference.report.rendered_unique_events.month);
    const delta = (current, old) => old === 0 ? '—' : `${current >= old ? '+' : ''}${((current - old) / old * 100).toFixed(2)} %`;
    const comparison = comparable
      ? `${number(previous.median_ms)} | ${delta(value.median_ms, previous.median_ms)} | ${number(previous.max_queries)} | ${delta(value.max_queries, previous.max_queries)}`
      : '— | — | — | —';
    return `| ${name} | ${number(value.samples)} | ${number(value.median_ms)} | ${number(value.p95_ms)} | ${number(value.max_queries)} | ${comparison} |`;
  });
  if (!rows.length || report.events !== 2000) throw new Error('Missing 2000-event benchmark');
  return `${marker}
### Performance: 2.000 Ereignisse pro Monat

Commit: \`${sha}\` · [CI-Lauf und JSON-Artefakt](${runUrl})

${reference ? `Referenz: [letzter erfolgreicher Lauf auf ${reference.branch}](${reference.url}), Commit \`${reference.sha}\`.` : 'Keine verfügbare Referenz auf dem Standardbranch; Vergleich folgt nach dem ersten erfolgreichen Lauf mit Benchmark-Artefakt.'}

| Aktion | Messungen | Median (ms) | P95 (ms) | SQL max. | Ref. Median (ms) | Δ Zeit | Ref. SQL | Δ SQL |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
${rows.join('\n')}

Monatsansicht: **${number(report.rendered_unique_events.month)} von 2.000 Ereignissen ausgegeben**.
Cold: Plugin-Cache geleert. Warm: gleicher PHP-Prozess. Sync/Cold: Einzelmessungen, kein belastbares P95.
Lokaler REST-Dispatcher und ICS-Fixture; keine Browser-, Netzwerk- oder parallele Lastmessung.
Zeiten sind runnerabhängig und keine feste Performance-Grenze. Positive Änderungen bedeuten höhere Kosten.
Fehlende Szenarien oder geänderte Anzahl ausgegebener Monatsereignisse werden nicht verglichen.
`;
}

async function run({ github, context, core }, reportPath, publish = false) {
  const url = `${context.serverUrl}/${context.repo.owner}/${context.repo.repo}/actions/runs/${context.runId}`;
  const sha = context.payload.pull_request?.head.sha || context.sha;
  const report = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
  const reference = publish ? await loadReference({ github, context, core }, report) : null;
  const body = format(report, url, sha, reference);
  await core.summary.addRaw(body).write();
  if (!publish) return;
  const issue_number = context.payload.pull_request.number;
  const { data: pr } = await github.rest.pulls.get({ ...context.repo, pull_number: issue_number });
  if (pr.head.sha !== sha) return; // A newer commit already superseded this run.
  const comments = await github.paginate(github.rest.issues.listComments, { ...context.repo, issue_number, per_page: 100 });
  const existing = comments.find(comment => comment.user?.type === 'Bot' && comment.body?.startsWith(marker));
  if (existing) {
    await github.rest.issues.updateComment({ ...context.repo, comment_id: existing.id, body });
  } else {
    await github.rest.issues.createComment({ ...context.repo, issue_number, body });
  }
}

function readArchive(data) {
  const os = require('node:os');
  const path = require('node:path');
  const { execFileSync } = require('node:child_process');
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'swc-reference-'));
  try {
    const archive = path.join(dir, 'report.zip');
    fs.writeFileSync(archive, Buffer.from(data));
    return JSON.parse(execFileSync('unzip', ['-p', archive, 'swc-performance.json'], { maxBuffer: 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'] }).toString());
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
}

async function loadReference({ github, context, core }, report, decode = readArchive) {
  const branch = context.payload.repository.default_branch;
  const { data } = await github.rest.actions.listWorkflowRuns({
    ...context.repo, workflow_id: 'tests.yml', branch, event: 'push', status: 'success', per_page: 1,
  });
  const previous = data.workflow_runs[0];
  if (!previous) return null;
  try {
    const artifacts = await github.paginate(github.rest.actions.listWorkflowRunArtifacts, {
      ...context.repo, run_id: previous.id, per_page: 100,
    });
    const artifact = artifacts.find(item => item.name === 'calendar-performance' && !item.expired);
    if (!artifact) return null;
    if (artifact.size_in_bytes > 2 * 1024 * 1024) throw new Error('Benchmark artifact exceeds size limit');
    const download = await github.rest.actions.downloadArtifact({
      ...context.repo, artifact_id: artifact.id, archive_format: 'zip',
    });
    const baseline = decode(download.data);
    // Validate the reference before using any of its metrics in a comment.
    format(baseline, '', '');
    if (baseline.events !== report.events || baseline.month !== report.month) {
      core.warning('Benchmark dataset changed; reference comparison skipped.');
      return null;
    }
    return { report: baseline, url: previous.html_url, sha: previous.head_sha, branch };
  } catch (error) {
    if (error.status && ![404, 410].includes(error.status)) throw error;
    core.warning(`Benchmark reference unavailable: ${error.message}`);
    return null;
  }
}

module.exports = { format, run, loadReference, readArchive };
