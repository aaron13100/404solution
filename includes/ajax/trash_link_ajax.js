

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
                    jQuery('.subsubsub').replaceWith(payload.subsubsub);
                    jQuery('.subsubsub').effect('highlight');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                var errMsg = "failure. result: " + errorThrown;
                try {
                    if (jqXHR && jqXHR.responseJSON) {
                        if (jqXHR.responseJSON.message) {
                            errMsg = jqXHR.responseJSON.message;
                        } else if (jqXHR.responseJSON.data) {
                            if (typeof jqXHR.responseJSON.data === 'string') {
                                errMsg = jqXHR.responseJSON.data;
                            } else if (jqXHR.responseJSON.data.message) {
                                errMsg = jqXHR.responseJSON.data.message;
                            } else {
                                errMsg = JSON.stringify(jqXHR.responseJSON.data, null, 2);
                            }
                        }
                    }
                } catch (e) {
                    // ignore and use generic msg
                }
                alert(errMsg);
                row.css("background-color", "yellow");
            }
        });
    });
}
