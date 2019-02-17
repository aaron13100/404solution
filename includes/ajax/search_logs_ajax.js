
jQuery(document).ready(function($) {
    
    // get the URL from the html page.
    var url = $("#logs_ajax_search_field").attr("data-url");
    var cache = {};
    $("#logs_ajax_search_field").catcomplete({
        source: function( request, response ) {
                    var term = request.term;
                    if ( term in cache ) {
                    response( cache[ term ] );
                    return;
                }
                $.getJSON( url, request, function( data, status, xhr ) {
                    cache[ term ] = data;
                    response( data );
                });
            },
        delay: 500,
        minLength: 0,
        select: function(event, ui) {
            event.preventDefault();
            // when an item is selected then update the hidden fields to store it.
            $("#logs_ajax_search_field").val(ui.item.label);
            $("#redirect_to_data_field_title").val(ui.item.label);
            $("#redirect_to_data_field_id").val(ui.item.value);

            $("#logs_search_form").submit();
        },
        focus: function(event, ui) {
            // don't change the contents of the textbox just by highlighting something.
            event.preventDefault();
        }
    });
    
});

