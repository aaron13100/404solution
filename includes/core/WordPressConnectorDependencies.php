<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optional dependency bundle for
 * {@see ABJ_404_Solution_WordPress_Connector::__construct()}.
 *
 * Replaces a 7-positional-parameter constructor (criterion 220 Interface
 * Size). Every field is nullable; the connector keeps its per-field
 * ?? abj_service(...) resolution and its duck-typed logsRepository /
 * statsRepository fallbacks (which inspect the raw redirectsRepository).
 * Fields are untyped to preserve the existing null-tolerant parameters.
 */
// allow-no-test-found: pure dependency-bundle DTO (nullable public fields, constructor-only assignment, no behavior); fields consumed by ABJ_404_Solution_WordPress_Connector, which is constructed and exercised in WordPressConnectorStaticMethodsTest and WordPressConnectorAdminLinkFilterTest.
class ABJ_404_Solution_WordPressConnectorDependencies {

    /** @var ABJ_404_Solution_PluginLogic|null */
    public $pluginLogic;

    /** @var ABJ_404_Solution_RedirectsRepository|null */
    public $redirectsRepository;

    /** @var ABJ_404_Solution_Logging|null */
    public $logging;

    /** @var ABJ_404_Solution_Functions|null */
    public $functions;

    /** @var ABJ_404_Solution_SpellChecker|null */
    public $spellChecker;

    /** @var mixed|null */
    public $logsRepository;

    /** @var mixed|null */
    public $statsRepository;

    /**
     * @param ABJ_404_Solution_PluginLogic|null $pluginLogic
     * @param ABJ_404_Solution_RedirectsRepository|null $redirectsRepository
     * @param ABJ_404_Solution_Logging|null $logging
     * @param ABJ_404_Solution_Functions|null $functions
     * @param ABJ_404_Solution_SpellChecker|null $spellChecker
     * @param mixed|null $logsRepository
     * @param mixed|null $statsRepository
     */
    public function __construct($pluginLogic = null, $redirectsRepository = null, $logging = null,
            $functions = null, $spellChecker = null, $logsRepository = null, $statsRepository = null) {
        $this->pluginLogic = $pluginLogic;
        $this->redirectsRepository = $redirectsRepository;
        $this->logging = $logging;
        $this->functions = $functions;
        $this->spellChecker = $spellChecker;
        $this->logsRepository = $logsRepository;
        $this->statsRepository = $statsRepository;
    }
}
