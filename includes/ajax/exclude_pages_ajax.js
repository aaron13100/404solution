const EXCL_SEPARATOR_CHAR = '|\\|';

jQuery(document).ready(function($) {	
    var field = jQuery('#add_exlude_page_field');
    field.keyup(function() {
        jQuery('#add_exlude_page_field').css('background-color', '');
    });
    field.focusout(function() {
        jQuery('#add_exlude_page_field').css('background-color', '');
    });
    
    var closeableULs = document.getElementsByClassName('closeable-ul');
    if (closeableULs.length == 0) {
    	return;
    }
    closeableULs[0].addEventListener("click", function(e) {
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
            addPageToExcludeToList(ui.item);
        },
        focus: function(event, ui) {
            // don't change the contents of the textbox just by highlighting something.
            event.preventDefault();
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
        }
    });
    
    loadExcludePagesFromOptions();
    
    alphabetizeExcludeList()
});

function loadExcludePagesFromOptions() {
	var ulToAddTo = document.getElementsByClassName('exclude-pages-ul')[0];
	var urlEncodedOptions = ulToAddTo.getAttribute('data-loaded-values');
	var jsonEncodedOptions = decodeURIComponent(urlEncodedOptions.replace(/\+/g, ' '));
	
	if (jsonEncodedOptions.trim() == '') {
		return;
	}
	var optionsList = JSON.parse(jsonEncodedOptions);
	
	if (!Array.isArray(optionsList)) {
		optionsList = new Array(optionsList);
	}
	
	// items are in the format (ID|type ID|type name|title) (13721|1|Page|About Etc)
	optionsList.forEach(function(page, i) {
		var someItems = page.split(EXCL_SEPARATOR_CHAR);
		var allItems = someItems[0].split('|');
		allItems = allItems.concat(someItems.slice(1));
		var label = allItems[3];
		var category = allItems[2];
		var value = page;
		insertExcludePage(ulToAddTo, label, category, value);
	});
	
	alphabetizeExcludeList();
}

function alphabetizeExcludeList() {
	var ulToAddTo = document.getElementsByClassName('exclude-pages-ul')[0];
	var allLIs = ulToAddTo.querySelectorAll('li');
	allLIs = jQuery(allLIs).get().sort(function(a, b) {
		var keyA = a.textContent;
		var keyB = b.textContent;

		if (keyA < keyB) { return -1; }
		if (keyA > keyB) { return 1; }
		
		return 0;
    });
	allLIs.forEach(function(li, i) {
    	ulToAddTo.append(li); /* This removes li from the old spot and moves it */
	});
}

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
	// clear the autocomplete box because we're done with it
	document.getElementById('add_exlude_page_field').value = '';
	
	// get the existing values from the ul
    var ulToAddTo = document.getElementsByClassName('exclude-pages-ul')[0];
    var inputs = ulToAddTo.querySelectorAll('li>input');
    var values = new Array();
    for (var i = 0; i < inputs.length; i++) {
    	values.push(inputs[i].value);
    }
    
    // if the value doesn't already exists in the list then add it.
    var pageValue = item.value + EXCL_SEPARATOR_CHAR + item.category + 
    	EXCL_SEPARATOR_CHAR + item.label;
    if (!values.includes(pageValue)) {
    	insertExcludePage(ulToAddTo, item.label, item.category, pageValue);
    }
    
    // alphabetize the pages
    alphabetizeExcludeList();
}

function insertExcludePage(ulToAddTo, label, category, value) {
    ulToAddTo.insertAdjacentHTML('afterbegin', '<li>' + label + 
    		'<span class="exclude-pages-page-type"> (' + category + ')</span>' +
    		'<input type="hidden" name="excludePages[]" id="exlucdePages" value="' + value + 
    		'"/><span class="close i-am-a-close-button">x</span></li>');
}

