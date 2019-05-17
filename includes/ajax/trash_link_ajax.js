

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
                if (data.result.startsWith("fail")) {
                    row.css("background-color", "yellow");
                    alert("Error 1: " + JSON.stringify(data, null, 2));
                    
                } else {
                    row.hide(1000, function(){ row.remove(); });
                    jQuery('.subsubsub').replaceWith(data.subsubsub);
                    jQuery('.subsubsub').effect('highlight');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                alert("failure. result: " + errorThrown);
                row.css("background-color", "yellow");
            }
        });
    });
}

