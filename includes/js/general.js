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

    // Show loading overlay
    showSaveOverlay();

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

    // Set timeout to handle hung requests
    var timeoutId = setTimeout(function() {
        showSaveError('Request timed out. Please check your connection and try again.');
    }, 30000); // 30 second timeout

    jQuery.ajax({
        url: saveOptionsURL,
        type: 'POST',
        data: {
            'encodedData': encodedData
        },
        dataType :'json',
        success: function (data) {
            clearTimeout(timeoutId);
            var message = striphtml(JSON.stringify(data, null, 2));
            console.log("saved options: " + message);

            // redirect and post a message (overlay will disappear on page reload)
            var form = jQuery('<form action="' + data['newURL'] + '" method="post">' +
            		  '<input type="text" name="display-this-message" value="' + data['message'] + '" />' +
            		  '</form>');
            jQuery('body').append(form);
            form.submit();
        },
        error: function (request, error) {
            clearTimeout(timeoutId);
            var errMsg = "Error saving settings. Please try again.";

            // Try to get a more specific error message
            if (request.responseJSON && request.responseJSON.message) {
                errMsg = request.responseJSON.message;
            } else if (request.statusText && request.statusText !== 'error') {
                errMsg = "Error: " + request.statusText;
            }

            showSaveError(errMsg);

            // Log full error to console for debugging
            console.error("Save error details:", {
                request: request,
                error: error
            });
        }
    });

    // don't submit the form.
    return false;
}

function showSaveOverlay() {
    var overlay = document.getElementById('abj404-save-overlay');
    if (overlay) {
        overlay.style.display = 'flex';
        overlay.classList.remove('error');

        // Update message to default
        var message = overlay.querySelector('.abj404-save-message');
        if (message) {
            message.textContent = abj404General.savingSettings;
        }

        // Announce to screen readers
        abj404AnnounceToScreenReader(abj404General.savingSettings);
    }
}

/**
 * Announce a message to screen readers using a live region
 * @param {string} message The message to announce
 * @param {string} priority 'polite' or 'assertive' (default: 'polite')
 */
function abj404AnnounceToScreenReader(message, priority) {
    priority = priority || 'polite';

    // Create or get the live region
    var liveRegion = document.getElementById('abj404-live-region');
    if (!liveRegion) {
        liveRegion = document.createElement('div');
        liveRegion.id = 'abj404-live-region';
        liveRegion.className = 'abj404-live-region';
        liveRegion.setAttribute('aria-live', priority);
        liveRegion.setAttribute('aria-atomic', 'true');
        liveRegion.setAttribute('role', 'status');
        document.body.appendChild(liveRegion);
    }

    // Update priority if needed
    liveRegion.setAttribute('aria-live', priority);

    // Clear and set the message (clearing first ensures re-announcement)
    liveRegion.textContent = '';
    setTimeout(function() {
        liveRegion.textContent = message;
    }, 100);
}

function showSaveError(errorMessage) {
    var overlay = document.getElementById('abj404-save-overlay');
    if (overlay) {
        overlay.classList.add('error');

        var message = overlay.querySelector('.abj404-save-message');
        if (message) {
            message.textContent = errorMessage;
        }

        // Announce error to screen readers with assertive priority
        abj404AnnounceToScreenReader(errorMessage, 'assertive');

        // Hide overlay after 5 seconds
        setTimeout(function() {
            overlay.style.display = 'none';
        }, 5000);
    }
}
