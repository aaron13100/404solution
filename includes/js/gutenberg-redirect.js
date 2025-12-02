/**
 * 404 Solution - Gutenberg Slug Change Detection
 *
 * Shows a "Create redirect from old URL to new URL" toggle ONLY when the user
 * modifies the post slug. Uses PluginPostStatusInfo to appear near
 * the publish controls rather than as a separate sidebar panel.
 */
(function() {
    'use strict';

    var el = wp.element.createElement;
    var registerPlugin = wp.plugins.registerPlugin;
    // Use wp.editor.PluginPostStatusInfo (WP 6.6+) with fallback to wp.editPost for older versions
    var PluginPostStatusInfo = (wp.editor && wp.editor.PluginPostStatusInfo) || wp.editPost.PluginPostStatusInfo;
    var CheckboxControl = wp.components.CheckboxControl;
    var useSelect = wp.data.useSelect;
    var useDispatch = wp.data.useDispatch;
    var useEffect = wp.element.useEffect;
    var useState = wp.element.useState;
    var useRef = wp.element.useRef;

    // Get localized settings
    var rawSettings = window.abj404GutenbergRedirect || {
        defaultEnabled: true,
        i18n: {
            checkboxLabel: 'Create redirect from old URL to new URL',
            slugChangedNotice: 'Slug changed:'
        }
    };

    // Normalize settings - defaultEnabled can be boolean true, string "1", or number 1
    var settings = {
        defaultEnabled: rawSettings.defaultEnabled === true || rawSettings.defaultEnabled === '1' || rawSettings.defaultEnabled === 1,
        i18n: rawSettings.i18n
    };

    /**
     * Check if meta value is not set (undefined, null, empty string, or missing)
     */
    function isMetaValueUnset(value) {
        return value === undefined || value === null || value === '';
    }

    /**
     * Main component - only renders when slug is modified
     */
    function ABJ404RedirectPanel() {
        // Track the original slug from when the editor first loaded
        var originalSlugRef = useRef(null);

        // Get post data including current and saved slug
        var postData = useSelect(function(select) {
            var editor = select('core/editor');
            var currentPost = editor.getCurrentPost();
            return {
                postStatus: editor.getEditedPostAttribute('status'),
                currentSlug: editor.getEditedPostAttribute('slug'),
                savedSlug: currentPost ? currentPost.slug : null,
                meta: editor.getEditedPostAttribute('meta') || {},
                isNewPost: editor.isEditedPostNew()
            };
        }, []);

        var editPost = useDispatch('core/editor').editPost;

        // State to track if we should show the checkbox
        var showCheckboxState = useState(false);
        var showCheckbox = showCheckboxState[0];
        var setShowCheckbox = showCheckboxState[1];

        // Store the original slug on first render
        useEffect(function() {
            if (originalSlugRef.current === null && postData.savedSlug) {
                originalSlugRef.current = postData.savedSlug;
            }
        }, [postData.savedSlug]);

        // Detect slug changes
        useEffect(function() {
            if (originalSlugRef.current !== null && postData.currentSlug !== undefined) {
                var slugChanged = postData.currentSlug !== originalSlugRef.current;
                setShowCheckbox(slugChanged);

                // Auto-set meta value when slug changes (if not already set)
                if (slugChanged && isMetaValueUnset(postData.meta._abj404_create_redirect)) {
                    editPost({
                        meta: { _abj404_create_redirect: settings.defaultEnabled ? '1' : '0' }
                    });
                }
            }
        }, [postData.currentSlug]);

        // Don't render anything if:
        // - Not a published post
        // - New post
        // - Slug hasn't changed
        if (postData.postStatus !== 'publish' || postData.isNewPost || !showCheckbox) {
            return null;
        }

        // Get meta value - check if it's '1', or if unset use the default setting
        var metaValue = postData.meta._abj404_create_redirect;
        var isChecked = metaValue === '1' || (isMetaValueUnset(metaValue) && settings.defaultEnabled);

        // Handle checkbox change
        function onCheckboxChange(newValue) {
            editPost({
                meta: { _abj404_create_redirect: newValue ? '1' : '0' }
            });
        }

        // Render as a compact notice in the post status area
        return el(
            PluginPostStatusInfo,
            {
                className: 'abj404-redirect-notice'
            },
            el('div', {
                style: {
                    width: '100%',
                    padding: '8px 0',
                    borderTop: '1px solid #ddd',
                    marginTop: '8px'
                }
            },
                el('div', {
                    style: {
                        fontSize: '11px',
                        color: '#757575',
                        marginBottom: '4px'
                    }
                }, settings.i18n.slugChangedNotice + ' ' + originalSlugRef.current + ' → ' + postData.currentSlug),
                el(CheckboxControl, {
                    label: settings.i18n.checkboxLabel,
                    checked: isChecked,
                    onChange: onCheckboxChange,
                    __nextHasNoMarginBottom: true
                })
            )
        );
    }

    // Register the plugin
    registerPlugin('abj404-redirect', {
        render: ABJ404RedirectPanel,
        icon: null
    });

})();
