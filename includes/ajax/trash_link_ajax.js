

jQuery(document).ready(function($) {	
    jQuery(".ajax-trash-link").click(function (e) {
        // preventDefault() means don't move to the top of the page. 
        e.preventDefault();
        
        var row = $(this).closest("tr");
        row.css("background-color", "grey");

        var theURL = jQuery(this).attr("data-url");
        jQuery.ajax({
            url: theURL, 
            type : 'GET',
            success: function (result) {
                if (result.startsWith("fail")) {
                    row.css("background-color", "yellow");
                    
                } else {
                    row.hide(1000, function(){ row.remove(); });
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                alert("failure. result: " + errorThrown);
                row.css("background-color", "yellow");
            }
        });
    });
    
});

