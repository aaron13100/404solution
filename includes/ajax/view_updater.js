
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
    
    // get the URL from the html page.
    var url = jQuery(".subsubsub").attr("data-pagination-ajax-url");

    jQuery('.abj404-pagination-right *, .abj404-pagination-right').animate({backgroundColor: "gray"});

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
            jQuery('.abj404-pagination-right').replaceWith(result);
            jQuery('.abj404-pagination-right *, .abj404-pagination-right').effect('highlight');
        },
        error: function (jqXHR, textStatus, errorThrown) {
            alert("Ajax error. Result: " + JSON.stringify(textStatus, null, 2) + 
                    ", error: " + JSON.stringify(errorThrown, null, 2));
        }
    });
}

