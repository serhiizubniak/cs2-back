<?php

class TeamBalancer {
    
    /**
     * Balance 10 players into 2 teams of 5 players each
     * Uses HLTV Rating to create balanced teams with randomization for uniqueness
     * 
     * @param array $players Array of player data with hltvRating
     * @return array ['team1' => [...], 'team2' => [...], 'team1Rating' => float, 'team2Rating' => float]
     */
    public function balanceTeams($players) {
        if (count($players) !== 10) {
            throw new Exception('Exactly 10 players required for team balancing');
        }
        
        // Shuffle players first to add randomness
        shuffle($players);
        
        // Sort players by rating (descending) after shuffle
        usort($players, function($a, $b) {
            $ratingA = $a['hltvRating'] ?? 0;
            $ratingB = $b['hltvRating'] ?? 0;
            return $ratingB <=> $ratingA;
        });
        
        // Try multiple random distributions and pick the best balanced one
        $bestBalance = null;
        $bestDiff = PHP_FLOAT_MAX;
        
        // Try 5 different random distributions
        for ($attempt = 0; $attempt < 5; $attempt++) {
            // Shuffle again for this attempt
            if ($attempt > 0) {
                shuffle($players);
                usort($players, function($a, $b) {
                    $ratingA = $a['hltvRating'] ?? 0;
                    $ratingB = $b['hltvRating'] ?? 0;
                    return $ratingB <=> $ratingA;
                });
            }
            
            // Initialize teams
            $team1 = [];
            $team2 = [];
            $team1Rating = 0;
            $team2Rating = 0;
            
            // Distribute players ensuring exactly 5 per team
            // Use snake draft with controlled randomization
            for ($i = 0; $i < 10; $i++) {
                $player = $players[$i];
                $rating = $player['hltvRating'] ?? 0;
                
                // Ensure we always have exactly 5 players per team
                // If team1 already has 5, add to team2
                if (count($team1) >= 5) {
                    $team2[] = $player;
                    $team2Rating += $rating;
                }
                // If team2 already has 5, add to team1
                else if (count($team2) >= 5) {
                    $team1[] = $player;
                    $team1Rating += $rating;
                }
                // Otherwise use snake draft with some randomization for middle players
                else {
                    // For first 2 and last 2 players, always use snake draft for balance
                    // For middle players (indices 2-7), add randomness
                    $isEdgePlayer = ($i < 2 || $i > 7);
                    $useSnakeDraft = $isEdgePlayer || (rand(1, 100) <= 70);
                    
                    if (($i % 2 === 0 && $useSnakeDraft) || ($i % 2 === 1 && !$useSnakeDraft)) {
                        $team1[] = $player;
                        $team1Rating += $rating;
                    } else {
                        $team2[] = $player;
                        $team2Rating += $rating;
                    }
                }
            }
            
            // Final safety check: ensure exactly 5 players per team
            if (count($team1) !== 5 || count($team2) !== 5) {
                // Redistribute if needed (shouldn't happen, but safety check)
                $team1 = array_slice($team1, 0, 5);
                $team2 = array_slice($team2, 0, 5);
                if (count($team1) < 5) {
                    $needed = 5 - count($team1);
                    $team1 = array_merge($team1, array_slice($team2, 0, $needed));
                    $team2 = array_slice($team2, $needed);
                }
                if (count($team2) < 5) {
                    $needed = 5 - count($team2);
                    $team2 = array_merge($team2, array_slice($team1, 0, $needed));
                    $team1 = array_slice($team1, $needed);
                }
                // Recalculate ratings
                $team1Rating = array_sum(array_column($team1, 'hltvRating'));
                $team2Rating = array_sum(array_column($team2, 'hltvRating'));
            }
            
            // Calculate average ratings
            $team1AvgRating = count($team1) > 0 ? $team1Rating / count($team1) : 0;
            $team2AvgRating = count($team2) > 0 ? $team2Rating / count($team2) : 0;
            $diff = abs($team1AvgRating - $team2AvgRating);
            
            // Keep the best balanced distribution
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestBalance = [
                    'team1' => $team1,
                    'team2' => $team2,
                    'team1Rating' => round($team1Rating, 2),
                    'team2Rating' => round($team2Rating, 2),
                    'team1AvgRating' => round($team1AvgRating, 2),
                    'team2AvgRating' => round($team2AvgRating, 2),
                    'ratingDifference' => round(abs($team1Rating - $team2Rating), 2),
                    'avgRatingDifference' => round($diff, 2)
                ];
            }
        }
        
        return $bestBalance;
    }
    
    /**
     * Try to improve balance by swapping players between teams
     * 
     * @param array $balancedTeams Result from balanceTeams()
     * @return array Improved balanced teams
     */
    public function improveBalance($balancedTeams) {
        $team1 = $balancedTeams['team1'];
        $team2 = $balancedTeams['team2'];
        
        // Ensure both teams have exactly 5 players
        if (count($team1) !== 5 || count($team2) !== 5) {
            return $balancedTeams; // Return original if invalid
        }
        
        $bestDiff = $balancedTeams['avgRatingDifference'];
        $bestTeams = $balancedTeams;
        
        // Try swapping players to improve balance
        for ($i = 0; $i < 5; $i++) {
            for ($j = 0; $j < 5; $j++) {
                // Swap players
                $temp = $team1[$i];
                $team1[$i] = $team2[$j];
                $team2[$j] = $temp;
                
                // Verify teams still have 5 players each (they should, but check)
                if (count($team1) !== 5 || count($team2) !== 5) {
                    // Revert swap if invalid
                    $temp = $team1[$i];
                    $team1[$i] = $team2[$j];
                    $team2[$j] = $temp;
                    continue;
                }
                
                // Calculate new ratings
                $team1Rating = array_sum(array_column($team1, 'hltvRating'));
                $team2Rating = array_sum(array_column($team2, 'hltvRating'));
                $team1AvgRating = $team1Rating / 5;
                $team2AvgRating = $team2Rating / 5;
                $diff = abs($team1AvgRating - $team2AvgRating);
                
                // If this is better, keep it
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $bestTeams = [
                        'team1' => $team1,
                        'team2' => $team2,
                        'team1Rating' => round($team1Rating, 2),
                        'team2Rating' => round($team2Rating, 2),
                        'team1AvgRating' => round($team1AvgRating, 2),
                        'team2AvgRating' => round($team2AvgRating, 2),
                        'ratingDifference' => round(abs($team1Rating - $team2Rating), 2),
                        'avgRatingDifference' => round($diff, 2)
                    ];
                } else {
                    // Revert swap
                    $temp = $team1[$i];
                    $team1[$i] = $team2[$j];
                    $team2[$j] = $temp;
                }
            }
        }
        
        // Final validation
        if (count($bestTeams['team1']) !== 5 || count($bestTeams['team2']) !== 5) {
            return $balancedTeams; // Return original if something went wrong
        }
        
        return $bestTeams;
    }
}
