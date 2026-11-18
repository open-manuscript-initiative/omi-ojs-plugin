(function () {
  'use strict';

  var VERSION = '1.8.5';
  var GLOBAL_KEY = '__OMI_STUDIO_LAUNCHER__';
  var LAUNCHER_ID = 'omi-studio-launcher';
  var NOTICE_ID = 'omi-studio-installation-notice';

  if (window[GLOBAL_KEY] && typeof window[GLOBAL_KEY].mount === 'function') {
    window[GLOBAL_KEY].mount();
    return;
  }

  var lastSubmissionId = null;
  var mountScheduled = false;

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
      if (markerIndex === -1) continue;

      for (var j = markerIndex + 1; j < segments.length; j += 1) {
        if (/^[1-9][0-9]*$/.test(segments[j])) return segments[j];
      }
    }

    return null;
  }

  function getLauncher() {
    return document.getElementById(LAUNCHER_ID);
  }

  function isCurrentLauncher(element) {
    return Boolean(
      element &&
      element.dataset &&
      element.dataset.omiStudioLauncherVersion === VERSION
    );
  }

  function removeLauncher() {
    var existing = getLauncher();
    if (existing) existing.remove();
    var notice = document.getElementById(NOTICE_ID);
    if (notice) notice.remove();
  }

  function createDirectLaunchUrl(config, submissionId) {
    var endpoint = new URL(config.launchEndpoint, window.location.origin);
    endpoint.searchParams.set('submissionId', submissionId);

    if (config.mode && config.mode !== 'auto') {
      endpoint.searchParams.set('mode', config.mode);
    }

    endpoint.searchParams.set('redirect', '1');
    endpoint.searchParams.set('_omi', Date.now().toString());
    return endpoint.toString();
  }

  function createLauncher(config, submissionId) {
    var button = document.createElement('button');
    var label = config.label || (
      config.mode === 'review' ? 'Open in Studio for Review' : 'Open in Studio'
    );

    button.id = LAUNCHER_ID;
    button.className = 'omi-studio-launcher';
    button.type = 'button';
    button.textContent = label;
    button.setAttribute('aria-label', label);
    button.dataset.omiStudioLauncherVersion = VERSION;
    button.dataset.omiSubmissionId = submissionId;
    button.dataset.omiInstallationError = config.installationError || '';

    if (config.installationError) {
      button.disabled = true;
      button.classList.add('omi-studio-launcher--unavailable');
      button.setAttribute('aria-describedby', NOTICE_ID);
      var notice = document.createElement('div');
      notice.id = NOTICE_ID;
      notice.className = 'omi-studio-installation-notice';
      notice.setAttribute('role', 'alert');
      notice.textContent = config.installationError;
      document.body.appendChild(notice);
    }

    button.addEventListener('pointerdown', function (event) {
      event.stopPropagation();
    }, true);

    button.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      if (button.disabled) return;

      button.disabled = true;
      button.classList.add('omi-studio-launcher--loading');
      button.setAttribute('aria-busy', 'true');
      button.textContent = 'Opening Studio…';
      window.location.assign(createDirectLaunchUrl(config, submissionId));
    }, true);

    document.body.appendChild(button);
    return button;
  }

  function mount() {
    mountScheduled = false;

    var config = getConfig();
    if (!config || !config.launchEndpoint) return;

    var submissionId = getSubmissionId();
    if (!submissionId) {
      lastSubmissionId = null;
      removeLauncher();
      return;
    }

    var existing = getLauncher();
    var existingMatches = isCurrentLauncher(existing) &&
      existing.dataset.omiSubmissionId === submissionId &&
      existing.dataset.omiInstallationError === (config.installationError || '');

    if (existingMatches) {
      lastSubmissionId = submissionId;
      return;
    }

    removeLauncher();
    createLauncher(config, submissionId);
    lastSubmissionId = submissionId;
  }

  function scheduleMount() {
    if (mountScheduled) return;
    mountScheduled = true;
    window.setTimeout(mount, 0);
  }

  window[GLOBAL_KEY] = {
    version: VERSION,
    mount: scheduleMount
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount, {once: true});
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
    var submissionId = getSubmissionId();
    var launcher = getLauncher();

    if (
      submissionId !== lastSubmissionId ||
      (submissionId && !isCurrentLauncher(launcher)) ||
      (submissionId && launcher && launcher.dataset.omiSubmissionId !== submissionId)
    ) {
      scheduleMount();
    }
  });

  observer.observe(document.documentElement, {
    childList: true,
    subtree: true
  });
}());
