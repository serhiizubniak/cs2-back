<?php

/**
 * Dependency-free checks for the Scope Tap ingest feature. Run with:
 *
 *     php tests/ingest_test.php
 *
 * The pure (adapter/validation/auth) checks always run. The idempotency checks
 * only run when a database is reachable via the usual env (DATABASE_URL / PG*);
 * they use a throwaway match id and clean up after themselves. No framework,
 * in the style of scripts/*.
 */

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/ExtensionMatch.php';

$failures = 0;
$count    = 0;

function check($cond, string $msg): void
{
    global $failures, $count;
    $count++;
    if ($cond) {
        echo "  PASS: $msg\n";
    } else {
        echo "  FAIL: $msg\n";
        $failures++;
    }
}

function throwsInvalid(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (InvalidArgumentException $e) {
        return true;
    }
}

/** Find a player across both teams by steamId64. */
function findPlayer(array $matchData, string $steam): ?array
{
    foreach ($matchData['teams'] ?? [] as $team) {
        foreach ($team['players'] ?? [] as $player) {
            if (($player['steamId64'] ?? null) === $steam) {
                return $player;
            }
        }
    }
    return null;
}

// The ТЗ sample payload, plus a second player on the other team so we exercise
// grouping and a non-100 KAST value.
$sample = [
    'matchId'    => '864163569323850',
    'capturedAt' => '2026-07-23T10:11:12.000Z',
    'source'     => 'scope.gg',
    'data'       => [
        'matchId'      => '864163569323850',
        'map'          => 'de_mirage',
        'mapDisplay'   => 'Mirage',
        'playedAt'     => '2026-07-22T18:04:24.967Z',
        'isCS2'        => true,
        'roundsInHalf' => 12,
        'score'        => [8, 13],
        'teams'        => ['Team 2', 'Team 1'],
        'rounds'       => [
            ['index' => 0, 'winnerSide' => 2, 'winnerTeam' => 1, 'reason' => 'TerroristsWin'],
        ],
        'players' => [
            [
                'playerId' => 296934395,
                'steamId64' => '76561198257200123',
                'name' => 'mucho_busy',
                'avatar' => 'https://avatars.steamstatic.com/a.jpg',
                'teamIndex' => 1, 'teamName' => 'Team 1', 'won' => true,
                'kills' => 22, 'deaths' => 16, 'assists' => 6,
                'adr' => 131.67, 'rating2' => 1.674, 'kastPercent' => 100,
                'hsPercentRifle' => 11.67,
                'openKills' => 3, 'tradeKills' => 2, 'clutchesWon' => 0,
                'mvp' => 3, 'damage' => 2765, 'roundsPlayed' => 21,
                'favouriteWeapon' => 'AK-47',
            ],
            [
                'playerId' => 123456789,
                'steamId64' => '76561198000000001',
                'name' => 'enemy_guy',
                'avatar' => 'https://avatars.steamstatic.com/b.jpg',
                'teamIndex' => 0, 'teamName' => 'Team 2', 'won' => false,
                'kills' => 15, 'deaths' => 20, 'assists' => 4,
                'adr' => 82.4, 'rating2' => 0.95, 'kastPercent' => 66,
                'hsPercentRifle' => 30.0,
                'openKills' => 1, 'tradeKills' => 1, 'clutchesWon' => 1,
                'mvp' => 1, 'damage' => 1730, 'roundsPlayed' => 21,
                'favouriteWeapon' => 'M4A1-S',
            ],
        ],
    ],
];

echo "Adapter (ExtensionMatch::toMatchData)\n";
$result    = ExtensionMatch::toMatchData($sample);
$matchData = $result['matchData'];

check($result['id'] === '864163569323850', 'match id preserved as string');
check($result['url'] === 'https://app.scope.gg/matches/864163569323850', 'url synthesized from match id');
check($result['map'] === 'mirage', 'map normalized: de_mirage -> mirage');
check($result['matchTime'] === '2026-07-22T18:04:24.967Z', 'match_time column value = playedAt ISO');
check($result['score'] === ['team1' => 13, 'team2' => 8], 'score keyed by team number (13-8, sides not swapped)');
check($matchData['isCs2'] === true, 'isCS2 -> isCs2');
check($matchData['matchTimeIso'] === '2026-07-22T18:04:24.967Z', 'matchTimeIso = playedAt');
check($matchData['rounds'][0]['reason'] === 'TerroristsWin', 'raw rounds preserved under match_data.rounds');

