<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API route adapter for the abj404/v1 namespace.
 *
 * Registers routes and delegates request parsing, mutations, reads, and
 * response shaping to focused collaborators.
 */
class ABJ_404_Solution_RestApiController {

    const NAMESPACE = 'abj404/v1';

    /** @var object */
    private $logic;

    /** @var ABJ_404_Solution_RestApiRequestParser */
    private $requestParser;

    /** @var ABJ_404_Solution_RestApiReadService */
    private $readService;

    /** @var ABJ_404_Solution_RestApiRedirectMutationService */
    private $mutationService;

    /**
     * @param ABJ_404_Solution_DataAccess|ABJ_404_Solution_PluginLogic $daoOrLogic Legacy: DataAccess + PluginLogic. New: just PluginLogic.
     * @param ABJ_404_Solution_PluginLogic|null $logic
     * @param ABJ_404_Solution_StatsRepositoryInterface|null $statsRepository
     */
    public function __construct($daoOrLogic, $logic = null, $statsRepository = null) {
        $presenter = new ABJ_404_Solution_RestApiResponsePresenter();
        $this->requestParser = new ABJ_404_Solution_RestApiRequestParser();

        if ($logic !== null && $daoOrLogic instanceof ABJ_404_Solution_DataAccess) {
            $dao = $daoOrLogic;
            $this->logic = $logic;
            $viewRead = $dao->getViewReadService();
            $redirectsRepo = $dao->getRedirectsRepo();
            $logsRepo = $dao->getLogsRepo();
            $statsRepo = $this->resolveStatsRepository($statsRepository, $dao);
            $dbCore = $dao->getDbCore();
        } else {
            $logicService = abj_service('plugin_logic');
            $this->logic = $daoOrLogic instanceof ABJ_404_Solution_PluginLogic
                ? $daoOrLogic
                : $logicService;
            $fallbackDao = abj_service('data_access');
            $viewReadService = abj_service('view_read_service');
            $viewRead = $viewReadService instanceof ABJ_404_Solution_ViewReadServiceInterface ? $viewReadService : $fallbackDao->getViewReadService();
            $redirectsService = abj_service('redirects_repository');
            $redirectsRepo = $redirectsService instanceof ABJ_404_Solution_RedirectsRepositoryInterface ? $redirectsService : $fallbackDao->getRedirectsRepo();
            $logsService = abj_service('logs_repository');
            $logsRepo = $logsService instanceof ABJ_404_Solution_LogsRepositoryInterface ? $logsService : $fallbackDao->getLogsRepo();
            $statsRepo = $this->resolveStatsRepository($statsRepository, null);
            $dbCoreService = abj_service('db_core');
            $dbCore = $dbCoreService instanceof ABJ_404_Solution_DatabaseQueryInterface ? $dbCoreService : $fallbackDao->getDbCore();
        }

        $this->readService = new ABJ_404_Solution_RestApiReadService(
            $viewRead,
            $redirectsRepo,
            $logsRepo,
            $statsRepo,
            $dbCore,
            $this->logic,
            $presenter
        );
        $this->mutationService = new ABJ_404_Solution_RestApiRedirectMutationService(
            $redirectsRepo,
            $presenter
        );
    }

    /**
     * @param ABJ_404_Solution_StatsRepositoryInterface|null $provided
     * @param mixed $legacyDao
     * @return ABJ_404_Solution_StatsRepositoryInterface
     */
    private function resolveStatsRepository($provided, $legacyDao): ABJ_404_Solution_StatsRepositoryInterface {
        if ($provided instanceof ABJ_404_Solution_StatsRepositoryInterface) {
            return $provided;
        }

        $service = class_exists('ABJ_404_Solution_ServiceContainer')
            ? ABJ_404_Solution_ServiceContainer::safeGet('stats_repository')
            : null;
        if ($service instanceof ABJ_404_Solution_StatsRepositoryInterface) {
            return $service;
        }

        return ABJ_404_Solution_StatsRepositoryResolver::resolve(__CLASS__);
    }

    /** @return void */
    public function register() {
        add_action('rest_api_init', array($this, 'registerRoutes'));
        add_filter('rest_index_data', array($this, 'hideNamespaceFromIndex'));
    }

