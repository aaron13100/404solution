
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
});

function getRefreshStatusHost() {
    var $host = jQuery('.abj404-pagination-right').first();
    if ($host.length === 0) {
        $host = jQuery('.abj404-filter-bar').first();
    }
    return $host;
}

function triggerBackgroundTableRefreshIfEnabled() {
    var $config = getRefreshStatusHost();
    if ($config.length === 0) {
        return;
    }
    if ($config.attr('data-pagination-auto-refresh') !== '1') {
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
        lastStatusCode: null,
        lastResponseBytes: null,
        lastSubpage: null,
        lastAction: null,
        lastRowsPerPage: null,
        lastFilterTextLength: 0,
        lastError: null
    };
    var startedAt = Date.now();
    var startedText = $config.attr('data-pagination-refresh-started-text') || 'Refreshing data in background...';
    setRefreshStatus($config, startedText);
    showRefreshToastStart(startedText);

    var perpageElements = document.querySelectorAll('.perpage');
    if (perpageElements == null || perpageElements.length === 0) {
        clearRefreshStatus($config);
        return;
    }

    // Show cached snapshot immediately, then refresh in the background during idle time.
    var runRefresh = function() {
        paginationLinksChange(perpageElements[0], {
            backgroundRefresh: true,
            onComplete: function() {
                var $latestConfig = getRefreshStatusHost();
                var finishedText = $latestConfig.attr('data-pagination-refresh-finished-text') || 'Data refreshed';
                var elapsed = Date.now() - startedAt;
                var minimumStartedMs = 850;
                var showFinished = function() {
                    setRefreshStatus($latestConfig, finishedText);
                    showRefreshToastComplete(finishedText);
                    window.setTimeout(function() { clearRefreshStatus($latestConfig); }, 3500);
                    window.setTimeout(hideRefreshToast, 3500);
                };
                if (elapsed < minimumStartedMs) {
                    window.setTimeout(showFinished, minimumStartedMs - elapsed);
                } else {
                    showFinished();
                }
                if (window.abj404BackgroundRefreshState) {
                    window.abj404BackgroundRefreshState.finishedAt = Date.now();
                }
            },
            onError: function() {
                var $latestConfig = getRefreshStatusHost();
                clearRefreshStatus($latestConfig.length > 0 ? $latestConfig : $config);
                hideRefreshToast();
                if (window.abj404BackgroundRefreshState) {
                    window.abj404BackgroundRefreshState.lastError = 'background-refresh-failed';
                    window.abj404BackgroundRefreshState.finishedAt = Date.now();
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

function ensureRefreshToastStyles() {
    if (document.getElementById('abj404-refresh-toast-styles')) {
        return;
    }
    var style = document.createElement('style');
    style.id = 'abj404-refresh-toast-styles';
    style.textContent =
        '#abj404-background-refresh-toast{' +
        'position:fixed;right:16px;bottom:16px;z-index:99999;padding:8px 10px;' +
        'background:rgba(30,32,35,.90);color:#fff;border-radius:18px;font-size:12px;' +
        'box-shadow:0 4px 14px rgba(0,0,0,.22);max-width:360px;display:flex;align-items:center;gap:8px;' +
        'cursor:default;transition:all .2s ease;box-sizing:border-box;}' +
        '#abj404-background-refresh-toast .abj404-refresh-spinner{' +
        'width:12px;height:12px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;' +
        'border-radius:50%;flex:0 0 auto;animation:abj404-refresh-spin .8s linear infinite;}' +
        '#abj404-background-refresh-toast .abj404-refresh-label{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed{padding:8px;width:28px;max-width:28px;overflow:hidden;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed{height:28px;min-height:28px;gap:0;justify-content:center;border-radius:50%;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed .abj404-refresh-label{display:none;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed:hover{max-width:340px;width:auto;padding:8px 10px;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed:hover .abj404-refresh-label{display:inline;}' +
        '#abj404-background-refresh-toast.abj404-refresh-complete .abj404-refresh-spinner{animation:none;border-color:rgba(255,255,255,.45);border-top-color:rgba(255,255,255,.45);}' +
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
    toast.setAttribute('title', message || '');
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

function bindSearchFieldListeners() {
    var filters = jQuery('input[name=searchFilter]');
    if (filters === undefined || filters === null || filters.length === 0) {
        return;
    }
    
    filters.prop('disabled', false);
    
    field = jQuery(filters[0]);
    var fieldLength = field.val().length;
    // only set the focus if the input box is visible. otherwise screen scrolls for no reason.
    if (isElementFullyVisible(filters[0])) {
        field.focus();
    }
    // put the cursor at the end of the field
    filters[0].setSelectionRange(fieldLength, fieldLength);
    
    filters.on("search", function(event) {
        field = jQuery(event.srcElement);
        var previousValue = field.attr("data-previous-value");
        var fieldLength = field.val() == null ? 0 : field.val().length;
        if (fieldLength === 0 && field.val() !== previousValue) {
            paginationLinksChange(event.srcElement);
            event.preventDefault();
        }
        field.attr("data-previous-value", field.val());
    });
    
    // update the page when the user presses enter.
    // store the typed value to restore once the page is reloaded.
    filters.keypress(function(event) {
        var keycode = (event.which ? event.which : event.keyCode);
        if (keycode === 13) {
            event.preventDefault();
            var srcElement = event.srcElement;
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
    var trashFilter = getURLParameter('filter');

    // Prefer nonce from attribute; fall back to legacy parsing from URL.
    var nonce = $ajaxConfigEl.attr("data-pagination-ajax-nonce") || '';
    if (!nonce) {
        var nonceMatch = url.match(/[?&]nonce=([^&]+)/);
        nonce = nonceMatch ? nonceMatch[1] : '';
    }

    // Use a clean admin-ajax base URL; always send 'action' in the payload for compatibility with security plugins.
    var baseUrl = url.split('?')[0];
    var requestStartedAt = Date.now();
    if (window.abj404BackgroundRefreshState && isBackgroundRefresh) {
        window.abj404BackgroundRefreshState.requestCount = (window.abj404BackgroundRefreshState.requestCount || 0) + 1;
        window.abj404BackgroundRefreshState.lastSubpage = subpage;
        window.abj404BackgroundRefreshState.lastAction = action;
        window.abj404BackgroundRefreshState.lastRowsPerPage = parseInt(rowsPerPage, 10) || 0;
        window.abj404BackgroundRefreshState.lastFilterTextLength = (filterText || '').length;
        window.abj404BackgroundRefreshState.lastError = null;
        window.abj404BackgroundRefreshState.lastStatusCode = null;
        window.abj404BackgroundRefreshState.lastResponseBytes = null;
    }

    if (!isBackgroundRefresh) {
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
            nonce: nonce
        },
        success: function (result) {
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