$p1 = findPlayer($matchData, '76561198257200123');
check($p1 !== null, 'mucho_busy present in match_data');
check($p1['steamId64'] === '76561198257200123', 'steamId64 kept byte-for-byte as string');
check(is_string($p1['playerId']) && $p1['playerId'] === '296934395', 'playerId coerced to string');
check($p1['hltvRating'] === 1.67, 'rating2 -> hltvRating (rounded to 2)');
check($p1['kast'] === 100, 'kastPercent 100 -> kast 100 (NOT multiplied by 100)');
check($p1['adr'] === 131.67, 'adr preserved');
check($p1['favouriteWeapon'] === 'AK-47', 'favouriteWeapon preserved');
check($p1['won'] === true, 'winning player flagged won');

$p2 = findPlayer($matchData, '76561198000000001');
check($p2 !== null && $p2['kast'] === 66, 'kastPercent 66 -> kast 66 (NOT 6600)');
check($p2 !== null && $p2['won'] === false, 'losing player flagged not won');

// Byte-exact survival through a JSON round-trip (what JSONB storage does).
$roundTrip = json_decode(json_encode($matchData, JSON_UNESCAPED_UNICODE), true);
check(
    findPlayer($roundTrip, '76561198257200123')['steamId64'] === '76561198257200123',
    'steamId64 survives json encode/decode byte-for-byte'
);

echo "\nValidation (ExtensionMatch::validate)\n";
check(!throwsInvalid(fn() => ExtensionMatch::validate($sample)), 'valid payload passes');

$noId = $sample;
unset($noId['matchId'], $noId['data']['matchId']);
check(throwsInvalid(fn() => ExtensionMatch::validate($noId)), 'missing matchId rejected');

$numSteam = $sample;
$numSteam['data']['players'][0]['steamId64'] = 76561198257200123; // number, not string
check(throwsInvalid(fn() => ExtensionMatch::validate($numSteam)), 'numeric steamId64 rejected (must be string)');

$badTeam = $sample;
$badTeam['data']['players'][0]['teamIndex'] = 2;
check(throwsInvalid(fn() => ExtensionMatch::validate($badTeam)), 'teamIndex out of {0,1} rejected');

$badScore = $sample;
$badScore['data']['score'] = 'nope';
check(throwsInvalid(fn() => ExtensionMatch::validate($badScore)), 'non-array score rejected');

$noPlayers = $sample;
$noPlayers['data']['players'] = [];
check(throwsInvalid(fn() => ExtensionMatch::validate($noPlayers)), 'empty players rejected');

echo "\nAuth compare (hash_equals, as used by the handler)\n";
$secret = 'super-secret-value';
check(hash_equals($secret, $secret) === true, 'correct secret accepted');
check(hash_equals($secret, 'wrong') === false, 'wrong secret rejected');
check(hash_equals($secret, '') === false, 'empty secret rejected');

echo "\nIdempotency (Db::upsertMatch — requires a database)\n";
$dbAvailable = false;
try {
    Db::pdo();
    $dbAvailable = true;
} catch (Throwable $e) {
    echo "  SKIP: DB not reachable (" . $e->getMessage() . ")\n";
}

if ($dbAvailable) {
    $testId = 'test-ingest-scope-tap';
    Db::deleteMatch($testId); // start clean

    $testBody = $sample;
    $testBody['matchId'] = $testId;
    $testBody['data']['matchId'] = $testId;
    $m = ExtensionMatch::toMatchData($testBody);

    $ins1 = Db::upsertMatch($testId, $m['url'], $m['map'], $m['score'], $m['matchData'], $m['matchTime']);
    check($ins1 === true, 'first upsert inserts the match');

    $ins2 = Db::upsertMatch($testId, $m['url'], $m['map'], $m['score'], $m['matchData'], $m['matchTime']);
    check($ins2 === false, 'second upsert is a no-op (idempotent, no duplicate row)');

    check(Db::matchExists($testId), 'match present after upsert');

    $stored = Db::getMatchData([$testId])[$testId] ?? null;
    $storedPlayer = $stored ? findPlayer($stored, '76561198257200123') : null;
    check(
        $storedPlayer !== null && $storedPlayer['steamId64'] === '76561198257200123',
        'steamId64 round-trips byte-for-byte through the database'
    );

    Db::deleteMatch($testId); // cleanup
    check(!Db::matchExists($testId), 'cleanup removed the test match');
}

echo "\n" . ($failures === 0 ? "OK" : "FAILED") . ": $count checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
