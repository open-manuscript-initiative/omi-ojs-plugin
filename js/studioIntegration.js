(function () {
  'use strict';

  function mount() {
    var config = window.OMI_STUDIO_INTEGRATION;
    if (!config || !config.launchUrl || document.getElementById('omi-studio-launcher')) {
      return;
    }

    var link = document.createElement('a');
    link.id = 'omi-studio-launcher';
    link.className = 'omi-studio-launcher';
    link.href = config.launchUrl;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.textContent = config.label || 'Open in Studio';
    link.setAttribute('aria-label', config.label || 'Open in Studio');
    document.body.appendChild(link);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
}());
