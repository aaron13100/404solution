
jQuery(document).ready(function($) {	
    var field = jQuery('#add_exlude_page_field');
    field.keyup(function() {
        jQuery('#add_exlude_page_field').css('background-color', '');
    });
    field.focusout(function() {
        jQuery('#add_exlude_page_field').css('background-color', '');
    });
    
    document.getElementsByClassName('closeable-ul')[0].addEventListener("click", function(e) {
    	  handleClosedULItemAction(e);
    });
    
    // get the URL from the html page.
    var url = jQuery("#add_exlude_page_field").attr("data-url");
    var cache = {};
    jQuery("#add_exlude_page_field").catcomplete({
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
            jQuery("#add_exlude_page_field").val(ui.item.label);
            addPageToExcludeToList(ui.item);
            // jQuery("#redirect_to_data_field_title").val(ui.item.label);
            // todo jQuery("#redirect_to_data_field_id").val(ui.item.value);

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
    jQuery('#add_exlude_page_field').keypress(function(event) {
        if (event.keyCode === 13) {
            // don't submit the form.
            event.preventDefault();
            
            // close the menu if it's open.
            jQuery('#add_exlude_page_field').catcomplete("close");
            
            abj404_validateAndUpdateFeedback();
        }
    });
    
    // if nothing was entered then reset the already selected value.
    jQuery('#add_exlude_page_field').focusout(function(event) {
        abj404_validateAndUpdateFeedback();
    });

    // we run this here for when the user edits an existing redirect.
    abj404_validateAndUpdateFeedback();
});

function handleClosedULItemAction(e) {
	if (e.target.classList.contains("i-am-a-close-button")) {
		// find the parent li and remove it
		var anElement = e.target;
		while (anElement != null && anElement.tagName != 'LI') {
			anElement = anElement.parentElement;
		}
		if (anElement.tagName == 'LI') {
			anElement.parentElement.removeChild(anElement);
		} else {
			alert("I couldn't find an LI element to remove. Hmmmm.....");
		}
	}
}

function addPageToExcludeToList(item) {
    jQuery("#add_exlude_page_field").val(item.label + '|' + item.value);
    var ulToAddTo = document.getElementsByClassName('exclude-pages-ul')[0];
    ulToAddTo.insertAdjacentHTML('afterbegin', '<li>' + item.label + 
		'<input type="hidden" name="excludePages[]" value="' + item.value + 
		'"/><span class="close i-am-a-close-button">x</span></li>');
}

/** Validate the selection and update the feedback label.
 * @returns {undefined}
 */
function abj404_validateAndUpdateFeedback() {
	return;
	// todo
    // 4 => ABJ404_TYPE_EXTERNAL
    var ABJ404_TYPE_EXTERNAL = "4";
    
    var userTypedValue = jQuery("#add_exlude_page_field").val();
    var selectedVal = jQuery('#redirect_to_data_field_title').val();
    
    // if the user entered a valid URL and pressed enter then it's ok.
    if (abj404_isValidURL(userTypedValue)) {
        jQuery("#redirect_to_data_field_title").val(userTypedValue);
        jQuery("#redirect_to_data_field_id").val(ABJ404_TYPE_EXTERNAL + '|' + ABJ404_TYPE_EXTERNAL);

    } else if (userTypedValue != '' && userTypedValue == selectedVal) {
    	// the typed value equals the selected value when the user chooses a
    	// an option from the dropdown.
        var selectedVal = jQuery('#redirect_to_data_field_title').val();
        jQuery("#add_exlude_page_field").val(selectedVal);
    	
    
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
        jQuery("#add_exlude_page_field").val(selectedVal);
    }

    var selectedPageID = jQuery("#redirect_to_data_field_id").val();
    var tooltip_empty = jQuery("#add_exlude_page_field").attr("data-tooltip-explanation-empty");
    var tooltip_page = jQuery("#add_exlude_page_field").attr("data-tooltip-explanation-page");
    var tooltip_custom_string = jQuery("#add_exlude_page_field").attr("data-tooltip-explanation-custom-string");
    var tooltip_url = jQuery("#add_exlude_page_field").attr("data-tooltip-explanation-url");
    if ((selectedPageID === null) || (selectedPageID === "")) {
        jQuery(".add_exlude_page_field_explanation").text(tooltip_empty);
        
    } else if (document.getElementById('is_regex_url') != null &&
    		document.getElementById('is_regex_url').checked && 
    		selectedPageID != undefined && selectedPageID.endsWith('|' + ABJ404_TYPE_EXTERNAL)) {
        jQuery("#add_exlude_page_field_explanation").text(tooltip_custom_string);
    
    } else if (selectedPageID != undefined && selectedPageID.endsWith('|' + ABJ404_TYPE_EXTERNAL)) {
        jQuery("#add_exlude_page_field_explanation").text(tooltip_url);
    } else {
        jQuery("#add_exlude_page_field_explanation").text(tooltip_page);
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
