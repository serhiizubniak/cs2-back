<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/MatchParser.php';
require_once __DIR__ . '/../src/StatisticsCalculator.php';
require_once __DIR__ . '/../src/TeamBalancer.php';
require_once __DIR__ . '/../src/Draft.php';

function fail(int $code, string $error, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['success' => false, 'error' => $error], $extra));
}

function jokerPlayerRow(array $joker): array {
    return [
        'name'        => $joker['name'] ?? 'Joker',
        'playerId'    => $joker['id'] ?? null,
        'avatar'      => $joker['avatar'] ?? 'https://api.dicebear.com/7.x/shapes/svg?seed=Joker&backgroundColor=6366f1&shape1Color=8b5cf6&shape2Color=a855f7',
        'matches'     => 0,
        'kills'       => 0,
        'deaths'      => 0,
        'assists'     => 0,
        'kd'          => 0,
        'damage'      => 0,
        'avgDamage'   => 0,
        'adr'         => 0,
        'hltvRating'  => (float) ($joker['rating'] ?? 1.0),
        'kast'        => 0,
        'openKills'   => 0,
        'tradeKills'  => 0,
        'isJoker'     => true,
    ];
}

function countTotalPlayers(array $teams): int {
    $count = 0;
    foreach ($teams as $team) {
        $count += count($team['players'] ?? []);
    }
    return $count;
}

/** Active map pool for the map vote on the team page. */
const MAP_POOL = [
    'de_dust2', 'de_mirage', 'de_inferno', 'de_nuke',
    'de_overpass', 'de_ancient', 'de_anubis', 'de_cache',
];

/**
 * Collects the identity keys of every player in a team composition —
 * playerId when present, otherwise the name. Used to verify that a map
 * vote comes from someone who is actually on one of the two teams.
 */
function teamVoterKeys(array $composition): array {
    $keys = [];
    foreach (['team1', 'team2'] as $side) {
        foreach (($composition['teams'][$side] ?? []) as $player) {
            $key = $player['playerId'] ?? null;
            if ($key === null || $key === '') {
                $key = $player['name'] ?? null;
            }
            if ($key !== null && $key !== '') {
                $keys[] = (string) $key;
            }
        }
    }
    return $keys;
}

/** Voter key of a single player row — playerId when present, else name. */
function playerKey(array $player): string {
    $key = $player['playerId'] ?? null;
    if ($key === null || $key === '') {
        $key = $player['name'] ?? '';
    }
    return (string) $key;
}

/**
 * Recomputes team rating aggregates from the rosters themselves. Used after
 * manual roster edits so the displayed balance always matches the players
 * actually on each side.
 */
function recalcTeamRatings(array $teams): array {
    foreach (['team1', 'team2'] as $side) {
        $players = $teams[$side] ?? [];
        $total   = 0.0;
        foreach ($players as $p) {
            $total += (float) ($p['hltvRating'] ?? 0);
        }
        $teams[$side . 'Rating']    = $total;
        $teams[$side . 'AvgRating'] = count($players) > 0 ? $total / count($players) : 0.0;
    }
    $teams['ratingDifference']    = abs($teams['team1Rating'] - $teams['team2Rating']);
    $teams['avgRatingDifference'] = abs($teams['team1AvgRating'] - $teams['team2AvgRating']);
    return $teams;
}

/** Map-vote limits: every player gets 2 picks (+1 each) and 1 ban (−1). */
const MAX_MAP_PICKS = 2;

/**
 * Players allowed to close/reopen the map vote. Trust-based like the rest
 * of the app, but the stop button only works for these two. Matched by
 * scope.gg playerId first, by roster name as a fallback.
 */
const MAP_VOTE_ADMIN_IDS   = ['190077542', '192104407']; // Василь Стус, Orion
const MAP_VOTE_ADMIN_NAMES = ['Василь Стус', 'Orion'];

/**
 * One voter's ballot: ['picks' => string[], 'ban' => ?string].
 * Legacy shapes (['maps' => [...]] multi-vote and ['map' => ...] single)
 * are read as picks capped at MAX_MAP_PICKS, with no ban.
 */
