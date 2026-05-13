/**
 * Stats dashboard background-refresh and per-tab auto-refresh throttling.
 *
 * Lives next to the table refresh code but talks to a different endpoint
 * (ajaxRefreshStatsDashboard). It also owns the localStorage cooldown
 * helpers shared with the redirects-table flow:
 *
 *   - getStatsAutoRefreshCacheKey/shouldRunStatsAutoRefreshNow/markStatsAutoRefreshCompleted
 *     throttle stats refresh to once per 30s per tab/subpage key.
 *   - getAutoRefreshCacheKey/shouldRunAutoRefreshNow/markAutoRefreshCompleted
 *     throttle the redirects-table detect-only refresh to once per 30s
 *     per tab/sort/filter key.
 *
 * Plus setRefreshStatus/clearRefreshStatus, the small DOM helpers used to
 * populate the inline `.abj404-refresh-status` element with status text.
 *
 * Globals defined: getStatsRefreshConfigHost, getStatsAutoRefreshCacheKey,
 * shouldRunStatsAutoRefreshNow, markStatsAutoRefreshCompleted,
 * triggerStatsBackgroundRefreshIfEnabled, setRefreshStatus, clearRefreshStatus,
 * getAutoRefreshCacheKey, shouldRunAutoRefreshNow, markAutoRefreshCompleted.
 *
 * Depends on view_updater.js (abj404UpdateAjaxDebugLog, getURLParameter) and
 * view_updater_toast.js (showRefreshAvailablePill).
 */

function getStatsRefreshConfigHost() {
    return jQuery('.abj404-stats-refresh-config').first();
}

function getStatsAutoRefreshCacheKey($config) {
    var page = getURLParameter('page') || '';
    var subpage = ($config && $config.attr) ? ($config.attr('data-stats-refresh-subpage') || getURLParameter('subpage') || 'abj404_stats') : (getURLParameter('subpage') || 'abj404_stats');
    return 'abj404:stats_auto_refresh:' + [page, subpage].join(':');
}

function shouldRunStatsAutoRefreshNow($config) {
    try {
        if (!window.localStorage) {
            return true;
        }
        var key = getStatsAutoRefreshCacheKey($config);
        var lastTs = parseInt(localStorage.getItem(key) || '0', 10);
        var cooldownMs = 30000; // at most once every 30s for this tab
        return !(lastTs > 0 && (Date.now() - lastTs) < cooldownMs);
    } catch (e) {
        return true;
    }
}

function markStatsAutoRefreshCompleted($config) {
    try {
        if (!window.localStorage) {
            return;
        }
        var key = getStatsAutoRefreshCacheKey($config);
        localStorage.setItem(key, String(Date.now()));
    } catch (e) {
        // allow-silent-catch: best-effort cooldown timestamp; storage failures must not block the page
    }
}

