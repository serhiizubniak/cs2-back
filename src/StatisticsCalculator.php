<?php

class StatisticsCalculator {
    
    public function calculateOverallStatistics($matches) {
        $allPlayers = [];
        $totalMatches = count($matches);
        $totalRounds = 0;
        $maps = [];
        
        // Collect all player data from all matches
        foreach ($matches as $match) {
            $totalRounds += ($match['score']['team1'] + $match['score']['team2']);
            
            if (!in_array($match['map'], $maps)) {
                $maps[] = $match['map'];
            }
            
            foreach ($match['teams'] as $team) {
                foreach ($team['players'] as $player) {
                    $playerName = $player['name'];
                    $playerId = $player['playerId'] ?? null;
                    
                    // Find existing player by playerId first (if available), otherwise by name
                    $key = null;
                    if (!empty($playerId)) {
                        // Look for existing player with same playerId
                        foreach ($allPlayers as $existingKey => $existingData) {
                            if (!empty($existingData['playerId']) && $existingData['playerId'] === $playerId) {
                                $key = $existingKey;
                                break;
                            }
                        }
                    }
                    
                    // If not found by playerId, try to find by name
                    if ($key === null) {
                        if (isset($allPlayers[$playerName])) {
                            $key = $playerName;
                        }
                    }
                    
                    // If still not found, create new entry using name as key
                    if ($key === null) {
                        $key = $playerName;
                        $allPlayers[$key] = [
                            'name' => $playerName,
                            'playerId' => $playerId,
                            'avatar' => $player['avatar'] ?? 'https://app.scope.gg/images/default-avatar.png',
                            'matches' => 0,
                            'kills' => 0,
                            'deaths' => 0,
                            'assists' => 0,
                            'damage' => 0,
                            'adr' => [],
                            'hltvRating' => [],
                            'kast' => [],
                            'openKills' => 0,
                            'tradeKills' => 0,
                            // New aggregates from clutches + weapons
                            'clutchesWonByCount'  => [1=>0, 2=>0, 3=>0, 4=>0, 5=>0],
                            'clutchesLostByCount' => [1=>0, 2=>0, 3=>0, 4=>0, 5=>0],
                            'weaponKills'         => [], // weaponId => totalKills
                            'weaponClassKills'    => [], // classId  => totalKills
                        ];
                    }
                    
                    // If playerId exists and we already have this player, update name to the newest one
                    // (in case player changed their name - always use the latest name)
                    if (!empty($playerId) && !empty($allPlayers[$key]['playerId']) && 
                        $allPlayers[$key]['playerId'] === $playerId) {
                        // Always use the newest name (last encountered)
                        $allPlayers[$key]['name'] = $playerName;
                    }
                    
                    // Update avatar if we found a better one
                    if (isset($player['avatar']) && $player['avatar'] !== 'https://app.scope.gg/images/default-avatar.png') {
                        $allPlayers[$key]['avatar'] = $player['avatar'];
                    }
                    
                    // Update playerId if we found one and didn't have it before
                    if (!empty($playerId) && empty($allPlayers[$key]['playerId'])) {
                        $allPlayers[$key]['playerId'] = $playerId;
                    }
                    
                    $allPlayers[$key]['matches']++;
                    $allPlayers[$key]['kills'] += $player['kills'];
                    $allPlayers[$key]['deaths'] += $player['deaths'];
                    $allPlayers[$key]['assists'] += $player['assists'];
                    $allPlayers[$key]['damage'] += $player['damage'];
                    $allPlayers[$key]['adr'][] = $player['adr'];
                    $allPlayers[$key]['hltvRating'][] = $player['hltvRating'];
                    $allPlayers[$key]['kast'][] = $player['kast'];
                    $allPlayers[$key]['openKills'] += $player['openKills'];
                    $allPlayers[$key]['tradeKills'] += $player['tradeKills'];

                    // Aggregate clutches by enemy count (1..5).
                    if (isset($player['clutches']) && is_array($player['clutches'])) {
                        foreach ([1,2,3,4,5] as $cnt) {
                            $allPlayers[$key]['clutchesWonByCount'][$cnt]
                                += (int) ($player['clutches']['wonByCount'][$cnt] ?? 0);
                            $allPlayers[$key]['clutchesLostByCount'][$cnt]
                                += (int) ($player['clutches']['lostByCount'][$cnt] ?? 0);
                        }
                    }

                    // Aggregate weapon kills (specific weapons + broad classes).
                    if (isset($player['weaponKills']) && is_array($player['weaponKills'])) {
                        foreach (($player['weaponKills']['general'] ?? []) as $wid => $n) {
                            $wid = (string) $wid;
                            $allPlayers[$key]['weaponKills'][$wid] =
                                ($allPlayers[$key]['weaponKills'][$wid] ?? 0) + (int) $n;
                        }
                        foreach (($player['weaponKills']['classes'] ?? []) as $cid => $n) {
                            $cid = (string) $cid;
                            $allPlayers[$key]['weaponClassKills'][$cid] =
                                ($allPlayers[$key]['weaponClassKills'][$cid] ?? 0) + (int) $n;
                        }
                    }
                }
            }
        }
        
        // Calculate averages and totals
        $statistics = [];
        foreach ($allPlayers as $key => $data) {
            $avgAdr = count($data['adr']) > 0 ? array_sum($data['adr']) / count($data['adr']) : 0;
            $avgHltvRating = count($data['hltvRating']) > 0 ? array_sum($data['hltvRating']) / count($data['hltvRating']) : 0;
            $avgKast = count($data['kast']) > 0 ? array_sum($data['kast']) / count($data['kast']) : 0;
            
            $kd = $data['deaths'] > 0 ? $data['kills'] / $data['deaths'] : $data['kills'];
            
            // Derive main weapon: highest-kill specific weapon ID; fall back to
            // the dominant weapon class if specific IDs aren't available.
            $weaponKills = $data['weaponKills'] ?? [];
            $mainWeaponId = null;
            $mainWeaponKills = 0;
            foreach ($weaponKills as $wid => $n) {
                if ($n > $mainWeaponKills) {
                    $mainWeaponKills = $n;
                    $mainWeaponId    = (string) $wid;
                }
            }
            $weaponClassKills = $data['weaponClassKills'] ?? [];
            $mainWeaponClass = null;
            $mainClassKills = 0;
            foreach ($weaponClassKills as $cid => $n) {
                if ($n > $mainClassKills) {
                    $mainClassKills  = $n;
                    $mainWeaponClass = (int) $cid;
                }
            }

            $statistics[] = [
                'name' => $data['name'], // Use the actual name from data, not the array key
                'playerId' => $data['playerId'] ?? null,
                'avatar' => $data['avatar'] ?? 'https://app.scope.gg/images/default-avatar.png',
                'matches' => $data['matches'],
                'kills' => $data['kills'],
                'deaths' => $data['deaths'],
                'assists' => $data['assists'],
                'kd' => round($kd, 2),
                'damage' => $data['damage'],
                'avgDamage' => round($data['damage'] / $data['matches'], 0),
                'adr' => round($avgAdr, 1),
                'hltvRating' => round($avgHltvRating, 2),
                'kast' => round($avgKast, 1),
                'openKills' => $data['openKills'],
                'tradeKills' => $data['tradeKills'],
                // New fields shipped to the frontend
                'clutchesWonByCount'  => array_values($data['clutchesWonByCount']),  // index 0 = 1v1
                'clutchesLostByCount' => array_values($data['clutchesLostByCount']),
                'weaponKills'         => (object) $weaponKills,
                'mainWeaponId'        => $mainWeaponId,
                'mainWeaponKills'     => $mainWeaponKills,
                'mainWeaponClass'     => $mainWeaponClass,
            ];
        }
        
        // Sort by HLTV Rating descending
        usort($statistics, function($a, $b) {
            return $b['hltvRating'] <=> $a['hltvRating'];
        });
        
        return [
            'totalMatches' => $totalMatches,
            'totalRounds' => $totalRounds,
            'maps' => $maps,
            'players' => $statistics
        ];
    }
}
