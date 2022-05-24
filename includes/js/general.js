var abj404_whichButtonClicked = null;

jQuery(document).ready(function($) {
	var adminOptionsPage = document.getElementById("admin-options-page");
	if (adminOptionsPage) {
		adminOptionsPage.addEventListener('submit', submitOptions);
	}
	
	var deleteDebugFileButton = document.querySelector('#deleteDebugFile');
	if (deleteDebugFileButton) {
		deleteDebugFileButton.addEventListener('click', function(e) {
			abj404_whichButtonClicked = 'deleteDebugFile';
			submitOptions(e);
		});
	}
})

function striphtml(html) {
    var tmp = document.createElement("DIV");
    tmp.innerHTML = html;
    return tmp.textContent || tmp.innerText || "";
}

function submitOptions(e) {
    e.preventDefault();
    
	// gather form data.
	var form = document.getElementById("admin-options-page");
	var formElements = form.elements;
	var formData = {};
	for (var i = 0; i < formElements.length; i++) {
		var field = formElements[i];
		var currentValue = field.value;
		if (field.type == 'checkbox') {
			currentValue = field.checked ? 1 : 0;
		}
		
		if (!(field.name in formData)) {
			formData[field.name] = currentValue;
		} else {
			if (!Array.isArray(formData[field.name])) {
				formData[field.name] = new Array(formData[field.name]);
			}
			formData[field.name].push(currentValue);
		}
	}

    // if we should just delete the log file.
    if (abj404_whichButtonClicked == 'deleteDebugFile') {
    	// set the action to 'updateOptions' and set deleteDebugFile to true
    	formData['action'] = 'updateOptions';
    	formData['deleteDebugFile'] = true;
    } else {
    	formData['deleteDebugFile'] = false;
    }

	// fix checkboxes.
    var formDataAsJson = JSON.stringify(formData);
    var encodedData = encodeURI(formDataAsJson);

    // save / send the data via an ajax request.
    var saveOptionsURL = form.getAttribute('data-url')
    
    jQuery.ajax({
        url: saveOptionsURL,
        type: 'POST',
        data: {
            'encodedData': encodedData
        },
        dataType :'json',
        success: function (data) {
            var message = striphtml(JSON.stringify(data, null, 2));
            console.log("saved options: " + message);
            
            // redirect and post a message
            var form = jQuery('<form action="' + data['newURL'] + '" method="post">' +
            		  '<input type="text" name="display-this-message" value="' + data['message'] + '" />' +
            		  '</form>');
            jQuery('body').append(form);
            form.submit();            
        },
        error: function (request, error) {
            var errMsg = "Error saving options. Request: " + 
            	JSON.stringify(request, null, 2) + "  /// Error: " + JSON.stringify(error, null, 2);
        	alert(errMsg);
        }
    });    

    // don't submit the form.
    return false;
}
