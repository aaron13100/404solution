// allow-no-test-found: exercised by background-refresh-detect-only.spec.js (E2E)
/**
 * "Refresh available" pill UI.
 *
 * A single transient overlay (#abj404-refresh-available-pill) sits at the
 * bottom-right of the admin table view. It appears only when a background
 * detect-only poll finds the data changed since the page loaded (new 404s
 * arrived, or an edit was made in another tab); clicking it reloads the page
 * to show the new data.
 *
 * There is deliberately no "refreshing / data refreshed" progress toast: the
 * table always renders live from the single denorm read, so a background poll
 * that finds nothing changed is a silent no-op. This pill is the only surface
 * the background refresh ever shows.
 *
 * The helper is defensive: it creates its own <style> tag exactly once and
 * tolerates being called from multiple paths in a single page lifecycle.
 *
 * Globals defined: ensureRefreshAvailablePillStyles, hideRefreshAvailablePill,
 * showRefreshAvailablePill.
 */

function ensureRefreshAvailablePillStyles() {
    if (document.getElementById('abj404-refresh-available-pill-styles')) {
        return;
    }
    var style = document.createElement('style');
    style.id = 'abj404-refresh-available-pill-styles';
    style.textContent =
        '#abj404-refresh-available-pill{' +
        'position:fixed;right:16px;bottom:16px;z-index:99999;padding:8px 12px;' +
        'background:var(--abj404-accent,#2271b1);color:#fff;border:1px solid rgba(0,0,0,.08);' +
        'border-radius:999px;font-size:12px;font-weight:600;line-height:1.2;cursor:pointer;' +
        'box-shadow:0 4px 14px rgba(0,0,0,.22);transition:opacity .15s ease,transform .15s ease;}' +
        '#abj404-refresh-available-pill:hover{transform:translateY(-1px);background:var(--abj404-accent-hover,#135e96);}';
    document.head.appendChild(style);
}

function hideRefreshAvailablePill() {
    var pill = document.getElementById('abj404-refresh-available-pill');
    if (pill && pill.parentNode) {
        pill.parentNode.removeChild(pill);
    }
    if (window.abj404RefreshAvailableHideTimer) {
        window.clearTimeout(window.abj404RefreshAvailableHideTimer);
        window.abj404RefreshAvailableHideTimer = null;
    }
    window.abj404RefreshAvailableHiddenAt = Date.now(); // allow-direct-time: telemetry timestamp; browser admin script, no client-side clock adapter exists in this plugin
}

function showRefreshAvailablePill(message, timeoutMs) {
    ensureRefreshAvailablePillStyles();
    hideRefreshAvailablePill();
    var pill = document.createElement('button');
    pill.type = 'button';
    pill.id = 'abj404-refresh-available-pill';
    pill.textContent = message || 'Refresh available';
    pill.setAttribute('aria-live', 'polite');
    pill.setAttribute('title', message || 'Refresh available');
    pill.addEventListener('click', function() {
        window.location.reload();
    });
    document.body.appendChild(pill);
    window.abj404RefreshAvailableShownAt = Date.now(); // allow-direct-time: telemetry timestamp; browser admin script, no client-side clock adapter exists in this plugin
    window.abj404RefreshAvailableLastMessage = message || 'Refresh available';
    var delay = Math.max(1000, parseInt(timeoutMs, 10) || 5000);
    window.abj404RefreshAvailableHideTimer = window.setTimeout(function() {
        hideRefreshAvailablePill();
    }, delay);
}
