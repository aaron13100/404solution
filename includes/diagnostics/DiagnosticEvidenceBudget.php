<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which line indexes survive a hard byte ceiling, given an already
 * priority-ordered list of request groups.
 *
 * ABJ_404_Solution_DiagnosticEvidencePriority decides WHO is prioritized and
 * in what order; this class decides, mechanically, how many of each
 * prioritized group's bytes actually fit. A request with no terminal event is
 * granted its full record run before any reserve is split across the rest,
 * because its middle is the only account of a stall (report 193: a
 * 165-second holder with no request_end had 7 mid-flight records dropped by
 * an even split before this ordering existed). Every other group competes
 * for whatever budget remains and loses its own middle first when it does
 * not fully fit, because a terminal event anchors its tail as "where it
 * stopped" and its middle is comparatively spendable.
 */
final class ABJ_404_Solution_DiagnosticEvidenceBudget {

    /**
     * Grant budget in priority order. Each prioritized request still to come
     * holds a reserved share, so an early one can use everything that is not
     * reserved but can never consume a later failure's guarantee.
     *
     * @param array<int, string> $lines
     * @param array<string, ABJ_404_Solution_DiagnosticRequestGroup> $groups
     * @param array<int, string> $ordered
     * @return array{lines: array<int, string>, requests: int, records: int, bytes: int,
     *     includedIds: array<string, bool>, elided: int}
     */
    public static function allocate(array $lines, array $groups, array $ordered,
            int $prioritizedCount, int $budgetBytes): array {
        $remaining = max(0, $budgetBytes);
        $keptIndexes = array();
        $notes = array();
        $included = array();
        $elided = 0;

        $grantedWhole = array();
        foreach ($ordered as $position => $id) {
            if ($position >= $prioritizedCount || !$groups[$id]->isMaximallyDecisive()) {
                continue;
            }
            $bytes = $groups[$id]->bytes();
            if ($bytes > $remaining) {
                // Does not even fit in what is left of the whole budget;
                // fall through to the ordinary allowance-based picking below
                // rather than dropping it outright.
                continue;
            }
            foreach ($groups[$id]->indexes() as $index) {
                $keptIndexes[$index] = true;
            }
            $remaining -= $bytes;
            $included[$id] = true;
            $grantedWhole[$id] = true;
        }

        $rest = array();
        $prioritizedLeft = 0;
        foreach ($ordered as $position => $id) {
            if (isset($grantedWhole[$id])) {
                continue;
            }
            $isPrioritized = $position < $prioritizedCount;
            if ($isPrioritized) {
                $prioritizedLeft++;
            }
            $rest[] = array('id' => $id, 'prioritized' => $isPrioritized);
        }
        $reservePerRequest = $prioritizedLeft > 0 ? intdiv(max(0, $remaining), $prioritizedLeft) : 0;

        foreach ($rest as $entry) {
            $id = $entry['id'];
            if ($entry['prioritized']) {
                $prioritizedLeft--;
            }
            $heldBack = $entry['prioritized'] ? ($prioritizedLeft * $reservePerRequest) : 0;
            $allowance = max(0, $remaining - $heldBack);
            if ($allowance <= 0) {
                continue;
            }
            $picked = self::pickWithinAllowance($lines, $groups[$id]->indexes(), $allowance, $id);
            if ($picked['indexes'] === array()) {
                continue;
            }
            foreach ($picked['indexes'] as $index) {
                $keptIndexes[$index] = true;
            }
            $remaining -= $picked['bytes'];
            $included[$id] = true;
            $elided += $picked['elided'];
            if ($picked['elided'] > 0) {
                $notes[max($picked['indexes'])] = self::elisionNote($id, $picked['elided']);
            }
        }

        ksort($keptIndexes);
        $out = array();
        $records = 0;
        foreach (array_keys($keptIndexes) as $index) {
            $out[] = $lines[$index];
            $records++;
            if (isset($notes[$index])) {
                $out[] = $notes[$index];
            }
        }

        return array(
            'lines' => $out,
            'requests' => count($included),
            'records' => $records,
            'bytes' => max(0, $budgetBytes) - $remaining,
            'includedIds' => $included,
            'elided' => $elided,
        );
    }

    /**
     * The line indexes of one request that fit in $allowance. When the whole
     * request does not fit, records are taken from both ENDS: the opening
     * records carry the environment the request ran in, and the last ones are
     * where it stopped. The middle is what a reader can most afford to lose --
     * true only because a terminal event exists to anchor that tail. A request
     * with no terminal event is granted its full run before this is ever
     * called (see allocate()); this heuristic is backwards for one, because
     * its middle is the only account of the stall, not the least of it.
     *
     * @param array<int, string> $lines
     * @param array<int, int> $indexes
     * @return array{indexes: array<int, int>, bytes: int, elided: int}
     */
    private static function pickWithinAllowance(array $lines, array $indexes, int $allowance,
            string $requestId): array {
        $total = 0;
        foreach ($indexes as $index) {
            $total += strlen($lines[$index]) + 1;
        }
        if ($total <= $allowance) {
            return array('indexes' => $indexes, 'bytes' => $total, 'elided' => 0);
        }

        // The note that will declare the elision costs bytes too, and its
        // length depends on the request id (up to 64 characters) and the
        // count. Reserved at its true upper bound rather than at a guessed
        // constant: a reserve that is one byte short makes the returned block
        // exceed a budget the caller was promised was hard.
        $noteReserve = strlen(self::elisionNote($requestId, PHP_INT_MAX)) + 1;
        $allowance -= $noteReserve;
        $head = array();
        $tail = array();
        $used = $noteReserve;
        $low = 0;
        $high = count($indexes) - 1;
        $fromHead = true;
        while ($low <= $high && $allowance > 0) {
            $index = $fromHead ? $indexes[$low] : $indexes[$high];
            $cost = strlen($lines[$index]) + 1;
            if ($cost > $allowance) {
                if (!$fromHead) {
                    break;
                }
                // The head record did not fit; a shorter tail record still might.
                $fromHead = false;
                continue;
            }
            $allowance -= $cost;
            $used += $cost;
            if ($fromHead) {
                $head[] = $index;
                $low++;
            } else {
                array_unshift($tail, $index);
                $high--;
            }
            $fromHead = !$fromHead;
        }

        $kept = array_merge($head, $tail);
        if ($kept === array()) {
            return array('indexes' => array(), 'bytes' => 0, 'elided' => 0);
        }
        return array('indexes' => $kept, 'bytes' => $used, 'elided' => count($indexes) - count($kept));
    }

    /**
     * A JSON line, so the excerpt stays parseable as JSONL end to end, saying
     * exactly what was dropped and why.
     */
    private static function elisionNote(string $requestId, int $elided): string {
        $note = json_encode(array(
            'abj404_excerpt_note' => 'records elided to fit the support budget',
            'request_id' => $requestId === ABJ_404_Solution_DiagnosticEvidencePriority::UNJOINABLE_KEY
                ? '' : $requestId,
            'elided_records' => $elided,
        ), JSON_UNESCAPED_SLASHES);
        return is_string($note) ? $note : '{"abj404_excerpt_note":"records elided"}';
    }
}