function voterBallot(array $vote): array {
    if (isset($vote['picks']) || array_key_exists('ban', $vote)) {
        $picks = is_array($vote['picks'] ?? null) ? $vote['picks'] : [];
        $ban   = $vote['ban'] ?? null;
        return [
            'picks' => array_slice(array_values($picks), 0, MAX_MAP_PICKS),
            'ban'   => (is_string($ban) && $ban !== '') ? $ban : null,
        ];
    }
    $legacy = isset($vote['maps']) && is_array($vote['maps'])
        ? $vote['maps']
        : (isset($vote['map']) ? [$vote['map']] : []);
    return ['picks' => array_slice(array_values($legacy), 0, MAX_MAP_PICKS), 'ban' => null];
}

/**
 * Per-map score sheet over the whole pool: picks, bans, score = picks − bans.
 * Ordered by score desc, then fewer bans first (the deterministic part of
 * the ranking — random tie-breaks only happen once, at close time).
 */
function mapVoteScores(array $composition): array {
    $sheet = [];
    foreach (MAP_POOL as $map) {
        $sheet[$map] = ['map' => $map, 'picks' => 0, 'bans' => 0, 'score' => 0];
    }
    foreach (($composition['mapVotes'] ?? []) as $vote) {
        $ballot = voterBallot((array) $vote);
        foreach ($ballot['picks'] as $map) {
            if (isset($sheet[$map])) {
                $sheet[$map]['picks']++;
                $sheet[$map]['score']++;
            }
        }
        $ban = $ballot['ban'];
        if ($ban !== null && isset($sheet[$ban])) {
            $sheet[$ban]['bans']++;
            $sheet[$ban]['score']--;
        }
    }
    $rows = array_values($sheet);
    usort($rows, fn ($a, $b) => [$b['score'], $a['bans']] <=> [$a['score'], $b['bans']]);
    return $rows;
}

/** Whether this roster member may close/reopen the map vote. */
function isMapVoteAdmin(array $composition, string $voterId): bool {
    if (in_array($voterId, MAP_VOTE_ADMIN_IDS, true)) {
        return true;
    }
    foreach (['team1', 'team2'] as $side) {
        foreach (($composition['teams'][$side] ?? []) as $player) {
            if (playerKey((array) $player) === $voterId) {
                return in_array($player['name'] ?? '', MAP_VOTE_ADMIN_NAMES, true);
            }
        }
    }
    return false;
}

/** Display name of a roster member by voter key (falls back to the key). */
function rosterPlayerName(array $composition, string $voterId): string {
    foreach (['team1', 'team2'] as $side) {
        foreach (($composition['teams'][$side] ?? []) as $player) {
            if (playerKey((array) $player) === $voterId) {
                return (string) ($player['name'] ?? $voterId);
            }
        }
    }
    return $voterId;
}

/**
 * Decides the night's three maps from the closed ballot box:
 *   maps 1–2 — best score (ties: fewer bans, then coin flip),
 *   map 3    — the most-banned remaining map is knocked out (ties: worst
 *              score, then coin flip; nobody banned → nothing knocked out),
 *              then a weighted random over the rest where
 *              weight = score − min(score) + 1, so popular maps are
 *              proportionally likelier but every survivor keeps a chance.
 * Called exactly once, when an admin closes the vote — the random rolls
 * are frozen into the stored result.
 */
function decideMapVote(array $composition): array {
    $rows = mapVoteScores($composition);
    // Random tie-break: shuffle, then stable-sort by (score desc, bans asc)
    // so equal rows keep their shuffled order.
    shuffle($rows);
    usort($rows, fn ($a, $b) => [$b['score'], $a['bans']] <=> [$a['score'], $b['bans']]);

    $picked = [$rows[0]['map'], $rows[1]['map']];
    $rest   = array_slice($rows, 2);

    // Knock out the most-banned remaining map. $rest is sorted best-first,
    // so walking it in reverse finds the worst-scored among the most-banned.
    $banned  = null;
    $maxBans = max(array_column($rest, 'bans'));
    if ($maxBans > 0) {
        foreach (array_reverse($rest) as $row) {
            if ($row['bans'] === $maxBans) {
                $banned = $row['map'];
                break;
            }
        }
    }
    $pool = array_values(array_filter($rest, fn ($r) => $r['map'] !== $banned));

    $minScore = min(array_column($pool, 'score'));
    $weighted = array_map(fn ($r) => [
        'map'    => $r['map'],
        'weight' => $r['score'] - $minScore + 1,
    ], $pool);

    $roll   = mt_rand(1, array_sum(array_column($weighted, 'weight')));
    $random = $weighted[0]['map'];
    foreach ($weighted as $entry) {
        $roll -= $entry['weight'];
        if ($roll <= 0) {
            $random = $entry['map'];
            break;
        }
    }

    return [
        'picked'     => $picked,
        'random'     => $random,
        'banned'     => $banned,
        'maps'       => array_merge($picked, [$random]),
        'scores'     => $rows,
        'randomPool' => $weighted,
    ];
}

