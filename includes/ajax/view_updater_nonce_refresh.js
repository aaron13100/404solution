/**
 * Transparent admin AJAX nonce-refresh helper (B20).
 *
 * Long-idle admins (12-24h, the WordPress nonce-tick window) hit a wall the
 * first time they tab back into a plugin admin screen: the table fetch fires
 * with the page's now-stale nonce, the server returns 403 + "Invalid security
 * token", and the user sees a generic AJAX error notice that only goes away
 * after a full page refresh. The fix is to detect the 403 in the shared
 * response handler, fetch a fresh nonce via the dedicated
 * ajaxRefreshAdminNonces endpoint, update the page data-attrs + JS state, and
 * retry the original request exactly once.
 *
 * Public surface:
 *   - abj404AjaxWithNonceRetry(ajaxOpts) -> jqXHR
 *       Drop-in replacement for jQuery.ajax() at admin AJAX call sites that
 *       send a `nonce` field. The first call passes ajaxOpts straight to
 *       jQuery.ajax. If the server replies 403 + nonce-expired, the helper
 *       fetches a fresh nonce, applies it to ajaxOpts.data.nonce, the
 *       relevant page data-attrs, and window.ABJ404.nonces, then re-issues
 *       the request once. On retry, success/error fire normally. If the
 *       refresh itself fails (user actually logged out, network down), the
 *       original 403 surfaces to the caller's error handler unchanged.
 *
 *   - window.abj404NonceRefresh.fetchFresh() -> Promise<{action: nonce, ...}>
 *       Lower-level call exposed for tests and special-case callers.
 *
 *   - window.abj404NonceRefresh.applyToPage(nonces)
 *       Push a freshly-fetched nonce map into page data-attrs and
 *       window.ABJ404.nonces. Idempotent.
 *
 * Single-flight: concurrent retries share one in-flight refresh Promise so
 * a wave of expired-nonce errors from parallel call sites does not trigger
 * N refresh round-trips.
 *
 * Loop guard: each ajax retry is tagged with `_abj404NonceRetry: true` on
 * the original options object. The helper never retries a request that
 * already carries this tag, even on subsequent 403s.
 *
 * Globals defined: abj404AjaxWithNonceRetry, abj404NonceRefresh.
 */

