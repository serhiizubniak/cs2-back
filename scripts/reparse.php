<?php
/**
 * Re-fetch every match from scope.gg via the new __NEXT_DATA__ parser
 * and overwrite match_data + match_time in place.
 *
 * Usage:
 *   DATABASE_URL=... php scripts/reparse.php           # all matches
 *   DATABASE_URL=... php scripts/reparse.php <id> ...  # specific ids
 *
 * Safe to re-run. Skips rows that currently return null from the parser
 * (e.g. scope.gg deleted the match) so one bad apple doesn't stop the loop.
 */

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/MatchParser.php';

try {
    Db::pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$filterIds = array_slice($argv, 1);

$rows = Db::pdo()->query('SELECT id FROM matches ORDER BY added_at ASC')->fetchAll();
$ids  = array_map(fn($r) => $r['id'], $rows);
if ($filterIds) {
    $set = array_flip($filterIds);
    $ids = array_values(array_filter($ids, fn($id) => isset($set[$id])));
}

$total  = count($ids);
$parser = new MatchParser();
$ok     = 0;
$fail   = 0;
$skip   = 0;

echo "== Re-parsing $total matches ==\n";
foreach ($ids as $i => $id) {
    $n = $i + 1;
    try {
        $parsed = $parser->parseMatch($id);
    } catch (Throwable $e) {
        $fail++;
        echo "[$n/$total] FAIL $id (exception: " . $e->getMessage() . ")\n";
        continue;
    }

    if (!$parsed) {
        $skip++;
        echo "[$n/$total] SKIP $id (parser returned null)\n";
        continue;
    }

    $map   = isset($parsed['map']) ? (string) $parsed['map'] : null;
    $score = is_array($parsed['score'] ?? null) ? $parsed['score'] : [];
    $updated = Db::updateMatchFull((string) $id, $map, $score, $parsed);
    if ($updated) {
        $ok++;
        $won = '';
        foreach (($parsed['teams'] ?? []) as $t) {
            if (!empty($t['won'])) $won = "won=team{$t['teamNumber']} ";
        }
        echo "[$n/$total] OK   $id {$won}map={$map} score={$score['team1']}:{$score['team2']} matchTime={$parsed['matchTimeIso']}\n";
    } else {
        $fail++;
        echo "[$n/$total] FAIL $id (updateMatchFull returned 0 rows)\n";
    }
}

echo "== Done. ok=$ok skip=$skip fail=$fail ==\n";
