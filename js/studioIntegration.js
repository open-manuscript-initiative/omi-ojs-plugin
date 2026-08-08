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
      if (/^[1-9][0-9]*$/.test(queryValue || '')) {
        return queryValue;
      }
    }

    var segments = url.pathname.split('/').filter(Boolean);
    var markers = ['workflow', 'submission'];

    for (var m = 0; m < markers.length; m += 1) {
      var markerIndex = segments.indexOf(markers[m]);
      if (markerIndex !== -1) {
        for (var j = markerIndex + 1; j < segments.length; j += 1) {
          if (/^[1-9][0-9]*$/.test(segments[j])) {
            return segments[j];
          }
        }
      }
    }

    return null;
  }

  function removeLauncher() {
    var existing = document.getElementById('omi-studio-launcher');
    if (existing) {
      existing.remove();
    }
  }

  function setState(link, state, message) {
    link.classList.remove(
      'omi-studio-launcher--loading',
      'omi-studio-launcher--error'
    );

    if (state === 'loading') {
      link.classList.add('omi-studio-launcher--loading');
      link.setAttribute('aria-busy', 'true');
      link.textContent = message || 'Opening Studio…';
      return;
    }

    link.removeAttribute('aria-busy');

    if (state === 'error') {
      link.classList.add('omi-studio-launcher--error');
      link.textContent = message || 'Studio launch failed';
      return;
    }

    link.textContent = message;
  }

  async function fetchLaunchUrl(config, submissionId) {
    var endpoint = new URL(config.launchEndpoint, window.location.origin);
    endpoint.searchParams.set('submissionId', submissionId);

    var response = await fetch(endpoint.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json'
      },
      cache: 'no-store'
    });

    var data = null;
    try {
      data = await response.json();
    } catch (_error) {
      data = null;
    }

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
    var link = document.createElement('button');
    var label = config.label || 'Open in Studio';

    link.id = 'omi-studio-launcher';
    link.className = 'omi-studio-launcher';
    link.type = 'button';
    link.textContent = label;
    link.setAttribute('aria-label', label);

    link.addEventListener('click', async function () {
      if (link.disabled) {
        return;
      }

      link.disabled = true;
      setState(link, 'loading', 'Opening Studio…');

      try {
        var launchUrl = await fetchLaunchUrl(config, submissionId);
        window.location.assign(launchUrl);
      } catch (error) {
        var message = error instanceof Error ? error.message : 'Studio launch failed.';
        console.error('OMI Studio launch failed:', error);
        setState(link, 'error', message);
        link.title = message;
        link.disabled = false;

        window.setTimeout(function () {
          if (document.body.contains(link)) {
            setState(link, 'ready', label);
          }
        }, 5000);
      }
    });

    document.body.appendChild(link);
  }

  function mount() {
    var config = getConfig();
    if (!config || !config.launchEndpoint) {
      return;
    }

    var submissionId = getSubmissionId();
    if (!submissionId) {
      lastSubmissionId = null;
      removeLauncher();
      return;
    }

    if (
      submissionId === lastSubmissionId &&
      document.getElementById('omi-studio-launcher')
    ) {
      return;
    }

    lastSubmissionId = submissionId;
    removeLauncher();
    createLauncher(config, submissionId);
  }

  function scheduleMount() {
    window.setTimeout(mount, 0);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }

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
    if (getSubmissionId() !== lastSubmissionId) {
      scheduleMount();
    }
  });

  observer.observe(document.documentElement, {
    childList: true,
    subtree: true
  });
}());
