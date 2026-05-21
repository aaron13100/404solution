function show404AdminDebugData() {
    var debugText = 'Suggestion Debug Data:\n====================\n\n';
    if (typeof abj404_suggestionData !== 'undefined' && abj404_suggestionData.length > 0) {
        for (var i = 0; i < abj404_suggestionData.length; i++) {
            var item = abj404_suggestionData[i];
            debugText += 'Suggestion #' + (i + 1) + ':\n';
            for (var key in item) {
                if (item.hasOwnProperty(key) && item[key]) {
                    debugText += '  ' + key + ': ' + String(item[key]).replace(/</g, '&lt;').replace(/>/g, '&gt;') + '\n';
                }
            }
            debugText += '--------------------\n';
        }
    } else {
        debugText += 'No suggestion data collected.';
    }

    var modalOverlay = document.createElement('div');
    modalOverlay.style.position = 'fixed';
    modalOverlay.style.top = '0';
    modalOverlay.style.left = '0';
    modalOverlay.style.width = '100%';
    modalOverlay.style.height = '100%';
    modalOverlay.style.backgroundColor = 'rgba(0,0,0,0.5)';
    modalOverlay.style.zIndex = '9999';

    var modalContent = document.createElement('div');
    modalContent.style.position = 'absolute';
    modalContent.style.top = '50%';
    modalContent.style.left = '50%';
    modalContent.style.transform = 'translate(-50%, -50%)';
    modalContent.style.backgroundColor = 'white';
    modalContent.style.padding = '20px';
    modalContent.style.borderRadius = '5px';
    modalContent.style.maxWidth = '80%';
    modalContent.style.maxHeight = '80%';
    modalContent.style.overflow = 'auto';

    var textArea = document.createElement('textarea');
    textArea.style.width = '100%';
    textArea.style.height = '300px';
    textArea.style.marginBottom = '10px';
    textArea.value = debugText;
    textArea.readOnly = true;

    var copyButton = document.createElement('button');
    copyButton.textContent = 'Copy to Clipboard';
    copyButton.style.marginRight = '10px';
    copyButton.onclick = function() {
        textArea.select();
        document.execCommand('copy');
    };

    var closeButton = document.createElement('button');
    closeButton.textContent = 'Close';
    closeButton.onclick = function() {
        document.body.removeChild(modalOverlay);
    };

    modalContent.appendChild(textArea);
    modalContent.appendChild(copyButton);
    modalContent.appendChild(closeButton);
    modalOverlay.appendChild(modalContent);
    document.body.appendChild(modalOverlay);
}