function normalizeDate($value): ?string {
    if ($value === null || $value === '') return null;
    $value = (string) $value;
    // Accept ISO 8601 or any strtotime-parseable date; reject garbage.
    $ts = strtotime($value);
    if ($ts === false) return null;
    return gmdate('c', $ts);
}

try {
    Db::pdo();
} catch (Throwable $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    fail(500, 'Database connection failed');
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Fallback: derive the action from the URL path (e.g. /api/create-teams).
// router.php does this for the php -S server (prod), but Apache (local Docker)
// rewrites to index.php without populating it, so recover it here too.
if ($action === '') {
    $reqPath = strtok((string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''), '?');
    $parts   = explode('/', trim($reqPath, '/'));
    if (count($parts) >= 2 && $parts[0] === 'api') {
        $action = $parts[1];
    }
}

// Cacheable read endpoints — short TTL so updates show up quickly but the
// browser/CDN can absorb repeat hits during navigation.
// NOTE: get-statistics and get-jokers are intentionally NOT cached — jokers
// are embedded in both responses, and the HTTP cache made freshly created or
// deleted jokers invisible until the TTL expired. React-query already caches
// these client-side (staleTime), so the HTTP layer was redundant anyway.
$cacheableActions = ['get-matches', 'get-match-data'];
if (in_array($action, $cacheableActions, true) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Cache-Control: public, max-age=30, stale-while-revalidate=60');
}

try {
    switch ($action) {
        case 'get-statistics': {
            $matchIds = $_GET['match_ids'] ?? '';
            $matchIdsArray = array_values(array_filter(array_map('trim', $matchIds ? explode(',', $matchIds) : [])));
            $from = normalizeDate($_GET['from'] ?? null);
            $to   = normalizeDate($_GET['to']   ?? null);

            $calculator = new StatisticsCalculator();
            $cache       = Db::getMatchData($matchIdsArray, $from, $to);
            $parser      = new MatchParser();
            $allMatches  = [];

            foreach ($matchIdsArray as $matchId) {
                if (isset($cache[$matchId])) {
                    $allMatches[] = $cache[$matchId];
                    continue;
                }
                // Cache miss: only re-parse when no date filter is active,
                // otherwise we'd bypass the filter silently.
                if ($from === null && $to === null) {
                    $parsed = $parser->parseMatch($matchId);
                    if ($parsed) {
                        $allMatches[] = $parsed;
                        Db::updateMatchData($matchId, $parsed);
                    }
                }
            }

            $statistics = $calculator->calculateOverallStatistics($allMatches);

            foreach (Db::getJokers() as $joker) {
                $statistics['players'][] = jokerPlayerRow($joker);
            }

            // Note: we intentionally do NOT echo back the full $allMatches
            // here. That used to inflate every response to ~400 KB just so
            // the home page could compute "best player of last match". Use
            // the dedicated /api/get-match-data?id=... for single-match
            // detail when you need it.
            echo json_encode([
                'success'    => true,
                'statistics' => $statistics,
                'filter'     => ['from' => $from, 'to' => $to],
            ]);
            break;
        }

        case 'get-match-data': {
            $id = $_GET['id'] ?? $_GET['matchId'] ?? '';
            if ($id === '') {
                fail(400, 'Match id is required');
                break;
            }
            $cache = Db::getMatchData([$id]);
            if (!isset($cache[$id])) {
                fail(404, 'Match not found or has no parsed data');
                break;
            }
            echo json_encode([
                'success' => true,
                'match'   => $cache[$id],
            ]);
            break;
        }

        case 'parse-match': {
            $urlOrId = $_GET['url'] ?? '';
            error_log('parse-match request: url=' . substr($urlOrId, 0, 100));

            if (empty($urlOrId)) {
                fail(400, 'URL or match ID is required');
                break;
            }

            $parser  = new MatchParser();
            $matchId = $parser->extractMatchIdFromUrl($urlOrId);

            if (!$matchId) {
                fail(400, 'Invalid URL or match ID format', ['url' => $urlOrId]);
                break;
            }

            // Serve cached parsed data when available — avoids a 20s scope.gg
            // round-trip on every match-detail page load.
            $cache = Db::getMatchData([$matchId]);
            if (isset($cache[$matchId]) && !empty($cache[$matchId]['teams'])) {
                echo json_encode([
                    'success' => true,
                    'match'   => $cache[$matchId],
                ]);
                break;
            }

            // FlareSolverr (Cloudflare bypass) is slow and can add a cold-start
            // on top, so give the request plenty of headroom when it's in use.
            // Two FlareSolverr attempts at up to 150s each, plus parsing slack.
            set_time_limit(getenv('FLARESOLVERR_URL') ? 320 : 20);
            $matchData = $parser->parseMatch($matchId);

            if (!$matchData) {
                fail(404, 'Match not found or could not be parsed. The match may not exist, the HTML structure may have changed, or the server may be slow to respond.', [
                    'matchId' => $matchId,
                    'hint'    => 'Please check if the match ID is correct and try again. If the problem persists, the match page structure may have changed.',
                ]);
                break;
            }

            $playerCount = countTotalPlayers($matchData['teams'] ?? []);
            if (empty($matchData['teams']) || $playerCount === 0) {
                error_log("Match $matchId: parseMatch returned data but no players found.");
                fail(404, 'No players found in match. The match page may have a different structure.', [
                    'matchId' => $matchId,
                    'teams'   => $matchData['teams'] ?? [],
                    'debug'   => [
                        'teamsCount'   => count($matchData['teams'] ?? []),
                        'playersCount' => $playerCount,
                    ],
                ]);
                break;
            }

            echo json_encode([
                'success' => true,
                'match'   => $matchData,
            ]);
            break;
        }

        case 'get-matches': {
            $from = normalizeDate($_GET['from'] ?? null);
            $to   = normalizeDate($_GET['to']   ?? null);
            echo json_encode([
                'success' => true,
                'matches' => Db::getMatches($from, $to),
                'filter'  => ['from' => $from, 'to' => $to],
            ]);
            break;
        }

        case 'save-match': {
            $input     = json_decode(file_get_contents('php://input'), true) ?: [];
            $matchId   = $input['matchId']   ?? $_POST['matchId']   ?? '';
            $url       = $input['url']       ?? $_POST['url']       ?? '';
            $map       = $input['map']       ?? $_POST['map']       ?? '';
            $score     = $input['score']     ?? $_POST['score']     ?? null;
            $matchData = $input['matchData'] ?? null;

            if (empty($matchId)) {
                fail(400, 'Match ID is required');
                break;
            }

            if (Db::matchExists($matchId)) {
                fail(409, 'Match already exists');
                break;
            }

            $saved = Db::insertMatch(
                $matchId,
                $url,
                $map !== '' ? $map : null,
                is_array($score) ? $score : [],
                is_array($matchData) ? $matchData : null
            );

            echo json_encode([
                'success' => true,
                'match'   => $saved,
            ]);
            break;
        }

        case 'delete-match': {
            $input   = json_decode(file_get_contents('php://input'), true) ?: [];
            $matchId = $input['matchId'] ?? $_GET['matchId'] ?? $_POST['matchId'] ?? '';

            if (empty($matchId)) {
                fail(400, 'Match ID is required');
                break;
            }

            if (!Db::deleteMatch($matchId)) {
                fail(404, 'Match not found');
                break;
            }

            echo json_encode(['success' => true]);
            break;
        }

        case 'clear-matches': {
            Db::clearMatches();
            echo json_encode(['success' => true]);
            break;
        }

        case 'create-teams': {
            $input      = json_decode(file_get_contents('php://input'), true) ?: [];
            $playerIds  = $input['playerIds'] ?? $_POST['playerIds'] ?? [];
            $matchIds   = $input['matchIds']  ?? $_GET['match_ids']  ?? $_POST['matchIds']  ?? '';
            $dateFilter = is_array($input['dateFilter'] ?? null) ? $input['dateFilter'] : null;

            if (empty($playerIds) || !is_array($playerIds) || count($playerIds) !== 10) {
                fail(400, 'Exactly 10 player IDs are required');
                break;
            }

            $matchIdsArray = is_array($matchIds)
                ? $matchIds
                : ($matchIds ? array_values(array_filter(array_map('trim', explode(',', $matchIds)))) : []);

            if (empty($matchIdsArray)) {
                fail(400, 'No matches provided. Please load statistics first.');
                break;
            }

            $cache      = Db::getMatchData($matchIdsArray);
            $allMatches = array_values($cache);

            if (empty($allMatches)) {
                fail(400, 'No match data found');
                break;
            }

            $statistics = (new StatisticsCalculator())->calculateOverallStatistics($allMatches);

            $jokersMap = [];
            foreach (Db::getJokers() as $joker) {
                if (!empty($joker['id'])) {
                    $jokersMap[$joker['id']] = $joker;
                }
            }

            $selectedPlayers = [];
            foreach ($statistics['players'] as $player) {
                $key = $player['playerId'] ?? ($player['name'] ?? '');
                if (in_array($key, $playerIds, true)) {
                    $selectedPlayers[] = $player;
                }
            }
            foreach ($playerIds as $playerId) {
                if (isset($jokersMap[$playerId])) {
                    $selectedPlayers[] = jokerPlayerRow($jokersMap[$playerId]);
                }
            }

            if (count($selectedPlayers) !== 10) {
                fail(400, 'Could not find all selected players. Found: ' . count($selectedPlayers));
                break;
            }

            $balancer      = new TeamBalancer();
            $balancedTeams = $balancer->balanceTeams($selectedPlayers);
            $improvedTeams = $balancer->improveBalance($balancedTeams);

            $teamId = uniqid('team_', true);
            Db::insertTeam($teamId, [
                'id'           => $teamId,
                'teams'        => $improvedTeams,
                'createdAt'    => date('c'),
                'playerNames'  => array_map(fn($p) => $p['name'] ?? '', $selectedPlayers),
                'dateFilter'   => $dateFilter,
                'matchesCount' => count($allMatches),
            ]);

            echo json_encode([
                'success' => true,
                'teams'   => $improvedTeams,
                'teamId'  => $teamId,
            ]);
            break;
        }

        case 'create-draft': {
            // Same 10-player selection as create-teams, but instead of
            // auto-balancing we seed a live captains draft: two random
            // captains, the other eight in the pool, snake pick order.
            $input      = json_decode(file_get_contents('php://input'), true) ?: [];
            $playerIds  = $input['playerIds'] ?? $_POST['playerIds'] ?? [];
            $matchIds   = $input['matchIds']  ?? $_GET['match_ids']  ?? $_POST['matchIds']  ?? '';
            $dateFilter = is_array($input['dateFilter'] ?? null) ? $input['dateFilter'] : null;

            if (empty($playerIds) || !is_array($playerIds) || count($playerIds) !== 10) {
                fail(400, 'Exactly 10 player IDs are required');
                break;
            }

            $matchIdsArray = is_array($matchIds)
                ? $matchIds
                : ($matchIds ? array_values(array_filter(array_map('trim', explode(',', $matchIds)))) : []);

            if (empty($matchIdsArray)) {
                fail(400, 'No matches provided. Please load statistics first.');
                break;
            }

            $cache      = Db::getMatchData($matchIdsArray);
            $allMatches = array_values($cache);

            if (empty($allMatches)) {
                fail(400, 'No match data found');
                break;
            }

            $statistics = (new StatisticsCalculator())->calculateOverallStatistics($allMatches);

            $jokersMap = [];
            foreach (Db::getJokers() as $joker) {
                if (!empty($joker['id'])) {
                    $jokersMap[$joker['id']] = $joker;
                }
            }

            $selectedPlayers = [];
            foreach ($statistics['players'] as $player) {
                $key = $player['playerId'] ?? ($player['name'] ?? '');
                if (in_array($key, $playerIds, true)) {
                    $selectedPlayers[] = $player;
                }
            }
            foreach ($playerIds as $playerId) {
                if (isset($jokersMap[$playerId])) {
                    $selectedPlayers[] = jokerPlayerRow($jokersMap[$playerId]);
                }
            }

            if (count($selectedPlayers) !== 10) {
                fail(400, 'Could not find all selected players. Found: ' . count($selectedPlayers));
                break;
            }

            // Two random captains (coin flip = who picks first), eight in the pool.
            $draft = Draft::build($selectedPlayers);

            $teamId = uniqid('team_', true);
            Db::insertTeam($teamId, [
                'id'           => $teamId,
                'draft'        => $draft,
                'createdAt'    => date('c'),
                'playerNames'  => array_map(fn($p) => $p['name'] ?? '', $selectedPlayers),
                'dateFilter'   => $dateFilter,
                'matchesCount' => count($allMatches),
            ]);

            echo json_encode([
                'success' => true,
                'teamId'  => $teamId,
                'draft'   => $draft,
            ]);
            break;
        }

        case 'draft-pick': {
            $input     = json_decode(file_get_contents('php://input'), true) ?: [];
            $teamId    = $input['teamId']    ?? $_POST['teamId']    ?? '';
            $voterId   = $input['voterId']   ?? $_POST['voterId']   ?? '';
            $playerKey = $input['playerKey'] ?? $_POST['playerKey'] ?? '';

            if (empty($teamId) || empty($voterId) || $playerKey === '') {
                fail(400, 'teamId, voterId and playerKey are required');
                break;
            }

            try {
                $result = Db::mutateTeam($teamId, fn (array $composition) =>
                    Draft::applyPick($composition, (string) $voterId, (string) $playerKey));
            } catch (InvalidArgumentException $e) {
                fail(403, $e->getMessage());
                break;
            } catch (RuntimeException $e) {
                fail(409, $e->getMessage());
                break;
            }

            if ($result === null) {
                fail(404, 'Team not found');
                break;
            }

            echo json_encode([
                'success' => true,
                'team'    => $result,
            ]);
            break;
        }

        case 'cancel-draft': {
            // Abort a stuck draft (e.g. an AFK captain): a captain or admin
            // falls back to the auto-balancer over all 10 players.
            $input   = json_decode(file_get_contents('php://input'), true) ?: [];
            $teamId  = $input['teamId']  ?? $_POST['teamId']  ?? '';
            $voterId = $input['voterId'] ?? $_POST['voterId'] ?? '';

            if (empty($teamId) || empty($voterId)) {
                fail(400, 'teamId and voterId are required');
                break;
            }

            try {
                $result = Db::mutateTeam($teamId, fn (array $composition) =>
                    Draft::cancel($composition, (string) $voterId, MAP_VOTE_ADMIN_IDS));
            } catch (InvalidArgumentException $e) {
                fail(403, $e->getMessage());
                break;
            } catch (RuntimeException $e) {
                fail(409, $e->getMessage());
                break;
            }

            if ($result === null) {
                fail(404, 'Team not found');
                break;
            }

            echo json_encode([
                'success' => true,
                'team'    => $result,
            ]);
            break;
        }

        case 'get-team': {
            $teamId = $_GET['teamId'] ?? '';
            if (empty($teamId)) {
                fail(400, 'Team ID is required');
                break;
            }
            $team = Db::getTeam($teamId);
            if (!$team) {
                fail(404, 'Team not found');
                break;
            }
            echo json_encode([
                'success' => true,
                'team'    => $team,
            ]);
            break;
        }

        case 'vote-map': {
            $input   = json_decode(file_get_contents('php://input'), true) ?: [];
            $teamId  = $input['teamId']  ?? $_POST['teamId']  ?? '';
            $voterId = $input['voterId'] ?? $_POST['voterId'] ?? '';

            // The voter's ballot: up to MAX_MAP_PICKS picks and one optional
            // ban. Legacy 'maps'/'map' payloads are accepted as picks.
            $picks = $input['picks'] ?? null;
            if (!is_array($picks)) {
                $picks = $input['maps'] ?? null;
            }
            if (!is_array($picks)) {
                $legacy = $input['map'] ?? $_POST['map'] ?? '';
                $picks  = $legacy === '' ? [] : [$legacy];
            }
            $picks = array_values(array_unique(array_filter(array_map('strval', $picks))));
            $ban   = $input['ban'] ?? null;
            $ban   = (is_string($ban) && $ban !== '') ? $ban : null;

            if (empty($teamId) || empty($voterId)) {
                fail(400, 'teamId and voterId are required');
                break;
            }
            if (count($picks) > MAX_MAP_PICKS) {
                fail(400, 'You can pick at most ' . MAX_MAP_PICKS . ' maps');
                break;
            }
            foreach (array_merge($picks, $ban !== null ? [$ban] : []) as $map) {
                if (!in_array($map, MAP_POOL, true)) {
                    fail(400, 'Unknown map: ' . $map, ['allowed' => MAP_POOL]);
                    break 2;
                }
            }

            try {
                $result = Db::mutateTeam($teamId, function (array $composition) use ($voterId, $picks, $ban) {
                    if (!in_array((string) $voterId, teamVoterKeys($composition), true)) {
                        throw new InvalidArgumentException('Voter is not part of this team');
                    }
                    if (!empty($composition['mapVoting']['closedAt'])) {
                        throw new RuntimeException('Voting is closed');
                    }
                    $votes = $composition['mapVotes'] ?? [];
                    if (empty($picks) && $ban === null) {
                        unset($votes[$voterId]);
                    } else {
                        $votes[$voterId] = ['picks' => $picks, 'ban' => $ban, 'votedAt' => date('c')];
                    }
                    // Keep JSON object shape even when the last vote is retracted.
                    $composition['mapVotes'] = $votes ?: new stdClass();
                    return $composition;
                });
            } catch (InvalidArgumentException $e) {
                fail(403, $e->getMessage());
                break;
            } catch (RuntimeException $e) {
                fail(409, $e->getMessage());
                break;
            }

            if ($result === null) {
                fail(404, 'Team not found');
                break;
            }

            echo json_encode([
                'success'  => true,
                'team'     => $result,
                'scores'   => mapVoteScores($result),
                'mapPool'  => MAP_POOL,
            ]);
            break;
        }

        case 'close-map-vote':
        case 'reopen-map-vote': {
            $input   = json_decode(file_get_contents('php://input'), true) ?: [];
            $teamId  = $input['teamId']  ?? '';
            $voterId = $input['voterId'] ?? '';

            if (empty($teamId) || empty($voterId)) {
                fail(400, 'teamId and voterId are required');
                break;
            }

            $closing = $action === 'close-map-vote';
            try {
                $result = Db::mutateTeam($teamId, function (array $composition) use ($voterId, $closing) {
                    if (!in_array((string) $voterId, teamVoterKeys($composition), true)) {
                        throw new InvalidArgumentException('Voter is not part of this team');
                    }
                    if (!isMapVoteAdmin($composition, (string) $voterId)) {
                        throw new InvalidArgumentException('Only a vote admin can do this');
                    }
                    if ($closing) {
                        if (!empty($composition['mapVoting']['closedAt'])) {
                            throw new RuntimeException('Voting is already closed');
                        }
                        $composition['mapVoting'] = [
                            'closedAt' => date('c'),
                            'closedBy' => rosterPlayerName($composition, (string) $voterId),
                            'result'   => decideMapVote($composition),
                        ];
                    } else {
                        unset($composition['mapVoting']);
                    }
                    return $composition;
                });
            } catch (InvalidArgumentException $e) {
                fail(403, $e->getMessage());
                break;
            } catch (RuntimeException $e) {
                fail(409, $e->getMessage());
                break;
            }

            if ($result === null) {
                fail(404, 'Team not found');
                break;
            }

            echo json_encode([
                'success' => true,
                'team'    => $result,
                'scores'  => mapVoteScores($result),
            ]);
            break;
        }

        case 'swap-players': {
            $input   = json_decode(file_get_contents('php://input'), true) ?: [];
            $teamId  = $input['teamId']  ?? '';
            $playerA = $input['playerA'] ?? ''; // key of a player on team 1
            $playerB = $input['playerB'] ?? ''; // key of a player on team 2

            if (empty($teamId) || empty($playerA) || empty($playerB)) {
                fail(400, 'teamId, playerA and playerB are required');
                break;
            }

            try {
                $result = Db::mutateTeam($teamId, function (array $composition) use ($playerA, $playerB) {
                    $teams = $composition['teams'] ?? null;
                    if (!is_array($teams)) {
                        throw new InvalidArgumentException('Team has no rosters');
                    }

                    // Locate each player on either side — the swap is
                    // direction-agnostic so the UI can pass them in any order.
                    $find = function (array $players, string $key): ?int {
                        foreach ($players as $i => $p) {
                            if (playerKey($p) === $key) return $i;
                        }
                        return null;
                    };

                    $a1 = $find($teams['team1'] ?? [], $playerA);
                    $a2 = $find($teams['team2'] ?? [], $playerA);
                    $b1 = $find($teams['team1'] ?? [], $playerB);
                    $b2 = $find($teams['team2'] ?? [], $playerB);

                    if ($a1 !== null && $b2 !== null) {
                        [$teams['team1'][$a1], $teams['team2'][$b2]] = [$teams['team2'][$b2], $teams['team1'][$a1]];
                    } elseif ($a2 !== null && $b1 !== null) {
                        [$teams['team2'][$a2], $teams['team1'][$b1]] = [$teams['team1'][$b1], $teams['team2'][$a2]];
                    } else {
                        throw new InvalidArgumentException('Players must be on opposite teams');
                    }

                    $composition['teams'] = recalcTeamRatings($teams);
                    $composition['editedAt'] = date('c');
                    return $composition;
                });
            } catch (InvalidArgumentException $e) {
                fail(400, $e->getMessage());
                break;
            }

            if ($result === null) {
                fail(404, 'Team not found');
                break;
            }

            echo json_encode([
                'success' => true,
                'team'    => $result,
            ]);
            break;
        }

        case 'create-joker': {
            $input  = json_decode(file_get_contents('php://input'), true) ?: [];
            $name   = $input['name']   ?? $_POST['name']   ?? 'Joker';
            $rating = (float) ($input['rating'] ?? $_POST['rating'] ?? 1.0);

            if ($rating < 0 || $rating > 5) {
                fail(400, 'Rating must be between 0 and 5');
                break;
            }

            $jokerId = 'joker_' . uniqid();
            $avatar  = 'https://api.dicebear.com/7.x/shapes/svg?seed=' . urlencode($name) . '&backgroundColor=6366f1&shape1Color=8b5cf6&shape2Color=a855f7';

            $joker = Db::insertJoker($jokerId, $name, $rating, $avatar);

            echo json_encode([
                'success' => true,
                'joker'   => $joker,
            ]);
            break;
        }

        case 'get-jokers': {
            echo json_encode([
                'success' => true,
                'jokers'  => Db::getJokers(),
            ]);
            break;
        }

        case 'delete-joker': {
            $input   = json_decode(file_get_contents('php://input'), true) ?: [];
            $jokerId = $input['jokerId'] ?? $_POST['jokerId'] ?? '';

            if (empty($jokerId)) {
                fail(400, 'Joker ID is required');
                break;
            }
            if (!Db::deleteJoker($jokerId)) {
                fail(404, 'Joker not found');
                break;
            }
            echo json_encode(['success' => true]);
            break;
        }

        default:
            fail(400, 'Invalid action');
            break;
    }
} catch (PDOException $e) {
    error_log('DB error in action "' . $action . '": ' . $e->getMessage());
    fail(500, 'Database error');
} catch (Throwable $e) {
    error_log('Error in action "' . $action . '": ' . $e->getMessage());
    fail(500, 'Internal server error: ' . $e->getMessage());
}
