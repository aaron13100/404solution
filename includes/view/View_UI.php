<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * View UI component. Owns the admin-page chrome (wrap open, header tabs,
 * message notices, regex auto-promote notice, postbox / options-section /
 * sticky save bar / toast / restore-defaults modal / mode toggles).
 *
 * HTML for every rendered fragment lives in includes/html/*.html with
 * placeholder substitution at render time. This class is the PHP side of
 * that contract: it loads the template, substitutes values, and echoes.
 * Do not add raw inline HTML strings here. New surface gets a new template.
 */
class ABJ_404_Solution_View_UI extends ABJ_404_Solution_ViewComponent {

    /**
     * Load a template file from includes/html/ and trim its trailing newline.
     * Passes $appendExtraData=false to suppress the BEGIN/END HTML comment
     * markers that readFileContents() adds by default, since these templates
     * are spliced into tight inline contexts where stray comments would alter
     * the rendered chrome.
     *
     * @param string $name Template filename under includes/html/
     * @return string Template contents, no trailing newline.
     */
    private function tpl(string $name): string {
        $raw = ABJ_404_Solution_FileSystemService::readFileContents(dirname(__DIR__) . '/html/' . $name, false);
        return rtrim((string)$raw, "\n");
    }

    /**
     * Load a template and substitute an associative array of placeholders.
     * Keys are placeholder tokens (without braces). Substitution is plain
     * string replacement; callers must apply esc_html / esc_attr / esc_url
     * BEFORE passing the value in.
     *
     * @param string                $name Template filename under includes/html/
     * @param array<string,string>  $vars Placeholder name (without braces) to substituted value.
     * @return string Filled template, no trailing newline.
     */
    private function fillTpl(string $name, array $vars): string {
        $tpl = $this->tpl($name);
        $search = array();
        $replace = array();
        foreach ($vars as $k => $v) {
            $search[] = '{' . $k . '}';
            $replace[] = $v;
        }
        // Fall back to native str_replace when the Functions helper isn't wired
        // (e.g. reflection tests using newInstanceWithoutConstructor()).
        if (is_object($this->f)) {
            return (string)$this->f->str_replace($search, $replace, $tpl);
        }
        return (string)str_replace($search, $replace, $tpl);
    }

    /**
     * Render a native-shape error notice (`.notice.notice-error`) on a
     * plugin admin page with the "Send debug log to developer" support
     * affordance as a small inline text link inside the notice body.
     * Shape matches wp-admin/css/common.css:1441-1580 (white background,
     * 4px red left border, 13px near-black text, no bold heading wall,
     * no green CTA button. See docs/ui-aesthetic/UI_AESTHETIC.md).
     *
     * The link is the same anchor mountAll() attaches to via attachLink(),
     * so a JS regression that breaks the modal still leaves a clickable
     * fallback link to the Settings support section. Trigger sources are
     * allowlisted server-side by ALLOWED_TRIGGER_SOURCES; an off-list
     * slug renders a link whose AJAX 400s on click.
     *
     * Only call this from screens that live under the plugin's own pages
     * (CLAUDE.md Self-Healing §4 bans support buttons on generic wp-admin
     * notices).
     *
     * @param string      $messageHtml    Pre-escaped/-allowed inner HTML
     *                                    for the <p> inside the notice.
     *                                    Callers escape per their own
     *                                    needs (esc_html / wp_kses_post).
     * @param string      $triggeredFrom  One of
     *   ABJ_404_Solution_Ajax_SupportRequest::ALLOWED_TRIGGER_SOURCES.
     * @param string|null $contextSummary Optional one-line summary shown
     *   in the support-request modal.
     * @return string Notice HTML, ready to echo.
     */
    public static function renderErrorNoticeWithSupportButton(string $messageHtml,
            string $triggeredFrom, ?string $contextSummary = null): string {
        $linkHtml = class_exists('ABJ_404_Solution_SupportRequestButton')
            ? ABJ_404_Solution_SupportRequestButton::renderInlineLink($triggeredFrom, $contextSummary)
            : '';
        $tpl = dirname(__DIR__) . '/html/ajaxErrorNoticeWithSupportLink.html';
        $template = is_readable($tpl) ? (string)@file_get_contents($tpl) : '';
        return str_replace(['{message}', '{support_link}'], [$messageHtml, $linkHtml], $template);
    }

