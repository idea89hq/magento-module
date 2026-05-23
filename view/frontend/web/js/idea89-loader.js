(function () {
  'use strict';

  if (window.__IDEA89_LOADED__) return;
  window.__IDEA89_LOADED__ = true;

  var script = document.currentScript;
  var apiKey = script.getAttribute('data-key');
  var position = script.getAttribute('data-position') || 'bottom-right';
  var color = script.getAttribute('data-color') || '#000000';
  var apiBase = script.src.replace(/\/widget\/v1\/.*$/, '');

  if (!apiKey) return;

  // Inject the Web Component definition served from our API
  var s = document.createElement('script');
  s.async = true;
  s.src = apiBase + '/widget/v1/' + encodeURIComponent(apiKey) + '.js';
  s.setAttribute('data-position', position);
  s.setAttribute('data-color', color);
  document.head.appendChild(s);
})();
