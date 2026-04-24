jQuery(document).ready(function ($) {
    var $container = $('#abj404-gsc-deferred-content');
    if ($container.length === 0) {
        return;
    }
    if ($container.attr('data-deferred-load') !== '1') {
        return;
    }

    var action = $container.attr('data-ajax-action') || 'abj404_load_gsc_section';
    var nonce = $container.attr('data-ajax-nonce') || '';

    $.ajax({
        url: window.ajaxurl || 'admin-ajax.php',
        type: 'POST',
        dataType: 'json',
        timeout: 15000,
        data: {
            action: action,
            nonce: nonce
        },
        success: function (response) {
            if (response && response.success && response.data && typeof response.data.html === 'string') {
                $container.html(response.data.html);
                $container.attr('data-deferred-load', '0');
                return;
            }

            var message = 'Unable to load Google Search Console section.';
            if (response && response.data && response.data.message) {
                message = String(response.data.message);
            }
            $container.html('<p class="abj404-form-help">' + $('<div>').text(message).html() + '</p>');
            $container.attr('data-deferred-load', '0');
        },
        error: function (jqXHR, textStatus) {
            if (typeof console !== 'undefined' && console.log) {
                console.log('404 Solution: GSC section load failed (' + textStatus + ')');
            }
            $container.html('<p class="abj404-form-help">Unable to load Google Search Console section.</p>');
            $container.attr('data-deferred-load', '0');
        }
    });
});
