<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the visit link and title for a redirect row destination.
 */
class ABJ_404_Solution_RedirectDestinationLinkResolver {

    /** @var ABJ_404_Solution_Logging */
    private $logger;

    public function __construct(ABJ_404_Solution_Logging $logger) {
        $this->logger = $logger;
    }

    /**
     * @param mixed $rowType
     * @param string $rowFinalDest
     * @return array{link: string, title: string}
     */
    public function resolve($rowType, string $rowFinalDest): array {
        $handlers = $this->handlers();
        $key = is_scalar($rowType) ? (int)$rowType : -1;
        if (isset($handlers[$key])) {
            return $handlers[$key]($rowFinalDest);
        }

        // A corrupt redirect row with an unrecognized type is tolerable bad
        // data while rendering the table: we degrade to a bare "Visit" link.
        // Per Defensive Coding Philosophy #8 log this at WARNING level rather
        // than errorMessage() (which fires a production telemetry report).
        $this->logger->warn('Unexpected row type while displaying table: ' . $key);
        return array('link' => '', 'title' => __('Visit', '404-solution') . ' ');
    }

    /** @return array<int, callable(string): array{link: string, title: string}> */
    private function handlers(): array {
        return array(
            ABJ404_TYPE_EXTERNAL => function(string $rowFinalDest): array {
                $title = __('Visit', '404-solution') . ' ';
                if ($rowFinalDest === '') {
                    return array('link' => '', 'title' => $title);
                }
                return array('link' => $rowFinalDest, 'title' => $title . $rowFinalDest);
            },
            ABJ404_TYPE_CAT => function(string $rowFinalDest): array {
                return $this->taxonomyDestination($rowFinalDest, ABJ404_TYPE_CAT, __('Category:', '404-solution'));
            },
            ABJ404_TYPE_TAG => function(string $rowFinalDest): array {
                return $this->taxonomyDestination($rowFinalDest, ABJ404_TYPE_TAG, __('Tag:', '404-solution'));
            },
            ABJ404_TYPE_HOME => function(string $rowFinalDest): array {
                return $this->permalinkDestination($rowFinalDest, ABJ404_TYPE_HOME, __('Home Page:', '404-solution') . ' ');
            },
            ABJ404_TYPE_POST => function(string $rowFinalDest): array {
                if ($rowFinalDest === '') {
                    return array('link' => '', 'title' => __('Visit', '404-solution') . ' ');
                }
                return $this->permalinkDestination($rowFinalDest, ABJ404_TYPE_POST, '');
            },
            ABJ404_TYPE_404_DISPLAYED => function(string $rowFinalDest): array {
                $result = $this->permalinkDestination($rowFinalDest, ABJ404_TYPE_404_DISPLAYED, '');
                if ($rowFinalDest == '0') {
                    $result['link'] = '';
                }
                return $result;
            },
        );
    }

    /** @return array{link: string, title: string} */
    private function taxonomyDestination(string $rowFinalDest, int $type, string $label): array {
        if ($rowFinalDest === '') {
            return array('link' => '', 'title' => __('Visit', '404-solution') . ' ');
        }
        return $this->permalinkDestination($rowFinalDest, $type, $label . ' ');
    }

    /** @return array{link: string, title: string} */
    private function permalinkDestination(string $rowFinalDest, int $type, string $titlePrefix): array {
        $permalink = ABJ_404_Solution_PermalinkResolver::permalinkInfoToArray($rowFinalDest . '|' . $type, 0);
        $link = is_string($permalink['link']) ? $permalink['link'] : '';
        $title = is_string($permalink['title']) ? $permalink['title'] : '';
        return array('link' => $link, 'title' => __('Visit', '404-solution') . ' ' . $titlePrefix . $title);
    }
}
