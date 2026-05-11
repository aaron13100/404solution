/**
 * Background-refresh toast and "Refresh available" pill UI.
 *
 * Two transient overlays sit on top of the admin table view:
 *
 *   - The toast (#abj404-background-refresh-toast) shows during a
 *     background refresh, collapses to a spinner-only bubble after 2s,
 *     and turns green on completion before fading out.
 *   - The pill (#abj404-refresh-available-pill) appears only when the
 *     detect-only refresh found new data; click triggers a full reload.
 *
 * Both helpers are defensive: they create their own <style> tag exactly
 * once and tolerate being called from multiple paths in a single page
 * lifecycle.
 *
 * Globals defined: ensureRefreshToastStyles, ensureRefreshToast,
 * setRefreshToastMessage, showRefreshToastStart, showRefreshToastComplete,
 * hideRefreshToast, ensureRefreshAvailablePillStyles,
 * hideRefreshAvailablePill, showRefreshAvailablePill.
 */

function ensureRefreshToastStyles() {
    if (document.getElementById('abj404-refresh-toast-styles')) {
        return;
    }
    var style = document.createElement('style');
    style.id = 'abj404-refresh-toast-styles';
    style.textContent =
        '#abj404-background-refresh-toast{' +
        'position:fixed;right:16px;bottom:16px;z-index:99999;padding:8px 10px;' +
        'background:var(--abj404-surface,#1f2937);color:var(--abj404-text,#fff);' +
        'border:1px solid var(--abj404-border,rgba(255,255,255,.15));border-radius:18px;font-size:12px;' +
        'box-shadow:0 4px 14px rgba(0,0,0,.22);max-width:360px;display:flex;align-items:center;gap:8px;' +
        'cursor:default;transition:width .18s ease,max-width .18s ease,padding .18s ease,gap .18s ease,border-radius .18s ease,opacity .18s ease,background-color .18s ease;box-sizing:border-box;}' +
        '#abj404-background-refresh-toast .abj404-refresh-spinner{' +
        'width:12px;height:12px;border:2px solid var(--abj404-text-muted,rgba(255,255,255,.45));border-top-color:var(--abj404-accent,#fff);' +
        'border-radius:50%;flex:0 0 auto;animation:abj404-refresh-spin .8s linear infinite;}' +
        '#abj404-background-refresh-toast .abj404-refresh-label{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed{padding:0;width:28px;min-width:28px;max-width:28px;overflow:hidden;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed{height:28px;min-height:28px;gap:0;line-height:0;justify-content:center;border-radius:14px;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed .abj404-refresh-label{display:none;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed:hover{max-width:340px;width:auto;min-width:0;padding:8px 10px;height:28px;min-height:28px;line-height:normal;gap:8px;border-radius:14px;}' +
        '#abj404-background-refresh-toast.abj404-refresh-collapsed:hover .abj404-refresh-label{display:inline;}' +
        '#abj404-background-refresh-toast.abj404-refresh-complete{background:var(--abj404-success,#1f9d55);color:#fff;border-color:transparent;}' +
        '#abj404-background-refresh-toast.abj404-refresh-complete .abj404-refresh-spinner{animation:none;border-color:rgba(255,255,255,.5);border-top-color:rgba(255,255,255,.5);}' +
        '@keyframes abj404-refresh-spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}';
    document.head.appendChild(style);
}

function ensureRefreshToast() {
    ensureRefreshToastStyles();
    var id = 'abj404-background-refresh-toast';
    var toast = document.getElementById(id);
    if (!toast) {
        toast = document.createElement('div');
        toast.id = id;
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        toast.innerHTML = '<span class="abj404-refresh-spinner" aria-hidden="true"></span><span class="abj404-refresh-label"></span>';
        document.body.appendChild(toast);
    }
    return toast;
}

function setRefreshToastMessage(message) {
    var toast = ensureRefreshToast();
    var label = toast.querySelector('.abj404-refresh-label');
    if (label) {
        label.textContent = message || '';
    }
    return toast;
}

function showRefreshToastStart(message) {
    var toast = setRefreshToastMessage(message);
    toast.classList.remove('abj404-refresh-complete');
    toast.classList.remove('abj404-refresh-collapsed');
    toast.style.display = 'flex';
    window.setTimeout(function() {
        if (toast.style.display !== 'none' && !toast.classList.contains('abj404-refresh-complete')) {
            toast.classList.add('abj404-refresh-collapsed');
        }
    }, 2000);
}

function showRefreshToastComplete(message) {
    var toast = setRefreshToastMessage(message);
    toast.classList.remove('abj404-refresh-collapsed');
    toast.classList.add('abj404-refresh-complete');
    toast.style.display = 'flex';
}

function hideRefreshToast() {
    var toast = document.getElementById('abj404-background-refresh-toast');
    if (toast) {
        toast.classList.remove('abj404-refresh-collapsed');
        toast.classList.remove('abj404-refresh-complete');
        toast.style.display = 'none';
    }
}

function ensureRefreshAvailablePillStyles() {
    if (document.getElementById('abj404-refresh-available-pill-styles')) {
        return;
    }
    var style = document.createElement('style');
    style.id = 'abj404-refresh-available-pill-styles';
    style.textContent =
        '#abj404-refresh-available-pill{' +
        'position:fixed;right:16px;bottom:16px;z-index:99999;padding:8px 12px;' +
        'background:var(--abj404-accent,#2271b1);color:#fff;border:1px solid rgba(0,0,0,.08);' +
        'border-radius:999px;font-size:12px;font-weight:600;line-height:1.2;cursor:pointer;' +
        'box-shadow:0 4px 14px rgba(0,0,0,.22);transition:opacity .15s ease,transform .15s ease;}' +
        '#abj404-refresh-available-pill:hover{transform:translateY(-1px);background:var(--abj404-accent-hover,#135e96);}';
    document.head.appendChild(style);
}

function hideRefreshAvailablePill() {
    var pill = document.getElementById('abj404-refresh-available-pill');
    if (pill && pill.parentNode) {
        pill.parentNode.removeChild(pill);
    }
    if (window.abj404RefreshAvailableHideTimer) {
        window.clearTimeout(window.abj404RefreshAvailableHideTimer);
        window.abj404RefreshAvailableHideTimer = null;
    }
    window.abj404RefreshAvailableHiddenAt = Date.now();
}

function showRefreshAvailablePill(message, timeoutMs) {
    ensureRefreshAvailablePillStyles();
    hideRefreshAvailablePill();
    var pill = document.createElement('button');
    pill.type = 'button';
    pill.id = 'abj404-refresh-available-pill';
    pill.textContent = message || 'Refresh available';
    pill.setAttribute('aria-live', 'polite');
    pill.setAttribute('title', message || 'Refresh available');
    pill.addEventListener('click', function() {
        window.location.reload();
    });
    document.body.appendChild(pill);
    window.abj404RefreshAvailableShownAt = Date.now();
    window.abj404RefreshAvailableLastMessage = message || 'Refresh available';
    var delay = Math.max(1000, parseInt(timeoutMs, 10) || 5000);
    window.abj404RefreshAvailableHideTimer = window.setTimeout(function() {
        hideRefreshAvailablePill();
    }, delay);
}
