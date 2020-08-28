
jQuery(document).ready(function($) {
	var adminOptionsPage = document.getElementById("admin-options-page");
	if (adminOptionsPage) {
		adminOptionsPage.addEventListener('submit', submitOptions);
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
	var formData = Object.values(form.elements).reduce((obj,field) => {
			if (field.type == 'checkbox') {
				obj[field.name] = field.checked ? 1 : 0;
			} else {
				obj[field.name] = field.value;
			}
			return obj 
		}, {});
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
