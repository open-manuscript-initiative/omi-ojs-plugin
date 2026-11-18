const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const elements = new Map();
const document = {
  readyState: 'complete', documentElement: {},
  getElementById: id => elements.get(id) || null,
  createElement: tag => ({
    tag, dataset: {}, attributes: {}, events: {}, disabled: false,
    classList: {add() {}},
    setAttribute(name, value) { this.attributes[name] = value; },
    addEventListener(name, handler) { this.events[name] = handler; },
    remove() { elements.delete(this.id); },
    set innerHTML(value) { throw new Error('Untrusted diagnostic must not be inserted as HTML'); },
  }),
  body: {appendChild(element) { elements.set(element.id, element); }},
};
const navigations = [];
const window = {
  location: {href: 'https://journal.example/ojs/index.php/e/workflow?workflowSubmissionId=220', origin: 'https://journal.example', assign(url) { navigations.push(url); }},
  OMI_STUDIO_INTEGRATION: {launchEndpoint: '/ojs/index.php/e/omiIntegration/launch', mode: 'editor', label: 'Open in Studio'},
  setTimeout(fn) { fn(); }, addEventListener() {},
};
vm.runInNewContext(fs.readFileSync(require.resolve('../js/studioIntegration.js'), 'utf8'), {
  window, document, URL, Date,
  history: {pushState() {}, replaceState() {}},
  MutationObserver: class { observe() {} },
});
const click = {preventDefault() {}, stopPropagation() {}};
let button = elements.get('omi-studio-launcher');
assert.ok(button && !button.disabled, 'Healthy integration must expose an active launcher');
button.events.click(click);
assert.equal(navigations.length, 1);
assert.equal(new URL(navigations[0]).searchParams.get('submissionId'), '220');

const warning = 'Plugin incomplete <script>unsafe</script>';
window.OMI_STUDIO_INTEGRATION.installationError = warning;
window.__OMI_STUDIO_LAUNCHER__.mount();
button = elements.get('omi-studio-launcher');
assert.ok(button && button.disabled, 'Incomplete integration must keep the launcher visible and disabled');
assert.equal(elements.get('omi-studio-installation-notice').textContent, warning);
assert.equal(button.attributes['aria-describedby'], 'omi-studio-installation-notice');
button.events.click(click);
assert.equal(navigations.length, 1, 'Disabled integration must not navigate');

window.OMI_STUDIO_INTEGRATION.installationError = null;
window.__OMI_STUDIO_LAUNCHER__.mount();
assert.ok(!elements.get('omi-studio-launcher').disabled, 'Restored integration must reactivate the launcher');
assert.ok(!elements.has('omi-studio-installation-notice'));
window.location.href = 'https://journal.example/ojs/index.php/e/dashboard';
window.__OMI_STUDIO_LAUNCHER__.mount();
assert.equal(elements.size, 0, 'Do not leave launch controls on pages without a submission');
console.log('Launcher visibility, blocked navigation, escaping and recovery checks passed.');
