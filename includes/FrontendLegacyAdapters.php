<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves legacy frontend pipeline collaborators into typed services.
 */
class ABJ_404_Solution_FrontendLegacyAdapters {

    /**
     * @param mixed $logsRepository
     * @param mixed $redirectsRepository
     * @return mixed
     */
    function resolveLogsRepository($logsRepository, $redirectsRepository) {
        if ($logsRepository !== null) {
            return $logsRepository;
        }
        if (is_object($redirectsRepository) && method_exists($redirectsRepository, 'getLogsRepo')) {
            try {
                $resolved = $redirectsRepository->getLogsRepo();
                return ($resolved !== null) ? $resolved : $redirectsRepository;
            } catch (\Throwable $e) {
                // allow-silent-catch: Mockery mocks throw if getLogsRepo() has no expectation;
                // legacy tests may still construct a redirects mock without LogsRepo plumbing.
                return (method_exists($redirectsRepository, 'logRedirectHit'))
                    ? $redirectsRepository
                    : abj_service('logs_repository');
            }
        }
        if (is_object($redirectsRepository) && method_exists($redirectsRepository, 'logRedirectHit')) {
            return $redirectsRepository;
        }
        return abj_service('logs_repository');
    }

    /**
     * @param mixed $notFoundResponse
     * @return ABJ_404_Solution_NotFoundResponseService
     */
    function resolveNotFoundResponse($notFoundResponse) {
        $resolved = $notFoundResponse !== null ? $notFoundResponse : abj_service('not_found_response');
        if ($resolved instanceof ABJ_404_Solution_NotFoundResponseService) {
            return $resolved;
        }
        if (is_object($resolved)) {
            $forceRedirect = array($resolved, 'forceRedirect');
            $sendTo404Page = array($resolved, 'sendTo404Page');
            $thereIsAUserSpecified404Page = array($resolved, 'thereIsAUserSpecified404Page');
            if (is_callable($forceRedirect)
                    && is_callable($sendTo404Page)
                    && is_callable($thereIsAUserSpecified404Page)) {
                return $this->adaptNotFoundResponse($forceRedirect, $sendTo404Page, $thereIsAUserSpecified404Page);
            }
        }
        throw new InvalidArgumentException('FrontendRequestPipeline requires NotFoundResponseService.');
    }

    /**
     * @param mixed $requestIgnoreNormalizer
     * @param ABJ_404_Solution_LogsRepositoryInterface|null $logsRepository
     * @param ABJ_404_Solution_Functions $functions
     * @param ABJ_404_Solution_Logging $logging
     * @param ABJ_404_Solution_RedirectsRepositoryInterface $redirectsRepository
     * @param ABJ_404_Solution_NotFoundResponseService $notFoundResponse
     * @return ABJ_404_Solution_RequestIgnoreNormalizer
     */
    function resolveRequestIgnoreNormalizer($requestIgnoreNormalizer, $logsRepository, $functions, $logging, $redirectsRepository, $notFoundResponse) {
        if ($requestIgnoreNormalizer instanceof ABJ_404_Solution_RequestIgnoreNormalizer) {
            return $requestIgnoreNormalizer;
        }
        if (is_object($requestIgnoreNormalizer)) {
            $initializeIgnoreValues = array($requestIgnoreNormalizer, 'initializeIgnoreValues');
            $tryNormalPostQuery = array($requestIgnoreNormalizer, 'tryNormalPostQuery');
            if (is_callable($initializeIgnoreValues) && is_callable($tryNormalPostQuery)) {
                return $this->adaptRequestIgnoreNormalizer($initializeIgnoreValues, $tryNormalPostQuery);
            }
        }
        return new ABJ_404_Solution_RequestIgnoreNormalizer(
            abj_service('options_repository'),
            $functions,
            $logging,
            $redirectsRepository,
            $logsRepository,
            $notFoundResponse
        );
    }

    /**
     * @param mixed $previousRequestCookieTracker
     * @param ABJ_404_Solution_Logging $logging
     * @return ABJ_404_Solution_PreviousRequestCookieTracker
     */
    function resolvePreviousRequestCookieTracker($previousRequestCookieTracker, $logging) {
        if ($previousRequestCookieTracker instanceof ABJ_404_Solution_PreviousRequestCookieTracker) {
            return $previousRequestCookieTracker;
        }
        if (is_object($previousRequestCookieTracker)) {
            $setCookieWithPreviousRequest = array($previousRequestCookieTracker, 'setCookieWithPreviousRequest');
            $readCookieWithPreviousRqeuestShort = array($previousRequestCookieTracker, 'readCookieWithPreviousRqeuestShort');
            if (is_callable($setCookieWithPreviousRequest) && is_callable($readCookieWithPreviousRqeuestShort)) {
                return $this->adaptPreviousRequestCookieTracker($readCookieWithPreviousRqeuestShort, $setCookieWithPreviousRequest);
            }
        }
        return new ABJ_404_Solution_PreviousRequestCookieTracker($logging);
    }

