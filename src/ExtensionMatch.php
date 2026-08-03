<?php

/**
 * Adapts a match payload sent by the Chrome extension (Scope Tap) into the
 * exact match_data shape produced by MatchParser::buildMatch(), so ingested
 * matches drop straight into the existing pipeline (StatisticsCalculator,
 * get-statistics, get-matches, get-match-data) with no new read code and no
 * new tables.
 *
 * Pure and I/O-free (like Draft.php) so it can be unit-tested; the API layer
 * wires it to Db::upsertMatch. validate() throws InvalidArgumentException on
 * bad input, which the handler maps to a 400.
 *
 * Field-mapping notes (the non-obvious ones):
 *   - steamId64 is kept verbatim as a STRING and never cast — it exceeds
 *     Number.MAX_SAFE_INTEGER, so any (int)/parseInt would corrupt its tail.
 *   - kastPercent is already a percentage (0..100); we do NOT multiply by 100
 *     (MatchParser multiplies because scope.gg gives it a 0..1 fraction).
 *   - rating2 -> hltvRating, kastPercent -> kast, map "de_x" -> "x".
 *   - score[] is aligned with teams[]; we re-key it by team number.
 */
class ExtensionMatch
{
    /** Canonical scope.gg match URL base — mirrors MatchParser::$baseUrl. */
    private const BASE_URL = 'https://app.scope.gg/matches/';

    /**
     * Validate the raw decoded webhook body. Throws InvalidArgumentException
     * with a human-readable message on the first problem found.
     */
    public static function validate(array $body): void
    {
        if (self::extractId($body) === '') {
            throw new InvalidArgumentException('matchId is required');
        }

        $data = $body['data'] ?? null;
        if (!is_array($data)) {
            throw new InvalidArgumentException('data object is required');
        }

        $score = $data['score'] ?? null;
        if (!is_array($score) || !is_numeric($score[0] ?? null) || !is_numeric($score[1] ?? null)) {
            throw new InvalidArgumentException('data.score must be [number, number]');
        }

        $teams = $data['teams'] ?? null;
        if (!is_array($teams) || count($teams) < 2) {
            throw new InvalidArgumentException('data.teams must have 2 entries');
        }

        $players = $data['players'] ?? null;
        if (!is_array($players) || count($players) === 0) {
            throw new InvalidArgumentException('data.players must be a non-empty array');
        }

        foreach ($players as $i => $p) {
            if (!is_array($p)) {
                throw new InvalidArgumentException("data.players[$i] must be an object");
            }
            // Must arrive as a string: it is larger than MAX_SAFE_INTEGER and a
            // numeric JSON value could already have lost precision upstream.
            if (!is_string($p['steamId64'] ?? null) || ($p['steamId64'] ?? '') === '') {
                throw new InvalidArgumentException("data.players[$i].steamId64 must be a non-empty string");
            }
            $ti = $p['teamIndex'] ?? null;
            if ($ti !== 0 && $ti !== 1) {
                throw new InvalidArgumentException("data.players[$i].teamIndex must be 0 or 1");
            }
        }
    }

    /**
     * Build the persistence payload for Db::upsertMatch. Assumes validate()
     * has passed but stays defensive with null-safe access.
     *
     * @return array{id:string,url:string,map:?string,score:array,matchData:array,matchTime:?string}
     */
    public static function toMatchData(array $body): array
    {
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $id   = self::extractId($body);
        $map  = self::normalizeMap($data['map'] ?? null);

        $playedAt = (isset($data['playedAt']) && is_string($data['playedAt']) && $data['playedAt'] !== '')
            ? $data['playedAt']
            : null;

        $teamNames   = array_values(is_array($data['teams'] ?? null) ? $data['teams'] : []);
        $teamNumbers = self::resolveTeamNumbers($teamNames);

        // score[i] aligns with teams[i]; re-key by resolved team number so the
        // sides never get swapped or collapsed.
        $rawScore = array_values(is_array($data['score'] ?? null) ? $data['score'] : []);
        $score = ['team1' => 0, 'team2' => 0];
        foreach ([0, 1] as $i) {
            $score['team' . $teamNumbers[$i]] = (int) round((float) ($rawScore[$i] ?? 0));
        }

        // Group players into their two teams, preserving order.
        $playersByTeam = [0 => [], 1 => []];
        foreach ((is_array($data['players'] ?? null) ? $data['players'] : []) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $ti = (($p['teamIndex'] ?? 0) === 1) ? 1 : 0;
            $playersByTeam[$ti][] = self::buildPlayer($p);
        }

        $teams = [];
        foreach ([0, 1] as $i) {
            $tn    = $teamNumbers[$i];
            $other = ($tn === 1) ? 2 : 1;
            $teams[] = [
                'teamNumber' => $tn,
                'name'       => (string) ($teamNames[$i] ?? ''),
                'won'        => $score['team' . $tn] > $score['team' . $other],
                'players'    => $playersByTeam[$i],
            ];
        }
        usort($teams, fn($a, $b) => $a['teamNumber'] <=> $b['teamNumber']);

        $matchData = [
            'matchId'      => $id,
            'map'          => $map ?? 'unknown',
            'score'        => $score,
            'matchTime'    => self::isoToMillis($playedAt),
            'matchTimeIso' => $playedAt,
            'isCs2'        => (bool) ($data['isCS2'] ?? false),
            'roundsInHalf' => (int) ($data['roundsInHalf'] ?? 12),
            'teams'        => $teams,
            // The extension has no scope.gg RoundInfos; keep the key present but
            // empty, and preserve the source rounds verbatim under `rounds`.
            'roundInfos'   => [],

            // Extension-only extras — preserved so nothing from the source is
            // lost even though the current pipeline doesn't read them.
            'rounds'     => array_values(is_array($data['rounds'] ?? null) ? $data['rounds'] : []),
            'mapDisplay' => (isset($data['mapDisplay']) && is_string($data['mapDisplay'])) ? $data['mapDisplay'] : null,
            'source'     => (isset($body['source']) && is_string($body['source'])) ? $body['source'] : null,
            'capturedAt' => (isset($body['capturedAt']) && is_string($body['capturedAt'])) ? $body['capturedAt'] : null,
        ];

        return [
            'id'        => $id,
            'url'       => self::BASE_URL . $id,
            'map'       => $map,
            'score'     => $score,
            'matchData' => $matchData,
            'matchTime' => $playedAt,
        ];
    }

