<?php

class MatchParser {
    private $baseUrl = 'https://app.scope.gg/matches/';
    
    public function extractMatchIdFromUrl($urlOrId) {
        // Decode URL-encoded string if needed (may be double-encoded)
        $decoded = $urlOrId;
        for ($i = 0; $i < 3; $i++) {
            $temp = @urldecode($decoded);
            if ($temp === false || $temp === $decoded) {
                break;
            }
            $decoded = $temp;
        }
        $urlOrId = $decoded;
        
        // Trim whitespace
        $urlOrId = trim($urlOrId);
        
        error_log("extractMatchIdFromUrl: Input: " . substr($urlOrId, 0, 200));
        
        // If it's already just an ID (numeric)
        if (preg_match('/^\d+$/', $urlOrId)) {
            error_log("extractMatchIdFromUrl: Found numeric ID: $urlOrId");
            return $urlOrId;
        }
        
        // Extract from URL: https://app.scope.gg/matches/1071305309699760
        // Also handles URLs with query parameters like ?utm_source=xplaygg&utm_medium=praccInfo
        if (preg_match('/scope\.gg\/matches\/(\d+)/', $urlOrId, $matches)) {
            error_log("extractMatchIdFromUrl: Extracted from URL: " . $matches[1]);
            return $matches[1];
        }
        
        // Try to extract any numeric ID from the string (at least 10 digits)
        if (preg_match('/(\d{10,})/', $urlOrId, $matches)) {
            error_log("extractMatchIdFromUrl: Extracted numeric ID: " . $matches[1]);
            return $matches[1];
        }
        
        error_log("extractMatchIdFromUrl: Failed to extract match ID");
        return null;
    }
    
