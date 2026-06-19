/**
 * Browser-driven lazy backfill poller for pending URL/Destination sort keys.
 */
(function (global, $) {
    'use strict';

    if (!$) {
        return;
    }

    var ACTION = 'abj404_run_lazy_backfill';
    var pollIntervalMs = 1000;
    var maxAttempts = 20;

    function configHost() {
        return $('.abj404-pagination-right[data-lazy-backfill-ajax-url]').first();
    }

    function pendingTooltips() {
        return $('[data-abj404-pending-sort]');
    }

    function responseData(result) {
        if (result && result.success === true && result.data && typeof result.data === 'object') {
            return result.data;
        }
        return null;
    }

    function updatePendingTooltip(sortKey, sortData) {
        if (!sortData || typeof sortData !== 'object') {
            return;
        }
        pendingTooltips().filter('[data-abj404-pending-sort="' + sortKey + '"]').each(function () {
            var $tooltip = $(this);
            var $body = $tooltip.find('.lefty-tooltiptext').first();
            if (!$body.length || sortData.ready === true) {
                return;
            }
            if (typeof sortData.message === 'string' && sortData.message !== '') {
                $body.text(sortData.message);
            }
        });
    }

    function allVisiblePendingSortsReady(sorts) {
        var allReady = true;
        pendingTooltips().each(function () {
            var sortKey = String($(this).attr('data-abj404-pending-sort') || '');
            if (!sorts || !sorts[sortKey] || sorts[sortKey].ready !== true) {
                allReady = false;
            }
        });
        return allReady;
    }

    function refreshTableWithoutPageReload($host) {
        if (typeof global.paginationLinksChange !== 'function') {
            return;
        }
        global.paginationLinksChange($host.get(0));
    }

    function runLazyBackfillPoll(attempt) {
        var $host = configHost();
        if (!$host.length || !pendingTooltips().length || attempt > maxAttempts) {
            return;
        }

        var ajaxUrl = $host.attr('data-lazy-backfill-ajax-url') || global.ajaxurl || '/wp-admin/admin-ajax.php';
        var nonce = $host.attr('data-lazy-backfill-nonce') || '';
        var subpage = $host.attr('data-pagination-ajax-subpage') || '';
        if (!nonce) {
            return;
        }

        var ajaxRunner = (typeof global.abj404AjaxWithNonceRetry === 'function')
            ? global.abj404AjaxWithNonceRetry : $.ajax; // ajax-direct-approved: fallback matches view_updater_pagination.js when nonce retry has not loaded

        ajaxRunner({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            timeout: 30000,
            data: {
                action: ACTION,
                nonce: nonce,
                subpage: subpage
            },
            success: function (result) {
                var data = responseData(result);
                var sorts = data && data.sorts ? data.sorts : null;
                if (sorts) {
                    updatePendingTooltip('url', sorts.url);
                    updatePendingTooltip('dest', sorts.dest);
                }
                if (sorts && allVisiblePendingSortsReady(sorts)) {
                    refreshTableWithoutPageReload($host);
                    return;
                }
                global.setTimeout(function () {
                    runLazyBackfillPoll(attempt + 1);
                }, pollIntervalMs);
            },
            error: function (jqXHR, textStatus, errorThrown) {
                if (global.console && typeof global.console.error === 'function') {
                    global.console.error('ABJ404 lazy backfill AJAX failed', {
                        status: jqXHR ? jqXHR.status : 0,
                        textStatus: textStatus,
                        errorThrown: errorThrown
                    });
                }
            }
        });
    }

    $(function () {
        runLazyBackfillPoll(1);
    });

    global.abj404RunLazyBackfillPoll = runLazyBackfillPoll;
})(window, jQuery);
