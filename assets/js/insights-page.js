/**
 * Initialise Chart.js figures from #insights-chart-payload (built by insights.php).
 */
(function () {
  'use strict';

  function mergeDefaults(spec) {
    return {
      type: spec.type,
      data: spec.data,
      options: Object.assign(
        {
          responsive: true,
          maintainAspectRatio: false,
        },
        spec.options || {}
      ),
    };
  }

  function init() {
    var payloadEl = document.getElementById('insights-chart-payload');
    if (!payloadEl || typeof Chart === 'undefined') {
      return;
    }
    var payload;
    try {
      payload = JSON.parse(payloadEl.textContent || '{}');
    } catch (e) {
      return;
    }
    (payload.charts || []).forEach(function (spec) {
      var canvas = document.getElementById(spec.canvasId);
      if (!canvas) {
        return;
      }
      var ctx = canvas.getContext('2d');
      if (!ctx) {
        return;
      }
      new Chart(ctx, mergeDefaults(spec));
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
