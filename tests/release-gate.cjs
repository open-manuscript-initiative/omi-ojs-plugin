const assert = require('node:assert/strict');
const gate = require('../scripts/release-gate.cjs');
async function probe({php = 'success', pkp = 'success', tag, event = 'workflow_run', foreign = false} = {}) {
  const outputs = {};
  const failures = [];
  const sha = 'validated-main-sha';
  const result = conclusion => ({data: {workflow_runs: [
    {id: 1, head_sha: foreign ? 'another-sha' : sha, head_branch: 'main', event: 'push', status: conclusion === 'pending' ? 'in_progress' : 'completed', conclusion},
  ]}});
  await gate({
    context: {eventName: event, sha, payload: {workflow_run: {head_sha: sha}}, repo: {owner: 'fixture', repo: 'plugin'}},
    core: {setOutput: (k, v) => outputs[k] = v, notice() {}, setFailed: v => failures.push(v)},
    versionXml: '<version><release>1.8.7.0</release></version>',
    github: {rest: {
      actions: {listWorkflowRuns: async ({workflow_id, head_sha}) => {
        assert.equal(head_sha, sha);
        return result(workflow_id === 'php.yml' ? php : pkp);
      }},
      git: {getRef: async () => {
        if (!tag) throw Object.assign(new Error('No tag yet'), {status: 404});
        return {data: {object: {type: 'commit', sha: tag}}};
      }},
    }},
  });
  return {outputs, failures};
}
(async () => {
  assert.equal((await probe()).outputs.ready, 'true');
  assert.equal((await probe({pkp: 'pending'})).outputs.ready, 'false');
  assert.equal((await probe({php: 'failure'})).outputs.ready, 'false');
  assert.equal((await probe({foreign: true})).outputs.ready, 'false');
  assert.equal((await probe({tag: 'older-tagged-commit'})).outputs.ready, 'false');
  assert.equal((await probe({tag: 'validated-main-sha'})).outputs.ready, 'true');
  assert.equal((await probe({event: 'workflow_dispatch', pkp: 'pending'})).failures.length, 1);
  console.log('Release waits for matching successful PHP/native OJS CI and preserves existing release tags.');
})().catch(error => { console.error(error); process.exitCode = 1; });
