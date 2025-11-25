
function validateAddManualRedirectForm(event) {
    var field = jQuery('#redirect_to_user_field');
    var val = field.val();
    if (val === undefined || val === '') {
        field.css("background-color", "#f79999");
        field.focus();
        return false;
    }
    return true;
}

jQuery(document).ready(function($) {	
    var field = jQuery('#redirect_to_user_field');
    field.keyup(function() {
        jQuery('#redirect_to_user_field').css('background-color', '');
    });
    field.focusout(function() {
        jQuery('#redirect_to_user_field').css('background-color', '');
    });
    
    // mostly copied from https://jqueryui.com/autocomplete/#categories	
    $.widget("custom.catcomplete", $.ui.autocomplete, {
      _create: function() {
        this._super();
        this.widget().menu( "option", "items", "> :not(.ui-autocomplete-category)" );
      },
      _renderMenu: function( ul, items ) {
          var that = this, currentCategory = "";
          $.each( items, function( index, item ) {
              var li;
              // setup the category.
              if ( item.category !== currentCategory ) {
                  var classesForCategoryLabel = "ui-autocomplete-category";
                  // if we're supposed to hide the item then this is the last category.
                  if (item.data_overflow_item) {
                      classesForCategoryLabel += " data-overflow-category";
                  }
                  ul.append( "<li class='" + classesForCategoryLabel + "'>" + item.category + "</li>" );
                  currentCategory = item.category;
              }
              // render the items
              li = that._renderItemData( ul, item );
              
              // set attributes and classes on the item.
              if ( item.category ) {
                  li.attr( "aria-label", item.category + " : " + item.label );
              }
              if (item.data_overflow_item) {
                  li.addClass('hide-me-please');
              } else {
                  li.addClass('indent-depth-' + item.depth);
              }
          });
      }
    });
    
    // highlight the text when the textbox gets focus.
    jQuery(".highlight-text-on-focus").focus(function() { this.select(); });

    // get the URL from the html page.
    var url = jQuery("#redirect_to_user_field").attr("data-url");
    var cache = {};
    jQuery("#redirect_to_user_field").catcomplete({
        source: function( request, response ) {
                var term = request.term;
				if (term in cache) {
					response(cache[term]);
					return;
				}
				$.getJSON(url, request, function(data, status, xhr) {
					cache[term] = data;
					response(data);
				});
            },
        delay: 500,
        minLength: 0,
        select: function(event, ui) {
            event.preventDefault();
            // when an item is selected then update the hidden fields to store it.
            jQuery("#redirect_to_user_field").val(ui.item.label);
            jQuery("#redirect_to_data_field_title").val(ui.item.label);
            jQuery("#redirect_to_data_field_id").val(ui.item.value);

            abj404_validateAndUpdateFeedback();
        },
        focus: function(event, ui) {
            // don't change the contents of the textbox just by highlighting something.
            event.preventDefault();
        },
        change: function( event, ui ) {
            abj404_validateAndUpdateFeedback();
        }
    });
    
    // prevent/disable the enter key from submitting the form for the search box.
    // maybe the user pressed enter after entering an external URL.
    jQuery('#redirect_to_user_field').keypress(function(event) {
        if (event.keyCode === 13) {
            // don't submit the form.
            event.preventDefault();
            
            // close the menu if it's open.
            jQuery('#redirect_to_user_field').catcomplete("close");
            
            abj404_validateAndUpdateFeedback();
        }
    });
    
    // if nothing was entered then reset the already selected value.
    jQuery('#redirect_to_user_field').focusout(function(event) {
        abj404_validateAndUpdateFeedback();
    });

    // we run this here for when the user edits an existing redirect.
    abj404_validateAndUpdateFeedback();
});

/** Validate the selection and update the feedback label.
 * @returns {undefined}
 */
function abj404_validateAndUpdateFeedback() {
    // 4 => ABJ404_TYPE_EXTERNAL
    var ABJ404_TYPE_EXTERNAL = "4";
    
    var userTypedValue = jQuery("#redirect_to_user_field").val();
    var selectedVal = jQuery('#redirect_to_data_field_title').val();
    
    // if the user entered a valid URL and pressed enter then it's ok.
    if (abj404_isValidURL(userTypedValue)) {
        jQuery("#redirect_to_data_field_title").val(userTypedValue);
        jQuery("#redirect_to_data_field_id").val(ABJ404_TYPE_EXTERNAL + '|' + ABJ404_TYPE_EXTERNAL);

    } else if (userTypedValue != '' && userTypedValue == selectedVal) {
    	// the typed value equals the selected value when the user chooses a
    	// an option from the dropdown.
        var selectedVal = jQuery('#redirect_to_data_field_title').val();
        jQuery("#redirect_to_user_field").val(selectedVal);
    	
    
    // if we're using a regular expression and the user pressed enter then it's ok.
    } else if (userTypedValue != '' &&
    		document.getElementById('is_regex_url') != null &&
    		document.getElementById('is_regex_url').checked) {
        jQuery("#redirect_to_data_field_title").val(userTypedValue);
        jQuery("#redirect_to_data_field_id").val(ABJ404_TYPE_EXTERNAL + '|' + ABJ404_TYPE_EXTERNAL);
    	
    } else {
        // if no item was selected then we force the search box to change back to 
        // whatever the user previously selected.
        var selectedVal = jQuery('#redirect_to_data_field_title').val();
        jQuery("#redirect_to_user_field").val(selectedVal);
    }

    var selectedPageID = jQuery("#redirect_to_data_field_id").val();
    var tooltip_empty = jQuery("#redirect_to_user_field").attr("data-tooltip-explanation-empty");
    var tooltip_page = jQuery("#redirect_to_user_field").attr("data-tooltip-explanation-page");
    var tooltip_custom_string = jQuery("#redirect_to_user_field").attr("data-tooltip-explanation-custom-string");
    var tooltip_url = jQuery("#redirect_to_user_field").attr("data-tooltip-explanation-url");
    if ((selectedPageID === null) || (selectedPageID === "")) {
        jQuery(".redirect_to_user_field_explanation").text(tooltip_empty);
        
    } else if (document.getElementById('is_regex_url') != null &&
    		document.getElementById('is_regex_url').checked && 
    		selectedPageID != undefined && selectedPageID.endsWith('|' + ABJ404_TYPE_EXTERNAL)) {
        jQuery("#redirect_to_user_field_explanation").text(tooltip_custom_string);
    
    } else if (selectedPageID != undefined && selectedPageID.endsWith('|' + ABJ404_TYPE_EXTERNAL)) {
        jQuery("#redirect_to_user_field_explanation").text(tooltip_url);
    } else {
        jQuery("#redirect_to_user_field_explanation").text(tooltip_page);
    }
}

/** Validate a URL.
 * @param {type} url
 * @returns {Boolean} true if the URL is valid. false otherwise.
 */
function abj404_isValidURL(url) {
    if (url === undefined || url === null) {
        return false;
    }
    if ((url.indexOf(' ') === -1) && (url.indexOf("://") > -1)) {
    	return true;
    }
    return false;
}
