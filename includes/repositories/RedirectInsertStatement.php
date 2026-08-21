<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SQL and bound values for one redirect insert.
 *
 * Owns the persistence-level distinction between an ordinary insert and the
 * conditional "insert only when this source is absent" form used by canonical
 * evidence capture. The caller makes that form atomic by executing it in a
 * SERIALIZABLE transaction; a lone INSERT...SELECT is not sufficient under
 * READ COMMITTED isolation.
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
     *        canonicalUrl: string,
     *        engine: string|null, score: float|null, liveColumns: ABJ_404_Solution_RedirectsLiveColumnSet,
     *        requireAbsentSource: bool} $request
     */
    public static function fromRequest(array $request): self {
        $table = $request['table'];
        $insertData = array(
            'url' => $request['sourceUrl'],
            'status' => $request['status'],
            'type' => $request['type'],
            'final_dest' => $request['finalDest'],
            'code' => $request['code'],
            'disabled' => $request['disabled'],
            'timestamp' => $request['timestamp'],
        );
        $insertFormats = array('%s', '%d', '%d', '%s', '%d', '%d', '%d');
        $liveColumns = $request['liveColumns'];
        $optionalCandidates = array(array(
            'columnName' => 'canonical_url',
            'value' => $request['canonicalUrl'],
            'format' => '%s',
        ));
        if ($request['engine'] !== null) {
            $optionalCandidates[] = array(
                'columnName' => 'engine',
                'value' => substr($request['engine'], 0, 64),
                'format' => '%s',
            );
        }
        if ($request['score'] !== null) {
            $optionalCandidates[] = array(
                'columnName' => 'score',
                'value' => round($request['score'], 2),
                'format' => '%f',
            );
        }
        foreach ($optionalCandidates as $candidate) {
            $presentCandidate = $liveColumns->candidateIfPresent($candidate);
            if ($presentCandidate === null) {
                continue;
            }
            $insertData[$presentCandidate['columnName']] = $presentCandidate['value'];
            $insertFormats[] = $presentCandidate['format'];
        }

        $sql = "INSERT INTO `" . $table . "` (`" .
            implode('`, `', array_keys($insertData)) . "`) ";
        $params = array_values($insertData);

        if ($request['requireAbsentSource']) {
            // The transaction executor runs this indexed absence check under
            // SERIALIZABLE isolation. Concurrent callers therefore cannot
            // both pass the range check; a retried deadlock loser observes the
            // winner without imposing a UNIQUE(url) constraint that would also
            // forbid intentional overlapping manual/regex redirect rows.
            $sql .= "SELECT " . implode(', ', $insertFormats) . " FROM DUAL " .
                "WHERE NOT EXISTS (SELECT 1 FROM `" . $table . "` " .
                "WHERE `url` = %s LIMIT 1)";
            $params[] = $request['sourceUrl'];
        } else {
            $sql .= "VALUES (" . implode(', ', $insertFormats) . ")";
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
