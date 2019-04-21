
/* Here we disable the "Apply" button if there are no checkboxes selected.
    We enable it if there is at least one selected. */
function enableDisableApplyButton() {
    var shouldBeEnabled = false;
    var inputs = document.getElementsByTagName("input");
    for(var i = 0; i < inputs.length; i++) {
        if(inputs[i].type === "checkbox") {
            if (inputs[i].checked === true) {
                shouldBeEnabled = true;
                break;
            }
        }  
    }

    var button = document.getElementById("captured_404s_bulk_apply");
    var selector = document.getElementById("bulkCaptured404select");
    
    // This can be null because we're on the trash page.
    if (button === null) {
        return;
    }
    
    button.disabled = !shouldBeEnabled;
    selector.disabled = !shouldBeEnabled;
    if (shouldBeEnabled) {
        button.setAttribute("alt", '');
        button.setAttribute("title", '');
        selector.setAttribute("alt", '');
        selector.setAttribute("title", '');
    } else {
        button.setAttribute("alt", "{altText}");
        button.setAttribute("title", "{altText}");
        selector.setAttribute("alt", "{altText}");
        selector.setAttribute("title", "{altText}");
    }
}
enableDisableApplyButton();
