/*! FlatRate GROWTH-001B plain-voting foundation — UI suppression + gate-aware chrome. */
(function () {
  'use strict';

  if (typeof app === 'undefined') {
    return;
  }

  // Boot-safe stub: register only. Mutations added after SPA boot is green.
  app.initializers.add('flatrate-wiki-plain-voting', function () {
    // Intentionally empty while isolating SPA boot regression.
  });
})();