	/** Get the text to notify the user when some URLs have been captured and need attention.
     * @param int $captured the number of captured URLs
     * @return string html
     */
    function getDashboardNotificationCaptured($captured) {
        /* Translators: %s is the number of captured 404 URLs. */
    	$capturedMessage = sprintf( _n( 'There is <a>%s captured 404 URL</a> that needs to be processed.',
                'There are <a>%s captured 404 URLs</a> to be processed.',
                $captured, '404-solution'), $captured);
        $capturedMessage = $this->f->str_replace("<a>",
                "<a href=\"options-general.php?page=" . ABJ404_PP . "&subpage=abj404_captured\" >",
                $capturedMessage);
        $capturedMessage = $this->f->str_replace("</a>", "</a>", $capturedMessage);

        return $this->fillTpl('dashboardNotificationCaptured.html', array(
            'plugin_name' => PLUGIN_NAME,
            'message'     => $capturedMessage,
        ));
    }

    /** Display the chosen admin page.
     * @param string $action
     * @param string $sub
     * @param string $message
     * @return void
     */
    function echoChosenAdminTab($action, $sub, $message) {
        global $abj404view;

        // If globals are not set, use sensible defaults
        if ($abj404view === null) {
            $abj404view = $this->view;
        }

        // Deal With Page Tabs
        if ($sub == "") {
            $sub = $this->f->strtolower($this->shared->viewGetPostOrGetSanitize('subpage'));
        }
        if ($sub == "") {
            $sub = 'abj404_redirects';
            $this->logger->debugMessage('No tab selected. Displaying the "redirects" tab.');
        }

        // Check if we're returning from a successful redirect update
        $updated = $this->shared->viewGetPostOrGetSanitize('updated');
        if ($updated == '1') {
            $message .= __('Redirect Information Updated Successfully!', '404-solution');
        }

        $this->logger->debugMessage("Displaying sub page: " . esc_html($sub == '' ? '(none)' : $sub));

        $abj404view->outputAdminHeaderTabs($sub, $message);

        $abj404action = $this->shared->viewGetPostOrGetSanitize('abj404action');
        if ($this->isEditRedirectScreen($action, $sub, $abj404action)) {
            $abj404view->echoAdminEditRedirectPage();
        } else if ($sub == 'abj404_redirects') {
            $abj404view->echoAdminRedirectsPage();
        } else if ($sub == 'abj404_captured') {
            $abj404view->echoAdminCapturedURLsPage();
        } else if ($sub == "abj404_options") {
            $abj404view->echoAdminOptionsPage();
        } else if ($sub == 'abj404_logs') {
            $abj404view->echoAdminLogsPage();
        } else if ($sub == 'abj404_stats') {
            $abj404view->outputAdminStatsPage();
        } else if ($sub == 'abj404_tools') {
            $abj404view->echoAdminToolsPage();
        } else if ($sub == 'abj404_debugfile') {
            $abj404view->echoAdminDebugFile();
        } else {
            $this->logger->debugMessage('No tab selected. Displaying the "redirects" tab.');
            $abj404view->echoAdminRedirectsPage();
        }
        
        $abj404view->echoAdminFooter();
    }

    /**
     * Identify all request shapes that should render the edit redirect form.
     *
     * @param string $action Current request action.
     * @param string $sub Current plugin subpage.
     * @param string $bulkAction Current list-table bulk action.
     * @return bool
     */
    private function isEditRedirectScreen(string $action, string $sub, string $bulkAction): bool {
        if ($action == 'editRedirect' || $bulkAction == 'editRedirect' || $sub == 'abj404_edit') {
            return true;
        }

        $requestAction = $action !== '' ? $action : (string)$this->shared->viewGetPostOrGetSanitize('action');
        if ($requestAction !== 'edit') {
            return false;
        }

        if (!in_array($sub, array('abj404_redirects', 'abj404_captured'), true)) {
            return false;
        }

        return isset($_GET['id']) || isset($_POST['id']) || isset($_GET['idnum']) || isset($_POST['idnum']);
    }
    
}
