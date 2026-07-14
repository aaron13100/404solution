/**
 * Stats page: Match Confidence distribution doughnut chart.
 *
 * Reads its configuration (labels + counts) from a JSON blob on the
 * canvas element's `data-abj404-confidence` attribute, so the PHP
 * render path doesn't need to inline any JS.
 *
 * Renders into #abj404-chart-confidence using Chart.js (bundled with the
 * plugin and enqueued as a hard dependency, so window.Chart is normally
 * already defined). As a fallback it also waits for the `abj404ChartJsLoaded`
 * event that statsTrends.js dispatches, in case of script-order differences.
 */
(function () {
    'use strict';

    // Returns the translated chart-data-unusable message when wp.i18n's
    // locale data is available, else the English fallback. The literal must
    // be passed directly to wp.i18n.__() (not through a variable) so
    // make-pot can statically extract it; mirrors the switch-dispatched
    // literal pattern in support-request-modal-view.js's t(key) for the same
    // situation: a user-facing string needed on a failure path where the
    // normal PHP-supplied, pre-translated config (cfg) is unavailable.
    function chartDataUnusableMessage() {
        if (window.wp && window.wp.i18n && typeof window.wp.i18n.__ === 'function') {
            return window.wp.i18n.__('Could not load chart data. Reload this page and try again.', '404-solution');
        }
        return 'Could not load chart data. Reload this page and try again.';
    }

    function readConfig(canvas) {
        var raw = canvas.getAttribute('data-abj404-confidence');
        if (!raw) {
            return null;
        }
        try {
            return JSON.parse(raw);
        } catch (e) {
            // Malformed config JSON previously left the canvas permanently
            // blank with no diagnostic trail. The counts in the legend
            // below it are rendered server-side and stay correct, but the
            // visual chart itself cannot be built without cfg. Log for
            // diagnosis and hint at recovery via a native tooltip instead
            // of a silently blank canvas.
            if (window.console && window.console.error) {
                window.console.error('404 Solution: abj404-chart-confidence data-abj404-confidence attribute is not valid JSON', raw, e);
            }
            canvas.title = chartDataUnusableMessage();
            return null;
        }
    }

    function renderConfidenceChart() {
        var canvas = document.getElementById('abj404-chart-confidence');
        if (!canvas || !window.Chart) {
            return;
        }
        var cfg = readConfig(canvas);
        if (!cfg) {
            return;
        }
        new window.Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: [cfg.labelHigh, cfg.labelMedium, cfg.labelLow, cfg.labelManual],
                datasets: [{
                    data: [cfg.high, cfg.medium, cfg.low, cfg.manual],
                    // allow-hardcoded-color: chart dataset palette; matches .abj404-conf-* dots in CSS
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545', '#adb5bd']
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } }
            }
        });
    }

    if (window.Chart) {
        renderConfidenceChart();
    } else {
        document.addEventListener('abj404ChartJsLoaded', renderConfidenceChart);
    }
})();