    /**
     * Remove this plugin's namespace from the publicly-enumerable namespace list.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function hideNamespaceFromIndex($data) {
        if (is_array($data) && isset($data['namespaces']) && is_array($data['namespaces'])) {
            $data['namespaces'] = array_values(
                array_filter($data['namespaces'], function ($ns) {
                    return $ns !== self::NAMESPACE;
                })
            );
        }
        return $data;
    }

    /** @return void */
    public function registerRoutes() {
        register_rest_route(self::NAMESPACE, '/redirects', array(
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'getRedirects'),
                'permission_callback' => array($this, 'permissionCheck'),
                'args'                => array(
                    'page'     => array('type' => 'integer', 'default' => 1, 'minimum' => 1),
                    'per_page' => array('type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100),
                    'status'   => array('type' => 'string', 'default' => ''),
                    'filter'   => array('type' => 'string', 'default' => ''),
                ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'createRedirect'),
                'permission_callback' => array($this, 'permissionCheck'),
                'args'                => array(
                    'from'  => array('type' => 'string', 'required' => true),
                    'to'    => array('type' => 'string', 'required' => true),
                    'code'  => array('type' => 'integer', 'default' => 301),
                    'regex' => array('type' => 'boolean', 'default' => false),
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/redirects/(?P<id>\d+)', array(
            array(
                'methods'             => 'PUT',
                'callback'            => array($this, 'updateRedirect'),
                'permission_callback' => array($this, 'permissionCheck'),
                'args'                => array(
                    'id'    => array('type' => 'integer', 'required' => true),
                    'from'  => array('type' => 'string'),
                    'to'    => array('type' => 'string'),
                    'code'  => array('type' => 'integer'),
                    'regex' => array('type' => 'boolean'),
                ),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array($this, 'deleteRedirect'),
                'permission_callback' => array($this, 'permissionCheck'),
                'args'                => array(
                    'id' => array('type' => 'integer', 'required' => true),
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/captured', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'getCaptured'),
            'permission_callback' => array($this, 'permissionCheck'),
            'args'                => array(
                'page'     => array('type' => 'integer', 'default' => 1, 'minimum' => 1),
                'per_page' => array('type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/captured/(?P<id>\d+)/redirect', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'createRedirectFromCaptured'),
            'permission_callback' => array($this, 'permissionCheck'),
            'args'                => array(
                'id'   => array('type' => 'integer', 'required' => true),
                'to'   => array('type' => 'string', 'required' => true),
                'code' => array('type' => 'integer', 'default' => 301),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/stats', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'getStats'),
            'permission_callback' => array($this, 'permissionCheck'),
        ));

        register_rest_route(self::NAMESPACE, '/logs', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'getLogs'),
            'permission_callback' => array($this, 'permissionCheck'),
            'args'                => array(
                'page'     => array('type' => 'integer', 'default' => 1, 'minimum' => 1),
                'per_page' => array('type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/test', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'testRedirect'),
            'permission_callback' => array($this, 'permissionCheck'),
            'args'                => array(
                'url' => array('type' => 'string', 'required' => true),
            ),
        ));
    }

    /**
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function permissionCheck($request) {
        return ABJ_404_Solution_PluginAdminAccessPolicy::currentUserCanAccessPluginAdmin();
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function getRedirects($request) {
        $input = $this->requestParser->redirectsList($request);
        if ($input instanceof \WP_Error) {
            return $input;
        }

        return $this->readService->redirects($input);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function createRedirect($request) {
        $input = $this->requestParser->createRedirect($request);
        if ($input instanceof \WP_Error) {
            return $input;
        }

        return $this->mutationService->create($input);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function updateRedirect($request) {
        $input = $this->requestParser->updateRedirect($request);
        if ($input instanceof \WP_Error) {
            return $input;
        }

        return $this->mutationService->update($input);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function deleteRedirect($request) {
        $input = $this->requestParser->redirectId($request);
        if ($input instanceof \WP_Error) {
            return $input;
        }

        return $this->mutationService->trash($input);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function getCaptured($request) {
        return $this->readService->captured($this->requestParser->pagination($request));
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function createRedirectFromCaptured($request) {
        $input = $this->requestParser->capturedRedirect($request);
        if ($input instanceof \WP_Error) {
            return $input;
        }

        return $this->mutationService->promoteCaptured($input);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function getStats($request) {
        return $this->readService->stats();
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function getLogs($request) {
        return $this->readService->logs($this->requestParser->pagination($request));
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function testRedirect($request) {
        $input = $this->requestParser->testRedirect($request);
        if ($input instanceof \WP_Error) {
            return $input;
        }

        return $this->readService->testRedirect($input);
    }

}
