(function () {
  'use strict';

  var lastSubmissionId = null;
  var requestSerial = 0;

  function getConfig() {
    return window.OMI_STUDIO_INTEGRATION || null;
  }

  function getSubmissionId() {
    var url = new URL(window.location.href);

    var queryKeys = [
      'workflowSubmissionId',
      'submissionId',
      'id'
    ];

    for (var i = 0; i < queryKeys.length; i += 1) {
      var queryValue = url.searchParams.get(queryKeys[i]);
      if (/^[1-9][0-9]*$/.test(queryValue || '')) {
        return queryValue;
      }
    }

    var segments = url.pathname
      .split('/')
      .filter(Boolean);

    var workflowIndex = segments.indexOf('workflow');
    if (workflowIndex !== -1) {
      for (var j = workflowIndex + 1; j < segments.length; j += 1) {
        if (/^[1-9][0-9]*$/.test(segments[j])) {
          return segments[j];
        }
      }
    }

    var submissionIndex = segments.indexOf('submission');
    if (submissionIndex !== -1) {
      for (var k = submissionIndex + 1; k < segments.length; k += 1) {
        if (/^[1-9][0-9]*$/.test(segments[k])) {
          return segments[k];
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

  function createLauncher(label) {
    var link = document.createElement('a');
    link.id = 'omi-studio-launcher';
    link.className = 'omi-studio-launcher';
    link.href = '#';
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.textContent = label || 'Open in Studio';
    link.setAttribute('aria-label', label || 'Open in Studio');
    link.setAttribute('aria-disabled', 'true');
    link.classList.add('omi-studio-launcher--loading');
    document.body.appendChild(link);
    return link;
  }

  function requestLaunchUrl(config, submissionId, link) {
    requestSerial += 1;
    var serial = requestSerial;

    var endpoint = new URL(config.launchEndpoint, window.location.origin);
    endpoint.searchParams.set('submissionId', submissionId);

    fetch(endpoint.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json'
      }
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Launch URL request failed with HTTP ' + response.status + '.');
        }
        return response.json();
      })
      .then(function (data) {
        if (
          serial !== requestSerial ||
          submissionId !== getSubmissionId() ||
          !data ||
          !data.launchUrl
        ) {
          return;
        }

        link.href = data.launchUrl;
        link.classList.remove('omi-studio-launcher--loading');
        link.removeAttribute('aria-disabled');
      })
      .catch(function () {
        if (serial !== requestSerial) {
          return;
        }
        removeLauncher();
      });
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

    var link = createLauncher(config.label);
    requestLaunchUrl(config, submissionId, link);
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