function triggerStatsBackgroundRefreshIfEnabled() {
    var $config = getStatsRefreshConfigHost();
    if ($config.length === 0) {
        return;
    }
    if ($config.attr('data-stats-refresh-enabled') !== '1') {
        return;
    }
    if (!shouldRunStatsAutoRefreshNow($config)) {
        return;
    }
    if (window.abj404StatsRefreshTriggered) {
        return;
    }
    window.abj404StatsRefreshTriggered = true;

    var nonce = $config.attr('data-stats-refresh-nonce') || '';
    if (nonce === '') {
        return;
    }

    window.abj404StatsBackgroundRefreshState = {
        enabled: true,
        startedAt: Date.now(),
        finishedAt: null,
        difference: null,
        durationMs: null,
        lastStatusCode: null,
        hasUpdateAvailable: false,
        lastError: null
    };

    var action = $config.attr('data-stats-refresh-action') || 'ajaxRefreshStatsDashboard';
    var refreshAvailableText = $config.attr('data-stats-refresh-available-text') || 'Refresh available';
    var currentHash = $config.attr('data-stats-refresh-current-hash') || '';

    var runRefresh = function() {
        var startMs = Date.now();
        abj404UpdateAjaxDebugLog('Starting Stats AJAX: ' + action);
        var statsAjaxRunner = (typeof abj404AjaxWithNonceRetry === 'function')
            ? abj404AjaxWithNonceRetry : jQuery.ajax;
        statsAjaxRunner({
            url: window.ajaxurl || 'admin-ajax.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: action,
                page: getURLParameter('page') || '',
                subpage: getURLParameter('subpage') || 'abj404_stats',
                nonce: nonce,
                currentHash: currentHash
            },
            success: function(result) {
                var payload = result;
                if (payload && payload.data && typeof payload.data === 'object' && payload.success === false) {
                    payload = payload.data;
                }
                var hasUpdate = !!(payload && payload.hasUpdate);
                abj404UpdateAjaxDebugLog('Stats AJAX Success: ' + action, {
                    hasUpdate: hasUpdate,
                    durationMs: Date.now() - startMs
                });
                if (hasUpdate) {
                    showRefreshAvailablePill(refreshAvailableText, 5000);
                }
                if (payload && payload.hash) {
                    $config.attr('data-stats-refresh-current-hash', payload.hash);
                }
                if (window.abj404StatsBackgroundRefreshState) {
                    var duration = Date.now() - startMs;
                    window.abj404StatsBackgroundRefreshState.finishedAt = Date.now();
                    window.abj404StatsBackgroundRefreshState.durationMs = duration;
                    window.abj404StatsBackgroundRefreshState.difference = duration;
                    window.abj404StatsBackgroundRefreshState.lastStatusCode = 200;
                    window.abj404StatsBackgroundRefreshState.hasUpdateAvailable = hasUpdate;
                }
                markStatsAutoRefreshCompleted($config);
            },
            error: function(xhr, textStatus, errorThrown) {
                abj404UpdateAjaxDebugLog('Stats AJAX Error: ' + action, {
                    status: xhr ? xhr.status : '',
                    textStatus: textStatus,
                    errorThrown: errorThrown,
                    durationMs: Date.now() - startMs
                });
                if (window.abj404StatsBackgroundRefreshState) {
                    var duration = Date.now() - startMs;
                    window.abj404StatsBackgroundRefreshState.finishedAt = Date.now();
                    window.abj404StatsBackgroundRefreshState.durationMs = duration;
                    window.abj404StatsBackgroundRefreshState.difference = duration;
                    window.abj404StatsBackgroundRefreshState.lastStatusCode = xhr ? xhr.status : null;
                    window.abj404StatsBackgroundRefreshState.lastError = textStatus || errorThrown || 'ajax-error';
                    window.abj404StatsBackgroundRefreshState.hasUpdateAvailable = false;
                }
                markStatsAutoRefreshCompleted($config);
            }
        });
    };

    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(runRefresh, {timeout: 2000});
    } else {
        setTimeout(runRefresh, 900);
    }
}

function setRefreshStatus($config, message) {
    if (!$config || $config.length === 0) {
        return;
    }
    var $status = $config.find('.abj404-refresh-status').first();
    if ($status.length === 0) {
        $status = jQuery('<span class="abj404-refresh-status" aria-live="polite"></span>');
        $config.append($status);
    }
    if ($status.length > 0) {
        $status.text(message || '');
        if (message) {
            abj404UpdateAjaxDebugLog('Status: ' + message);
        }
    }
}

function clearRefreshStatus($config) {
    setRefreshStatus($config, '');
}

function getAutoRefreshCacheKey($config) {
    var page = getURLParameter('page') || '';
    var subpage = ($config && $config.attr) ? ($config.attr('data-pagination-ajax-subpage') || '') : '';
    var filter = ($config && $config.attr) ? ($config.attr('data-pagination-current-filter') || '') : '';
    if (filter === '') {
        filter = getURLParameter('filter') || '0';
    }
    var orderby = ($config && $config.attr) ? ($config.attr('data-pagination-current-orderby') || '') : '';
    if (orderby === '') {
        orderby = getURLParameter('orderby') || '';
    }
    var order = ($config && $config.attr) ? ($config.attr('data-pagination-current-order') || '') : '';
    if (order === '') {
        order = getURLParameter('order') || '';
    }
    return 'abj404:auto_refresh:' + [page, subpage, filter, orderby, order].join(':');
}

function shouldRunAutoRefreshNow($config) {
    try {
        if (!window.localStorage) {
            return true;
        }
        var key = getAutoRefreshCacheKey($config);
        var lastTs = parseInt(localStorage.getItem(key) || '0', 10);
        var cooldownMs = 30000; // 30s throttle per tab/sort/filter key
        return !(lastTs > 0 && (Date.now() - lastTs) < cooldownMs);
    } catch (e) {
        return true;
    }
}

function markAutoRefreshCompleted($config) {
    try {
        if (!window.localStorage) {
            return;
        }
        var key = getAutoRefreshCacheKey($config);
        localStorage.setItem(key, String(Date.now()));
    } catch (e) {
        // allow-silent-catch: best-effort cooldown timestamp; storage failures must not block the page
    }
}
