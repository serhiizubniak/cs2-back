<?php

/**
 * Dependency-free checks for highlight clips. Run with:
 *
 *     php tests/highlights_test.php
 *
 * The pure checks (payload validation, cleanup schedule, SigV4 signing, public
 * URLs) always run. The database checks only run when a database is reachable
 * via the usual env (.env / DATABASE_URL / PG*); they use a throwaway match id
 * and clean up after themselves. R2 is never contacted. No framework, in the
 * style of tests/ingest_test.php.
 */

require_once __DIR__ . '/../src/Env.php';
Env::load(__DIR__ . '/../.env');

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Highlight.php';
require_once __DIR__ . '/../src/R2Client.php';

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

/** The payload the recorder sends, per the agreed contract. */
function samplePayload(array $overrides = []): array
{
    return array_merge([
        'matchId'    => '2779927906597830',
        'playerId'   => '192104407',
        'steamId64'  => '76561198152370135',
        'playerName' => 'Orion',
        'round'      => 7,
        'kind'       => '3k',
        'score'      => 39,
        'tags'       => ['headshot'],
        'startTick'  => 12148,
        'endTick'    => 13138,
        'objectKey'  => '2779927906597830/192104407_r07_3k.mp4',
        'sizeBytes'  => 47200000,
    ], $overrides);
}

function without(array $payload, string $field): array
{
    unset($payload[$field]);
    return $payload;
}

// ---------------------------------------------------------------------------
echo "\nValidation (Highlight::validate)\n";

check(!throwsInvalid(fn() => Highlight::validate(samplePayload())), 'the contract sample is accepted');
check(!throwsInvalid(fn() => Highlight::validate(samplePayload(['matchId' => '-7688518421519457510']))), 'a negative scope match id is accepted');
check(!throwsInvalid(fn() => Highlight::validate(samplePayload(['playerId' => 192104407]))), 'playerId as a JSON integer is tolerated');
check(!throwsInvalid(fn() => Highlight::validate([
    'matchId' => '1', 'playerId' => '2', 'round' => 0, 'kind' => '1v2', 'objectKey' => '1/2_r00_1v2.mp4',
])), 'optional fields may be omitted');

foreach (['matchId', 'playerId', 'round', 'kind', 'objectKey'] as $field) {
    check(throwsInvalid(fn() => Highlight::validate(without(samplePayload(), $field))), "missing $field is rejected");
}
check(throwsInvalid(fn() => Highlight::validate(samplePayload(['steamId64' => 76561198152370135]))), 'steamId64 as a JSON number is rejected');
check(throwsInvalid(fn() => Highlight::validate(samplePayload(['round' => -1]))), 'a negative round is rejected');
check(throwsInvalid(fn() => Highlight::validate(samplePayload(['round' => '7']))), 'round as a string is rejected');
check(throwsInvalid(fn() => Highlight::validate(samplePayload(['objectKey' => '/abs/key.mp4']))), 'an absolute object key is rejected');
check(throwsInvalid(fn() => Highlight::validate(samplePayload(['objectKey' => 'a/../b.mp4']))), 'an object key with ".." is rejected');
check(throwsInvalid(fn() => Highlight::validate(samplePayload(['objectKey' => 'a//b.mp4']))), 'an object key with an empty segment is rejected');
check(throwsInvalid(fn() => Highlight::validate(samplePayload(['tags' => 'headshot']))), 'tags as a string is rejected');
check(throwsInvalid(fn() => Highlight::validate(samplePayload(['tags' => ['a' => 'headshot']]))), 'tags as an object is rejected');
check(throwsInvalid(fn() => Highlight::validate(samplePayload(['startTick' => 500, 'endTick' => 100]))), 'endTick before startTick is rejected');
check(throwsInvalid(fn() => Highlight::validate(samplePayload(['matchId' => '12 34']))), 'a match id with spaces is rejected');

// ---------------------------------------------------------------------------
echo "\nRecord (Highlight::toRecord)\n";

