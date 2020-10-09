
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
    
    // make this work for .abj404-pagination-right which has a transparent background.
    var allSelectors = ('.abj404-pagination-right input, .abj404-pagination-right select' + 
            ', .wp-list-table .normal-non-alternate, .wp-list-table' + 
            ", .wp-list-table .alternate, .abj404-pagination-right");
    
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
            bindSearchFieldListeners();
            jQuery('input[name=searchFilter]').val(currentFieldValue);
            jQuery('input[name=searchFilter]').attr("data-previous-value", currentFieldValue);
            
            // get the original colors
            var allSelectorsArr = allSelectors.split(', ');
            var originalColors = {};
            for (var i = 0; i < allSelectorsArr.length; i++) {
                var currentSelector = allSelectorsArr[i];
                originalColors[currentSelector] = jQuery(currentSelector).css('background-color');
            }
            
            // make them gray immediately as if they were always gray.
            for (var i = 0; i < allSelectorsArr.length; i++) {
                var currentSelector = allSelectorsArr[i];
                jQuery(currentSelector).css('background-color', fadeToColor);
            }
            
            // fade them back to their normal colors.
            for (var i = 0; i < allSelectorsArr.length; i++) {
                var currentSelector = allSelectorsArr[i];
                var originalColor = originalColors[currentSelector];
                jQuery(currentSelector).animate({backgroundColor: originalColor});
            }

        	bindTrashLinkListeners();
        },
        error: function (jqXHR, textStatus, errorThrown) {
            alert("Ajax error. Result: " + JSON.stringify(textStatus, null, 2) + 
                    ", error: " + JSON.stringify(errorThrown, null, 2));
        }
    });

    // we do the animation after the ajax request so that it's happening while the server is thinking.
    jQuery(allSelectors).animate({backgroundColor: fadeToColor}, 3000);

}

