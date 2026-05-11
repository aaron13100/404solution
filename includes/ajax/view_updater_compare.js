/**
 * Background-refresh comparison helpers.
 *
 * The detect-only background refresh decides whether to surface a
 * "Refresh available" pill by comparing the current visible table to the
 * incoming server response. These helpers normalize away cosmetic
 * differences (entity encodings, time-ago timestamps, nonce churn,
 * SVG self-closing variations) before diffing semantic row + cell text.
 *
 * Globals defined: normalizeHtmlForBackgroundComparison,
 * hasBackgroundRefreshUpdate, getComparableTableHtml,
 * buildComparableTableSignature, hasBackgroundRefreshUpdateWithBaseline.
 */

function normalizeHtmlForBackgroundComparison(html) {
    if (!html) {
        return '';
    }
    return String(html)
            .replace(/&#0*38;|&amp;/gi, '&')
            .replace(/<!--[\s\S]*?-->/g, '')
            // Browser DOM serialization of SVG can differ from server-rendered HTML
            // (e.g., <path .../> vs <path ...></path>) without any data change.
            .replace(/<svg\b[\s\S]*?<\/svg>/gi, '')
            .replace(/<span class="abj404-refresh-status"[^>]*>[\s\S]*?<\/span>/g, '')
            .replace(/(<span class="abj404-time-ago"[^>]*>)[\s\S]*?(<\/span>)/g, '$1$2')
            // "time-ago" markers are freshness metadata; timestamp drift alone should not signal table-data change.
            .replace(/\sdata-timestamp="[^"]*"/gi, '')
            .replace(/\sdata-previous-value="[^"]*"/g, '')
            // Search input is initially rendered disabled and then enabled client-side;
            // ignore this client-only attribute drift for no-change detection.
            .replace(/\sdisabled(?:=(?:"disabled"|""))?/gi, '')
            .replace(/data-pagination-ajax-nonce="[^"]*"/g, 'data-pagination-ajax-nonce="nonce"')
            // URLs in attributes may encode "&" as "&amp;" or "&#038;".
            // Normalize all nonce query params so nonce churn is not treated as data change.
            .replace(/((?:\?|&|&amp;|&#038;)(?:_wpnonce|nonce)=)[^&"'\s>]+/gi, '$1nonce')
            .replace(/\s+/g, ' ')
            .trim();
}

function hasBackgroundRefreshUpdate(result) {
    return hasBackgroundRefreshUpdateWithBaseline(result, null);
}

function getComparableTableHtml(html) {
    if (!html) {
        return '';
    }
    var raw = String(html);
    // Compare rendered row data (tbody) only; header/pagination/link-encoding differences
    // are presentation concerns and can trigger false positives.
    var match = raw.match(/<tbody[^>]*>[\s\S]*<\/tbody>/i);
    if (match && match.length > 0) {
        return match[0];
    }
    return raw;
}

function buildComparableTableSignature(html) {
    var comparableHtml = getComparableTableHtml(html);
    var normalizedHtml = normalizeHtmlForBackgroundComparison(comparableHtml);
    if (!normalizedHtml) {
        return '';
    }

    // Compare semantic row/cell text instead of raw markup so entity/quote/style
    // serialization differences do not produce false positives.
    if (typeof DOMParser !== 'function') {
        return normalizedHtml;
    }

    try {
        var parser = new DOMParser();
        var doc = parser.parseFromString('<table>' + normalizedHtml + '</table>', 'text/html');
        var rows = doc.querySelectorAll('tbody tr');
        if (!rows || rows.length === 0) {
            return normalizedHtml;
        }
        var rowParts = [];
        for (var i = 0; i < rows.length; i++) {
            var cells = rows[i].querySelectorAll('td');
            var cellParts = [];
            for (var j = 0; j < cells.length; j++) {
                var text = (cells[j].textContent || '').replace(/\s+/g, ' ').trim();
                cellParts.push(text);
            }
            rowParts.push(cellParts.join('||'));
        }
        // Ignore non-deterministic row ordering for equal sort values.
        rowParts.sort();
        return rowParts.join('\n');
    } catch (e) {
        return normalizedHtml;
    }
}

function hasBackgroundRefreshUpdateWithBaseline(result, baseline) {
    var currentTableHtml = '';
    var incomingTableHtml = '';

    var currentTable = jQuery('.abj404-table, .wp-list-table').first();
    if (currentTable.length > 0) {
        currentTableHtml = currentTable.prop('outerHTML') || '';
    }
    if (result && typeof result.table === 'string') {
        incomingTableHtml = result.table;
    }

    var normalizedCurrentTable = buildComparableTableSignature(currentTableHtml);
    var normalizedIncomingTable = buildComparableTableSignature(incomingTableHtml);

    if (baseline && typeof baseline === 'object') {
        if (typeof baseline.table === 'string') {
            normalizedCurrentTable = baseline.table;
        }
    }

    return normalizedCurrentTable !== normalizedIncomingTable;
}