    /**
     * @param callable $forceRedirect
     * @param callable $sendTo404Page
     * @param callable $thereIsAUserSpecified404Page
     * @return ABJ_404_Solution_NotFoundResponseService
     */
    private function adaptNotFoundResponse($forceRedirect, $sendTo404Page, $thereIsAUserSpecified404Page) {
        return new class($forceRedirect, $sendTo404Page, $thereIsAUserSpecified404Page) extends ABJ_404_Solution_NotFoundResponseService {
            /** @var callable */
            private $forceRedirect;
            /** @var callable */
            private $sendTo404Page;
            /** @var callable */
            private $thereIsAUserSpecified404Page;

            function __construct(callable $forceRedirect, callable $sendTo404Page, callable $thereIsAUserSpecified404Page) {
                $this->forceRedirect = $forceRedirect;
                $this->sendTo404Page = $sendTo404Page;
                $this->thereIsAUserSpecified404Page = $thereIsAUserSpecified404Page;
            }

            function forceRedirect(string $location, int $status = 302, $type = -1, string $requestedURL = '', bool $isCustom404 = false): bool {
                return (bool)call_user_func($this->forceRedirect, $location, $status, $type, $requestedURL, $isCustom404);
            }

            function sendTo404Page(string $requestedURL, string $reason = '', bool $useUserSpecified404 = true, $optionsOverride = null): void {
                call_user_func($this->sendTo404Page, $requestedURL, $reason, $useUserSpecified404, $optionsOverride);
            }

            function thereIsAUserSpecified404Page($dest404page): bool {
                return (bool)call_user_func($this->thereIsAUserSpecified404Page, $dest404page);
            }
        };
    }

    /**
     * @param callable $initializeIgnoreValues
     * @param callable $tryNormalPostQuery
     * @return ABJ_404_Solution_RequestIgnoreNormalizer
     */
    private function adaptRequestIgnoreNormalizer($initializeIgnoreValues, $tryNormalPostQuery) {
        return new class($initializeIgnoreValues, $tryNormalPostQuery) extends ABJ_404_Solution_RequestIgnoreNormalizer {
            /** @var callable */
            private $initializeIgnoreValues;
            /** @var callable */
            private $tryNormalPostQuery;

            function __construct(callable $initializeIgnoreValues, callable $tryNormalPostQuery) {
                $this->initializeIgnoreValues = $initializeIgnoreValues;
                $this->tryNormalPostQuery = $tryNormalPostQuery;
            }

            function initializeIgnoreValues(string $urlRequest, string $urlSlugOnly): void {
                call_user_func($this->initializeIgnoreValues, $urlRequest, $urlSlugOnly);
            }

            function tryNormalPostQuery(array $options): void {
                call_user_func($this->tryNormalPostQuery, $options);
            }
        };
    }

    /**
     * @param callable $readCookieWithPreviousRqeuestShort
     * @param callable $setCookieWithPreviousRequest
     * @return ABJ_404_Solution_PreviousRequestCookieTracker
     */
    private function adaptPreviousRequestCookieTracker($readCookieWithPreviousRqeuestShort, $setCookieWithPreviousRequest) {
        return new class($readCookieWithPreviousRqeuestShort, $setCookieWithPreviousRequest) extends ABJ_404_Solution_PreviousRequestCookieTracker {
            /** @var callable */
            private $readCookieWithPreviousRqeuestShort;
            /** @var callable */
            private $setCookieWithPreviousRequest;

            function __construct(callable $readCookieWithPreviousRqeuestShort, callable $setCookieWithPreviousRequest) {
                $this->readCookieWithPreviousRqeuestShort = $readCookieWithPreviousRqeuestShort;
                $this->setCookieWithPreviousRequest = $setCookieWithPreviousRequest;
            }

            function readCookieWithPreviousRqeuestShort(): string {
                $value = call_user_func($this->readCookieWithPreviousRqeuestShort);
                return is_string($value) ? $value : '';
            }

            function setCookieWithPreviousRequest(): void {
                call_user_func($this->setCookieWithPreviousRequest);
            }
        };
    }
}