$record = Highlight::toRecord(samplePayload(['playerId' => 192104407, 'kind' => '3K', 'tags' => null, 'playerName' => '  ']));
check($record['playerId'] === '192104407', 'integer playerId becomes a string');
check($record['kind'] === '3k', 'kind is lower-cased');
check($record['tags'] === [], 'missing tags become an empty array');
check($record['playerName'] === null, 'a blank player name becomes null');
check($record['steamId64'] === '76561198152370135', 'steamId64 is kept verbatim');
check(Highlight::toRecord(samplePayload(['matchId' => '-7688518421519457510']))['matchId'] === '-7688518421519457510', 'the leading minus of a match id survives');

// ---------------------------------------------------------------------------
echo "\nStorage usage (Highlight::storageUsage)\n";

$usage = Highlight::storageUsage([
    ['key' => 'm/fav.mp4',    'size' => 3000, 'lastModified' => ''],
    ['key' => 'm/clip.mp4',   'size' => 2000, 'lastModified' => ''],
    ['key' => 'm/clip2.mp4',  'size' => 500,  'lastModified' => ''],
    ['key' => 'anything.mp4', 'size' => 1000, 'lastModified' => ''],
], ['m/fav.mp4'], ['m/clip.mp4', 'm/clip2.mp4', 'm/not-in-bucket.mp4'], 10000);
check($usage['usedBytes'] === 6500 && $usage['objectCount'] === 4, 'totals every object in the bucket');
check($usage['favoriteBytes'] === 3000 && $usage['favoriteObjects'] === 1, 'objects of favourite clips are kept');
check($usage['clipBytes'] === 2500 && $usage['clipObjects'] === 2, 'objects of other clips are counted separately');
check($usage['orphanBytes'] === 1000 && $usage['orphanObjects'] === 1, 'objects no clip row points at are orphans');
check($usage['reclaimableBytes'] === 3500 && $usage['reclaimableObjects'] === 3, 'a cleanup would free clips plus orphans');
check($usage['usedPercent'] === 65.0 && $usage['limitBytes'] === 10000, 'usage is reported against the limit');
check(Highlight::storageUsage([], [], [], null)['usedPercent'] === null, 'no limit means no percentage');

// ---------------------------------------------------------------------------
echo "\nSigV4 (R2Client::authorization) against AWS's published S3 examples\n";

$exampleKeyId  = 'AKIAIOSFODNN7EXAMPLE';
$exampleSecret = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
$emptyHash     = hash('sha256', '');

$auth = R2Client::authorization('GET', '/test.txt', [], [
    'Host'                 => 'examplebucket.s3.amazonaws.com',
    'Range'                => 'bytes=0-9',
    'x-amz-content-sha256' => $emptyHash,
    'x-amz-date'           => '20130524T000000Z',
], $emptyHash, $exampleKeyId, $exampleSecret, 'us-east-1', 's3');
check(
    $auth === 'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,'
        . 'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date,'
        . 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
    'GET Object example'
);

$auth = R2Client::authorization('GET', '/', ['prefix' => 'J', 'max-keys' => '2'], [
    'host'                 => 'examplebucket.s3.amazonaws.com',
    'x-amz-content-sha256' => $emptyHash,
    'x-amz-date'           => '20130524T000000Z',
], $emptyHash, $exampleKeyId, $exampleSecret, 'us-east-1', 's3');
check(
    substr($auth, -64) === '34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7',
    'GET Bucket (list objects) example, query parameters given out of order'
);

// ---------------------------------------------------------------------------
echo "\nPublic URL (R2Client::publicUrl)\n";

check(R2Client::publicUrl(null, 'a/b.mp4') === null, 'no public origin means no URL');
check(R2Client::publicUrl('', 'a/b.mp4') === null, 'an empty public origin means no URL');
check(
    R2Client::publicUrl('https://pub-x.r2.dev/', '-7688518421519457510/192104407_r07_3k.mp4')
        === 'https://pub-x.r2.dev/-7688518421519457510/192104407_r07_3k.mp4',
    'trailing slash on the origin is tolerated and the key is kept as is'
);
check(
    R2Client::publicUrl('https://clips.example.com', 'dir/with space/кліп.mp4')
        === 'https://clips.example.com/dir/with%20space/%D0%BA%D0%BB%D1%96%D0%BF.mp4',
    'each key segment is percent-encoded, slashes are kept'
);

// ---------------------------------------------------------------------------
echo "\nDatabase (Db highlight methods — requires a database)\n";