(function (global, $) {
    'use strict';

    if (!$) {
        return;
    }

    var ACTION = 'ajaxRefreshAdminNonces';

    /**
     * Map of WP nonce action verb -> selector + attribute name to update on
     * the page when a fresh nonce arrives. Keep this in sync with the
     * server-side ABJ_404_Solution_ViewUpdater::adminNonceActions().
     */
    var NONCE_DATA_ATTRS = {
        'abj404_updatePaginationLink': [
            ['[data-pagination-ajax-nonce]', 'data-pagination-ajax-nonce']
        ],
        'abj404_fetchInflightStage': [
            ['[data-pagination-inflight-nonce]', 'data-pagination-inflight-nonce']
        ],
        'abj404_refreshStatsDashboard': [
            ['[data-stats-refresh-nonce]', 'data-stats-refresh-nonce']
        ],
        'abj404_refreshHealthBar': [
            ['[data-health-bar-nonce]', 'data-health-bar-nonce']
        ],
        'abj404_trendData': [
            ['[data-trend-nonce]', 'data-trend-nonce']
        ]
    };

    var inFlightRefresh = null;

    function resolveAjaxUrl() {
        if (typeof global.ajaxurl === 'string' && global.ajaxurl) {
            return global.ajaxurl;
        }
        return '/wp-admin/admin-ajax.php';
    }

    /**
     * Heuristic: does this AJAX failure look like an expired nonce?
     *
     * The plugin's handlers consistently return HTTP 403 + a JSON body of
     * `{success:false, data:{message:'Invalid security token'}}` for both
     * a missing and an expired nonce; we treat both the same because either
     * one is recoverable for an admin whose session cookie is still valid.
     *
     * 401/403 from other origins (auth plugin, WAF) is intentionally NOT
     * matched here - those failures cannot be recovered by minting a new
     * nonce.
     */
    function looksLikeExpiredNonce(jqXHR) {
        if (!jqXHR || jqXHR.status !== 403) {
            return false;
        }
        var body = jqXHR.responseJSON;
        if (body && body.success === false && body.data && typeof body.data.message === 'string') {
            return /security token|nonce/i.test(body.data.message);
        }
        // No JSON body parsed - assume the 403 came from our handler;
        // attempting the refresh once is cheap and the retry is loop-guarded.
        return true;
    }

    function applyToPage(nonces) {
        if (!nonces || typeof nonces !== 'object') {
            return;
        }
        for (var action in nonces) {
            if (!Object.prototype.hasOwnProperty.call(nonces, action)) {
                continue;
            }
            var value = nonces[action];
            if (typeof value !== 'string' || value === '') {
                continue;
            }
            var attrs = NONCE_DATA_ATTRS[action];
            if (attrs) {
                for (var i = 0; i < attrs.length; i++) {
                    var sel = attrs[i][0];
                    var attrName = attrs[i][1];
                    $(sel).each(function () {
                        this.setAttribute(attrName, value);
                    });
                }
            }
        }
        if (global.ABJ404 && global.ABJ404.nonces && typeof global.ABJ404.nonces === 'object') {
            for (var k in nonces) {
                if (Object.prototype.hasOwnProperty.call(nonces, k)) {
                    global.ABJ404.nonces[k] = nonces[k];
                }
            }
        }
    }

    /**
     * Fetch a fresh nonce map. Single-flight: concurrent callers share one
     * round-trip. Resolves to the nonces object; rejects with the underlying
     * jqXHR (so callers can inspect 403 vs network-error and decide whether
     * to fall through to the original error handler).
     *
     * @return {Promise<object>}
     */
    function fetchFresh() {
        if (inFlightRefresh) {
            return inFlightRefresh;
        }
        inFlightRefresh = new Promise(function (resolve, reject) {
            $.ajax({
                url: resolveAjaxUrl(),
                type: 'POST',
                dataType: 'json',
                timeout: 10000,
                data: { action: ACTION }
            }).done(function (result) {
                var nonces = (result && result.data && result.data.nonces) || null;
                if (!nonces || typeof nonces !== 'object') {
                    reject({ reason: 'malformed-response', result: result });
                    return;
                }
                applyToPage(nonces);
                resolve(nonces);
            }).fail(function (jqXHR, textStatus, errorThrown) {
                reject({
                    reason: 'refresh-failed',
                    status: jqXHR ? jqXHR.status : 0,
                    textStatus: textStatus,
                    errorThrown: errorThrown
                });
            });
        });
        // Allow the next batch of expired-nonce errors to trigger their own
        // refresh once this one settles, in either direction.
        inFlightRefresh.then(function () {
            inFlightRefresh = null;
        }, function () {
            inFlightRefresh = null;
        });
        return inFlightRefresh;
    }

    /**
     * Wrapper around $.ajax that recovers transparently from an expired-nonce
     * 403 by minting a fresh nonce and retrying the request once.
     *
     * Behaviour matches $.ajax exactly when the first call succeeds or fails
     * with anything other than an expired-nonce 403. Returns the underlying
     * jqXHR so callers can attach .done()/.fail() if they prefer.
     */
    function withNonceRetry(originalOpts) {
        if (!originalOpts || typeof originalOpts !== 'object') {
            return $.ajax(originalOpts);
        }
        if (originalOpts._abj404NonceRetry === true) {
            // Already a retry - loop guard.
            return $.ajax(originalOpts);
        }

        var userSuccess = originalOpts.success;
        var userError = originalOpts.error;
        var userComplete = originalOpts.complete;
        var firedTerminal = false;
        var attempt = function (opts) {
            return $.ajax($.extend({}, opts, {
                success: function (data, textStatus, jqXHR) {
                    firedTerminal = true;
                    if (typeof userSuccess === 'function') {
                        userSuccess.call(this, data, textStatus, jqXHR);
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    if (!opts._abj404NonceRetry && looksLikeExpiredNonce(jqXHR)) {
                        if (global.abj404UpdateAjaxDebugLog) {
                            global.abj404UpdateAjaxDebugLog('Nonce expired; attempting transparent refresh + retry', {
                                action: (opts.data && opts.data.action) || ''
                            });
                        }
                        fetchFresh().then(function (nonces) {
                            var retryOpts = $.extend({}, opts);
                            retryOpts._abj404NonceRetry = true;
                            // Replace the nonce on the retry. The handler we
                            // are retrying carries `action` in data, and the
                            // server keys its nonce by the same action verb,
                            // so the mapping is one-to-one.
                            var actionVerb = mapAjaxActionToNonceVerb(opts.data && opts.data.action);
                            if (actionVerb && nonces[actionVerb]) {
                                retryOpts.data = $.extend({}, opts.data || {}, { nonce: nonces[actionVerb] });
                            }
                            attempt(retryOpts);
                        }, function () {
                            firedTerminal = true;
                            if (typeof userError === 'function') {
                                userError.call(this, jqXHR, textStatus, errorThrown);
                            }
                        });
                        return;
                    }
                    firedTerminal = true;
                    if (typeof userError === 'function') {
                        userError.call(this, jqXHR, textStatus, errorThrown);
                    }
                },
                complete: function (jqXHR, textStatus) {
                    // Only fire user's complete once the terminal outcome
                    // (final success or final non-retryable failure) is in.
                    if (firedTerminal && typeof userComplete === 'function') {
                        userComplete.call(this, jqXHR, textStatus);
                    }
                }
            }));
        };
        return attempt(originalOpts);
    }

    /**
     * Map an admin-ajax `action` (URL-style verb sent in the request body)
     * to the WordPress nonce action verb the server expects. Keep in sync
     * with the wp_verify_nonce() calls in includes/ajax/ViewUpdater.php and
     * includes/ajax/Ajax_TrendData.php.
     */
    function mapAjaxActionToNonceVerb(ajaxAction) {
        switch (ajaxAction) {
            case 'ajaxUpdatePaginationLinks':
            case 'ajaxWarmTableCache':
                return 'abj404_updatePaginationLink';
            case 'ajaxFetchInflightStage':
            case 'ajaxAdvanceViewBuild':
                return 'abj404_fetchInflightStage';
            case 'ajaxRefreshStatsDashboard':
                return 'abj404_refreshStatsDashboard';
            case 'ajaxRefreshHealthBar':
                return 'abj404_refreshHealthBar';
            case 'abj404getTrendData':
                return 'abj404_trendData';
            default:
                return '';
        }
    }

    global.abj404AjaxWithNonceRetry = withNonceRetry;
    global.abj404NonceRefresh = {
        fetchFresh: fetchFresh,
        applyToPage: applyToPage,
        mapAjaxActionToNonceVerb: mapAjaxActionToNonceVerb,
        looksLikeExpiredNonce: looksLikeExpiredNonce
    };
})(typeof window !== 'undefined' ? window : this, typeof jQuery !== 'undefined' ? jQuery : null);
