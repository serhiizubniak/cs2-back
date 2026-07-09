<?php

require_once __DIR__ . '/TeamBalancer.php';

/**
 * Captains draft: two random captains take turns (snake order) picking from a
 * shared pool of eight players. Self-contained and free of I/O so it can be
 * unit-tested — the API layer only wires it to the row-locked team mutation.
 */
class Draft {
    /** Snake pick order for 8 picks: each captain ends up with 4 (+self = 5v5). */
    const ORDER = ['A', 'B', 'B', 'A', 'A', 'B', 'B', 'A'];

    /** Voter key of a player row: playerId when present, otherwise the name. */
    public static function keyOf(array $player): string {
        $key = $player['playerId'] ?? null;
        if ($key === null || $key === '') {
            $key = $player['name'] ?? '';
        }
        return (string) $key;
    }

    /**
     * Seeds a fresh draft from exactly 10 resolved player rows: shuffles, takes
     * the first two as captains (the coin flip — A picks first), the rest pool.
     */
    public static function build(array $players): array {
        if (count($players) !== 10) {
            throw new InvalidArgumentException('A draft needs exactly 10 players');
        }
        shuffle($players);
        $captainA = $players[0];
        $captainB = $players[1];
        return [
            'status'    => 'active',
            'order'     => self::ORDER,
            'pickIndex' => 0,
            'captains'  => ['A' => $captainA, 'B' => $captainB],
            'teamA'     => [$captainA],
            'teamB'     => [$captainB],
            'pool'      => array_values(array_slice($players, 2)),
            'coinFlip'  => self::keyOf($captainA),
            'startedAt' => date('c'),
        ];
    }

    /**
     * The current captain drafts one player from the pool. Enforces turn order.
     * @throws InvalidArgumentException on a forbidden action (map to HTTP 403)
     * @throws RuntimeException          on an invalid state (map to HTTP 409)
     */
    public static function applyPick(array $composition, string $voterId, string $playerKey): array {
        $draft = $composition['draft'] ?? null;
        if (!is_array($draft)) {
            throw new RuntimeException('This team has no draft');
        }
        if (($draft['status'] ?? '') !== 'active') {
            throw new RuntimeException('Draft is already finished');
        }
        $order = is_array($draft['order'] ?? null) ? $draft['order'] : self::ORDER;
        $idx   = (int) ($draft['pickIndex'] ?? 0);
        if ($idx >= count($order)) {
            throw new RuntimeException('Draft is already finished');
        }

        // Only the captain whose turn it is may pick right now.
        $side    = $order[$idx];
        $captain = $draft['captains'][$side] ?? null;
        if (!is_array($captain) || self::keyOf($captain) !== $voterId) {
            throw new InvalidArgumentException('It is not your turn to pick');
        }

        // The player must still be in the pool.
        $pool  = is_array($draft['pool'] ?? null) ? $draft['pool'] : [];
        $found = null;
        $rest  = [];
        foreach ($pool as $p) {
            if ($found === null && self::keyOf((array) $p) === $playerKey) {
                $found = $p;
            } else {
                $rest[] = $p;
            }
        }
        if ($found === null) {
            throw new InvalidArgumentException('That player is not available');
        }

        $draft['pool']   = array_values($rest);
        $bucket          = $side === 'A' ? 'teamA' : 'teamB';
        $draft[$bucket]  = array_values(array_merge($draft[$bucket] ?? [], [$found]));
        $draft['pickIndex']  = $idx + 1;
        $draft['lastPickAt'] = date('c');

        // Last pick — freeze the draft and materialise the final rosters so the
        // rest of the team page (map vote, swaps) picks up from here.
        if ($draft['pickIndex'] >= count($order)) {
            $draft['status'] = 'complete';
            $composition['teams'] = self::ratings(
                array_values($draft['teamA'] ?? []),
                array_values($draft['teamB'] ?? [])
            );
        }

        $composition['draft'] = $draft;
        return $composition;
    }

    /**
     * Aborts a draft (captain or listed admin) and falls back to the
     * auto-balancer over all 10 players — the rescue for an AFK captain.
     */
    public static function cancel(array $composition, string $voterId, array $adminIds = []): array {
        $draft = $composition['draft'] ?? null;
        if (!is_array($draft)) {
            throw new RuntimeException('This team has no draft');
        }
        if (!self::canControl($draft, $voterId, $adminIds)) {
            throw new InvalidArgumentException('Only a captain or admin can cancel the draft');
        }
        $all = array_merge(
            $draft['teamA'] ?? [],
            $draft['teamB'] ?? [],
            $draft['pool']  ?? []
        );
        if (count($all) !== 10) {
            throw new RuntimeException('Draft roster is incomplete');
        }
        $balancer = new TeamBalancer();
        $composition['teams'] = $balancer->improveBalance($balancer->balanceTeams($all));
        unset($composition['draft']);
        return $composition;
    }

    /** Whether this voter may cancel the draft: a captain or a listed admin. */
    public static function canControl(array $draft, string $voterId, array $adminIds = []): bool {
        if (in_array($voterId, $adminIds, true)) {
            return true;
        }
        foreach (['A', 'B'] as $side) {
            $captain = $draft['captains'][$side] ?? null;
            if (is_array($captain) && self::keyOf($captain) === $voterId) {
                return true;
            }
        }
        return false;
    }

    /** Builds the final `teams` aggregate the team page expects. */
    private static function ratings(array $teamA, array $teamB): array {
        $sum = function (array $players): float {
            $t = 0.0;
            foreach ($players as $p) {
                $t += (float) ($p['hltvRating'] ?? 0);
            }
            return $t;
        };
        $ratingA = $sum($teamA);
        $ratingB = $sum($teamB);
        $avgA    = count($teamA) > 0 ? $ratingA / count($teamA) : 0.0;
        $avgB    = count($teamB) > 0 ? $ratingB / count($teamB) : 0.0;
        return [
            'team1'               => $teamA,
            'team2'               => $teamB,
            'team1Rating'         => $ratingA,
            'team2Rating'         => $ratingB,
            'team1AvgRating'      => $avgA,
            'team2AvgRating'      => $avgB,
            'ratingDifference'    => abs($ratingA - $ratingB),
            'avgRatingDifference' => abs($avgA - $avgB),
        ];
    }
}
