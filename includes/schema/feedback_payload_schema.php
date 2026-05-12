<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wire-schema for ABJ_404_Solution_FeedbackTransport payloads.
 *
 * This file is the contract between:
 *   - the PHP producer (FeedbackTransport::buildPayload), tested by
 *     FeedbackPayloadSchemaContractTest;
 *   - the JS producer of the uninstall AJAX form (uninstall-modal.js),
 *     which ships the user-facing extras the PHP builder folds in;
 *   - the reports server endpoint, which accepts these fields and stores
 *     them in typed columns or the environment JSON passthrough.
 *
 * Each report type (`error`, `heartbeat`, `uninstall`) shares a base set
 * of fields and adds a small per-type extras section. The schema is
 * declared once and merged per-type so a new field added in one place
 * (say a new server column) requires updates only here, not in three
 * test files.
 *
 * Reference fixes that this schema would have caught:
 *
 *   - 4080ffb5 B4: resource_limits values shipped as ini shorthand
 *     strings ("256M", "30") instead of integers (bytes/seconds). The
 *     `php_memory`, `wp_memory`, `php_post_max_size`, etc. specifiers
 *     below pin those to `int`.
 *   - 4080ffb5 B4 (field-name): the resource_limits map used names that
 *     did not match the server columns (`max_execution_time` vs
 *     `php_max_execution_seconds`). `unexpected_field` detection +
 *     declared key names catch this.
 *   - 4080ffb5 B5: `active_theme` shipped as `{name, version}` object but
 *     server schema declares `string`. `object_cache` shipped as bool,
 *     server expects "external"/"default" string. `extensions` shipped
 *     as `array<string>`, server expects `object` keyed by ext name.
 *     The specifiers below force the string/object shapes.
 *   - 35380dcc: nested `content_counts` / `redirect_counts` /
 *     `captured_counts` arrays were flattened to top-level fields.
 *     Listing every flat field as required + `unexpected_field` rejects
 *     the nested re-introduction.
 *
 * Returns array<string, array<string, array<string, mixed>>> keyed by
 * report type. Each value is a flat FieldSpec map ready to feed to
 * ABJ_404_Solution_PayloadSchema::validate().
 */

return (function (): array {

    // Base fields shared by every report type. The producer always
    // returns these regardless of `$type`; the per-type extras are
    // unioned in below.
    $base = [
        // Plugin and request metadata
        'plugin_version'  => ['type' => 'string', 'description' => 'ABJ404_VERSION at send time. Empty string is allowed only in early-boot test contexts.'],
        'report_type'     => ['type' => 'string', 'enum' => ['error', 'heartbeat', 'uninstall']],
        'is_uninstall'    => ['type' => 'bool', 'description' => 'Back-compat alias for report_type=uninstall. True iff report_type=uninstall.'],
        'site_url'        => ['type' => 'string', 'description' => 'home_url(). Server GROUP BY key.'],
        'locale'          => ['type' => 'string'],

        // Database identity
        'db_type'         => ['type' => 'string', 'enum' => ['mysql', 'mariadb']],
        'db_version'      => ['type' => 'string'],
        'table_prefix'    => ['type' => 'string'],

        // WordPress identity
        'wp_version'      => ['type' => 'string'],
        'is_multisite'    => ['type' => 'bool'],
        'wp_debug'        => ['type' => 'bool'],

        // PHP runtime identity
        'php_version'     => ['type' => 'string'],
        'server_software' => ['type' => 'string'],

        // Resource limits. Every value is bytes (for size fields) or
        // seconds (for time fields). Strings like "256M" are a schema
        // violation: that was bug 4080ffb5 B4.
        'resource_limits' => [
            'type' => 'object',
            'key_type' => 'string',
            'value_type' => 'int',
            'description' => 'Bytes for size fields, seconds for time fields. Never ini shorthand strings.',
        ],
        'wp_memory_limit_bytes' => ['type' => 'int|null', 'description' => 'Convenience top-level alias for resource_limits.wp_memory.'],

        // Extensions and active plugins
        'extensions'    => [
            'type' => 'object',
            'key_type' => 'string',
            'value_type' => 'bool',
            'description' => '{curl:true, mbstring:true, ...} map. Bug 4080ffb5 B5 shipped this as array<string>; the server rejected it.',
        ],
        'active_plugins' => ['type' => 'array', 'item_type' => 'string'],
        'active_theme'   => ['type' => 'string', 'description' => '"Name Version" string. Bug 4080ffb5 B5 shipped {name,version} object.'],

        // Cache identity
        'object_cache'   => [
            'type' => 'string',
            'enum' => ['external', 'default'],
            'description' => 'Bug 4080ffb5 B5 shipped this as bool. Server schema is string enum.',
        ],

        // Content-count diagnostics. Nullable because the underlying
        // wp_count_posts / wp_count_terms can fail; null distinguishes
        // "lookup failed" from "really zero".
        'published_posts_count' => ['type' => 'int|null'],
        'published_pages_count' => ['type' => 'int|null'],
        'categories_count'      => ['type' => 'int|null'],
        'tags_count'            => ['type' => 'int|null'],

        // Redirect status counts. Flat layout was required by server
        // schema (commit 35380dcc); the nested layout below the line is
        // explicitly forbidden by `unexpected_field`.
        'redirects_active_total'    => ['type' => 'int|null'],
        'redirects_manual_count'    => ['type' => 'int|null'],
        'redirects_automatic_count' => ['type' => 'int|null'],
        'redirects_regex_count'     => ['type' => 'int|null'],
        'redirects_trashed_count'   => ['type' => 'int|null'],

        // Captured-404 status counts.
        'captured_404s_active_total'  => ['type' => 'int|null'],
        'captured_404s_new_count'     => ['type' => 'int|null'],
        'captured_404s_ignored_count' => ['type' => 'int|null'],
        'captured_404s_later_count'   => ['type' => 'int|null'],
        'captured_404s_trashed_count' => ['type' => 'int|null'],

        // Log + debug file health.
        'log_entries_count'     => ['type' => 'int|null'],
        'log_table_size_bytes'  => ['type' => 'int|null'],
        'error_count_in_log'    => ['type' => 'int|null'],
        'debug_file_size_bytes' => ['type' => 'int|null'],

        // Optional dev-environment marker. Only present when the
        // producer detected a dev host; absence on real prod sites is
        // expected and not a schema violation.
        'environment_type' => [
            'type' => 'string',
            'enum' => ['development'],
            'required' => false,
        ],
    ];

    $errorExtras = [
        'error_signature'      => ['type' => 'string'],
        'previously_sent_line' => ['type' => 'int'],
    ];

    $heartbeatExtras = $errorExtras;

    $uninstallExtras = [
        'uninstall_reason'    => ['type' => 'string'],
        'selected_issues'     => ['type' => 'string', 'description' => 'Comma-joined checkbox values from the modal (sanitized server-side).'],
        'followup_details'    => ['type' => 'string'],
        'better_plugin_name'  => ['type' => 'string', 'required' => false],
        'other_reason_text'   => ['type' => 'string', 'required' => false],
        'contact_email'       => ['type' => 'string'],
        'include_diagnostics' => ['type' => 'bool'],
        'debug_log'           => ['type' => 'string'],
    ];

    return [
        'error'     => array_merge($base, $errorExtras),
        'heartbeat' => array_merge($base, $heartbeatExtras),
        'uninstall' => array_merge($base, $uninstallExtras),
    ];
})();
