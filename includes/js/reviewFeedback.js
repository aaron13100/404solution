/**
 * 404 Solution - Review Feedback Notice submit handler
 * Avoid nested <form> markup in admin screens by submitting feedback
 * through a generated hidden POST form.
 */
(function() {
    'use strict';

    function canScrollableAncestorConsumeWheel(target, deltaY) {
        var node = target;
        while (node && node !== document.body && node !== document.documentElement) {
            if (node.nodeType !== 1) {
                node = node.parentElement;
                continue;
            }
            var style = window.getComputedStyle(node);
            var overflowY = style ? style.overflowY : '';
            var isScrollable = (overflowY === 'auto' || overflowY === 'scroll') && node.scrollHeight > (node.clientHeight + 1);
            if (isScrollable) {
                if (deltaY < 0 && node.scrollTop > 0) {
                    return true;
                }
                if (deltaY > 0 && (node.scrollTop + node.clientHeight) < (node.scrollHeight - 1)) {
                    return true;
                }
            }
            node = node.parentElement;
        }
        return false;
    }

    function initReviewNoticeScrollBridge() {
        if (window.abj404ReviewNoticeWheelBridgeBound === true) {
            return;
        }

        var handler = function(event) {
            if (!event || event.defaultPrevented) {
                return;
            }
            var target = event.target;
            if (!target || typeof target.closest !== 'function') {
                return;
            }
            var notice = target.closest('.abj404-review-notice');
            if (!notice) {
                return;
            }
            var deltaY = event.deltaY || 0;
            if (deltaY === 0) {
                return;
            }
            if (canScrollableAncestorConsumeWheel(target, deltaY)) {
                return;
            }
            window.scrollBy(0, deltaY);
            event.preventDefault();
        };

        window.addEventListener('wheel', handler, { capture: true, passive: false });
        window.abj404ReviewNoticeWheelBridgeBound = true;
    }

    function appendHiddenField(form, name, value) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }

    function submitFeedback(container, triggerButton) {
        var nonceField = container.querySelector('input[name="abj404_feedback_nonce"]');
        if (!nonceField || !nonceField.value) {
            return;
        }

        var issues = container.querySelectorAll('input[name="feedback_issues[]"]:checked');
        var detailsField = container.querySelector('textarea[name="feedback_details"]');
        var details = detailsField ? detailsField.value : '';

        var form = document.createElement('form');
        form.method = 'post';
        form.action = window.location.href;
        form.style.display = 'none';

        appendHiddenField(form, 'abj404_submit_feedback', '1');
        appendHiddenField(form, 'abj404_feedback_nonce', nonceField.value);
        appendHiddenField(form, '_wp_http_referer', window.location.pathname + window.location.search);

        for (var i = 0; i < issues.length; i++) {
            appendHiddenField(form, 'feedback_issues[]', issues[i].value);
        }

        appendHiddenField(form, 'feedback_details', details);

        document.body.appendChild(form);
        if (triggerButton) {
            triggerButton.disabled = true;
        }
        form.submit();
    }

    function initReviewFeedbackSubmit() {
        var forms = document.querySelectorAll('.abj404-review-feedback-form');
        if (!forms.length) {
            return;
        }

        for (var i = 0; i < forms.length; i++) {
            var container = forms[i];
            var submitButton = container.querySelector('.abj404-submit-feedback');
            if (!submitButton) {
                continue;
            }

            submitButton.addEventListener('click', function(event) {
                event.preventDefault();
                var button = event.currentTarget;
                var parent = button.closest('.abj404-review-feedback-form');
                if (!parent) {
                    return;
                }
                submitFeedback(parent, button);
            });
        }
    }

    window.abj404InitReviewNoticeScrollBridge = initReviewNoticeScrollBridge;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initReviewNoticeScrollBridge();
            initReviewFeedbackSubmit();
        });
    } else {
        initReviewNoticeScrollBridge();
        initReviewFeedbackSubmit();
    }
})();
