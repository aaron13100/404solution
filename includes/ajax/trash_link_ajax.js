

jQuery(document).ready(function($) {
	bindTrashLinkListeners();
});

function bindTrashLinkListeners() {
    jQuery(".ajax-trash-link").click(function (e) {
        // preventDefault() means don't move to the top of the page. 
        e.preventDefault();
        
        var trashFilter = getURLParameter('filter');
        
        var row = jQuery(this).closest("tr");
        row.css("background-color", "grey");

        var theURL = jQuery(this).attr("data-url");
        jQuery.ajax({
            url: theURL, 
            type : 'GET',
            dataType: "json",
            data: {
                filter: trashFilter
            },
            success: function (data) {
                // Support both legacy payload ({ result, subsubsub, ... }) and WP-shaped AJAX payloads
                // ({ success: boolean, data: {...} }).
                var payload = data;
                if (data && typeof data === 'object' && typeof data.success === 'boolean' && data.data !== undefined) {
                    if (data.success === false) {
                        row.css("background-color", "yellow");
                        alert("Error: " + JSON.stringify(data.data, null, 2));
                        return;
                    }
                    payload = data.data;
                }

                if (payload.result && payload.result.startsWith("fail")) {
                    row.css("background-color", "yellow");
                    alert("Error: " + JSON.stringify(payload, null, 2));

                } else {
                    row.hide(1000, function(){ row.remove(); });
                    // Update filter-row counts with fresh values from the server.
                    if (payload.tabCounts && Array.isArray(payload.tabCounts)) {
                        jQuery('.subsubsub a .count').each(function(i) {
                            if (i < payload.tabCounts.length) {
                                jQuery(this).text('(' + payload.tabCounts[i] + ')');
                            }
                        });
                    }
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                // Shared describe-and-record seam (abj404-admin-ajax.js), guarded
                // so a missing asset degrades to a generic message instead of
                // throwing out of the error handler and showing nothing at all.
                var errMsg = "The redirect could not be moved to the trash.";
                if (typeof abj404AdminAjaxErrorMessage === 'function') {
                    errMsg = abj404AdminAjaxErrorMessage(jqXHR, {
                        fallback: errMsg,
                        source: 'trash-link',
                        errorThrown: errorThrown
                    });
                }
                alert(errMsg);
                row.css("background-color", "yellow");
            }
        });
    });
}
