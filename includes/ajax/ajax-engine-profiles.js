/**
 * Engine Profiles Admin UI
 *
 * Handles loading, saving, and deleting engine profiles via AJAX.
 * Depends on: jQuery, abj404EngineProfiles (localized object).
 */
(function ($) {
    'use strict';

    if (typeof abj404EngineProfiles === 'undefined') {
        return;
    }

    var nonce    = abj404EngineProfiles.nonce;
    var ajaxUrl  = abj404EngineProfiles.ajaxUrl;
    var editingId = 0;

    // ── Load profiles ───────────────────────────────────────────────────────

    function loadProfiles() {
        $.post(ajaxUrl, {
            action: 'abj404_engine_profiles_list',
            nonce:  nonce
        }, function (resp) {
            if (!resp.success) {
                return;
            }
            renderProfiles(resp.data.profiles || []);
        });
    }

    function renderProfiles(profiles) {
        var $tbody  = $('#abj404-engine-profiles-tbody');
        var $empty  = $('#abj404-engine-profiles-empty-row');

        // Remove all data rows (keep empty-row placeholder).
        $tbody.find('tr[data-profile-id]').remove();

        if (!profiles || profiles.length === 0) {
            $empty.show();
            return;
        }

        $empty.hide();

        profiles.forEach(function (p) {
            var engines = [];
            try { engines = JSON.parse(p.enabled_engines || '[]'); } catch (e) {}
            var engineLabels = engines.map(function (cls) {
                return cls.replace(/^ABJ_404_Solution_/, '').replace(/Engine$/, '').replace(/MatchingEngine$/, '');
            }).join(', ');

            var $row = $('<tr>')
                .attr('data-profile-id', p.id)
                .append($('<td>').text(p.name))
                .append($('<td>').text(p.url_pattern))
                .append($('<td>').text(p.is_regex === '1' || p.is_regex === 1 ? '✓' : ''))
                .append($('<td>').text(engineLabels || '(all)'))
                .append($('<td>').text(p.priority))
                .append($('<td>').text(p.status === '1' || p.status === 1 ? '✓' : ''))
                .append($('<td>').append(
                    $('<button>').addClass('button button-small abj404-edit-profile-btn').text(abj404EngineProfiles.i18n.edit).attr('data-id', p.id),
                    ' ',
                    $('<button>').addClass('button button-small abj404-delete-profile-btn').text(abj404EngineProfiles.i18n.delete).attr('data-id', p.id)
                ));

            $tbody.append($row);
        });
    }

    // ── Add / edit form ─────────────────────────────────────────────────────

    $(document).on('click', '#abj404-add-engine-profile-btn', function () {
        openForm(null);
    });

    $(document).on('click', '.abj404-edit-profile-btn', function () {
        var id = $(this).data('id');
        // Load from current row data
        var $row = $('tr[data-profile-id="' + id + '"]');
        // Re-fetch profile list to get full data
        $.post(ajaxUrl, {
            action: 'abj404_engine_profiles_list',
            nonce:  nonce
        }, function (resp) {
            if (!resp.success) { return; }
            var profiles = resp.data.profiles || [];
            var profile = null;
            profiles.forEach(function (p) { if (parseInt(p.id, 10) === parseInt(id, 10)) { profile = p; } });
            if (profile) { openForm(profile); }
        });
    });

    function openForm(profile) {
        var $form = $('#abj404-engine-profile-form-wrap');
        var $title = $('#abj404-engine-profile-form-title');

        // Reset
        $('#abj404-profile-id').val(0);
        $('#abj404-profile-name').val('');
        $('#abj404-profile-pattern').val('');
        $('#abj404-profile-is-regex').prop('checked', false);
        $('#abj404-profile-priority').val(0);
        $('#abj404-profile-status').prop('checked', true);
        $('.abj404-engine-cb').prop('checked', false);
        $('#abj404-engine-profile-save-msg').hide().text('');

        if (profile) {
            $title.text(abj404EngineProfiles.i18n.editProfile);
            editingId = parseInt(profile.id, 10);
            $('#abj404-profile-id').val(editingId);
            $('#abj404-profile-name').val(profile.name);
            $('#abj404-profile-pattern').val(profile.url_pattern);
            $('#abj404-profile-is-regex').prop('checked', profile.is_regex === '1' || profile.is_regex === 1);
            $('#abj404-profile-priority').val(profile.priority);
            $('#abj404-profile-status').prop('checked', profile.status === '1' || profile.status === 1);

            var engines = [];
            try { engines = JSON.parse(profile.enabled_engines || '[]'); } catch (e) {}
            engines.forEach(function (cls) {
                $('.abj404-engine-cb[value="' + cls + '"]').prop('checked', true);
            });
        } else {
            $title.text(abj404EngineProfiles.i18n.addProfile);
            editingId = 0;
        }

        $form.show();
    }

    $(document).on('click', '#abj404-cancel-engine-profile-btn', function () {
        $('#abj404-engine-profile-form-wrap').hide();
        editingId = 0;
    });

    // ── Save ────────────────────────────────────────────────────────────────

    $(document).on('click', '#abj404-save-engine-profile-btn', function () {
        var name     = $.trim($('#abj404-profile-name').val());
        var pattern  = $.trim($('#abj404-profile-pattern').val());
        var isRegex  = $('#abj404-profile-is-regex').is(':checked') ? 1 : 0;
        var priority = parseInt($('#abj404-profile-priority').val(), 10) || 0;
        var status   = $('#abj404-profile-status').is(':checked') ? 1 : 0;
        var id       = parseInt($('#abj404-profile-id').val(), 10) || 0;

        var engines = [];
        $('.abj404-engine-cb:checked').each(function () {
            engines.push($(this).val());
        });

        var $msg = $('#abj404-engine-profile-save-msg');
        $msg.hide().text('');

        if (!name) {
            $msg.text(abj404EngineProfiles.i18n.nameRequired).css('color', 'red').show();
            return;
        }
        if (!pattern) {
            $msg.text(abj404EngineProfiles.i18n.patternRequired).css('color', 'red').show();
            return;
        }

        $.post(ajaxUrl, {
            action:          'abj404_engine_profiles_save',
            nonce:           nonce,
            id:              id,
            name:            name,
            url_pattern:     pattern,
            is_regex:        isRegex,
            enabled_engines: JSON.stringify(engines),
            priority:        priority,
            status:          status
        }, function (resp) {
            if (!resp.success) {
                $msg.text(resp.data && resp.data.message ? resp.data.message : abj404EngineProfiles.i18n.saveFailed)
                    .css('color', 'red').show();
                return;
            }
            $msg.text(abj404EngineProfiles.i18n.saved).css('color', 'green').show();
            setTimeout(function () {
                $('#abj404-engine-profile-form-wrap').hide();
                editingId = 0;
                loadProfiles();
            }, 800);
        });
    });

    // ── Delete ──────────────────────────────────────────────────────────────

    $(document).on('click', '.abj404-delete-profile-btn', function () {
        var id = parseInt($(this).data('id'), 10);
        if (!id) { return; }

        if (!window.confirm(abj404EngineProfiles.i18n.confirmDelete)) {
            return;
        }

        $.post(ajaxUrl, {
            action: 'abj404_engine_profiles_delete',
            nonce:  nonce,
            id:     id
        }, function (resp) {
            if (resp.success) {
                loadProfiles();
            }
        });
    });

    // ── Init ────────────────────────────────────────────────────────────────

    $(function () {
        if ($('#abj404-engine-profiles-section').length) {
            loadProfiles();
        }
    });

})(jQuery);
