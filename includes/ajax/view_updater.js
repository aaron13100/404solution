
if (typeof(getURLParameter) !== "function") {
    function getURLParameter(name) {
        return (location.search.split('?' + name + '=')[1] || 
                location.search.split('&' + name + '=')[1] || 
                '').split('&')[0];
    }
}

// when the user presses enter on the filter text input then update the table
jQuery(document).ready(function($) {
    bindSearchFieldListeners();
    triggerBackgroundTableRefreshIfEnabled();
    triggerStatsBackgroundRefreshIfEnabled();
});

function getRefreshStatusHost() {
    var $host = jQuery('.abj404-pagination-right').first();
    if ($host.length === 0) {
        $host = jQuery('.abj404-filter-bar').first();
    }
    return $host;
}

function isDetectOnlyRefreshInFlight() {
    return window.abj404DetectOnlyRefreshInFlight === true;
}

function setDetectOnlyRefreshInFlight(isRunning) {
    window.abj404DetectOnlyRefreshInFlight = !!isRunning;
}

function triggerBackgroundTableRefreshIfEnabled() {
    var $config = getRefreshStatusHost();
    if ($config.length === 0) {
        return;
    }
    if ($config.attr('data-pagination-auto-refresh') !== '1') {
        return;
    }
    if (!shouldRunAutoRefreshNow($config)) {
        return;
    }
    if (window.abj404InitialTableRefreshTriggered) {
        return;
    }
    window.abj404InitialTableRefreshTriggered = true;
    window.abj404BackgroundRefreshState = {
        enabled: true,
        startedAt: Date.now(),
        finishedAt: null,
        difference: null,
        durationMs: null,
        requestCount: 0,
        hasUpdateAvailable: false,
        lastStatusCode: null,
        lastResponseBytes: null,
        lastSubpage: null,
        lastAction: null,
        lastRowsPerPage: null,
        lastFilterTextLength: 0,
        lastError: null
    };

    var perpageElements = document.querySelectorAll('.perpage');
    if (perpageElements == null || perpageElements.length === 0) {
        return;
    }

    // Run a detect-only check in the background. Never overwrite the visible table automatically.
    var runRefresh = function() {
        if (isDetectOnlyRefreshInFlight()) {
            return;
        }
        paginationLinksChange(perpageElements[0], {
            backgroundRefresh: true,
            detectOnly: true,
            onComplete: function(meta) {
                var $latestConfig = getRefreshStatusHost();
                var hasUpdate = !!(meta && meta.hasUpdate);
                if (hasUpdate) {
                    var availableText = $latestConfig.attr('data-pagination-refresh-available-text') || 'Refresh available';
                    showRefreshAvailablePill(availableText, 5000);
                }
                if (window.abj404BackgroundRefreshState) {
                    window.abj404BackgroundRefreshState.finishedAt = Date.now();
                    window.abj404BackgroundRefreshState.hasUpdateAvailable = hasUpdate;
                }
                markAutoRefreshCompleted($latestConfig);
            },
            onError: function() {
                if (window.abj404BackgroundRefreshState) {
                    window.abj404BackgroundRefreshState.lastError = 'background-refresh-failed';
                    window.abj404BackgroundRefreshState.finishedAt = Date.now();
                    window.abj404BackgroundRefreshState.hasUpdateAvailable = false;
                }
            }
        });
    };
    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(runRefresh, {timeout: 2000});
    } else {
        setTimeout(runRefresh, 900);
    }
}

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
        // ignore storage failures
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
        jQuery.ajax({
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
    }
}

function clearRefreshStatus($config) {
    setRefreshStatus($config, '');
}

