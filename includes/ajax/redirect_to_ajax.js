
jQuery(document).ready(function($) {	
	
    $.widget( "custom.catcomplete", $.ui.autocomplete, {
      _create: function() {
        this._super();
        this.widget().menu( "option", "items", "> :not(.ui-autocomplete-category)" );
      },
      _renderMenu: function( ul, items ) {
          var that = this, currentCategory = "";
          $.each( items, function( index, item ) {
              var li;
              if ( item.category != currentCategory ) {
                  ul.append( "<li class='ui-autocomplete-category'>" + item.category + "</li>" );
                  currentCategory = item.category;
              }
              li = that._renderItemData( ul, item );
              if ( item.category ) {
                  li.attr( "aria-label", item.category + " : " + item.label );
              }
              li.addClass('indent-depth-' + item.depth);
          });
      }
    });

    var url = MyAutoComplete.url + "?action=echoRedirectToPages";
    var url = "admin-ajax.php?action=echoRedirectToPages";
    var cache = {};
    $( "#redirect_to_user_field" ).catcomplete({
        //source: url,
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
            $("#redirect_to_user_field").val(ui.item.label);
            $("#redirect_to_data_field_title").val(ui.item.label);
            $("#redirect_to_data_field_id").val(ui.item.value);

            abj404_updateFeedbackField();
        },
        focus: function(event, ui) {
            // don't change the contents of the textbox just by highlighting something.
            event.preventDefault();
        },
        change: function( event, ui ) {
            abj404_validateURLOrSelectedItem();
        }
    });
    
    // prevent / disable the enter key for the search box.
    $('#redirect_to_user_field').keypress(function(event) {
        if (event.keyCode == 13) {
            // don't submit the form.
            event.preventDefault();
            
            // close the menu if it's open.
            $('#redirect_to_user_field').catcomplete("close");
            
            abj404_validateURLOrSelectedItem();
        }
    });

});

/** Validate an external URL or restore the previously selected value.
 * @returns {undefined}
 */
function abj404_validateURLOrSelectedItem() {
    var userTypedValue = jQuery("#redirect_to_user_field").val();
    if (abj404_isValidURL(userTypedValue)) {
        jQuery("#redirect_to_data_field_title").val(userTypedValue);
        jQuery("#redirect_to_data_field_id").val('4|4'); // 4 => ABJ404_TYPE_EXTERNAL
        jQuery("#redirect_to_user_field_feedback").text("(External URL selected.)");

    } else {
        // if no item was selected then we force the search box to change back to 
        // whatever the user previously selected.
        var selectedVal = jQuery('#redirect_to_data_field_title').val();
        jQuery("#redirect_to_user_field").val(selectedVal);
        
        abj404_updateFeedbackField();
    }
}

function abj404_updateFeedbackField() {
    var selectedPageID = jQuery("#redirect_to_data_field_id").val();
    if ((selectedPageID === null) || (selectedPageID === "")) {
        jQuery("#redirect_to_user_field_feedback").text("(Type a page name or an external URL)");
        
    } else {
        jQuery("#redirect_to_user_field_feedback").text("(Page selected.)");
    }
}
/** 
 * @param {type} url
 * @returns {Boolean} true if the URL is valid. false otherwise.
 */
function abj404_isValidURL(url) {
    if ((url.indexOf(' ') == -1) && (url.indexOf("://") > -1)) {
    	return true;
    }
    return false;
}