    /**
     * Map one extension player onto the scraper's player shape. Legacy fields
     * (kills..tradeKills, adr, hltvRating, kast) are what StatisticsCalculator
     * consumes; the rest are carried through verbatim.
     */
    private static function buildPlayer(array $p): array
    {
        return [
            // StatisticsCalculator / existing frontend compatible
            'playerId'   => (string) ($p['playerId'] ?? ''),   // number -> string
            'name'       => (string) ($p['name'] ?? ''),
            'avatar'     => (string) ($p['avatar'] ?? ''),
            'kills'      => (int) ($p['kills'] ?? 0),
            'deaths'     => (int) ($p['deaths'] ?? 0),
            'assists'    => (int) ($p['assists'] ?? 0),
            'damage'     => (int) ($p['damage'] ?? 0),
            'adr'        => round((float) ($p['adr'] ?? 0), 2),
            'hltvRating' => round((float) ($p['rating2'] ?? 0), 2),   // rating2 -> hltvRating
            'kast'       => (int) round((float) ($p['kastPercent'] ?? 0)), // already 0..100
            'openKills'  => (int) ($p['openKills'] ?? 0),
            'tradeKills' => (int) ($p['tradeKills'] ?? 0),
            'mvp'          => (int) ($p['mvp'] ?? 0),
            'clutchesWon'  => (int) ($p['clutchesWon'] ?? 0),
            'roundsPlayed' => (int) ($p['roundsPlayed'] ?? 0),

            // Extension extras — steamId64 stays a raw string (never cast).
            'steamId64'       => (string) ($p['steamId64'] ?? ''),
            'favouriteWeapon' => (isset($p['favouriteWeapon']) && is_string($p['favouriteWeapon'])) ? $p['favouriteWeapon'] : null,
            'hsPercentRifle'  => isset($p['hsPercentRifle']) ? round((float) $p['hsPercentRifle'], 2) : null,
            'teamIndex'       => (($p['teamIndex'] ?? 0) === 1) ? 1 : 0,
            'teamName'        => (string) ($p['teamName'] ?? ''),
            'won'             => (bool) ($p['won'] ?? false),
        ];
    }

    /** matchId is a string; accept it top-level or nested under data. */
    private static function extractId(array $body): string
    {
        $id = $body['matchId'] ?? ($body['data']['matchId'] ?? null);
        if (is_string($id)) {
            return trim($id);
        }
        if (is_int($id)) {
            return (string) $id;
        }
        return '';
    }

    /** "de_mirage" -> "mirage"; empty -> null. Mirrors MatchParser::normalizeMap. */
    private static function normalizeMap($raw): ?string
    {
        $raw = strtolower(trim((string) ($raw ?? '')));
        if ($raw === '') {
            return null;
        }
        return preg_replace('/^de_/', '', $raw);
    }

    /**
     * Resolve a team number (1/2) per team index. Primary heuristic matches the
     * scraper (name containing "2" -> 2), but falls back to positional order
     * whenever that doesn't yield a clean 1/2 split, so the two sides can never
     * collapse onto the same number.
     *
     * @return array{0:int,1:int}
     */
    private static function resolveTeamNumbers(array $teamNames): array
    {
        $byName = [];
        foreach ([0, 1] as $i) {
            $name = (string) ($teamNames[$i] ?? '');
            $byName[$i] = (strpos($name, '2') !== false) ? 2 : 1;
        }
        if ($byName[0] !== $byName[1]) {
            return [0 => $byName[0], 1 => $byName[1]];
        }
        return [0 => 1, 1 => 2];
    }

    /** ISO-8601 string -> epoch milliseconds (preserving ms), or null. */
    private static function isoToMillis(?string $iso): ?int
    {
        if (!is_string($iso) || $iso === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($iso);
        } catch (Throwable $e) {
            return null;
        }
        return ((int) $dt->format('U')) * 1000 + (int) $dt->format('v');
    }
}
