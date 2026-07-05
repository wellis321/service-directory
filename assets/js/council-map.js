/**
 * Scotland council picker (Leaflet + centroid markers).
 * Config: <script type="application/json" id="council-map-config">
 *   { markers, query: {q,type,min_grade}, view?: { mode:'fixed', lat, lng, zoom, minZoom?, maxZoom? } }
 */
(function () {
  'use strict';

  function buildFilterUrl(councilName) {
    var cfgEl = document.getElementById('council-map-config');
    if (!cfgEl) return '/';
    var cfg;
    try {
      cfg = JSON.parse(cfgEl.textContent || '{}');
    } catch (e) {
      return '/';
    }
    var q = cfg.query || {};
    var p = new URLSearchParams();
    if (q.q) p.set('q', q.q);
    if (q.type) p.set('type', q.type);
    if (q.min_grade && String(q.min_grade) !== '0') p.set('min_grade', String(q.min_grade));
    if (q.min_avg) p.set('min_avg', String(q.min_avg));
    if (q.sp) p.set('sp', q.sp);
    if (q.sort && q.sort !== 'default') p.set('sort', q.sort);
    if (q.graded_within && String(q.graded_within) !== '0') p.set('graded_within', String(q.graded_within));
    if (councilName) p.set('council', councilName);
    var s = p.toString();
    return s ? '/?' + s + '#directory' : '/#directory';
  }

  function markerRadius(count) {
    var n = Math.max(0, Number(count) || 0);
    return Math.max(5, Math.min(18, 4 + Math.sqrt(n)));
  }

  function init() {
    var mapEl = document.getElementById('council-map');
    var cfgEl = document.getElementById('council-map-config');
    if (!mapEl || !cfgEl || typeof L === 'undefined') return;

    var cfg;
    try {
      cfg = JSON.parse(cfgEl.textContent || '{}');
    } catch (e) {
      return;
    }
    var markers = cfg.markers;
    if (!markers || !markers.length) return;

    var v = cfg.view || {};
    var fixed = v.mode === 'fixed';
    var minZ = typeof v.minZoom === 'number' ? v.minZoom : 5;
    var maxZ = typeof v.maxZoom === 'number' ? v.maxZoom : 11;

    var map = L.map(mapEl, {
      scrollWheelZoom: true,
      attributionControl: true,
      minZoom: minZ,
      maxZoom: maxZ,
      doubleClickZoom: true,
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);

    var layer = L.layerGroup().addTo(map);

    markers.forEach(function (m) {
      var r = markerRadius(m.count);
      var isSel = !!m.selected;
      var circle = L.circleMarker([m.lat, m.lng], {
        radius: r,
        weight: isSel ? 3 : 1,
        color: isSel ? '#063d32' : '#0f6e56',
        fillColor: '#0f6e56',
        fillOpacity: isSel ? 0.55 : 0.38,
      });
      var label = m.name + (m.count ? ' — ' + m.count + ' service' + (m.count === 1 ? '' : 's') : ' — no matches');
      circle.bindTooltip(label, { sticky: true, direction: 'top' });
      circle.on('click', function (ev) {
        if (ev && ev.originalEvent && L.DomEvent) {
          L.DomEvent.stopPropagation(ev.originalEvent);
        }
        window.location.href = buildFilterUrl(m.name);
      });
      circle.addTo(layer);
    });

    if (fixed && typeof v.lat === 'number' && typeof v.lng === 'number' && typeof v.zoom === 'number') {
      map.setView([v.lat, v.lng], v.zoom);
    } else {
      var bounds = L.latLngBounds([]);
      markers.forEach(function (m) {
        bounds.extend([m.lat, m.lng]);
      });
      if (bounds.isValid()) {
        map.fitBounds(bounds, { padding: [28, 28], maxZoom: 8 });
      } else {
        map.setView([56.5, -4.2], 6);
      }
    }

    setTimeout(function () {
      map.invalidateSize();
    }, 0);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
