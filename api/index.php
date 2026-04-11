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

try {
    Db::pdo();
} catch (Throwable $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    fail(500, 'Database connection failed', [
        'debug' => [
            'message'         => $e->getMessage(),
            'has_database_url'=> getenv('DATABASE_URL') ? 'yes' : 'no',
            'pdo_drivers'     => class_exists('PDO') ? PDO::getAvailableDrivers() : 'PDO class missing',
        ],
    ]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get-statistics': {
            $matchIds = $_GET['match_ids'] ?? '';
            $matchIdsArray = array_values(array_filter(array_map('trim', $matchIds ? explode(',', $matchIds) : [])));

            $calculator = new StatisticsCalculator();
            $cache       = Db::getMatchData($matchIdsArray);
            $parser      = new MatchParser();
            $allMatches  = [];

            foreach ($matchIdsArray as $matchId) {
                if (isset($cache[$matchId])) {
                    $allMatches[] = $cache[$matchId];
                    continue;
                }
                $parsed = $parser->parseMatch($matchId);
                if ($parsed) {
                    $allMatches[] = $parsed;
                    Db::updateMatchData($matchId, $parsed);
                }
            }

            $statistics = $calculator->calculateOverallStatistics($allMatches);

            foreach (Db::getJokers() as $joker) {
                $statistics['players'][] = jokerPlayerRow($joker);
            }

            echo json_encode([
                'success'    => true,
                'statistics' => $statistics,
                'matches'    => $allMatches,
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

            set_time_limit(20);
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
            echo json_encode([
                'success' => true,
                'matches' => Db::getMatches(),
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
            $input     = json_decode(file_get_contents('php://input'), true) ?: [];
            $playerIds = $input['playerIds'] ?? $_POST['playerIds'] ?? [];
            $matchIds  = $input['matchIds']  ?? $_GET['match_ids']  ?? $_POST['matchIds']  ?? '';

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
                'id'          => $teamId,
                'teams'       => $improvedTeams,
                'createdAt'   => date('c'),
                'playerNames' => array_map(fn($p) => $p['name'] ?? '', $selectedPlayers),
            ]);

            echo json_encode([
                'success' => true,
                'teams'   => $improvedTeams,
                'teamId'  => $teamId,
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
