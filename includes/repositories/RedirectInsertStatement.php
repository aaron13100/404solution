<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SQL and bound values for one redirect insert.
 *
 * Owns the persistence-level distinction between an ordinary insert and the
 * atomic "insert only when this source is absent" form used by canonical
 * evidence capture.
 *
 * // allow-no-test-found: exercised by RedirectWriteServiceUpdateAtomicityTest
 */
final class ABJ_404_Solution_RedirectInsertStatement {

    /** @var string */
    private $sql;

    /** @var array<int, mixed> */
    private $params;

    /**
     * @param string $sql
     * @param array<int, mixed> $params
     */
    private function __construct(string $sql, array $params) {
        $this->sql = $sql;
        $this->params = $params;
    }

    /**
     * @param array{table: string, sourceUrl: string, status: int|string, type: int|string,
     *        finalDest: string, code: int|string, disabled: int, timestamp: int,
     *        engine: string|null, score: float|null, liveColumns: ABJ_404_Solution_RedirectsLiveColumnSet,
     *        requireAbsentSource: bool} $request
     */
    public static function fromRequest(array $request): self {
        $table = $request['table'];
        $data = array(
            'url' => $request['sourceUrl'],
            'status' => $request['status'],
            'type' => $request['type'],
            'final_dest' => $request['finalDest'],
            'code' => $request['code'],
            'disabled' => $request['disabled'],
            'timestamp' => $request['timestamp'],
        );
        $formats = array('%s', '%d', '%d', '%s', '%d', '%d', '%d');
        $liveColumns = $request['liveColumns'];
        $liveColumns->appendIfPresent($data, $formats, 'canonical_url',
            ABJ_404_Solution_RedirectCanonicalUrl::compute($request['sourceUrl']), '%s');
        if ($request['engine'] !== null) {
            $liveColumns->appendIfPresent($data, $formats, 'engine',
                substr($request['engine'], 0, 64), '%s');
        }
        if ($request['score'] !== null) {
            $liveColumns->appendIfPresent($data, $formats, 'score', round($request['score'], 2), '%f');
        }

        $sql = "INSERT INTO `" . $table . "` (`" .
            implode('`, `', array_keys($data)) . "`) ";
        $params = array_values($data);

        if ($request['requireAbsentSource']) {
            // INSERT...SELECT makes the indexed range check and insert one
            // database operation; a retried deadlock loser observes the winner.
            $sql .= "SELECT " . implode(', ', $formats) . " FROM DUAL " .
                "WHERE NOT EXISTS (SELECT 1 FROM `" . $table . "` " .
                "WHERE `url` = %s LIMIT 1)";
            $params[] = $request['sourceUrl'];
        } else {
            $sql .= "VALUES (" . implode(', ', $formats) . ")";
        }

        return new self($sql, $params);
    }

    public function sql(): string {
        return $this->sql;
    }

    /** @return array<int, mixed> */
    public function params(): array {
        return $this->params;
    }
}
