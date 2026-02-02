<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../src/MatchParser.php';
require_once __DIR__ . '/../src/StatisticsCalculator.php';
require_once __DIR__ . '/../src/TeamBalancer.php';

function countTotalPlayers($teams) {
    $count = 0;
    foreach ($teams as $team) {
        $count += count($team['players'] ?? []);
    }
    return $count;
}

// Storage file paths
// Use DATA_DIR environment variable if set (for Railway Volume), otherwise use default path
$dataDir = getenv('DATA_DIR') ?: __DIR__ . '/../data';
$storageFile = $dataDir . '/matches.json';
$matchesDataFile = $dataDir . '/matches_data.json'; // Full match data cache
$jokersFile = $dataDir . '/jokers.json'; // Joker players storage
$storageDir = $dataDir;

// Ensure storage directory exists
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0755, true);
}

// Helper function to load matches from file
function loadMatches($file) {
    if (!file_exists($file)) {
        return [];
    }
    $content = file_get_contents($file);
    if (empty($content)) {
        return [];
    }
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

// Helper function to save matches to file
function saveMatches($file, $matches) {
    return file_put_contents($file, json_encode($matches, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

// Helper function to load full match data cache
function loadMatchesData($file) {
    if (!file_exists($file)) {
        return [];
    }
    $content = file_get_contents($file);
    if (empty($content)) {
        return [];
    }
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

// Helper function to save full match data cache
function saveMatchesData($file, $matchesData) {
    return file_put_contents($file, json_encode($matchesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

// Helper function to load jokers
function loadJokers($file) {
    if (!file_exists($file)) {
        return [];
    }
    $content = file_get_contents($file);
    if (empty($content)) {
        return [];
    }
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

// Helper function to save jokers
function saveJokers($file, $jokers) {
    return file_put_contents($file, json_encode($jokers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get-statistics':
        $matchIds = $_GET['match_ids'] ?? '';
        $matchIdsArray = $matchIds ? explode(',', $matchIds) : [];
        
        $calculator = new StatisticsCalculator();
        
        // Load cached match data
        $matchesDataCache = loadMatchesData($matchesDataFile);
        
        $allMatches = [];
        $parser = new MatchParser();
        $needsSave = false;
        
        foreach ($matchIdsArray as $matchId) {
            $matchId = trim($matchId);
            if (empty($matchId)) {
                continue;
            }
            
            // Try to get from cache first
            if (isset($matchesDataCache[$matchId])) {
                $allMatches[] = $matchesDataCache[$matchId];
            } else {
                // If not in cache, parse it (shouldn't happen often, but handle it)
                $matchData = $parser->parseMatch($matchId);
                if ($matchData) {
                    $allMatches[] = $matchData;
                    // Cache it for next time
                    $matchesDataCache[$matchId] = $matchData;
                    $needsSave = true;
                }
            }
        }
        
        // Save cache if we added new matches
        if ($needsSave) {
            saveMatchesData($matchesDataFile, $matchesDataCache);
        }
        
        $statistics = $calculator->calculateOverallStatistics($allMatches);
        
        // Load and merge Joker players
        $jokers = loadJokers($jokersFile);
        foreach ($jokers as $joker) {
            // Add Joker as a player with special identifier
            $jokerPlayer = [
                'name' => $joker['name'] ?? 'Joker',
                'playerId' => $joker['id'] ?? null,
                'avatar' => $joker['avatar'] ?? 'https://api.dicebear.com/7.x/shapes/svg?seed=Joker&backgroundColor=6366f1&shape1Color=8b5cf6&shape2Color=a855f7',
                'matches' => 0,
                'kills' => 0,
                'deaths' => 0,
                'assists' => 0,
                'kd' => 0,
                'damage' => 0,
                'avgDamage' => 0,
                'adr' => 0,
                'hltvRating' => floatval($joker['rating'] ?? 1.0),
                'kast' => 0,
                'openKills' => 0,
                'tradeKills' => 0,
                'isJoker' => true
            ];
            $statistics['players'][] = $jokerPlayer;
        }
        
        echo json_encode([
            'success' => true,
            'statistics' => $statistics,
            'matches' => $allMatches
        ]);
        break;
        
    case 'parse-match':
        try {
            // Get URL from GET parameter (may be URL-encoded)
            $urlOrId = $_GET['url'] ?? '';
            
            // Log for debugging (remove in production)
            error_log("parse-match request: url=" . substr($urlOrId, 0, 100));
            
            if (empty($urlOrId)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'URL or match ID is required'
                ]);
                break;
            }
            
            $parser = new MatchParser();
            
            // Extract match ID from URL if needed
            $matchId = $parser->extractMatchIdFromUrl($urlOrId);
            
            if (!$matchId) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid URL or match ID format',
                    'url' => $urlOrId
                ]);
                break;
            }
            
            // Set execution time limit for parsing
            set_time_limit(20); // 20 seconds max execution time
            
            $matchData = $parser->parseMatch($matchId);
            
            if (!$matchData) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Match not found or could not be parsed. The match may not exist, the HTML structure may have changed, or the server may be slow to respond.',
                    'matchId' => $matchId,
                    'hint' => 'Please check if the match ID is correct and try again. If the problem persists, the match page structure may have changed.'
                ]);
                break;
            }
            
            // Check if we have teams with players
            $playerCount = countTotalPlayers($matchData['teams'] ?? []);
            if (empty($matchData['teams']) || $playerCount === 0) {
                http_response_code(404);
                error_log("Match $matchId: parseMatch returned data but no players found. Teams: " . json_encode($matchData['teams'] ?? []));
                echo json_encode([
                    'success' => false,
                    'error' => 'No players found in match. The match page may have a different structure.',
                    'matchId' => $matchId,
                    'teams' => $matchData['teams'] ?? [],
                    'debug' => [
                        'teamsCount' => count($matchData['teams'] ?? []),
                        'playersCount' => $playerCount
                    ]
                ]);
                break;
            }
            
            echo json_encode([
                'success' => true,
                'match' => $matchData
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Internal server error: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        } catch (Error $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Fatal error: ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
        break;
        
    case 'get-matches':
        // Get all saved matches
        $matches = loadMatches($storageFile);
        echo json_encode([
            'success' => true,
            'matches' => $matches
        ]);
        break;
        
    case 'save-match':
        // Save a match to storage
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $matchId = $input['matchId'] ?? $_POST['matchId'] ?? '';
            $url = $input['url'] ?? $_POST['url'] ?? '';
            $map = $input['map'] ?? $_POST['map'] ?? '';
            $score = $input['score'] ?? $_POST['score'] ?? null;
            $matchData = $input['matchData'] ?? null; // Full match data for caching
            
            if (empty($matchId)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Match ID is required'
                ]);
                break;
            }
            
            $matches = loadMatches($storageFile);
            
            // Check if match already exists
            $exists = false;
            foreach ($matches as $match) {
                if ($match['id'] === $matchId) {
                    $exists = true;
                    break;
                }
            }
            
            if ($exists) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'error' => 'Match already exists'
                ]);
                break;
            }
            
            // Add new match
            $newMatch = [
                'id' => $matchId,
                'url' => $url,
                'map' => $map,
                'score' => $score,
                'addedAt' => date('c')
            ];
            
            $matches[] = $newMatch;
            
            // Save full match data to cache if provided
            if ($matchData && is_array($matchData)) {
                $matchesDataCache = loadMatchesData($matchesDataFile);
                $matchesDataCache[$matchId] = $matchData;
                saveMatchesData($matchesDataFile, $matchesDataCache);
            }
            
            if (saveMatches($storageFile, $matches)) {
                echo json_encode([
                    'success' => true,
                    'match' => $newMatch
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to save match'
                ]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Error: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'delete-match':
        // Delete a match from storage
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $matchId = $input['matchId'] ?? $_GET['matchId'] ?? $_POST['matchId'] ?? '';
            
            if (empty($matchId)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Match ID is required'
                ]);
                break;
            }
            
            $matches = loadMatches($storageFile);
            $filteredMatches = array_filter($matches, function($match) use ($matchId) {
                return $match['id'] !== $matchId;
            });
            
            if (count($filteredMatches) === count($matches)) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Match not found'
                ]);
                break;
            }
            
            // Also remove from cache
            $matchesDataCache = loadMatchesData($matchesDataFile);
            if (isset($matchesDataCache[$matchId])) {
                unset($matchesDataCache[$matchId]);
                saveMatchesData($matchesDataFile, $matchesDataCache);
            }
            
            if (saveMatches($storageFile, array_values($filteredMatches))) {
                echo json_encode([
                    'success' => true
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to delete match'
                ]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Error: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'clear-matches':
        // Clear all matches
        try {
            // Clear both storage files
            if (saveMatches($storageFile, []) && saveMatchesData($matchesDataFile, [])) {
                echo json_encode([
                    'success' => true
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to clear matches'
                ]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Error: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'create-teams':
        // Create balanced teams from selected players
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $playerIds = $input['playerIds'] ?? $_POST['playerIds'] ?? [];
            $matchIds = $input['matchIds'] ?? $_GET['match_ids'] ?? $_POST['matchIds'] ?? '';
            
            if (empty($playerIds) || !is_array($playerIds) || count($playerIds) !== 10) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Exactly 10 player IDs are required'
                ]);
                break;
            }
            
            // Get statistics to find player data
            $matchIdsArray = $matchIds ? (is_array($matchIds) ? $matchIds : explode(',', $matchIds)) : [];
            
            if (empty($matchIdsArray)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'No matches provided. Please load statistics first.'
                ]);
                break;
            }
            
            $calculator = new StatisticsCalculator();
            $matchesDataCache = loadMatchesData($matchesDataFile);
            
            $allMatches = [];
            foreach ($matchIdsArray as $matchId) {
                $matchId = trim($matchId);
                if (!empty($matchId) && isset($matchesDataCache[$matchId])) {
                    $allMatches[] = $matchesDataCache[$matchId];
                }
            }
            
            if (empty($allMatches)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'No match data found'
                ]);
                break;
            }
            
            $statistics = $calculator->calculateOverallStatistics($allMatches);
            
            // Load Jokers
            $jokers = loadJokers($jokersFile);
            $jokersMap = [];
            foreach ($jokers as $joker) {
                $jokerId = $joker['id'] ?? null;
                if ($jokerId) {
                    $jokersMap[$jokerId] = $joker;
                }
            }
            
            // Find selected players
            $selectedPlayers = [];
            foreach ($statistics['players'] as $player) {
                $playerId = $player['playerId'] ?? null;
                $playerName = $player['name'] ?? '';
                
                // Create key for matching (same logic as frontend)
                $key = $playerId ?: $playerName;
                
                // Match by key
                if (in_array($key, $playerIds)) {
                    $selectedPlayers[] = $player;
                }
            }
            
            // Check for Joker players in selection
            foreach ($playerIds as $playerId) {
                if (isset($jokersMap[$playerId])) {
                    $joker = $jokersMap[$playerId];
                    $selectedPlayers[] = [
                        'name' => $joker['name'] ?? 'Joker',
                        'playerId' => $joker['id'] ?? null,
                        'avatar' => $joker['avatar'] ?? 'https://api.dicebear.com/7.x/shapes/svg?seed=Joker&backgroundColor=6366f1&shape1Color=8b5cf6&shape2Color=a855f7',
                        'matches' => 0,
                        'kills' => 0,
                        'deaths' => 0,
                        'assists' => 0,
                        'kd' => 0,
                        'damage' => 0,
                        'avgDamage' => 0,
                        'adr' => 0,
                        'hltvRating' => floatval($joker['rating'] ?? 1.0),
                        'kast' => 0,
                        'openKills' => 0,
                        'tradeKills' => 0,
                        'isJoker' => true
                    ];
                }
            }
            
            if (count($selectedPlayers) !== 10) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Could not find all selected players. Found: ' . count($selectedPlayers)
                ]);
                break;
            }
            
            // Balance teams
            $balancer = new TeamBalancer();
            $balancedTeams = $balancer->balanceTeams($selectedPlayers);
            $improvedTeams = $balancer->improveBalance($balancedTeams);
            
            // Generate unique ID for this team composition
            $teamId = uniqid('team_', true);
            
            // Save team to storage
            $teamsStorageFile = __DIR__ . '/../data/teams.json';
            $savedTeams = [];
            if (file_exists($teamsStorageFile)) {
                $content = file_get_contents($teamsStorageFile);
                if (!empty($content)) {
                    $savedTeams = json_decode($content, true);
                    if (!is_array($savedTeams)) {
                        $savedTeams = [];
                    }
                }
            }
            $savedTeams[$teamId] = [
                'id' => $teamId,
                'teams' => $improvedTeams,
                'createdAt' => date('c'),
                'playerNames' => array_map(function($p) {
                    return $p['name'] ?? '';
                }, $selectedPlayers)
            ];
            file_put_contents($teamsStorageFile, json_encode($savedTeams, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            echo json_encode([
                'success' => true,
                'teams' => $improvedTeams,
                'teamId' => $teamId
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Error: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'get-team':
        // Get a specific team by ID
        try {
            $teamId = $_GET['teamId'] ?? '';
            
            if (empty($teamId)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Team ID is required'
                ]);
                break;
            }
            
            $teamsStorageFile = __DIR__ . '/../data/teams.json';
            $savedTeams = [];
            if (file_exists($teamsStorageFile)) {
                $content = file_get_contents($teamsStorageFile);
                if (!empty($content)) {
                    $savedTeams = json_decode($content, true);
                    if (!is_array($savedTeams)) {
                        $savedTeams = [];
                    }
                }
            }
            
            if (!isset($savedTeams[$teamId])) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Team not found'
                ]);
                break;
            }
            
            echo json_encode([
                'success' => true,
                'team' => $savedTeams[$teamId]
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Error: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'create-joker':
        // Create a Joker player
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $name = $input['name'] ?? $_POST['name'] ?? 'Joker';
            $rating = floatval($input['rating'] ?? $_POST['rating'] ?? 1.0);
            
            if ($rating < 0 || $rating > 5) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Rating must be between 0 and 5'
                ]);
                break;
            }
            
            $jokers = loadJokers($jokersFile);
            $jokerId = 'joker_' . uniqid();
            
            $joker = [
                'id' => $jokerId,
                'name' => $name,
                'rating' => $rating,
                'avatar' => 'https://api.dicebear.com/7.x/shapes/svg?seed=' . urlencode($name) . '&backgroundColor=6366f1&shape1Color=8b5cf6&shape2Color=a855f7',
                'createdAt' => date('c')
            ];
            
            $jokers[$jokerId] = $joker;
            
            if (saveJokers($jokersFile, $jokers)) {
                echo json_encode([
                    'success' => true,
                    'joker' => $joker
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to save Joker player'
                ]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Error: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'get-jokers':
        // Get all Joker players
        try {
            $jokers = loadJokers($jokersFile);
            echo json_encode([
                'success' => true,
                'jokers' => array_values($jokers)
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Error: ' . $e->getMessage()
            ]);
        }
        break;
        
    case 'delete-joker':
        // Delete a Joker player
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $jokerId = $input['jokerId'] ?? $_POST['jokerId'] ?? '';
            
            if (empty($jokerId)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Joker ID is required'
                ]);
                break;
            }
            
            $jokers = loadJokers($jokersFile);
            
            if (!isset($jokers[$jokerId])) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Joker not found'
                ]);
                break;
            }
            
            unset($jokers[$jokerId]);
            
            if (saveJokers($jokersFile, $jokers)) {
                echo json_encode([
                    'success' => true
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to delete Joker player'
                ]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Error: ' . $e->getMessage()
            ]);
        }
        break;
        
    default:
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid action'
        ]);
        break;
}
