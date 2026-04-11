<?php
/**
 * One-shot import: backup-YYYY-MM-DD/ -> Postgres.
 *
 * Usage:
 *   php scripts/import.php /path/to/backup-YYYY-MM-DD
 *
 * Expects inside the backup dir:
 *   - matches.json          { success, matches: [...] }
 *   - jokers.json           { success, jokers:  [...] }
 *   - parsed/<matchId>.json { success, match: {...} }  (optional, one file per match)
 *
 * Idempotent: skips rows that already exist. Safe to re-run.
 */

require_once __DIR__ . '/../src/Db.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/import.php <backup-dir>\n");
    exit(1);
}

$backupDir = rtrim($argv[1], '/');
if (!is_dir($backupDir)) {
    fwrite(STDERR, "Not a directory: $backupDir\n");
    exit(1);
}

$matchesFile = "$backupDir/matches.json";
$jokersFile  = "$backupDir/jokers.json";
$parsedDir   = "$backupDir/parsed";

function readJson(string $file): ?array {
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

echo "== Importing from $backupDir ==\n";

try {
    Db::pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$parsedByMatchId = [];
if (is_dir($parsedDir)) {
    foreach (glob("$parsedDir/*.json") as $file) {
        $payload = readJson($file);
        if ($payload && !empty($payload['success']) && isset($payload['match']['matchId'])) {
            $parsedByMatchId[$payload['match']['matchId']] = $payload['match'];
        }
    }
    echo "- parsed/ : " . count($parsedByMatchId) . " match_data files\n";
} else {
    echo "- parsed/ : (none)\n";
}

$matchesPayload = readJson($matchesFile);
if (!$matchesPayload || !isset($matchesPayload['matches']) || !is_array($matchesPayload['matches'])) {
    fwrite(STDERR, "matches.json missing or invalid\n");
    exit(1);
}

$inserted = 0;
$skipped  = 0;
$withData = 0;

Db::transaction(function (PDO $pdo) use ($matchesPayload, $parsedByMatchId, &$inserted, &$skipped, &$withData) {
    foreach ($matchesPayload['matches'] as $m) {
        $id = $m['id'] ?? null;
        if (!$id) continue;

        if (Db::matchExists($id)) {
            $skipped++;
            continue;
        }

        $matchData = $parsedByMatchId[$id] ?? null;
        Db::insertMatch(
            (string) $id,
            (string) ($m['url'] ?? ''),
            isset($m['map']) ? (string) $m['map'] : null,
            is_array($m['score'] ?? null) ? $m['score'] : [],
            $matchData
        );
        $inserted++;
        if ($matchData !== null) $withData++;
    }
});

echo "- matches : inserted=$inserted (with match_data=$withData), skipped=$skipped\n";

$jokersPayload = readJson($jokersFile);
$jokerInserted = 0;
$jokerSkipped  = 0;
if ($jokersPayload && isset($jokersPayload['jokers']) && is_array($jokersPayload['jokers'])) {
    Db::transaction(function (PDO $pdo) use ($jokersPayload, &$jokerInserted, &$jokerSkipped) {
        foreach ($jokersPayload['jokers'] as $j) {
            $id = $j['id'] ?? null;
            if (!$id) continue;

            if (Db::getJoker((string) $id) !== null) {
                $jokerSkipped++;
                continue;
            }

            Db::insertJoker(
                (string) $id,
                (string) ($j['name'] ?? 'Joker'),
                (float) ($j['rating'] ?? 1.0),
                (string) ($j['avatar'] ?? '')
            );
            $jokerInserted++;
        }
    });
}
echo "- jokers  : inserted=$jokerInserted, skipped=$jokerSkipped\n";

$totalMatches = Db::pdo()->query('SELECT count(*) FROM matches')->fetchColumn();
$totalJokers  = Db::pdo()->query('SELECT count(*) FROM jokers')->fetchColumn();
echo "== Done. DB now has: matches=$totalMatches, jokers=$totalJokers ==\n";