$dbReady = false;
try {
    Db::pdo()->query("SELECT 1 FROM highlights LIMIT 1");
    $dbReady = true;
} catch (Throwable $e) {
    echo "  SKIP: database or highlights table not available (" . $e->getMessage() . ")\n";
}

if ($dbReady) {
    $matchId = 'test-hl-' . bin2hex(random_bytes(4));
    $admin   = '190077542'; // in MAP_VOTE_ADMIN_IDS
    try {
        $first = Db::upsertHighlight(Highlight::toRecord(samplePayload(['matchId' => $matchId, 'objectKey' => "$matchId/1_r07_3k.mp4"])));
        check($first['inserted'] === true, 'first publish inserts');
        check($first['highlight']['matchId'] === $matchId && $first['highlight']['round'] === 7, 'stored row reads back');
        check($first['highlight']['tags'] === ['headshot'] && $first['highlight']['isFavorite'] === false, 'tags decode and new clips are not favourites');
        check($first['highlight']['map'] === null, 'a clip whose match is not stored has no map');
        $id = $first['highlight']['id'];

        Db::upsertHighlight(Highlight::toRecord(samplePayload([
            'matchId' => $matchId, 'playerId' => '999000001', 'round' => 3, 'objectKey' => "$matchId/2_r03_1v2.mp4", 'kind' => '1v2',
        ])));
        $byMatch = Db::getHighlightsByMatch($matchId);
        check(count($byMatch) === 2 && $byMatch[0]['round'] === 3 && $byMatch[1]['round'] === 7, 'a match lists its clips in round order');

        $fav = Db::favoriteHighlight($id, 'voter-a', 'Voter A');
        check($fav['isFavorite'] === true && $fav['favoritedBy'] === 'voter-a' && $fav['favoritedByName'] === 'Voter A', 'favourite records who set it');
        $fav = Db::favoriteHighlight($id, 'voter-b', 'Voter B');
        check($fav['favoritedBy'] === 'voter-a', 'favouriting again keeps the first voter');

        $again = Db::upsertHighlight(Highlight::toRecord(samplePayload([
            'matchId' => $matchId, 'kind' => '4k', 'objectKey' => "$matchId/1_r07_4k.mp4",
        ])));
        check($again['inserted'] === false && $again['highlight']['id'] === $id, 're-publishing the same (match, player, round) updates in place');
        check($again['highlight']['kind'] === '4k' && $again['highlight']['isFavorite'] === true, 're-publishing refreshes metadata and keeps the favourite');

        check(throwsInvalid(fn() => Db::unfavoriteHighlight($id, 'voter-b', false)), 'someone else cannot unfavourite');
        $unfav = Db::unfavoriteHighlight($id, 'voter-a', false);
        check($unfav['isFavorite'] === false && $unfav['favoritedBy'] === null, 'whoever favourited can unfavourite');

        Db::favoriteHighlight($id, 'voter-a', 'Voter A');
        $unfav = Db::unfavoriteHighlight($id, $admin, true);
        check($unfav['isFavorite'] === false, 'an admin can unfavourite anyone');

        check(Db::favoriteHighlight(PHP_INT_MAX, 'voter-a', 'Voter A') === null, 'favouriting an unknown id returns null');
        check(Db::unfavoriteHighlight(PHP_INT_MAX, 'voter-a', false) === null, 'unfavouriting an unknown id returns null');

        check(Db::findPlayerProfile('0000000000') === null, 'an unknown player has no profile');
        $anyPlayer = Db::pdo()->query(
            "SELECT p->>'playerId' FROM matches m,
                    jsonb_array_elements(m.match_data->'teams') t, jsonb_array_elements(t->'players') p
             WHERE jsonb_typeof(m.match_data->'teams') = 'array' AND p->>'playerId' <> '' LIMIT 1"
        )->fetchColumn();
        if ($anyPlayer !== false) {
            $profile = Db::findPlayerProfile($anyPlayer);
            check($profile !== null && $profile['name'] !== '', 'a player from stored matches has a name');
        }
    } finally {
        Db::pdo()->prepare('DELETE FROM highlights WHERE match_id = ?')->execute([$matchId]);
    }
}

echo "\n" . ($failures === 0 ? 'OK' : 'FAILED') . ": $count checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
