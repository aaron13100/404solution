
if (typeof(getURLParameter) !== "function") {
    function getURLParameter(name) {
        return (location.search.split('?' + name + '=')[1] || 
                location.search.split('&' + name + '=')[1] || 
                '').split('&')[0];
    }
}

// when the user presses enter on the filter text input then update the table
jQuery(document).ready(function($) {
    bindSearchFieldKeyListener();
});

function bindSearchFieldKeyListener() {
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
    filters[0].setSelectionRange(fieldLength, fieldLength);
    
    filters.on("search", function(event) {
        field = jQuery(event.srcElement);
        var previousValue = field.attr("data-previous-value");
        var fieldLength = field.val().length;
        if (fieldLength === 0 && field.val() !== previousValue) {
            paginationLinksChange(event.srcElement);
            event.preventDefault();
        }
        field.attr("data-previous-value", field.val());
    });
    filters.keypress(function(event) {
        var keycode = (event.which ? event.which : event.keyCode);
        if (keycode === 13) {
            event.preventDefault();
            paginationLinksChange(event.srcElement);
        }
        field.attr("data-previous-value", field.val());
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
    // find the search filter
    var filters = jQuery('input[name=searchFilter]');
    if (filters === undefined || filters === null || filters.length === 0) {
        return;
    }

    var rowThatChanged = jQuery(triggerItem).parentsUntil('.tablenav').parent();
    var rowsPerPage = jQuery(rowThatChanged).find('select[name=perpage]').val();
    var filterText = jQuery(rowThatChanged).find('input[name=searchFilter]').val();
    
    var originalColorGraySelector = '.wp-list-table .alternate, .abj404-pagination-right';
    var originalColorWhiteSelector = '.abj404-pagination-right input' +
            ', .abj404-pagination-right select, .wp-list-table .normal-non-alternate, .wp-list-table';
    
    var fadeToColor = 'gray';
    
    // get the URL from the html page.
    var url = jQuery(".abj404-pagination-right").attr("data-pagination-ajax-url");
    var subpage = getURLParameter('subpage');
    var trashFilter = getURLParameter('filter');

    // do an ajax call to update the data
    jQuery.ajax({
        url: url,
        type: 'POST',
        dataType: "json",
        data: {
            rowsPerPage: rowsPerPage, 
            filterText: filterText,
            filter: trashFilter,
            subpage: subpage
        },
        success: function (result) {
            // get the current text value
            var currentFieldValue = jQuery('input[name=searchFilter]').val();
            
            // replace the tables
            var pageLinks = jQuery('.abj404-pagination-right');
            jQuery(pageLinks[0]).replaceWith(result.paginationLinksTop);
            jQuery(pageLinks[1]).replaceWith(result.paginationLinksBottom);
            jQuery('.wp-list-table').replaceWith(result.table);
            bindSearchFieldKeyListener();
            jQuery('input[name=searchFilter]').val(currentFieldValue);
            jQuery('input[name=searchFilter]').attr("data-previous-value", currentFieldValue);
            
            var originalAlternateRowColor = jQuery(originalColorGraySelector).css('background-color');
            var originalPaginationBGColor = jQuery(originalColorWhiteSelector).css('background-color');

            // make them gray immediately as if they were always gray.
            jQuery(originalColorGraySelector).css("background-color", fadeToColor);
            jQuery(originalColorWhiteSelector).css("background-color", fadeToColor);
            
            // fade them back to their normal colors.
            jQuery(originalColorGraySelector).animate({backgroundColor: originalAlternateRowColor});
            jQuery(originalColorWhiteSelector).animate({backgroundColor: originalPaginationBGColor});
        },
        error: function (jqXHR, textStatus, errorThrown) {
            alert("Ajax error. Result: " + JSON.stringify(textStatus, null, 2) + 
                    ", error: " + JSON.stringify(errorThrown, null, 2));
        }
    });

    // we do the animation after the ajax request so that it's happening while the server is thinking.
    jQuery(originalColorWhiteSelector).animate({backgroundColor: fadeToColor}, 3000);
    jQuery(originalColorGraySelector).animate({backgroundColor: fadeToColor}, 3000);
}

