/**
 * Stats page: Trend Analytics line charts.
 *
 * Uses Chart.js (bundled with the plugin at includes/js/lib/ and enqueued
 * as a hard dependency by AdminAssetEnqueuer), then fetches abj404getTrendData
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
            // Malformed config JSON (encoding issue, WAF/proxy mangling the
            // attribute) previously left the panel stuck on "Loading chart
            // data..." forever with nothing in the console to diagnose. Log
            // for diagnosis and surface the same recoverable error panel
            // fetchAndRender()/loadChartJs() use for other failure modes.
            if (window.console && window.console.error) {
                window.console.error('404 Solution: abj404-trends-config data-abj404-trends attribute is not valid JSON', raw, e);
            }
            var loadEl = document.querySelector('.abj404-trends-loading');
            if (loadEl) { loadEl.style.display = 'none'; }
            var errEl = document.getElementById('abj404-trends-error');
            if (errEl) { errEl.style.display = ''; }
            return null;
        }
    }

    var cfg = null;
    var nonce = '';
    var chartInstances = {};

    // Trend data is a lighter read (three small aggregate series) than the
    // one-shot admin actions elsewhere in this bundle, so a shorter bound is
    // reasonable while still tolerating a slow-but-working shared host. A
    // stalled response must not hold the loading state / browser resources
    // open forever (M501).
    var TREND_DATA_TIMEOUT_MS = 20000;

    function loadChartJs(cb) {
        // Chart.js is bundled with the plugin and enqueued as a hard dependency
        // (see AdminAssetEnqueuer::addScripts), so window.Chart is already
        // defined by the time this runs. We dispatch abj404ChartJsLoaded so
        // statsConfidenceChart.js renders too, regardless of script order.
        if (window.Chart) {
            document.dispatchEvent(new Event('abj404ChartJsLoaded'));
            cb();
            return;
        }
        // Defensive: if the bundled library somehow failed to load, surface the
        // trends error panel instead of silently rendering nothing.
        var loadEl = document.querySelector('.abj404-trends-loading');
        if (loadEl) { loadEl.style.display = 'none'; }
        var errEl = document.getElementById('abj404-trends-error');
        if (errEl) { errEl.style.display = ''; }
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
        // Each call (including the nonce-refresh retry below) gets its own
        // bounded controller so a stalled admin-ajax response cannot hold
        // the loading state open forever (M501). Abort surfaces as a
        // rejection that flows through the same generic .catch() in
        // fetchAndRender() already used for every other fetch failure.
        var controller = new AbortController();
        var timeoutId = setTimeout(function () { controller.abort(); }, TREND_DATA_TIMEOUT_MS);
        // ajax-direct-approved: trend chart endpoint streams a GET response and owns nonce-refresh retry handling locally.
        return fetch(cfg.ajaxUrl + '?action=abj404getTrendData&nonce=' + encodeURIComponent(nonce) + '&days=' + days, { signal: controller.signal }) // allow-direct-network: trend chart endpoint; no project-wide adapter exists for this AJAX surface
            .then(function (r) {
                clearTimeout(timeoutId);
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
            })
            .catch(function (e) {
                clearTimeout(timeoutId);
                throw e;
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
