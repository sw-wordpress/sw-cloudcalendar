const fs = require('node:fs');

const marker = '<!-- swc-performance -->';

function format(report, runUrl, sha) {
  const number = value => {
    if (!Number.isFinite(value) || value < 0) throw new Error('Invalid benchmark metric');
    return value.toFixed(2);
  };
  const rows = Object.entries(report.summary).map(([name, value]) => {
    if (!/^[a-z0-9_]+$/.test(name)) throw new Error('Invalid scenario name');
    return `| ${name} | ${number(value.samples)} | ${number(value.median_ms)} | ${number(value.p95_ms)} | ${number(value.max_queries)} |`;
  });
  if (!rows.length || report.events !== 2000) throw new Error('Missing 2000-event benchmark');
  return `${marker}
### Performance: 2.000 Ereignisse pro Monat

Commit: \`${sha}\` · [CI-Lauf und JSON-Artefakt](${runUrl})

| Aktion | Messungen | Median (ms) | P95 (ms) | SQL max. |
|---|---:|---:|---:|---:|
${rows.join('\n')}

Monatsansicht: **${number(report.rendered_unique_events.month)} von 2.000 Ereignissen ausgegeben**.
Cold: Plugin-Cache geleert. Warm: gleicher PHP-Prozess. Sync/Cold: Einzelmessungen, kein belastbares P95.
Lokaler REST-Dispatcher und ICS-Fixture; keine Browser-, Netzwerk- oder parallele Lastmessung.
Zeiten sind runnerabhängig und keine feste Performance-Grenze.
`;
}

async function run({ github, context, core }, reportPath, publish = false) {
  const url = `${context.serverUrl}/${context.repo.owner}/${context.repo.repo}/actions/runs/${context.runId}`;
  const sha = context.payload.pull_request?.head.sha || context.sha;
  const body = format(JSON.parse(fs.readFileSync(reportPath, 'utf8')), url, sha);
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

module.exports = { format, run };