function normalizeHtmlForBackgroundComparison(html) {
    if (!html) {
        return '';
    }
    return String(html)
            .replace(/&#0*38;|&amp;/gi, '&')
            .replace(/<!--[\s\S]*?-->/g, '')
            // Browser DOM serialization of SVG can differ from server-rendered HTML
            // (e.g., <path .../> vs <path ...></path>) without any data change.
            .replace(/<svg\b[\s\S]*?<\/svg>/gi, '')
            .replace(/<span class="abj404-refresh-status"[^>]*>[\s\S]*?<\/span>/g, '')
            .replace(/(<span class="abj404-time-ago"[^>]*>)[\s\S]*?(<\/span>)/g, '$1$2')
            // "time-ago" markers are freshness metadata; timestamp drift alone should not signal table-data change.
            .replace(/\sdata-timestamp="[^"]*"/gi, '')
            .replace(/\sdata-previous-value="[^"]*"/g, '')
            // Search input is initially rendered disabled and then enabled client-side;
            // ignore this client-only attribute drift for no-change detection.
            .replace(/\sdisabled(?:=(?:"disabled"|""))?/gi, '')
            .replace(/data-pagination-ajax-nonce="[^"]*"/g, 'data-pagination-ajax-nonce="nonce"')
            // URLs in attributes may encode "&" as "&amp;" or "&#038;".
            // Normalize all nonce query params so nonce churn is not treated as data change.
            .replace(/((?:\?|&|&amp;|&#038;)(?:_wpnonce|nonce)=)[^&"'\s>]+/gi, '$1nonce')
            .replace(/\s+/g, ' ')
            .trim();
}

function hasBackgroundRefreshUpdate(result) {
    return hasBackgroundRefreshUpdateWithBaseline(result, null);
}

function getComparableTableHtml(html) {
    if (!html) {
        return '';
    }
    var raw = String(html);
    // Compare rendered row data (tbody) only; header/pagination/link-encoding differences
    // are presentation concerns and can trigger false positives.
    var match = raw.match(/<tbody[^>]*>[\s\S]*<\/tbody>/i);
    if (match && match.length > 0) {
        return match[0];
    }
    return raw;
}

function buildComparableTableSignature(html) {
    var comparableHtml = getComparableTableHtml(html);
    var normalizedHtml = normalizeHtmlForBackgroundComparison(comparableHtml);
    if (!normalizedHtml) {
        return '';
    }

    // Compare semantic row/cell text instead of raw markup so entity/quote/style
    // serialization differences do not produce false positives.
    if (typeof DOMParser !== 'function') {
        return normalizedHtml;
    }

    try {
        var parser = new DOMParser();
        var doc = parser.parseFromString('<table>' + normalizedHtml + '</table>', 'text/html');
        var rows = doc.querySelectorAll('tbody tr');
        if (!rows || rows.length === 0) {
            return normalizedHtml;
        }
        var rowParts = [];
        for (var i = 0; i < rows.length; i++) {
            var cells = rows[i].querySelectorAll('td');
            var cellParts = [];
            for (var j = 0; j < cells.length; j++) {
                var text = (cells[j].textContent || '').replace(/\s+/g, ' ').trim();
                cellParts.push(text);
            }
            rowParts.push(cellParts.join('||'));
        }
        // Ignore non-deterministic row ordering for equal sort values.
        rowParts.sort();
        return rowParts.join('\n');
    } catch (e) {
        return normalizedHtml;
    }
}

function hasBackgroundRefreshUpdateWithBaseline(result, baseline) {
    var currentTableHtml = '';
    var incomingTableHtml = '';

    var currentTable = jQuery('.abj404-table, .wp-list-table').first();
    if (currentTable.length > 0) {
        currentTableHtml = currentTable.prop('outerHTML') || '';
    }
    if (result && typeof result.table === 'string') {
        incomingTableHtml = result.table;
    }

    var normalizedCurrentTable = buildComparableTableSignature(currentTableHtml);
    var normalizedIncomingTable = buildComparableTableSignature(incomingTableHtml);

    if (baseline && typeof baseline === 'object') {
        if (typeof baseline.table === 'string') {
            normalizedCurrentTable = baseline.table;
        }
    }

    return normalizedCurrentTable !== normalizedIncomingTable;
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
        // ignore storage failures
    }
}

function ensureRefreshToastStyles() {
    if (document.getElementById('abj404-refresh-toast-styles')) {
        return;
    }
    var style = document.createElement('style');
    style.id = 'abj404-refresh-toast-styles';
    style.textContent =
        '#abj404-background-refresh-toast{' +
        'position:fixed;right:16px;bottom:16px;z-index:99999;padding:8px 10px;' +
        'background:var(--abj404-surface,#1f2937);color:var(--abj404-text,#fff);' +
        'border:1px solid var(--abj404-border,rgba(255,255,255,.15));border-radius:18px;font-size:12px;' +
        'box-shadow:0 4px 14px rgba(0,0,0,.22);max-width:360px;display:flex;align-items:center;gap:8px;' +
        'cursor:default;transition:width .18s ease,max-width .18s ease,padding .18s ease,gap .18s ease,border-radius .18s ease,opacity .18s ease,background-color .18s ease;box-sizing:border-box;}' +
        '#abj404-background-refresh-toast .abj404-refresh-spinner{' +
        'width:12px;height:12px;border:2px solid var(--abj404-text-muted,rgba(255,255,255,.45));border-top-color:var(--abj404-accent,#fff);' +
        'border-radius:50%;flex:0 0 auto;animation:abj404-refresh-spin .8s linear infinite;}' +
        '#abj404-background-refresh-toast .abj404-refresh-label{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed{padding:0;width:28px;min-width:28px;max-width:28px;overflow:hidden;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed{height:28px;min-height:28px;gap:0;line-height:0;justify-content:center;border-radius:14px;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed .abj404-refresh-label{display:none;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed:hover{max-width:340px;width:auto;min-width:0;padding:8px 10px;height:28px;min-height:28px;line-height:normal;gap:8px;border-radius:14px;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed:hover .abj404-refresh-label{display:inline;}' +
        '#abj404-background-refresh-toast.abj404-refresh-complete{background:var(--abj404-success,#1f9d55);color:#fff;border-color:transparent;}' +
        '#abj404-background-refresh-toast.abj404-refresh-complete .abj404-refresh-spinner{animation:none;border-color:rgba(255,255,255,.5);border-top-color:rgba(255,255,255,.5);}' +
        '@keyframes abj404-refresh-spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}';
    document.head.appendChild(style);
}

function ensureRefreshToast() {
    ensureRefreshToastStyles();
    var id = 'abj404-background-refresh-toast';
    var toast = document.getElementById(id);
    if (!toast) {
        toast = document.createElement('div');
        toast.id = id;
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        toast.innerHTML = '<span class="abj404-refresh-spinner" aria-hidden="true"></span><span class="abj404-refresh-label"></span>';
        document.body.appendChild(toast);
    }
    return toast;
}

function setRefreshToastMessage(message) {
    var toast = ensureRefreshToast();
    var label = toast.querySelector('.abj404-refresh-label');
    if (label) {
        label.textContent = message || '';
    }
    return toast;
}

function showRefreshToastStart(message) {
    var toast = setRefreshToastMessage(message);
    toast.classList.remove('abj404-refresh-complete');
    toast.classList.remove('abj404-refresh-collapsed');
    toast.style.display = 'flex';
    window.setTimeout(function() {
        if (toast.style.display !== 'none' && !toast.classList.contains('abj404-refresh-complete')) {
            toast.classList.add('abj404-refresh-collapsed');
        }
    }, 2000);
}

function showRefreshToastComplete(message) {
    var toast = setRefreshToastMessage(message);
    toast.classList.remove('abj404-refresh-collapsed');
    toast.classList.add('abj404-refresh-complete');
    toast.style.display = 'flex';
}

function hideRefreshToast() {
    var toast = document.getElementById('abj404-background-refresh-toast');
    if (toast) {
        toast.classList.remove('abj404-refresh-collapsed');
        toast.classList.remove('abj404-refresh-complete');
        toast.style.display = 'none';
    }
}

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
    window.abj404RefreshAvailableHiddenAt = Date.now();
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
    window.abj404RefreshAvailableShownAt = Date.now();
    window.abj404RefreshAvailableLastMessage = message || 'Refresh available';
    var delay = Math.max(1000, parseInt(timeoutMs, 10) || 5000);
    window.abj404RefreshAvailableHideTimer = window.setTimeout(function() {
        hideRefreshAvailablePill();
    }, delay);
}

function bindSearchFieldListeners() {
    var filters = jQuery('input[name=searchFilter]');
    if (filters === undefined || filters === null || filters.length === 0) {
        return;
    }
    
    filters.prop('disabled', false);
    
    var field = jQuery(filters[0]);
    var fieldLength = field.val().length;
    // only set the focus if the input box is visible. otherwise screen scrolls for no reason.
    if (isElementFullyVisible(filters[0])) {
        field.focus();
    }
    // put the cursor at the end of the field
    filters[0].setSelectionRange(fieldLength, fieldLength);
    
    filters.on("search", function(event) {
        var field = jQuery(event.target || event.srcElement);
        var previousValue = field.attr("data-previous-value");
        var fieldLength = field.val() == null ? 0 : field.val().length;
        if (fieldLength === 0 && field.val() !== previousValue) {
            paginationLinksChange(event.target || event.srcElement || filters[0]);
            event.preventDefault();
        }
        field.attr("data-previous-value", field.val());
    });
    
    // update the page when the user presses enter.
    // store the typed value to restore once the page is reloaded.
    filters.keypress(function(event) {
        var field = jQuery(event.target || event.srcElement);
        var keycode = (event.which ? event.which : event.keyCode);
        if (keycode === 13) {
            event.preventDefault();
            var srcElement = event.target || event.srcElement;
            // prefer using the "perpage" element as the source element because when
            // the input box itself is used as a source element there's some kind of bug
            // and I don't care to figure out why at the moment, therefore this hack...
            var perpageElements = document.querySelectorAll('.perpage');
            if (perpageElements != null && perpageElements.length > 0) {
            	srcElement = perpageElements[0];
            }
            paginationLinksChange(srcElement);
        }
        field.attr("data-previous-value", field.val());
    });
    
    // select all text when clicked
    filters.click(function() {
        jQuery(this).select();
    });
}

/** Returns true if an element is within the viewport.
 * From https://stackoverflow.com/a/22480938/222564
 * @param {type} el
 * @returns {Boolean}
 */
function isElementFullyVisible(el) {
    var rect = el.getBoundingClientRect();
    var elemTop = rect.top;
    var elemBottom = rect.bottom;

    // Only completely visible elements return true:
    var isVisible = (elemTop >= 0) && (elemBottom <= window.innerHeight);
    return isVisible;
}

function paginationLinksChange(triggerItem, options) {
    options = options || {};
    var isBackgroundRefresh = options.backgroundRefresh === true;
    var detectOnly = options.detectOnly === true;
    var rowThatChanged = jQuery(triggerItem).parentsUntil('.tablenav').parent();
    var rowsPerPage = jQuery(rowThatChanged).find('select[name=perpage]').val();
    var filterText = jQuery(rowThatChanged).find('input[name=searchFilter]').val();

    // Only show loading on the table itself, not the filter bar or pagination
    var tableSelector = jQuery('.abj404-table').length > 0 ? '.abj404-table' : '.wp-list-table';

    // Get AJAX config from the page (supports both new data-attrs and legacy URL-with-query).
    var $ajaxConfigEl = jQuery(".abj404-pagination-right").first();
    if ($ajaxConfigEl.length === 0) {
        $ajaxConfigEl = jQuery(".abj404-filter-bar").first();
    }
    if ($ajaxConfigEl.length === 0) {
        $ajaxConfigEl = jQuery("[data-pagination-ajax-url]").first();
    }
    var url = $ajaxConfigEl.attr("data-pagination-ajax-url") || window.ajaxurl;
    if (!url) {
        console.warn('404 Solution: data-pagination-ajax-url attribute not found');
        return;
    }
    var action = $ajaxConfigEl.attr("data-pagination-ajax-action") || 'ajaxUpdatePaginationLinks';
    var subpage = $ajaxConfigEl.attr("data-pagination-ajax-subpage") || getURLParameter('subpage');
    var page = getURLParameter('page');
    var trashFilter = $ajaxConfigEl.attr('data-pagination-current-filter');
    if (typeof trashFilter === 'undefined' || trashFilter === null || trashFilter === '') {
        trashFilter = getURLParameter('filter');
    }
    var orderby = $ajaxConfigEl.attr('data-pagination-current-orderby');
    if (!orderby) {
        orderby = getURLParameter('orderby');
    }
    var order = $ajaxConfigEl.attr('data-pagination-current-order');
    if (!order) {
        order = getURLParameter('order');
    }
    var paged = $ajaxConfigEl.attr('data-pagination-current-paged');
    if (!paged) {
        paged = getURLParameter('paged');
    }
    var id = $ajaxConfigEl.attr('data-pagination-current-logsid');
    if (!id) {
        id = getURLParameter('id');
    }

    // Prefer nonce from attribute; fall back to legacy parsing from URL.
    var nonce = $ajaxConfigEl.attr("data-pagination-ajax-nonce") || '';
    if (!nonce) {
        var nonceMatch = url.match(/[?&]nonce=([^&]+)/);
        nonce = nonceMatch ? nonceMatch[1] : '';
    }

    // Use a clean admin-ajax base URL; always send 'action' in the payload for compatibility with security plugins.
    var baseUrl = url.split('?')[0];
    var requestStartedAt = Date.now();
    var baselineComparison = null;
    var isDetectOnlyBackground = (isBackgroundRefresh && detectOnly);
    if (isBackgroundRefresh && detectOnly) {
        var tableAtRequestStart = jQuery('.abj404-table, .wp-list-table').first();
        baselineComparison = {
            table: buildComparableTableSignature(
                tableAtRequestStart.length > 0 ? (tableAtRequestStart.prop('outerHTML') || '') : ''
            ),
            serverSignature: ($ajaxConfigEl.attr('data-pagination-current-signature') || '')
        };
    }
    if (isDetectOnlyBackground && isDetectOnlyRefreshInFlight()) {
        if (typeof options.onComplete === 'function') {
            options.onComplete({hasUpdate: false, skipped: true});
        }
        return;
    }
    if (isDetectOnlyBackground) {
        setDetectOnlyRefreshInFlight(true);
    }
    if (window.abj404BackgroundRefreshState && isBackgroundRefresh) {
        window.abj404BackgroundRefreshState.requestCount = (window.abj404BackgroundRefreshState.requestCount || 0) + 1;
        window.abj404BackgroundRefreshState.lastSubpage = subpage;
        window.abj404BackgroundRefreshState.lastAction = action;
        window.abj404BackgroundRefreshState.lastRowsPerPage = parseInt(rowsPerPage, 10) || 0;
        window.abj404BackgroundRefreshState.lastFilterTextLength = (filterText || '').length;
        window.abj404BackgroundRefreshState.lastError = null;
        window.abj404BackgroundRefreshState.lastStatusCode = null;
        window.abj404BackgroundRefreshState.lastResponseBytes = null;
        window.abj404BackgroundRefreshState.hasUpdateAvailable = false;
    }

    if (!isBackgroundRefresh) {
        hideRefreshAvailablePill();
        // Show loading overlay on the table for explicit user actions only.
        var $table = jQuery(tableSelector);
        if (!$table.parent().hasClass('abj404-table-wrapper')) {
            $table.wrap('<div class="abj404-table-wrapper"></div>');
        }
        var $wrapper = $table.parent();
        $wrapper.find('.abj404-loading-overlay').remove();
        $wrapper.append('<div class="abj404-loading-overlay"><div class="abj404-spinner-container"><div class="abj404-spinner"></div></div></div>');
    }

    // do an ajax call to update the data
    jQuery.ajax({
        url: baseUrl,
        type: 'POST',
        dataType: "json",
        data: {
            action: action,
            page: page,
            rowsPerPage: rowsPerPage,
            filterText: filterText,
            filter: trashFilter,
            subpage: subpage,
            nonce: nonce,
            orderby: orderby,
            order: order,
            paged: paged,
            id: id,
            detectOnly: detectOnly ? '1' : '0',
            currentSignature: (detectOnly && baselineComparison && baselineComparison.serverSignature)
                ? baselineComparison.serverSignature : ''
        },
        success: function (result) {
            if (isBackgroundRefresh && detectOnly) {
                setDetectOnlyRefreshInFlight(false);
                var hasUpdate;
                if (result && typeof result.hasUpdate === 'boolean') {
                    hasUpdate = !!result.hasUpdate;
                } else {
                    // Backward-compatible fallback for older server responses.
                    hasUpdate = hasBackgroundRefreshUpdateWithBaseline(result, baselineComparison);
                }
                if (typeof options.onComplete === 'function') {
                    options.onComplete({hasUpdate: hasUpdate});
                }
                if (window.abj404BackgroundRefreshState) {
                    var bgDurationMs = Date.now() - requestStartedAt;
                    var bgResultSize = 0;
                    if (result) {
                        try {
                            bgResultSize = JSON.stringify(result).length;
                        } catch (e) {
                            bgResultSize = 0;
                        }
                    }
                    window.abj404BackgroundRefreshState.finishedAt = Date.now();
                    window.abj404BackgroundRefreshState.durationMs = bgDurationMs;
                    window.abj404BackgroundRefreshState.difference = bgDurationMs;
                    window.abj404BackgroundRefreshState.lastStatusCode = 200;
                    window.abj404BackgroundRefreshState.lastResponseBytes = bgResultSize;
                    window.abj404BackgroundRefreshState.hasUpdateAvailable = hasUpdate;
                }
                return;
            }

            // get the current text value
            var currentFieldValue = jQuery('input[name=searchFilter]').val();

            // replace the tables - support both old (.wp-list-table) and new (.abj404-table) table classes
            var pageLinks = jQuery('.abj404-pagination-right');
            if (pageLinks.length > 0) {
                jQuery(pageLinks[0]).replaceWith(result.paginationLinksTop);
                if (pageLinks.length > 1) {
                    jQuery(pageLinks[1]).replaceWith(result.paginationLinksBottom);
                }
            }
            // Replace the table - try both class names
            if (jQuery('.wp-list-table').length > 0) {
                jQuery('.wp-list-table').replaceWith(result.table);
            } else if (jQuery('.abj404-table').length > 0) {
                jQuery('.abj404-table').replaceWith(result.table);
            }
            // Reinitialize table interactions (checkboxes, bulk actions) after AJAX refresh
            if (typeof window.abj404InitTableInteractions === 'function') {
                window.abj404InitTableInteractions();
            }
            bindSearchFieldListeners();
            if (typeof window.abj404InitTimeAgo === 'function') {
                window.abj404InitTimeAgo();
            }
            jQuery('input[name=searchFilter]').val(currentFieldValue);
            jQuery('input[name=searchFilter]').attr("data-previous-value", currentFieldValue);

            // Remove the loading overlay
            jQuery('.abj404-loading-overlay').fadeOut(200, function() {
                jQuery(this).remove();
            });

            bindTrashLinkListeners();
            if (typeof options.onComplete === 'function') {
                options.onComplete();
            }
            if (!isBackgroundRefresh && typeof triggerBackgroundTableRefreshIfEnabled === 'function') {
                // Re-arm one detect-only refresh for the newly loaded table state.
                // Without this, manual AJAX navigation can suppress update detection
                // for the rest of the current page session.
                window.abj404InitialTableRefreshTriggered = false;
                window.setTimeout(function() {
                    triggerBackgroundTableRefreshIfEnabled();
                }, 0);
            }
            if (window.abj404BackgroundRefreshState && isBackgroundRefresh) {
                var durationMs = Date.now() - requestStartedAt;
                var resultSize = 0;
                if (result) {
                    try {
                        resultSize = JSON.stringify(result).length;
                    } catch (e) {
                        resultSize = 0;
                    }
                }
                window.abj404BackgroundRefreshState.finishedAt = Date.now();
                window.abj404BackgroundRefreshState.durationMs = durationMs;
                window.abj404BackgroundRefreshState.difference = durationMs;
                window.abj404BackgroundRefreshState.lastStatusCode = 200;
                window.abj404BackgroundRefreshState.lastResponseBytes = resultSize;
            }
        },
        error: function (jqXHR, textStatus, errorThrown) {
            if (isBackgroundRefresh && detectOnly) {
                setDetectOnlyRefreshInFlight(false);
            }
            // Remove the loading overlay on error
            jQuery('.abj404-loading-overlay').remove();
            var status = jqXHR && jqXHR.status ? jqXHR.status : '';
            var responseText = jqXHR && jqXHR.responseText ? String(jqXHR.responseText) : '';
            var responseJson = jqXHR && jqXHR.responseJSON ? jqXHR.responseJSON : null;
            var responsePreview = responseText;
            if (responsePreview.length > 2000) {
                responsePreview = responsePreview.slice(0, 2000) + "\n…(truncated)…";
            }

            // Always log full details to the console for easier debugging.
            if (window && window.console && window.console.error) {
                window.console.error('404 Solution AJAX error', {
                    context: 'Updating table',
                    status: status,
                    textStatus: textStatus,
                    errorThrown: errorThrown,
                    url: baseUrl,
                    action: action,
                    subpage: subpage,
                    responseJson: responseJson,
                    responseText: responseText
                });
            }

            var messageFromServer = '';
            var detailsFromServer = '';
            if (responseJson && responseJson.data) {
                if (responseJson.data.message) {
                    messageFromServer = String(responseJson.data.message);
                }
                if (responseJson.data.details) {
                    try {
                        detailsFromServer = JSON.stringify(responseJson.data.details, null, 2);
                    } catch (e) {
                        detailsFromServer = String(responseJson.data.details);
                    }
                }
            }

            if (!isBackgroundRefresh) {
                alert(
                    "404 Solution: Ajax error while updating the table.\n\n" +
                    "HTTP status: " + status + "\n" +
                    "textStatus: " + textStatus + "\n" +
                    "errorThrown: " + errorThrown + "\n" +
                    "action: " + action + "\n" +
                    "subpage: " + subpage + "\n" +
                    "url: " + baseUrl + "\n\n" +
                    (messageFromServer ? ("Server message:\n" + messageFromServer + "\n\n") : "") +
                    (detailsFromServer ? ("Server details (admin only):\n" + detailsFromServer + "\n\n") : "") +
                    "Response (preview):\n" + responsePreview
                );
            }
            if (typeof options.onError === 'function') {
                options.onError();
            }
            if (window.abj404BackgroundRefreshState && isBackgroundRefresh) {
                var durationMs = Date.now() - requestStartedAt;
                window.abj404BackgroundRefreshState.finishedAt = Date.now();
                window.abj404BackgroundRefreshState.durationMs = durationMs;
                window.abj404BackgroundRefreshState.difference = durationMs;
                window.abj404BackgroundRefreshState.lastStatusCode = status || null;
                window.abj404BackgroundRefreshState.lastError = textStatus || errorThrown || 'ajax-error';
                window.abj404BackgroundRefreshState.lastResponseBytes = responseText ? responseText.length : 0;
            }
        }
    });
}
