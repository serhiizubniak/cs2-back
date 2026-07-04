<?php

/**
 * Parses scope.gg match pages by extracting the Next.js __NEXT_DATA__
 * hydration blob. This is a structured JSON payload used by scope.gg's own
 * frontend, so it's dramatically more stable and complete than regex-scraping
 * rendered HTML.
 *
 * Output shape is a superset of the previous regex-based parser:
 * legacy per-player fields (kills/deaths/assists/adr/hltvRating/kast/openKills/
 * tradeKills/damage/adrDiff/playerId/name/avatar) are preserved so that
 * StatisticsCalculator and the frontend keep working unchanged. New fields
 * (matchTime, team.won, T/CT splits, aim, grenades, fun metrics, rounds...)
 * live alongside them.
 */
class MatchParser
{
    private string $baseUrl = 'https://app.scope.gg/matches/';

    public function extractMatchIdFromUrl(string $urlOrId): ?string
    {
        $urlOrId = trim($urlOrId);
        if ($urlOrId === '') {
            return null;
        }
        // Match IDs are signed 64-bit ints, so they can be negative
        // (scope.gg now serves URLs like /matches/-7688518421519457510/...).
        if (preg_match('/^-?\d+$/', $urlOrId)) {
            return $urlOrId;
        }
        if (preg_match('~/matches/(-?\d+)~', $urlOrId, $m)) {
            return $m[1];
        }
        if (preg_match('~^-?\d+~', $urlOrId, $m)) {
            return $m[0];
        }
        return null;
    }

    public function parseMatch(string $matchId): ?array
    {
        $matchId = preg_replace('/[?&].*$/', '', trim($matchId));
        $url = $this->baseUrl . $matchId;

        $html = $this->fetch($url, $matchId);
        if ($html === null) {
            return null;
        }

        $payload = $this->extractNextData($html);
        if ($payload === null) {
            error_log("Match $matchId: __NEXT_DATA__ not found or invalid");
            return null;
        }

        $pageProps = $payload['props']['pageProps'] ?? null;
        if (!is_array($pageProps)) {
            error_log("Match $matchId: pageProps missing");
            return null;
        }

        return $this->buildMatch($matchId, $pageProps);
    }

