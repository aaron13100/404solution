/**
 * Stats page: Match Confidence distribution doughnut chart.
 *
 * Reads its configuration (labels + counts) from a JSON blob on the
 * canvas element's `data-abj404-confidence` attribute, so the PHP
 * render path doesn't need to inline any JS.
 *
 * Renders into #abj404-chart-confidence using Chart.js. If Chart.js
 * isn't loaded yet, waits for the `abj404ChartJsLoaded` event that
 * statsTrends.js dispatches after fetching the CDN script.
 */
(function () {
    'use strict';

    function readConfig(canvas) {
        var raw = canvas.getAttribute('data-abj404-confidence');
        if (!raw) {
            return null;
        }
        try {
            return JSON.parse(raw);
        } catch (e) {
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