    public function parseMatch($matchId) {
        // Clean matchId - remove any query parameters that might have been included
        $matchId = preg_replace('/[?&].*$/', '', $matchId);
        $matchId = trim($matchId);
        
        $url = $this->baseUrl . $matchId;
        
        error_log("parseMatch: Fetching URL: $url for matchId: $matchId");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20); // 20 seconds timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8); // 8 seconds connection timeout
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5); // Maximum redirects
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_ENCODING, ''); // Accept any encoding (gzip, deflate, etc.)
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Accept-Encoding: gzip, deflate, br',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
            'Cache-Control: no-cache'
        ]);
        
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        
        // curl_close() is deprecated in PHP 8.5+, but still works
        if (function_exists('curl_close')) {
            @curl_close($ch);
        }
        
        // Check for cURL errors
        if ($curlErrno !== 0) {
            error_log("cURL error for match $matchId: $curlError (code: $curlErrno, time: {$totalTime}s, effective URL: $effectiveUrl)");
            return null;
        }
        
        if ($httpCode !== 200 || !$html) {
            error_log("Failed to fetch match $matchId: HTTP $httpCode, HTML length: " . strlen($html ?? '') . ", time: {$totalTime}s, effective URL: $effectiveUrl");
            return null;
        }
        
        // Log successful fetch
        error_log("Successfully fetched match $matchId: HTTP $httpCode, HTML length: " . strlen($html) . ", time: {$totalTime}s");
        
        return $this->extractMatchData($html, $matchId);
    }
    
    private function extractMatchData($html, $matchId) {
        $match = [
            'matchId' => $matchId,
            'map' => $this->extractMap($html, $matchId),
            'score' => $this->extractScore($html, $matchId),
            'teams' => []
        ];
        
        // Extract team data
        $match['teams'] = $this->extractTeams($html, $matchId);
        
        $playerCount = $this->countPlayers($match['teams']);
        error_log("Match $matchId: Found " . count($match['teams']) . " teams, $playerCount players");

        // If no teams found, return null to indicate parsing failure
        if (empty($match['teams']) || $playerCount === 0) {
            error_log("Match $matchId: No teams or players found. Teams count: " . count($match['teams']) . ", Players count: $playerCount");
            // Try to get some debug info
            $htmlLength = strlen($html);
            $hasTable = strpos($html, '<table') !== false;
            $hasProgress = strpos($html, '/progress/') !== false;
            error_log("Match $matchId: HTML length: $htmlLength, Has table: " . ($hasTable ? 'yes' : 'no') . ", Has progress links: " . ($hasProgress ? 'yes' : 'no'));
            return null;
        }
        
        // Fix team order: On scope.gg shows losing team first, then winning team
        // But we parse first 5 as Team 1, next 5 as Team 2
        // Need to swap if Team 1 score > Team 2 score (Team 1 won, should be second)
        $match['teams'] = $this->fixTeamOrder($match['teams'], $match['score']);
        
        return $match;
    }
    
    private function fixTeamOrder($teams, $score) {
        // On scope.gg, the HTML order is: first table = losing team, second table = winning team
        // But we parse it as: first 5 players = Team 1, next 5 = Team 2
        // So if Team 1 won (score.team1 > score.team2), Team 1 should be second in our array
        // But we have Team 1 first, so we need to swap
        
        if (count($teams) !== 2) {
            return $teams;
        }
        
        $team1 = null;
        $team2 = null;
        foreach ($teams as $team) {
            if ($team['teamNumber'] === 1) {
                $team1 = $team;
            } elseif ($team['teamNumber'] === 2) {
                $team2 = $team;
            }
        }
        
        if (!$team1 || !$team2) {
            return $teams;
        }
        
        // If Team 1 won (score.team1 > score.team2), Team 1 should be second team in array
        // But we have Team 1 first, so swap
        if ($score['team1'] > $score['team2']) {
            // Team 1 won, swap: Team 1 becomes Team 2, Team 2 becomes Team 1
            $team1['teamNumber'] = 2;
            $team2['teamNumber'] = 1;
            return [$team2, $team1]; // Team 2 (was Team 1) first, Team 1 (was Team 2) second
        }
        // If Team 2 won, order is correct (Team 1 first, Team 2 second)
        
        return $teams;
    }
    
    private function extractMap($html, $matchId = null) {
        // Try to find map name in HTML
        if (preg_match('/<title>.*?(mirage|dust2|inferno|overpass|nuke|vertigo|ancient|anubis).*?<\/title>/i', $html, $matches)) {
            return strtolower($matches[1]);
        }
        
        // Alternative: look for map in body
        if (preg_match('/\b(mirage|dust2|inferno|overpass|nuke|vertigo|ancient|anubis)\b/i', $html, $matches)) {
            return strtolower($matches[1]);
        }
        
        return 'unknown';
    }
    
    private function extractScore($html, $matchId = null) {
        // First try DOM parsing for more accurate extraction
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        
        // Pattern 1: Look for structure like "Team 2" followed by number, then ":", then number, then "Team 1"
        // This is the most common format on scope.gg
        $team2Nodes = $xpath->query("//*[contains(text(), 'Team 2')]");
        foreach ($team2Nodes as $team2Node) {
            // Get parent and look for score structure nearby
            $parent = $team2Node->parentNode;
            if ($parent) {
                $parentText = $parent->textContent;
                // Look for pattern: Team 2, number, :, number, Team 1
                if (preg_match('/Team\s+2[^0-9]*(\d{1,2})\s*:\s*(\d{1,2})[^0-9]*Team\s+1/i', $parentText, $matches)) {
                    $score2 = (int)$matches[1]; // Team 2 score
                    $score1 = (int)$matches[2]; // Team 1 score
                    if ($this->isValidScore($score1, $score2)) {
                        return [
                            'team1' => $score1,
                            'team2' => $score2
                        ];
                    }
                }
            }
        }
        
        // Pattern 2: Look for text nodes containing "Team X" and score nearby
        $teamNodes = $xpath->query("//*[contains(text(), 'Team 1') or contains(text(), 'Team 2')]");
        foreach ($teamNodes as $node) {
            // Get surrounding context (parent and siblings)
            $parent = $node->parentNode;
            if ($parent) {
                $text = $parent->textContent;
                // Look for pattern like "Team 2 11 : 13 Team 1"
                if (preg_match('/Team\s+2[^0-9]*(\d{1,2})\s*:\s*(\d{1,2})[^0-9]*Team\s+1/i', $text, $matches)) {
                    $score1 = (int)$matches[2];
                    $score2 = (int)$matches[1];
                    if ($this->isValidScore($score1, $score2)) {
                        return [
                            'team1' => $score1,
                            'team2' => $score2
                        ];
                    }
                }
                if (preg_match('/Team\s+1[^0-9]*(\d{1,2})\s*:\s*(\d{1,2})[^0-9]*Team\s+2/i', $text, $matches)) {
                    $score1 = (int)$matches[1];
                    $score2 = (int)$matches[2];
                    if ($this->isValidScore($score1, $score2)) {
                        return [
                            'team1' => $score1,
                            'team2' => $score2
                        ];
                    }
                }
            }
        }
        
        // Pattern 3: Look for score in specific HTML structure (scope.gg format)
        // Usually score is in a heading or large text element
        $headings = $xpath->query("//h1 | //h2 | //h3 | //*[contains(@class, 'score')] | //*[contains(@class, 'result')]");
        foreach ($headings as $heading) {
            $text = $heading->textContent;
            if (preg_match('/(\d{1,2})\s*:\s*(\d{1,2})/', $text, $matches)) {
                $score1 = (int)$matches[1];
                $score2 = (int)$matches[2];
                // Skip if looks like time
                if (!preg_match('/\d{1,2}:\d{1,2}:\d{1,2}/', $text)) {
                    if ($this->isValidScore($score1, $score2)) {
                        return [
                            'team1' => $score1,
                            'team2' => $score2
                        ];
                    }
                }
            }
        }
        
        // Fallback to regex patterns - more flexible matching
        $foundScores = [];
        
        // Pattern 1: Look for "Team 2" followed by number, colon, number, "Team 1" (most common on scope.gg)
        // Allow for HTML tags and whitespace between elements
        if (preg_match('/Team\s+2[^>]*>.*?(\d{1,2})[^<]*<[^>]*>.*?:\s*.*?(\d{1,2})[^<]*<[^>]*>.*?Team\s+1/is', $html, $matches)) {
            $score2 = (int)$matches[1]; // Team 2 score
            $score1 = (int)$matches[2]; // Team 1 score
            if ($this->isValidScore($score1, $score2)) {
                $foundScores[] = [
                    'team1' => $score1,
                    'team2' => $score2,
                    'confidence' => 10
                ];
            }
        }
        
        // Pattern 1b: Simpler pattern for "Team 2 11 : 13 Team 1" (allowing HTML tags)
        if (preg_match('/Team\s+2[^0-9]*(\d{1,2})\s*:\s*(\d{1,2})[^0-9]*Team\s+1/is', $html, $matches)) {
            $score2 = (int)$matches[1]; // Team 2 score
            $score1 = (int)$matches[2]; // Team 1 score
            if ($this->isValidScore($score1, $score2)) {
                $foundScores[] = [
                    'team1' => $score1,
                    'team2' => $score2,
                    'confidence' => 10
                ];
            }
        }
        
        // Pattern 2: Look for "Team 1 X : Y Team 2" format
        if (preg_match('/Team\s+1[^0-9]*(\d{1,2})\s*:\s*(\d{1,2})[^0-9]*Team\s+2/is', $html, $matches)) {
            $score1 = (int)$matches[1]; // Team 1 score
            $score2 = (int)$matches[2]; // Team 2 score
            if ($this->isValidScore($score1, $score2)) {
                $foundScores[] = [
                    'team1' => $score1,
                    'team2' => $score2,
                    'confidence' => 10
                ];
            }
        }
        
        // Pattern 3: Look for score near "Win" or "Loss" text
        if (preg_match('/(?:Win|Loss)[^0-9<]*(\d{1,2})\s*:\s*(\d{1,2})/i', $html, $matches)) {
            $score1 = (int)$matches[1];
            $score2 = (int)$matches[2];
            if ($this->isValidScore($score1, $score2)) {
                $foundScores[] = [
                    'team1' => $score1,
                    'team2' => $score2,
                    'confidence' => 9
                ];
            }
        }
        
        // Pattern 4: Look for score in format like "11 : 13" with reasonable context
        // Extract all potential scores and filter
        if (preg_match_all('/(\d{1,2})\s*:\s*(\d{1,2})/', $html, $allMatches, PREG_SET_ORDER)) {
            foreach ($allMatches as $match) {
                $score1 = (int)$match[1];
                $score2 = (int)$match[2];
                
                // Skip if looks like time (e.g., 9:32:10)
                $context = substr($html, max(0, strpos($html, $match[0]) - 10), 30);
                if (preg_match('/\d{1,2}:\d{1,2}:\d{1,2}/', $context)) {
                    continue;
                }
                
                // Skip if both numbers are > 30 (likely not a match score)
                if ($score1 > 30 && $score2 > 30) {
                    continue;
                }
                
                if ($this->isValidScore($score1, $score2)) {
                    $confidence = 5;
                    // Higher confidence if at least one team has 13+ (win condition)
                    if ($score1 >= 13 || $score2 >= 13) {
                        $confidence = 7;
                    }
                    // Higher confidence if total rounds is reasonable (16-50)
                    $totalRounds = $score1 + $score2;
                    if ($totalRounds >= 16 && $totalRounds <= 50) {
                        $confidence += 1;
                    }
                    
                    $foundScores[] = [
                        'team1' => $score1,
                        'team2' => $score2,
                        'confidence' => $confidence
                    ];
                }
            }
        }
        
        // If we found scores, pick the one with highest confidence
        if (!empty($foundScores)) {
            // Remove duplicates
            $uniqueScores = [];
            foreach ($foundScores as $score) {
                $key = $score['team1'] . ':' . $score['team2'];
                if (!isset($uniqueScores[$key]) || $uniqueScores[$key]['confidence'] < $score['confidence']) {
                    $uniqueScores[$key] = $score;
                }
            }
            
            // Sort by confidence (higher first), then by total score (higher first)
            usort($uniqueScores, function($a, $b) {
                if ($a['confidence'] !== $b['confidence']) {
                    return $b['confidence'] - $a['confidence'];
                }
                $totalA = $a['team1'] + $a['team2'];
                $totalB = $b['team1'] + $b['team2'];
                return $totalB - $totalA;
            });
            
            return [
                'team1' => $uniqueScores[0]['team1'],
                'team2' => $uniqueScores[0]['team2']
            ];
        }
        
        return ['team1' => 0, 'team2' => 0];
    }
    
    private function isValidScore($score1, $score2) {
        // CS2 match scores are usually:
        // - Each team: 0-30 (regular time) or up to 50+ (overtime)
        // - At least one team must have 13+ to win (or 16+ in OT)
        // - Total rounds: usually 16-50 (can be more in OT)
        if ($score1 < 0 || $score1 > 60 || $score2 < 0 || $score2 > 60) {
            return false;
        }
        
        $totalRounds = $score1 + $score2;
        
        // Match must have at least 16 rounds (minimum for a win)
        if ($totalRounds < 16) {
            return false;
        }
        
        // At least one team should have 13+ (win condition) or both should be close
        if ($score1 >= 13 || $score2 >= 13) {
            return true;
        }
        
        // Or if it's overtime, both teams can have 12+
        if ($score1 >= 12 && $score2 >= 12 && $totalRounds >= 24) {
            return true;
        }
        
        return false;
    }
    
    private function extractTeams($html, $matchId = null) {
        $teams = [];
        
        // Use DOM parsing as primary method - it's more reliable
        $teams = $this->extractTeamsFromDOM($html);
        
        // If DOM parsing didn't find all players, try regex as fallback
        if (empty($teams) || $this->countPlayers($teams) < 10) {
            $playersData = $this->extractPlayersFromHTML($html);
            
            if (!empty($playersData) && count($playersData) >= 10) {
                // Group players by teams (usually 5 players per team)
                $team1Players = array_slice($playersData, 0, 5);
                $team2Players = array_slice($playersData, 5, 5);
                
                if (!empty($team1Players)) {
                    $teams = [];
                    $teams[] = [
                        'teamNumber' => 1,
                        'players' => $team1Players
                    ];
                }
                
                if (!empty($team2Players)) {
                    $teams[] = [
                        'teamNumber' => 2,
                        'players' => $team2Players
                    ];
                }
            }
        }
        
        return $teams;
    }
    
    private function countPlayers($teams) {
        $count = 0;
        foreach ($teams as $team) {
            $count += count($team['players']);
        }
        return $count;
    }
    
    private function extractPlayersFromHTML($html) {
        $players = [];
        
        // More comprehensive pattern to find player links with names
        // Pattern: <a href="/progress/ID">Name</a> or <a href="/progress/ID" ...>Name</a>
        preg_match_all('/<a[^>]*href=["\']\/progress\/(\d+)["\'][^>]*>(.*?)<\/a>/is', $html, $playerLinks, PREG_SET_ORDER);
        
        // Extract player names from links
        $playerNames = [];
        foreach ($playerLinks as $link) {
            $playerId = $link[1];
            $linkContent = $link[2];
            
            // Skip if already processed
            if (in_array($playerId, array_column($players, 'playerId'))) {
                continue;
            }
            
            // Extract name from link content (remove HTML tags)
            $playerName = trim(strip_tags($linkContent));
            if (empty($playerName) || strlen($playerName) < 2) {
                continue;
            }
            
            // Try to find avatar
            $avatar = $this->findPlayerAvatar($html, $playerId, $playerName);
            
            $players[] = [
                'name' => $playerName,
                'playerId' => $playerId,
                'avatar' => $avatar
            ];
        }
        
        // If we found player names, try to match them with stats from tables
        if (!empty($players)) {
            $players = $this->matchPlayersWithStats($html, $players);
        }
        
        return $players;
    }
    
    private function findPlayerAvatar($html, $playerId, $playerName) {
        $patterns = [
            // Pattern 1: img inside link with player ID - most common case
            '/<a[^>]*href=["\']\/progress\/' . preg_quote($playerId, '/') . '["\'][^>]*>.*?<img[^>]*src=["\']([^"\']+)["\'][^>]*>.*?<\/a>/is',
            // Pattern 1b: img inside link with player ID (different order)
            '/<a[^>]*href=["\']\/progress\/' . preg_quote($playerId, '/') . '["\'][^>]*>.*?<img[^>]*src=["\']([^"\']+)["\'][^>]*>/is',
            // Pattern 2: img with src containing player ID or avatar path
            '/<img[^>]*src=["\']([^"\']*\/progress\/' . preg_quote($playerId, '/') . '[^"\']*)["\'][^>]*>/i',
            // Pattern 3: img with src containing avatar and player ID
            '/<img[^>]*src=["\']([^"\']*avatar[^"\']*' . preg_quote($playerId, '/') . '[^"\']*)["\'][^>]*>/i',
            // Pattern 4: img near player name in alt or title
            '/<img[^>]*(?:alt|title)=["\'][^"\']*' . preg_quote($playerName, '/') . '[^"\']*["\'][^>]*src=["\']([^"\']+)["\'][^>]*>/i',
            // Pattern 5: img src with player ID in URL path (e.g., /avatars/ID.jpg)
            '/<img[^>]*src=["\']([^"\']*\/(?:avatar|image|img)[^"\']*' . preg_quote($playerId, '/') . '[^"\']*)["\'][^>]*>/i',
            // Pattern 6: Look for img near progress link (within 200 chars)
            '/<a[^>]*href=["\']\/progress\/' . preg_quote($playerId, '/') . '["\'][^>]*>.*?<\/a>.*?<img[^>]*src=["\']([^"\']+)["\'][^>]*>/is',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $avatarUrl = trim($matches[1]);
                
                // Skip data URIs, empty URLs, and placeholder images
                if (empty($avatarUrl) || 
                    strpos($avatarUrl, 'data:') === 0 || 
                    strpos($avatarUrl, 'placeholder') !== false ||
                    strpos($avatarUrl, 'default') !== false) {
                    continue;
                }
                
                // Convert relative URLs to absolute
                if (strpos($avatarUrl, 'http') !== 0) {
                    if (strpos($avatarUrl, '//') === 0) {
                        $avatarUrl = 'https:' . $avatarUrl;
                    } elseif (strpos($avatarUrl, '/') === 0) {
                        $avatarUrl = 'https://app.scope.gg' . $avatarUrl;
                    } else {
                        $avatarUrl = 'https://app.scope.gg/' . $avatarUrl;
                    }
                }
                
                // Verify it's a valid image URL
                if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg)/i', $avatarUrl) || strpos($avatarUrl, 'avatar') !== false || strpos($avatarUrl, 'steamstatic') !== false) {
                    return $avatarUrl;
                }
            }
        }
        
        // Try to find avatar in a context window around the player link
        $linkPos = strpos($html, '/progress/' . $playerId);
        if ($linkPos !== false) {
            $context = substr($html, max(0, $linkPos - 500), 1000);
            // Look for img tags in context
            if (preg_match('/<img[^>]*src=["\']([^"\']+)["\'][^>]*>/i', $context, $contextMatch)) {
                $avatarUrl = trim($contextMatch[1]);
                if (!empty($avatarUrl) && strpos($avatarUrl, 'data:') !== 0) {
                    if (strpos($avatarUrl, 'http') !== 0) {
                        if (strpos($avatarUrl, '//') === 0) {
                            $avatarUrl = 'https:' . $avatarUrl;
                        } elseif (strpos($avatarUrl, '/') === 0) {
                            $avatarUrl = 'https://app.scope.gg' . $avatarUrl;
                        } else {
                            $avatarUrl = 'https://app.scope.gg/' . $avatarUrl;
                        }
                    }
                    if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg)/i', $avatarUrl) || strpos($avatarUrl, 'avatar') !== false || strpos($avatarUrl, 'steamstatic') !== false) {
                        return $avatarUrl;
                    }
                }
            }
        }
        
        // Default avatar URL
        return 'https://app.scope.gg/images/default-avatar.png';
    }
    
    private function matchPlayersWithStats($html, $players) {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        
        // Find all tables with stats
        $tables = $xpath->query("//table");
        
        $statsRows = [];
        foreach ($tables as $table) {
            $rows = $xpath->query(".//tr", $table);
            foreach ($rows as $row) {
                $cells = $xpath->query(".//td", $row);
                if ($cells->length >= 10) {
                    $cellValues = [];
                    foreach ($cells as $cell) {
                        $cellValues[] = trim($cell->textContent);
                    }
                    
                    // Check if first cell is numeric (stats row)
                    if (is_numeric($cellValues[0])) {
                        $statsRows[] = $cellValues;
                    }
                }
            }
        }
        
        // Match players with stats (assuming same order)
        $matchedPlayers = [];
        for ($i = 0; $i < min(count($players), count($statsRows)); $i++) {
            $player = $players[$i];
            $stats = $statsRows[$i];
            
            $player['kills'] = (int)$stats[0];
            $player['deaths'] = (int)$stats[1];
            $player['assists'] = (int)$stats[2];
            $player['damage'] = (int)$stats[3];
            $player['adr'] = isset($stats[4]) ? (float)$stats[4] : 0;
            $player['adrDiff'] = isset($stats[5]) ? (float)$stats[5] : 0;
            $player['hltvRating'] = isset($stats[6]) ? (float)$stats[6] : 0;
            $player['kast'] = isset($stats[7]) ? (float)$stats[7] : 0;
            $player['openKills'] = isset($stats[8]) ? (int)$stats[8] : 0;
            $player['tradeKills'] = isset($stats[9]) ? (int)$stats[9] : 0;
            
            $matchedPlayers[] = $player;
        }
        
        // Add remaining players without stats
        for ($i = count($matchedPlayers); $i < count($players); $i++) {
            $matchedPlayers[] = $players[$i];
        }
        
        return $matchedPlayers;
    }
    
    private function extractTeamsFromDOM($html) {
        $teams = [];
        
        // First, use regex to extract all player links with names from HTML
        // This is more reliable than DOM parsing for scope.gg structure
        $playerMap = [];
        $playerLinks = [];
        
        error_log("extractTeamsFromDOM: Starting extraction, HTML length: " . strlen($html));
        
        // Pattern 1: <a href="/progress/ID">Name</a> - simple pattern
        preg_match_all('/<a[^>]*href=["\']\/progress\/(\d+)["\'][^>]*>([^<]+)<\/a>/i', $html, $matches1, PREG_SET_ORDER);
        foreach ($matches1 as $match) {
            $playerId = $match[1];
            $playerName = trim(strip_tags($match[2]));
            $playerName = html_entity_decode($playerName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $playerName = preg_replace('/\s+/', ' ', $playerName);
            $playerName = trim($playerName);
            if (!empty($playerName) && strlen($playerName) > 1 && strlen($playerName) < 50 && !is_numeric($playerName)) {
                if (!isset($playerMap[$playerId])) {
                    $playerMap[$playerId] = $playerName;
                    $playerLinks[] = ['id' => $playerId, 'name' => $playerName];
                }
            }
        }
        
        // Pattern 2: <a href="/progress/ID"><img alt="Name" ...></a> - extract from img alt/title
        preg_match_all('/<a[^>]*href=["\']\/progress\/(\d+)["\'][^>]*>.*?<img[^>]*(?:alt|title)=["\']([^"\']+)["\'][^>]*>.*?<\/a>/is', $html, $matches2, PREG_SET_ORDER);
        foreach ($matches2 as $match) {
            $playerId = $match[1];
            $playerName = trim($match[2]);
            $playerName = html_entity_decode($playerName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Clean up: remove "scope.gg", "avatar" and extra whitespace
            $playerName = preg_replace('/\bscope\.gg\b/i', '', $playerName);
            $playerName = preg_replace('/\bavatar\b/i', '', $playerName);
            $playerName = preg_replace('/\s+/', ' ', $playerName);
            $playerName = trim($playerName);
            if (!empty($playerName) && strlen($playerName) > 1 && strlen($playerName) < 50 && !is_numeric($playerName)) {
                if (!isset($playerMap[$playerId])) {
                    $playerMap[$playerId] = $playerName;
                    $playerLinks[] = ['id' => $playerId, 'name' => $playerName];
                }
            }
        }
        
        // Pattern 3: <a href="/progress/ID"><img ...></a> - get text after link or in parent
        preg_match_all('/<a[^>]*href=["\']\/progress\/(\d+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches3, PREG_SET_ORDER);
        foreach ($matches3 as $match) {
            $playerId = $match[1];
            $linkContent = $match[2];
            
            // Try to get name from img alt/title inside link
            if (preg_match('/<img[^>]*(?:alt|title)=["\']([^"\']+)["\'][^>]*>/i', $linkContent, $imgMatch)) {
                $playerName = trim($imgMatch[1]);
            } else {
                // Remove img tags and get text
                $linkContent = preg_replace('/<img[^>]*>/i', '', $linkContent);
                $playerName = trim(strip_tags($linkContent));
            }
            
            $playerName = html_entity_decode($playerName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Clean up: remove "scope.gg", "avatar" and extra whitespace
            $playerName = preg_replace('/\bscope\.gg\b/i', '', $playerName);
            $playerName = preg_replace('/\bavatar\b/i', '', $playerName);
            $playerName = preg_replace('/\s+/', ' ', $playerName);
            $playerName = trim($playerName);
            
            if (!empty($playerName) && strlen($playerName) > 1 && strlen($playerName) < 50 && !is_numeric($playerName)) {
                if (!isset($playerMap[$playerId])) {
                    $playerMap[$playerId] = $playerName;
                    $playerLinks[] = ['id' => $playerId, 'name' => $playerName];
                }
            }
        }
        
        // Pattern 4: Look for text content near progress links (in same table cell or row)
        // Find links and then look for text in surrounding context
        preg_match_all('/<a[^>]*href=["\']\/progress\/(\d+)["\'][^>]*>.*?<\/a>/is', $html, $linkMatches, PREG_SET_ORDER);
        foreach ($linkMatches as $linkMatch) {
            $playerId = $linkMatch[1];
            if (isset($playerMap[$playerId])) {
                continue; // Already found
            }
            
            // Look for text in a 200 character window around the link
            $linkPos = strpos($html, $linkMatch[0]);
            if ($linkPos !== false) {
                $context = substr($html, max(0, $linkPos - 100), 300);
                // Try to find player name in context (look for text that's not HTML)
                if (preg_match('/<a[^>]*href=["\']\/progress\/' . preg_quote($playerId, '/') . '["\'][^>]*>.*?<\/a>\s*([^\s<]{2,30})/is', $context, $nameMatch)) {
                    $playerName = trim($nameMatch[1]);
                    if (!empty($playerName) && !is_numeric($playerName) && strlen($playerName) < 50) {
                        $playerMap[$playerId] = $playerName;
                        $playerLinks[] = ['id' => $playerId, 'name' => $playerName];
                    }
                }
            }
        }
        
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        
        // Find all tables with player stats
        $tables = $xpath->query("//table");
        
        $allStatsRows = [];
        
        // Collect all stats rows from all tables
        foreach ($tables as $table) {
            $rows = $xpath->query(".//tr", $table);
            foreach ($rows as $row) {
                $cells = $xpath->query(".//td", $row);
                if ($cells->length >= 10) {
                    $cellValues = [];
                    foreach ($cells as $cell) {
                        $cellValues[] = trim($cell->textContent);
                    }
                    
                    // Check if first cell is numeric (stats row)
                    if (is_numeric($cellValues[0])) {
                        $allStatsRows[] = ['row' => $row, 'stats' => $cellValues, 'table' => $table];
                    }
                }
            }
        }
        
        // Match stats rows with player links by order
        // Usually first 5 stats rows = first 5 player links = team 1, next 5 = team 2
        $allPlayers = [];
        $playerLinkIndex = 0;
        
        foreach ($allStatsRows as $statsData) {
            $row = $statsData['row'];
            $cellValues = $statsData['stats'];
            
            $player = [
                    'kills' => (int)$cellValues[0],
                    'deaths' => (int)$cellValues[1],
                    'assists' => (int)$cellValues[2],
                    'damage' => (int)$cellValues[3],
                    'adr' => (float)$cellValues[4],
                    'adrDiff' => isset($cellValues[5]) ? (float)$cellValues[5] : 0,
                    'hltvRating' => isset($cellValues[6]) ? (float)$cellValues[6] : 0,
                    'kast' => isset($cellValues[7]) ? (float)$cellValues[7] : 0,
                    'openKills' => isset($cellValues[8]) ? (int)$cellValues[8] : 0,
                'tradeKills' => isset($cellValues[9]) ? (int)$cellValues[9] : 0
            ];
            
            // Try to find player name and ID
            // First, try to get from player links array by index
            if (isset($playerLinks[$playerLinkIndex])) {
                $player['playerId'] = $playerLinks[$playerLinkIndex]['id'];
                $player['name'] = $playerLinks[$playerLinkIndex]['name'];
                $playerLinkIndex++;
            } else {
                // Fallback: try to find in row
                $links = $xpath->query(".//a[contains(@href, '/progress/')]", $row);
                if ($links->length > 0) {
                    $link = $links->item(0);
                    $href = $link->getAttribute('href');
                    if (preg_match('/\/progress\/(\d+)/', $href, $matches)) {
                        $player['playerId'] = $matches[1];
                        // Get name from map
                        if (isset($playerMap[$player['playerId']])) {
                            $player['name'] = $playerMap[$player['playerId']];
                        } else {
                            $player['name'] = trim($link->textContent);
                        }
                    }
                }
            }
            
            // Final check - if we have playerId but no name, try map
            if (!empty($player['playerId']) && (empty($player['name']) || strpos($player['name'], 'Player ') === 0)) {
                if (isset($playerMap[$player['playerId']])) {
                    $player['name'] = $playerMap[$player['playerId']];
                }
            }
            
            // If still no playerId, try to get from playerLinks by position
            if (empty($player['playerId']) && isset($playerLinks[count($allPlayers)])) {
                $player['playerId'] = $playerLinks[count($allPlayers)]['id'];
                $player['name'] = $playerLinks[count($allPlayers)]['name'];
            }
            
            // Try to find avatar using playerId
            if (!empty($player['playerId']) && !isset($player['avatar'])) {
                $player['avatar'] = $this->findPlayerAvatar($html, $player['playerId'], $player['name'] ?? '');
            }
            
            // Fallback: try to get avatar from images in the row
            if (!isset($player['avatar']) || $player['avatar'] === 'https://app.scope.gg/images/default-avatar.png') {
                $images = $xpath->query(".//img", $row);
                foreach ($images as $img) {
                    $avatarSrc = $img->getAttribute('src');
                    if (!empty($avatarSrc) && strpos($avatarSrc, 'data:') !== 0) {
                        if (strpos($avatarSrc, 'http') !== 0) {
                            if (strpos($avatarSrc, '//') === 0) {
                                $avatarSrc = 'https:' . $avatarSrc;
                            } elseif (strpos($avatarSrc, '/') === 0) {
                                $avatarSrc = 'https://app.scope.gg' . $avatarSrc;
                            } else {
                                $avatarSrc = 'https://app.scope.gg/' . $avatarSrc;
                            }
                        }
                        $player['avatar'] = $avatarSrc;
                        break;
                    }
                }
            }
                
                if (empty($player['name'])) {
                    $player['name'] = 'Player ' . (count($allPlayers) + 1);
                }
                
                if (!isset($player['avatar'])) {
                    $player['avatar'] = 'https://app.scope.gg/images/default-avatar.png';
                }
                
                // Check if we already have this player (by playerId)
                $isDuplicate = false;
                if (!empty($player['playerId'])) {
                    foreach ($allPlayers as $existingPlayer) {
                        if (isset($existingPlayer['playerId']) && $existingPlayer['playerId'] === $player['playerId']) {
                            $isDuplicate = true;
                            break;
                        }
                    }
                }
                
                if (!$isDuplicate) {
                    $allPlayers[] = $player;
                }
            }
        
        // Group players into teams
        // NOTE: On scope.gg, the first table is usually Team 2 (losing team), second is Team 1 (winning team)
        // But we need to match teams with scores correctly
        // We'll assign based on the order found, but need to verify with score later
        if (!empty($allPlayers)) {
            $team1Players = array_slice($allPlayers, 0, 5);
            $team2Players = array_slice($allPlayers, 5, 5);
            
            if (!empty($team1Players)) {
                $teams[] = [
                    'teamNumber' => 1,
                    'players' => $team1Players
                ];
            }
            
            if (!empty($team2Players)) {
                $teams[] = [
                    'teamNumber' => 2,
                    'players' => $team2Players
                ];
            }
        }
        
        // Log extraction results
        error_log("extractTeamsFromDOM: Found " . count($playerLinks) . " player links, " . count($allStatsRows) . " stats rows, " . count($allPlayers) . " total players");
        $totalPlayers = $this->countPlayers($teams);
        error_log("extractTeamsFromDOM: Teams: " . count($teams) . ", Total players: $totalPlayers");
        
        // Log extraction results
        error_log("extractTeamsFromDOM: Found " . count($playerLinks) . " player links, " . count($allStatsRows) . " stats rows, " . count($allPlayers) . " total players");
        error_log("extractTeamsFromDOM: Teams: " . count($teams) . ", Total players: $totalPlayers");
        
        // If we didn't get 10 players (5 per team), try to find more
        if ($totalPlayers < 10) {
            error_log("extractTeamsFromDOM: Only found $totalPlayers players, trying to find additional players");
            // Try to find players in other parts of HTML
            $processedPlayers = [];
            foreach ($allPlayers as $p) {
                if (!empty($p['playerId'])) {
                    $processedPlayers[$p['playerId']] = true;
                }
            }
            $additionalPlayers = $this->findAdditionalPlayers($html, $processedPlayers);
            if (!empty($additionalPlayers)) {
                error_log("extractTeamsFromDOM: Found " . count($additionalPlayers) . " additional players");
                // Try to add them to existing teams or create new teams
                $this->mergeAdditionalPlayers($teams, $additionalPlayers, $html);
                $totalPlayers = $this->countPlayers($teams);
                error_log("extractTeamsFromDOM: After merging, total players: $totalPlayers");
            }
        }
        
        error_log("extractTeamsFromDOM: Final result - " . count($teams) . " teams, $totalPlayers players");
        return $teams;
    }
    
    private function findAdditionalPlayers($html, $processedPlayers) {
        $players = [];
        
        // Try to find all player links we might have missed
        preg_match_all('/<a[^>]*href=["\']\/progress\/(\d+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $playerId = $match[1];
            $linkContent = $match[2];
            
            // Skip if already processed
            if (isset($processedPlayers[$playerId])) {
                continue;
            }
            
            // Extract name from link content
            $playerName = trim(strip_tags($linkContent));
            if (empty($playerName) || strlen($playerName) < 2) {
                continue;
            }
            
            // Try to find avatar
            $avatar = $this->findPlayerAvatar($html, $playerId, $playerName);
            
            $players[] = [
                'name' => $playerName,
                'playerId' => $playerId,
                'avatar' => $avatar,
                'kills' => 0,
                'deaths' => 0,
                'assists' => 0,
                'damage' => 0,
                'adr' => 0,
                'adrDiff' => 0,
                'hltvRating' => 0,
                'kast' => 0,
                'openKills' => 0,
                'tradeKills' => 0
            ];
        }
        
        return $players;
    }
    
    private function mergeAdditionalPlayers(&$teams, $additionalPlayers, $html) {
        // For each additional player, try to add them to teams
        foreach ($additionalPlayers as $player) {
            // Try to add to first team that has less than 5 players
            $added = false;
            foreach ($teams as &$team) {
                if (count($team['players']) < 5) {
                    $team['players'][] = $player;
                    $added = true;
                    break;
                }
            }
            
            // If all teams are full or no teams exist, create new team
            if (!$added) {
                if (count($teams) < 2) {
                    $teams[] = [
                        'teamNumber' => count($teams) + 1,
                        'players' => [$player]
                    ];
                }
            }
        }
    }
}
