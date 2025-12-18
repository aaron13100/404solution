
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
});

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

function paginationLinksChange(triggerItem) {
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

    // Show loading overlay on the table
    var $table = jQuery(tableSelector);
    // Wrap table if not already wrapped, and add overlay
    if (!$table.parent().hasClass('abj404-table-wrapper')) {
        $table.wrap('<div class="abj404-table-wrapper"></div>');
    }
    var $wrapper = $table.parent();
    // Remove any existing overlay first
    $wrapper.find('.abj404-loading-overlay').remove();
    // Add the loading overlay with spinner (spinner-container uses sticky positioning to stay visible)
    $wrapper.append('<div class="abj404-loading-overlay"><div class="abj404-spinner-container"><div class="abj404-spinner"></div></div></div>');

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
    });
}
