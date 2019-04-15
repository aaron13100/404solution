
if (typeof(getURLParameter) !== "function") {
    function getURLParameter(name) {
        return (location.search.split('?' + name + '=')[1] || 
                location.search.split('&' + name + '=')[1] || 
                '').split('&')[0];
    }
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
            // replace the tables
            jQuery('.abj404-pagination-right').replaceWith(result.paginationLinks);
            jQuery('.wp-list-table').replaceWith(result.table);
            
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

