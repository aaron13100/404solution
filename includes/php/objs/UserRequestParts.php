<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable parameter object bundling the parsed pieces of an incoming user
 * request, passed to {@see ABJ_404_Solution_UserRequest::__construct()}.
 *
 * Replaces a 5-positional-parameter constructor (criterion 220 Interface
 * Size). Fields are read directly by the consumer; this is a plain data
 * carrier with no behavior.
 */
// allow-no-test-found: pure parameter-object DTO (typed public fields, constructor-only assignment, no behavior); fields consumed by ABJ_404_Solution_UserRequest, exercised in UserRequestTest.
class ABJ_404_Solution_UserRequestParts {

    /** @var string */
    public $requestURI;

    /** @var array<string, int|string> */
    public $urlParts;

    /** @var string */
    public $urlWithoutCommentPage;

    /** @var string */
    public $commentPagePart;

    /** @var string */
    public $queryString;

    /**
     * @param string $requestURI
     * @param array<string, int|string> $urlParts
     * @param string $urlWithoutCommentPage
     * @param string $commentPagePart
     * @param string $queryString
     */
    public function __construct(string $requestURI, array $urlParts, string $urlWithoutCommentPage,
            string $commentPagePart, string $queryString) {
        $this->requestURI = $requestURI;
        $this->urlParts = $urlParts;
        $this->urlWithoutCommentPage = $urlWithoutCommentPage;
        $this->commentPagePart = $commentPagePart;
        $this->queryString = $queryString;
    }
}
