
function paginationLinksChange(triggerItem) {
    // find the search filter
    var filters = jQuery('input[name=searchFilter]');
    if (filters === undefined || filters === null || filters.length === 0) {
        alert("No search filters found");
        return;
    }

    var rowThatChanged = jQuery(triggerItem).parentsUntil('.tablenav').parent();
    var rowsPerPage = jQuery(rowThatChanged).find('select[name=perpage]').val();
    var filterText = jQuery(rowThatChanged).find('input[name=searchFilter]').val();
    
    var oldPaginationLinks = jQuery('.abj404-pagination-right, .abj404-pagination-right input, .abj404-pagination-right select');
    var oldTable = jQuery('.wp-list-table, .wp-list-table input');
    
    // get the URL from the html page.
    var url = jQuery(".subsubsub").attr("data-pagination-ajax-url");

    // do an ajax call to update the data
    jQuery.ajax({
        url: url,
        type: 'POST',
        dataType: "json",
        data: {
            rowsPerPage: rowsPerPage, 
            filterText: filterText
        },
        success: function (result) {
            jQuery('.abj404-pagination-right').replaceWith(result.paginationLinks);
            jQuery('.wp-list-table').replaceWith(result.table);

            jQuery('.abj404-pagination-right, .abj404-pagination-right input').css("background-color", "gray");
            jQuery('.wp-list-table, .wp-list-table input').css("background-color", "gray");
            
            jQuery('.abj404-pagination-right, .abj404-pagination-right input, .abj404-pagination-right select')
                    .animate({backgroundColor: "white"});
            jQuery('.wp-list-table, .wp-list-table input').animate({backgroundColor: "white"});
        },
        error: function (jqXHR, textStatus, errorThrown) {
            alert("Ajax error. Result: " + JSON.stringify(textStatus, null, 2) + 
                    ", error: " + JSON.stringify(errorThrown, null, 2));
        }
    });

    // we do the animation after the ajax request so that it's happening while the server is thinking.
    oldPaginationLinks.animate({backgroundColor: "gray"});
    oldTable.animate({backgroundColor: "gray"});
}

