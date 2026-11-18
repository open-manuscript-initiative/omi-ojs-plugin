// Publish only the exact main-branch commit validated by both CI workflows.
module.exports = async function releaseGate({github, context, core, versionXml}) {
  const sha = context.eventName === 'workflow_run' ? context.payload.workflow_run.head_sha : context.sha;
  const version = versionXml.match(/<release>\s*(\d+\.\d+\.\d+)\.0\s*<\/release>/)?.[1];
  if (!version) throw new Error('Invalid release version.xml');
  core.setOutput('sha', sha);
  core.setOutput('ready', 'false');
  for (const workflow_id of ['php.yml', 'pkp-integration.yml']) {
    const result = await github.rest.actions.listWorkflowRuns({
      ...context.repo, workflow_id, head_sha: sha, branch: 'main', event: 'push', per_page: 100,
    });
    const latest = result.data.workflow_runs
      .filter(run => run.head_sha === sha && run.event === 'push' && run.head_branch === 'main')
      .sort((a, b) => b.id - a.id)[0];
    if (!latest || latest.status !== 'completed' || latest.conclusion !== 'success') {
      const message = 'Release waits for successful ' + workflow_id + ' at ' + sha;
      if (context.eventName === 'workflow_dispatch') core.setFailed(message);
      else core.notice(message);
      return;
    }
  }
  try {
    const tag = await github.rest.git.getRef({...context.repo, ref: 'tags/v' + version});
    let object = tag.data.object;
    if (object.type === 'tag') object = (await github.rest.git.getTag({...context.repo, tag_sha: object.sha})).data.object;
    if (object.type !== 'commit' || object.sha !== sha) {
      core.notice('Version v' + version + ' is already tagged at another commit; keep its published assets immutable.');
      return;
    }
  } catch (error) {
    if (error.status !== 404) throw error;
  }
  core.setOutput('ready', 'true');
};
