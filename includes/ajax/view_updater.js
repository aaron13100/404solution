/**
 * Admin redirects/captured/logs/stats table refresh entry point.
 *
 * Bootstraps the pagination + background-refresh flows on jQuery.ready
 * and owns the cross-cutting helpers used by every sibling module:
 *
 *   - getURLParameter / abj404GenerateRequestId:
 *     primitives shared with every helper file below.
 *   - bindPaginationLinkListeners / bindSearchFieldListeners +
 *     extractPagedFromTrigger / isElementFullyVisible:
 *     event wiring for the pagination links and the search input.
 *
 * The orchestration logic itself lives in the sibling files. Each
 * sibling defines top-level functions that this file references; load
 * order in WordPress_Connector::my_wp_enq_scrpt guarantees those globals
 * are available before jQuery.ready runs:
 *
 *   - view_updater_stage_diagnostics.js  (label lookup)
 *   - view_updater_compare.js            (background-refresh diff)
 *   - view_updater_refresh_pill.js       (refresh-available pill)
 *   - view_updater_stats.js              (stats refresh + cooldown helpers)
 *   - view_updater_table_init.js         (initial-load + bg refresh + healthbar)
 *   - view_updater_pagination.js         (paginationLinksChange fetch handler)
 *   - view_updater.js                    (this file: ready boot + utilities)
 */

if (typeof(getURLParameter) !== "function") {
    function getURLParameter(name) {
        return (location.search.split('?' + name + '=')[1] ||
                location.search.split('&' + name + '=')[1] ||
                '').split('&')[0];
    }
}


/**
 * Generate a short alphanumeric id that uniquely tags each foreground
 * pagination request. window.abj404LatestForegroundRequestId records the
 * most recently issued one so a stale response (e.g. a slow initial-load
 * hydration that resolves after a newer filter/sort request) can be
 * detected and dropped instead of clobbering the visible table.
 *
 * Server validates: `[a-zA-Z0-9]{8,64}`.  16 hex-ish chars is plenty of
 * entropy to avoid collisions across concurrent admin tabs.
 *
 * @returns {string}
 */
function abj404GenerateRequestId() {
    var alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    var out = '';
    for (var i = 0; i < 16; i++) {
        out += alphabet.charAt(Math.floor(Math.random() * alphabet.length));
    }
    return out;
}

// when the user presses enter on the filter text input then update the table
jQuery(document).ready(function($) {
    bindSearchFieldListeners();
    bindPaginationLinkListeners();
    triggerInitialTableLoadIfNeeded();
    triggerBackgroundTableRefreshIfEnabled();
    triggerStatsBackgroundRefreshIfEnabled();
    // The health bar is rendered as an empty placeholder by PHP and hydrated
    // here so the slow getHighImpactCapturedCount() query never blocks first
    // paint of the redirects table.  Safe to call on every page; returns
    // early when no placeholder is in the DOM.
    refreshHealthBarIfNeeded();
});

function bindPaginationLinkListeners() {
    // Delegate to document so handlers survive table HTML replacement after AJAX refresh.
    jQuery(document)
        .off('click.abj404Pagination', '.abj404-pagination-controls a')
        .on('click.abj404Pagination', '.abj404-pagination-controls a', function(event) {
            event.preventDefault();
            event.stopPropagation();
            paginationLinksChange(event.currentTarget || event.target || this);
        });
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

    // Initialize previous-value so the search event handler won't fire
    // spuriously when the field is programmatically cleared from an
    // already-empty state (e.g. Playwright's fill() clears before typing).
    filters.each(function() {
        var $f = jQuery(this);
        if (typeof $f.attr('data-previous-value') === 'undefined') {
            $f.attr('data-previous-value', $f.val() || '');
        }
    });

    // Remove any previously bound handlers to prevent accumulation
    // across repeated AJAX table refreshes.
    filters.off('search.abj404 keypress.abj404 click.abj404');

    filters.on("search.abj404", function(event) {
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
    filters.on("keypress.abj404", function(event) {
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
    filters.on("click.abj404", function() {
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

function extractPagedFromTrigger(triggerItem) {
    if (!triggerItem) {
        return '';
    }
    var href = '';
    var $trigger = jQuery(triggerItem);
    if ($trigger.is('a')) {
        href = $trigger.attr('href') || '';
    } else {
        var $link = $trigger.closest('a');
        href = ($link.length > 0) ? ($link.attr('href') || '') : '';
    }
    if (!href) {
        return '';
    }

    // Support both relative admin URLs and absolute URLs.
    var query = '';
    var queryStart = href.indexOf('?');
    if (queryStart >= 0) {
        query = href.substring(queryStart + 1);
    } else {
        query = href;
    }
    if (!query) {
        return '';
    }

    var pairs = query.split('&');
    for (var i = 0; i < pairs.length; i++) {
        var kv = pairs[i].split('=');
        if (kv.length < 2) {
            continue;
        }
        var key = decodeURIComponent(kv[0] || '');
        if (key !== 'paged') {
            continue;
        }
        var value = decodeURIComponent(kv[1] || '');
        if (/^[0-9]+$/.test(value)) {
            return value;
        }
        return '';
    }
    return '';
}
