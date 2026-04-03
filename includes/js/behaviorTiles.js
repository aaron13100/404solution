/**
 * Behavior Tiles — handles selection of 404 behavior option tiles.
 */
(function () {
    'use strict';

    function init() {
        var container = document.querySelector('.abj404-behavior-tiles');
        if (!container) {
            return;
        }

        var tiles = container.querySelectorAll('.abj404-tile');
        var hiddenInput = container.querySelector('#dest404_behavior');
        var customPicker = document.getElementById('abj404-custom-page-picker');

        function selectTile(behavior) {
            tiles.forEach(function (t) {
                var isSel = t.getAttribute('data-behavior') === behavior;
                t.classList.toggle('selected', isSel);
                t.setAttribute('aria-checked', isSel ? 'true' : 'false');
            });

            if (hiddenInput) {
                hiddenInput.value = behavior;
            }

            // Show/hide custom page picker and toggle required on its input
            // so hidden required fields don't block form submission.
            if (customPicker) {
                var isCustom = behavior === 'custom';
                customPicker.style.display = isCustom ? '' : 'none';
                var pickerInput = customPicker.querySelector('[name="redirect_to_user_field"]');
                if (pickerInput) {
                    if (isCustom) {
                        pickerInput.setAttribute('required', '');
                    } else {
                        pickerInput.removeAttribute('required');
                    }
                }
            }

            // Mark the form as dirty so the save bar shows
            var form = document.getElementById('admin-options-page');
            if (form) {
                var event = new Event('change', { bubbles: true });
                form.dispatchEvent(event);
            }
        }

        tiles.forEach(function (tile) {
            tile.addEventListener('click', function () {
                selectTile(tile.getAttribute('data-behavior'));
            });

            tile.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    selectTile(tile.getAttribute('data-behavior'));
                }
            });
        });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