    private function fetch(string $url, string $matchId): ?string
    {
        // scope.gg sits behind a Cloudflare interactive challenge that a plain
        // cURL cannot pass. When FLARESOLVERR_URL is configured we route the
        // request through FlareSolverr (self-hosted headless Chrome), which
        // solves the challenge and returns the real HTML with __NEXT_DATA__
        // intact. Without it we fall back to a direct fetch (works only for
        // non-challenged pages / local dev).
        $flareUrl = getenv('FLARESOLVERR_URL') ?: '';
        if ($flareUrl !== '') {
            return $this->fetchViaFlareSolverr($flareUrl, $url, $matchId);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Accept-Encoding: gzip, deflate, br',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
            ],
        ]);

        $html      = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        if (function_exists('curl_close')) {
            @curl_close($ch);
        }

        if ($curlErrno !== 0) {
            error_log("cURL error for match $matchId: $curlError (code: $curlErrno)");
            return null;
        }
        if ($httpCode !== 200 || !$html) {
            error_log("Failed to fetch match $matchId: HTTP $httpCode, HTML length: " . strlen($html ?? ''));
            return null;
        }

        return $html;
    }

    /**
     * Fetch through a FlareSolverr instance, which drives a real headless
     * Chrome to pass the Cloudflare challenge and returns the solved HTML.
     * FlareSolverr speaks a JSON API (POST /v1) rather than a plain URL.
     */
    private function fetchViaFlareSolverr(string $endpoint, string $url, string $matchId): ?string
    {
        // Cloudflare occasionally serves a heavier challenge that misses the
        // first window; a single retry almost always gets through.
        $resp = $this->flareSolverrRequest($endpoint, $url, $matchId)
             ?? $this->flareSolverrRequest($endpoint, $url, $matchId . ' (retry)');
        if ($resp === null) {
            return null;
        }

        $data = json_decode($resp, true);
        $sol  = is_array($data) ? ($data['solution'] ?? null) : null;
        if (($data['status'] ?? '') !== 'ok' || empty($sol['response'])) {
            error_log("FlareSolverr no solution for match $matchId: " . substr($resp, 0, 200));
            return null;
        }

        // FlareSolverr returns the rendered page; the Cloudflare challenge may
        // have been served first, so guard against that leaking through.
        $solvedHttp = (int) ($sol['status'] ?? 0);
        if ($solvedHttp !== 200) {
            error_log("FlareSolverr solved page non-200 for match $matchId: $solvedHttp");
            return null;
        }

        return $sol['response'];
    }

    /**
     * One POST /v1 round-trip to FlareSolverr. Returns the raw JSON body on
     * HTTP 200, null on any transport error (logged) so the caller can retry.
     */
    private function flareSolverrRequest(string $endpoint, string $url, string $matchId): ?string
    {
        $payload = json_encode([
            'cmd'        => 'request.get',
            'url'        => $url,
            'maxTimeout' => 120000, // ms FlareSolverr waits to solve the challenge
        ]);

        $headers = ['Content-Type: application/json'];
        // A private Hugging Face Space fronts the solver with auth; pass the
        // token through when one is configured.
        $token = getenv('FLARESOLVERR_TOKEN') ?: '';
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 150, // room for Chrome cold start + solve
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $resp      = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        if (function_exists('curl_close')) {
            @curl_close($ch);
        }

        if ($curlErrno !== 0) {
            error_log("FlareSolverr cURL error for match $matchId: $curlError (code: $curlErrno)");
            return null;
        }
        if ($httpCode !== 200 || !$resp) {
            error_log("FlareSolverr request failed for match $matchId: HTTP $httpCode");
            return null;
        }

        return $resp;
    }

    private function extractNextData(string $html): ?array
    {
        // strpos instead of preg_match because the JSON payload can exceed
        // PHP's PCRE backtrack limit on larger pages.
        $marker = '<script id="__NEXT_DATA__" type="application/json">';
        $start  = strpos($html, $marker);
        if ($start === false) {
            return null;
        }
        $start += strlen($marker);
        $end = strpos($html, '</script>', $start);
        if ($end === false) {
            return null;
        }
        $json = substr($html, $start, $end - $start);
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private function buildMatch(string $matchId, array $pp): ?array
    {
        $base   = $pp['Base'] ?? [];
        $sbTeam = $pp['ScoreboardTeamsData'] ?? [];
        $sbVal  = $pp['ScoreboardValues'] ?? [];
        $mData  = $pp['MatchData'] ?? [];

        if (count($base) !== 2 || count($sbTeam) !== 2) {
            error_log("Match $matchId: unexpected Base/ScoreboardTeamsData shape");
            return null;
        }

        $map = $this->normalizeMap($mData['MapName'] ?? ($pp['map']['csgoName'] ?? null));

        $clutches = is_array($pp['clutches'] ?? null) ? $pp['clutches'] : [];
        $weapons  = is_array($pp['weapons']  ?? null) ? $pp['weapons']  : [];

        $teams = [];
        foreach ($base as $idx => $baseTeam) {
            $teams[] = $this->buildTeam($baseTeam, $sbTeam[$idx] ?? [], $sbVal, $clutches, $weapons);
        }

        usort($teams, fn($a, $b) => $a['teamNumber'] <=> $b['teamNumber']);

        $score = [
            'team1' => $this->findByTeamNumber($teams, 1)['score'] ?? 0,
            'team2' => $this->findByTeamNumber($teams, 2)['score'] ?? 0,
        ];

        foreach ($teams as &$t) {
            unset($t['score']);
        }
        unset($t);

        $matchTimeMs = isset($mData['MatchTime']) ? (int) $mData['MatchTime'] : null;

        return [
            'matchId'       => $matchId,
            'map'           => $map,
            'score'         => $score,
            'matchTime'     => $matchTimeMs,
            'matchTimeIso'  => $matchTimeMs ? gmdate('c', intdiv($matchTimeMs, 1000)) : null,
            'isCs2'         => (bool) ($mData['IsCS2'] ?? false),
            'roundsInHalf'  => (int) ($mData['RoundsInHalf'] ?? 12),
            'teams'         => $teams,
            'roundInfos'    => $this->buildRoundInfos($pp['RoundInfos'] ?? []),
        ];
    }

    private function buildTeam(array $baseTeam, array $sbTeam, array $sbVal, array $allClutches, array $allWeapons): array
    {
        $name       = (string) ($baseTeam['Name'] ?? '');
        $teamNumber = (preg_match('/2/', $name)) ? 2 : 1;

        $players = [];
        foreach (($baseTeam['Players'] ?? []) as $p) {
            $pid   = (string) ($p['PlayerID'] ?? '');
            if ($pid === '') continue;
            $stats   = $sbVal[$pid]       ?? null;
            $clBlob  = $allClutches[$pid] ?? null;
            $wBlob   = $allWeapons[$pid]  ?? null;
            $players[] = $this->buildPlayer($p, $stats, $clBlob, $wBlob);
        }

        return [
            'teamNumber' => $teamNumber,
            'name'       => $name,
            'won'        => (bool) ($baseTeam['Won'] ?? false),
            'score'      => (int) ($sbTeam['Score'] ?? 0),
            'tScore'     => (int) ($sbTeam['TScore'] ?? 0),
            'ctScore'    => (int) ($sbTeam['CTScore'] ?? 0),
            'players'    => $players,
            'roles'      => $this->buildTeamRoles($sbTeam),
        ];
    }

    private function buildPlayer(array $p, ?array $stats, ?array $clutchesBlob = null, ?array $weaponsBlob = null): array
    {
        $general = $stats['GeneralStats']['ScoreboardPages'] ?? [];
        $tSide   = $stats['TSideStats']['ScoreboardPages']  ?? [];
        $ctSide  = $stats['CTSideStats']['ScoreboardPages'] ?? [];

        $basic     = $general['Basic']    ?? [];
        $aim       = $general['AIM']      ?? [];
        $grenades  = $general['Grenades'] ?? [];
        $fun       = $general['Fun']      ?? [];

        $kills   = (int)   ($basic['Kills']                   ?? 0);
        $deaths  = (int)   ($basic['Deaths']                  ?? 0);
        $assists = (int)   ($basic['Assists']                 ?? 0);
        $damage  = (int)   ($basic['Damage']                  ?? 0);
        $adrF    = (float) ($basic['AverageDamagePerRound']   ?? 0);
        $adrDiff = (float) ($basic['DamageDiffPerRound']      ?? 0);
        $rating  = (float) ($basic['Rating2']                 ?? 0);
        $kastF   = (float) ($basic['KAST']                    ?? 0);

        return [
            // Legacy-compatible fields (StatisticsCalculator + existing frontend)
            'playerId'   => (string) ($p['PlayerID'] ?? ''),
            'name'       => (string) ($p['Name'] ?? ''),
            'avatar'     => (string) ($p['AvatarURL'] ?? ''),
            'kills'      => $kills,
            'deaths'     => $deaths,
            'assists'    => $assists,
            'damage'     => $damage,
            'adr'        => (int) round($adrF),
            'adrDiff'    => (int) round($adrDiff),
            'hltvRating' => round($rating, 2),
            'kast'       => (int) round($kastF * 100),
            'openKills'  => (int) ($basic['OpenKills']  ?? 0),
            'tradeKills' => (int) ($basic['TradeKills'] ?? 0),

            // New — extended basic
            'mvp'          => (int) ($basic['MVP'] ?? 0),
            'clutchesWon'  => (int) ($basic['ClutchesWon'] ?? 0),
            'roundsPlayed' => (int) ($stats['GeneralStats']['RoundsPlayed'] ?? 0),

            // New — aim
            'accSpottedRifle'     => round((float) ($aim['AccuracySpottedByClass.Rifle']     ?? 0), 4),
            'hsPctRifle'          => round((float) ($aim['HeadshotPercentByClass.Rifle']     ?? 0), 4),
            'hsPctPistol'         => round((float) ($aim['HeadshotPercentByClass.Pistol']    ?? 0), 4),
            'firstBulletAccRifle' => round((float) ($aim['FirstBulletAccuracyByClass.Rifle'] ?? 0), 4),

            // New — grenades
            'flashAssists'         => (int) ($grenades['FlashAssists']          ?? 0),
            'impactfulFlashAssists'=> (int) ($grenades['ImpactfulFlashAssists'] ?? 0),
            'enemiesFlashed'       => (int) ($grenades['EnemiesFlashed']        ?? 0),
            'totalEnemyFlashTime'  => round((float) ($grenades['TotalEnemyFlashTime'] ?? 0), 2),
            'grenadeDamage'        => (int) ($grenades['GrenadeDamage']         ?? 0),
            'smokesUsed'           => (int) ($grenades['SmokesUsed']            ?? 0),
            'enemiesHE'            => (int) ($grenades['EnemiesDamagedWithHE']  ?? 0),
            'enemiesFire'          => (int) ($grenades['EnemiesDamagedWithFire']?? 0),

            // New — T / CT split (compact)
            't'  => $this->buildSideStats($tSide,  $stats['TSideStats']['RoundsPlayed']  ?? 0),
            'ct' => $this->buildSideStats($ctSide, $stats['CTSideStats']['RoundsPlayed'] ?? 0),

            // New — fun
            'chickenKills'       => (int) ($fun['ChickenKills'] ?? 0),
            'knifeKills'         => (int) ($fun['KnifeKills']   ?? 0),
            'zeusKills'          => (int) ($fun['ZeusKills']    ?? 0),
            'openedDoor'         => (int) ($fun['OpenedDoor']   ?? 0),
            'closedDoor'         => (int) ($fun['ClosedDoor']   ?? 0),
            'closedDoorBehind'   => (int) ($fun['ClosedDoorBehind'] ?? 0),
            'secondsInIsolation' => (int) ($fun['SecondsInIsolation'] ?? 0),

            // New — clutches (1vN breakdown + per-match details)
            'clutches'    => $this->buildClutches($clutchesBlob),

            // New — weapon loadout (kills per weapon ID, summed across hitgroups)
            'weaponKills' => $this->buildWeaponKills($weaponsBlob),
        ];
    }

    /**
     * Compresses scope.gg's clutches[playerId] block into something we can
     * both aggregate across matches and render per-match in the UI.
     *
     * Output:
     *   wonByCount  / lostByCount  : 5-element arrays keyed by enemy count (1..5)
     *   t / ct                     : { won, lost } totals per side
     *   detail                     : per-clutch records used by the match-detail page
     */
    private function buildClutches(?array $blob): array
    {
        $emptyByCount = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $result = [
            'wonByCount'  => $emptyByCount,
            'lostByCount' => $emptyByCount,
            't'           => ['won' => 0, 'lost' => 0],
            'ct'          => ['won' => 0, 'lost' => 0],
            'detail'      => [],
        ];
        if (!is_array($blob)) return $result;

        foreach (['T', 'CT'] as $sideKey) {
            $sideLc = strtolower($sideKey);
            $sideData = $blob[$sideKey] ?? null;
            if (!is_array($sideData)) continue;

            foreach (['Won' => true, 'Lost' => false] as $resultKey => $isWin) {
                $list = $sideData[$resultKey] ?? null;
                if (!is_array($list)) continue;

                foreach ($list as $clutch) {
                    if (!is_array($clutch)) continue;
                    $count = (int) ($clutch['EnemiesCount'] ?? count($clutch['Enemies'] ?? []));
                    if ($count < 1) continue;
                    if ($count > 5) $count = 5;

                    if ($isWin) {
                        $result['wonByCount'][$count]++;
                        $result[$sideLc]['won']++;
                    } else {
                        $result['lostByCount'][$count]++;
                        $result[$sideLc]['lost']++;
                    }

                    $enemies = [];
                    foreach (($clutch['Enemies'] ?? []) as $e) {
                        if (!is_array($e)) continue;
                        $enemies[] = [
                            'playerId'   => (string) ($e['PlayerID']   ?? ''),
                            'mainWeapon' => (int) ($e['MainWeapon']    ?? 0),
                            'hp'         => (int) ($e['HP']            ?? 0),
                            'hasKit'     => (bool) ($e['HasDefuseKit'] ?? false),
                        ];
                    }

                    $result['detail'][] = [
                        'side'    => $sideLc,
                        'won'     => $isWin,
                        'count'   => $count,
                        'round'   => (int) ($clutch['RoundIndexMatch'] ?? $clutch['RoundIndexDemo'] ?? 0),
                        'frame'   => (int) ($clutch['FrameNumber']     ?? 0),
                        'enemies' => $enemies,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Compresses scope.gg's weapons[playerId] block into per-weapon kill totals.
     *
     * Output:
     *   general / t / ct : { weaponId(string) => totalKillsAcrossAllHitgroups }
     *   classes          : { classId(string)  => totalKillsForThatClass } (rifle/pistol/sniper buckets)
     *
     * weaponId comes from DetailedStats.WeaponKills (specific weapon).
     * classId comes from Kills (broad weapon class — used as fallback when an
     * unknown weapon ID can't be name-resolved on the frontend).
     */
    private function buildWeaponKills(?array $blob): array
    {
        $extractDetailed = function (?array $side) {
            $out = [];
            $wk = $side['DetailedStats']['WeaponKills'] ?? null;
            if (!is_array($wk)) return $out;
            foreach ($wk as $wid => $hgMap) {
                if (!is_array($hgMap)) continue;
                $sum = 0;
                foreach ($hgMap as $n) $sum += (int) $n;
                if ($sum > 0) $out[(string) $wid] = $sum;
            }
            return $out;
        };

        $extractClasses = function (?array $side) {
            $out = [];
            $classes = $side['Kills'] ?? null;
            if (!is_array($classes)) return $out;
            foreach ($classes as $classId => $entry) {
                if (!is_array($entry)) continue;
                $overall = (int) ($entry['Overall'] ?? 0);
                if ($overall > 0) $out[(string) $classId] = $overall;
            }
            return $out;
        };

        if (!is_array($blob)) {
            return ['general' => [], 't' => [], 'ct' => [], 'classes' => []];
        }

        return [
            'general' => $extractDetailed($blob['General'] ?? null),
            't'       => $extractDetailed($blob['T']       ?? null),
            'ct'      => $extractDetailed($blob['CT']      ?? null),
            'classes' => $extractClasses($blob['General']  ?? null),
        ];
    }

    private function buildSideStats(array $pages, $roundsPlayed): array
    {
        $basic = $pages['Basic'] ?? [];
        return [
            'rounds'  => (int) $roundsPlayed,
            'kills'   => (int) ($basic['Kills']    ?? 0),
            'deaths'  => (int) ($basic['Deaths']   ?? 0),
            'assists' => (int) ($basic['Assists']  ?? 0),
            'adr'     => (int) round((float) ($basic['AverageDamagePerRound'] ?? 0)),
            'rating'  => round((float) ($basic['Rating2'] ?? 0), 2),
            'kast'    => (int) round(((float) ($basic['KAST'] ?? 0)) * 100),
        ];
    }

    private function buildTeamRoles(array $sbTeam): array
    {
        $roleKeys = ['EntryFragger','Support','Clutcher','WallBanger','Tradee','Trader','Survivor','SmokeShooter','Unlucky'];
        $roles = [];
        foreach ($roleKeys as $key) {
            $v = $sbTeam[$key] ?? null;
            if (is_array($v) && !empty($v)) {
                $roles[lcfirst($key)] = $v;
            }
        }
        return $roles;
    }

    private function buildRoundInfos(array $rounds): array
    {
        return array_values(array_map(fn($r) => [
            'winner'    => (int) ($r['Winner'] ?? 0),
            'endReason' => (int) ($r['RoundEndReason'] ?? 0),
        ], $rounds));
    }

    private function normalizeMap($raw): string
    {
        $raw = strtolower((string) ($raw ?? ''));
        if ($raw === '') return 'unknown';
        return preg_replace('/^de_/', '', $raw);
    }

    private function findByTeamNumber(array $teams, int $n): ?array
    {
        foreach ($teams as $t) {
            if (($t['teamNumber'] ?? null) === $n) {
                return $t;
            }
        }
        return null;
    }
}
