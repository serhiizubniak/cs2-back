<?php
/**
 * One-shot fix: restore matches.added_at from a backup JSON.
 *
 * Background: the initial import used DEFAULT now() inside a transaction,
 * which assigns the transaction start time to every row. Result: all rows
 * got identical timestamps, so ORDER BY added_at is non-deterministic.
 *
 * Usage:
 *   DATABASE_URL=... php scripts/fix-added-at.php /path/to/backup-YYYY-MM-DD
 */

require_once __DIR__ . '/../src/Db.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/fix-added-at.php <backup-dir>\n");
    exit(1);
}

$backupDir   = rtrim($argv[1], '/');
$matchesFile = "$backupDir/matches.json";

if (!file_exists($matchesFile)) {
    fwrite(STDERR, "Not found: $matchesFile\n");
    exit(1);
}

$payload = json_decode(file_get_contents($matchesFile), true);
if (!$payload || !isset($payload['matches']) || !is_array($payload['matches'])) {
    fwrite(STDERR, "matches.json missing 'matches' array\n");
    exit(1);
}

$updated = 0;
$missing = 0;

Db::transaction(function (PDO $pdo) use ($payload, &$updated, &$missing) {
    $stmt = $pdo->prepare('UPDATE matches SET added_at = ? WHERE id = ?');
    foreach ($payload['matches'] as $m) {
        $id      = $m['id']      ?? null;
        $addedAt = $m['addedAt'] ?? null;
        if (!$id || !$addedAt) {
            $missing++;
            continue;
        }
        $stmt->execute([(string) $addedAt, (string) $id]);
        if ($stmt->rowCount() > 0) {
            $updated++;
        }
    }
});

echo "Updated: $updated, skipped (no id/addedAt): $missing\n";
