
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
    
    // if nothing was entered then reset the already selected value.
    $('#logs_ajax_search_field').focusout(function(event) {
        abj404_validateAndUpdateFeedback();
    });

    // we run this here for when the user edits an existing redirect.
    abj404_validateAndUpdateFeedback();
});

/** Validate the selection and update the feedback label.
 * @returns {undefined}
 */
function abj404_validateAndUpdateFeedback() {
    // if no item was selected then we force the search box to change back to 
    // whatever the user previously selected.
    var selectedVal = jQuery('#redirect_to_data_field_title').val();
    jQuery("#logs_ajax_search_field").val(selectedVal);

    var selectedPageID = jQuery("#redirect_to_data_field_id").val();
    var tooltip_empty = jQuery("#logs_ajax_search_field").attr("data-tooltip-explanation-empty");
    var tooltip_page = jQuery("#logs_ajax_search_field").attr("data-tooltip-explanation-page");
    var tooltip_url = jQuery("#logs_ajax_search_field").attr("data-tooltip-explanation-url");
    if ((selectedPageID === null) || (selectedPageID === "")) {
        jQuery(".redirect_to_user_field_explanation").text(tooltip_empty);
        
    } else {
        jQuery("#logs_ajax_search_field_explanation").text(tooltip_page);
    }
}
