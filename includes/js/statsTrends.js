/**
 * Stats page: Trend Analytics line charts.
 *
 * Loads Chart.js from CDN on demand, then fetches abj404getTrendData
 * for the selected period (7 / 30 / 90 days) and renders three line
 * charts: 404 hits, redirects, new captures.
 *
 * Reads ajaxUrl, nonce, and translatable labels from a JSON blob on
 * the configuration carrier element's `data-abj404-trends` attribute
 * (#abj404-trends-config). On 403 (expired nonce), refreshes via
 * window.abj404NonceRefresh and retries once.
 */
(function () {
    'use strict';

    function readConfig() {
        var el = document.getElementById('abj404-trends-config');
        if (!el) {
            return null;
        }
        var raw = el.getAttribute('data-abj404-trends');
        if (!raw) {
            return null;
        }
        try {
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    var cfg = null;
    var nonce = '';
    var chartInstances = {};

    function loadChartJs(cb) {
        if (window.Chart) {
            cb();
            return;
        }
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js';
        s.onload = function () {
            document.dispatchEvent(new Event('abj404ChartJsLoaded'));
            cb();
        };
        s.onerror = function () {
            var loadEl = document.querySelector('.abj404-trends-loading');
            if (loadEl) { loadEl.style.display = 'none'; }
            var errEl = document.getElementById('abj404-trends-error');
            if (errEl) { errEl.style.display = ''; }
        };
        document.head.appendChild(s);
    }

    function buildChart(canvasId, label, color, labels, values) {
        var ctx = document.getElementById(canvasId);
        if (!ctx) { return null; }
        return new window.Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: label,
                    data: values,
                    borderColor: color,
                    backgroundColor: color.replace('rgb(', 'rgba(').replace(')', ', 0.15)'),
                    tension: 0.3,
                    fill: true,
                    pointRadius: 3
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: true } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }

    function getSelectedDays() {
        var radios = document.querySelectorAll('input[name=abj404_trend_period]');
        for (var i = 0; i < radios.length; i++) {
            if (radios[i].checked) {
                return parseInt(radios[i].value, 10);
            }
        }
        return 30;
    }

    function destroyCharts() {
        ['abj404-chart-404s', 'abj404-chart-redirects', 'abj404-chart-captures'].forEach(function (id) {
            if (chartInstances[id]) {
                chartInstances[id].destroy();
                delete chartInstances[id];
            }
        });
    }

    function fetchTrendData(days, allowRetry) {
        // ajax-direct-approved: trend chart endpoint streams a GET response and owns nonce-refresh retry handling locally.
        return fetch(cfg.ajaxUrl + '?action=abj404getTrendData&nonce=' + encodeURIComponent(nonce) + '&days=' + days)
            .then(function (r) {
                // B20: a 12-24h-idle nonce expires; admin-ajax replies 403.
                // Mint a fresh nonce via the shared refresh helper (if loaded)
                // and retry once. allowRetry guards against an infinite loop.
                if (r.status === 403 && allowRetry !== false && window.abj404NonceRefresh) {
                    return window.abj404NonceRefresh.fetchFresh().then(function (freshNonces) {
                        if (freshNonces && freshNonces['abj404_trendData']) {
                            nonce = freshNonces['abj404_trendData'];
                        }
                        return fetchTrendData(days, false);
                    });
                }
                return r.json();
            });
    }

    function fetchAndRender() {
        var days = getSelectedDays();
        var loadEl   = document.querySelector('.abj404-trends-loading');
        var errEl    = document.getElementById('abj404-trends-error');
        var chartsEl = document.getElementById('abj404-trends-charts');
        if (loadEl)   { loadEl.style.display = ''; }
        if (errEl)    { errEl.style.display = 'none'; }
        if (chartsEl) { chartsEl.style.display = 'none'; }
        destroyCharts();
        fetchTrendData(days, true)
            .then(function (resp) {
                if (loadEl) { loadEl.style.display = 'none'; }
                if (!resp || !resp.success || !Array.isArray(resp.data)) {
                    if (errEl) { errEl.style.display = ''; }
                    return;
                }
                var rows = resp.data;
                var labels    = rows.map(function (r) { return r.date; });
                var vals404   = rows.map(function (r) { return r.hits_404; });
                var valsRedir = rows.map(function (r) { return r.hits_redirect; });
                var valsCapt  = rows.map(function (r) { return r.new_captures; });
                if (chartsEl) { chartsEl.style.display = ''; }
                // allow-hardcoded-color: Chart.js dataset border colors must be JS string literals;
                // Chart.js cannot read CSS custom properties (--abj404-*) from a canvas context.
                chartInstances['abj404-chart-404s']      = buildChart('abj404-chart-404s',      cfg.label404,      'rgb(0,115,170)',  labels, vals404);
                chartInstances['abj404-chart-redirects'] = buildChart('abj404-chart-redirects', cfg.labelRedirect, 'rgb(70,170,100)', labels, valsRedir);
                chartInstances['abj404-chart-captures']  = buildChart('abj404-chart-captures',  cfg.labelCapture,  'rgb(220,100,50)', labels, valsCapt);
            })
            .catch(function () {
                if (loadEl) { loadEl.style.display = 'none'; }
                if (errEl)  { errEl.style.display  = ''; }
            });
    }

    function onPeriodChange() { fetchAndRender(); }

    document.addEventListener('DOMContentLoaded', function () {
        cfg = readConfig();
        if (!cfg) {
            return;
        }
        nonce = cfg.nonce || '';
        loadChartJs(fetchAndRender);
        document.querySelectorAll('input[name=abj404_trend_period]').forEach(function (r) {
            r.addEventListener('change', onPeriodChange);
        });
    });
})();
