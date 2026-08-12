(function () {
  'use strict';

  var lastSubmissionId = null;

  function getConfig() {
    return window.OMI_STUDIO_INTEGRATION || null;
  }

  function getSubmissionId() {
    var url = new URL(window.location.href);
    var queryKeys = ['workflowSubmissionId', 'submissionId', 'id'];

    for (var i = 0; i < queryKeys.length; i += 1) {
      var queryValue = url.searchParams.get(queryKeys[i]);
      if (/^[1-9][0-9]*$/.test(queryValue || '')) return queryValue;
    }

    var segments = url.pathname.split('/').filter(Boolean);
    var markers = ['workflow', 'submission', 'reviewer'];

    for (var m = 0; m < markers.length; m += 1) {
      var markerIndex = segments.indexOf(markers[m]);
      if (markerIndex !== -1) {
        for (var j = markerIndex + 1; j < segments.length; j += 1) {
          if (/^[1-9][0-9]*$/.test(segments[j])) return segments[j];
        }
      }
    }

    return null;
  }

  function removeLauncher() {
    var existing = document.getElementById('omi-studio-launcher');
    if (existing) existing.remove();
  }

  function setState(button, state, message) {
    button.classList.remove('omi-studio-launcher--loading', 'omi-studio-launcher--error');
    button.removeAttribute('aria-busy');
    if (state === 'loading') {
      button.classList.add('omi-studio-launcher--loading');
      button.setAttribute('aria-busy', 'true');
    } else if (state === 'error') {
      button.classList.add('omi-studio-launcher--error');
    }
    button.textContent = message;
  }

  async function fetchLaunchUrl(config, submissionId) {
    var endpoint = new URL(config.launchEndpoint, window.location.origin);
    endpoint.searchParams.set('submissionId', submissionId);
    if (config.mode === 'review') endpoint.searchParams.set('mode', 'review');
    endpoint.searchParams.set('_omi', Date.now().toString());

    var response = await fetch(endpoint.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      headers: {'Accept': 'application/json'},
      cache: 'no-store'
    });

    var data = null;
    try { data = await response.json(); } catch (_error) { data = null; }

    if (!response.ok) {
      var detail = data && data.error && data.error.message
        ? data.error.message
        : 'HTTP ' + response.status;
      throw new Error(detail);
    }
    if (!data || !data.launchUrl) {
      throw new Error('The OJS integration did not return a Studio launch URL.');
    }
    return data.launchUrl;
  }

  function createLauncher(config, submissionId) {
    var button = document.createElement('button');
    var label = config.label || (config.mode === 'review' ? 'Open in Studio for Review' : 'Open in Studio');

    button.id = 'omi-studio-launcher';
    button.className = 'omi-studio-launcher omi-studio-launcher--v114';
    button.type = 'button';
    button.textContent = label;
    button.setAttribute('aria-label', label);

    button.addEventListener('pointerdown', function (event) {
      event.stopPropagation();
    }, true);

    button.addEventListener('click', async function (event) {
      event.preventDefault();
      event.stopPropagation();
      if (button.disabled) return;

      button.disabled = true;
      setState(button, 'loading', 'Opening Studio…');
      try {
        var launchUrl = await fetchLaunchUrl(config, submissionId);
        window.location.href = launchUrl;
      } catch (error) {
        var message = error instanceof Error ? error.message : 'Studio launch failed.';
        console.error('OMI Studio launch failed:', error);
        setState(button, 'error', message);
        button.title = message;
        button.disabled = false;
        window.setTimeout(function () {
          if (document.body.contains(button)) setState(button, 'ready', label);
        }, 5000);
      }
    }, true);

    document.body.appendChild(button);
  }

  function mount() {
    var config = getConfig();
    if (!config || !config.launchEndpoint) return;
    var submissionId = getSubmissionId();
    if (!submissionId) {
      lastSubmissionId = null;
      removeLauncher();
      return;
    }
    if (submissionId === lastSubmissionId && document.getElementById('omi-studio-launcher')) return;
    lastSubmissionId = submissionId;
    removeLauncher();
    createLauncher(config, submissionId);
  }

  function scheduleMount() { window.setTimeout(mount, 0); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();

  window.addEventListener('popstate', scheduleMount);
  var originalPushState = history.pushState;
  history.pushState = function () {
    var result = originalPushState.apply(this, arguments);
    scheduleMount();
    return result;
  };
  var originalReplaceState = history.replaceState;
  history.replaceState = function () {
    var result = originalReplaceState.apply(this, arguments);
    scheduleMount();
    return result;
  };
  var observer = new MutationObserver(function () {
    if (getSubmissionId() !== lastSubmissionId) scheduleMount();
  });
  observer.observe(document.documentElement, {childList: true, subtree: true});
}());
