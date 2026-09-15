<?php

class Db {
    private static ?PDO $pdo = null;

    public static function pdo(): PDO {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dsn = self::buildDsn();
        [$user, $pass] = self::credentials();

        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return self::$pdo;
    }

    private static function buildDsn(): string {
        $url = getenv('DATABASE_URL');
        if ($url) {
            $p = parse_url($url);
            if ($p === false || !isset($p['host'])) {
                throw new RuntimeException('Invalid DATABASE_URL');
            }
            $host = $p['host'];
            $port = $p['port'] ?? 5432;
            $db   = isset($p['path']) ? ltrim($p['path'], '/') : 'postgres';
            return "pgsql:host=$host;port=$port;dbname=$db";
        }

        $host = getenv('PGHOST') ?: 'localhost';
        $port = getenv('PGPORT') ?: '5432';
        $db   = getenv('PGDATABASE') ?: 'cs2_statistics';
        return "pgsql:host=$host;port=$port;dbname=$db";
    }

    private static function credentials(): array {
        $url = getenv('DATABASE_URL');
        if ($url) {
            $p = parse_url($url);
            return [
                isset($p['user']) ? urldecode($p['user']) : null,
                isset($p['pass']) ? urldecode($p['pass']) : null,
            ];
        }
        return [getenv('PGUSER') ?: 'cs2', getenv('PGPASSWORD') ?: 'cs2_local'];
    }

    public static function transaction(callable $fn) {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function getMatches(?string $from = null, ?string $to = null): array {
        $sql = 'SELECT id, url, map, score, added_at, match_time FROM matches';
        $where = [];
        $params = [];
        if ($from !== null) {
            $where[] = 'COALESCE(match_time, added_at) >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = 'COALESCE(match_time, added_at) <= ?';
            $params[] = $to;
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY COALESCE(match_time, added_at) ASC, id ASC';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(fn($r) => [
            'id'        => $r['id'],
            'url'       => $r['url'],
            'map'       => $r['map'],
            'score'     => json_decode($r['score'], true),
            'addedAt'   => self::toIso8601($r['added_at']),
            'matchTime' => $r['match_time'] ? self::toIso8601($r['match_time']) : null,
        ], $stmt->fetchAll());
    }

    public static function matchExists(string $id): bool {
        $stmt = self::pdo()->prepare('SELECT 1 FROM matches WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    public static function insertMatch(
        string $id,
        string $url,
        ?string $map,
        array $score,
        ?array $matchData,
        ?string $addedAt = null,
        ?string $matchTime = null
    ): array {
        $matchTime = $matchTime ?: self::extractMatchTime($matchData);

        $stmt = self::pdo()->prepare(
            'INSERT INTO matches (id, url, map, score, match_data, added_at, match_time)
             VALUES (?, ?, ?, ?::jsonb, ?::jsonb, COALESCE(?::timestamptz, now()), ?::timestamptz)
             RETURNING added_at, match_time'
        );
        $stmt->execute([
            $id,
            $url,
            $map,
            json_encode($score, JSON_UNESCAPED_UNICODE),
            $matchData !== null ? json_encode($matchData, JSON_UNESCAPED_UNICODE) : null,
            $addedAt,
            $matchTime,
        ]);
        $row = $stmt->fetch();

        return [
            'id'        => $id,
            'url'       => $url,
            'map'       => $map,
            'score'     => $score,
            'addedAt'   => self::toIso8601($row['added_at']),
            'matchTime' => $row['match_time'] ? self::toIso8601($row['match_time']) : null,
        ];
    }

    /**
     * Idempotent, non-clobbering insert used by the Scope Tap ingest webhook.
     * Inserts a new match, but leaves an existing row untouched — a match may
     * already have been parsed by the scraper (which produces a richer
     * match_data), and we don't want a leaner extension payload to overwrite
     * it. Returns true when a row was actually inserted, false when the match
     * already existed (both are a successful, dedup-safe outcome). Single
     * statement, so concurrent retries can't race a SELECT/INSERT gap.
     */
    public static function upsertMatch(
        string $id,
        string $url,
        ?string $map,
        array $score,
        array $matchData,
        ?string $matchTime = null
    ): bool {
        $matchTime = $matchTime ?: self::extractMatchTime($matchData);

        $stmt = self::pdo()->prepare(
            'INSERT INTO matches (id, url, map, score, match_data, added_at, match_time)
             VALUES (?, ?, ?, ?::jsonb, ?::jsonb, now(), ?::timestamptz)
             ON CONFLICT (id) DO NOTHING
             RETURNING id'
        );
        $stmt->execute([
            $id,
            $url,
            $map,
            json_encode($score, JSON_UNESCAPED_UNICODE),
            json_encode($matchData, JSON_UNESCAPED_UNICODE),
            $matchTime,
        ]);

        // RETURNING yields a row only when the INSERT actually happened;
        // ON CONFLICT DO NOTHING returns no row for an already-present match.
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Append one row to the ingest receipt log. Kept dependency-free and
     * best-effort — the caller wraps this so a logging failure never masks the
     * real request outcome.
     */
    public static function logIngest(?string $matchId, string $status, ?string $error = null): void {
        $stmt = self::pdo()->prepare(
            'INSERT INTO ingest_log (match_id, status, error) VALUES (?, ?, ?)'
        );
        $stmt->execute([$matchId, $status, $error]);
    }

    public static function updateMatchFull(string $id, ?string $map, array $score, array $matchData): bool {
        $stmt = self::pdo()->prepare(
            'UPDATE matches
             SET map = ?, score = ?::jsonb, match_data = ?::jsonb, match_time = ?::timestamptz
             WHERE id = ?'
        );
        $stmt->execute([
            $map,
            json_encode($score, JSON_UNESCAPED_UNICODE),
            json_encode($matchData, JSON_UNESCAPED_UNICODE),
            self::extractMatchTime($matchData),
            $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    private static function extractMatchTime(?array $matchData): ?string {
        if (!is_array($matchData)) return null;
        $iso = $matchData['matchTimeIso'] ?? null;
        if (is_string($iso) && $iso !== '') return $iso;
        $ms = $matchData['matchTime'] ?? null;
        if (is_int($ms) && $ms > 0) return gmdate('c', intdiv($ms, 1000));
        return null;
    }

    public static function deleteMatch(string $id): bool {
        $stmt = self::pdo()->prepare('DELETE FROM matches WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public static function clearMatches(): void {
        self::pdo()->exec('TRUNCATE TABLE matches');
    }

    public static function updateMatchData(string $id, array $matchData): bool {
        $stmt = self::pdo()->prepare('UPDATE matches SET match_data = ?::jsonb WHERE id = ?');
        $stmt->execute([json_encode($matchData, JSON_UNESCAPED_UNICODE), $id]);
        return $stmt->rowCount() > 0;
    }

    public static function getMatchData(array $ids, ?string $from = null, ?string $to = null): array {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT id, match_data FROM matches
                WHERE id IN ($placeholders) AND match_data IS NOT NULL";
        $params = array_values($ids);
        if ($from !== null) {
            $sql .= ' AND COALESCE(match_time, added_at) >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND COALESCE(match_time, added_at) <= ?';
            $params[] = $to;
        }
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        $result = [];
        while ($row = $stmt->fetch()) {
            $result[$row['id']] = json_decode($row['match_data'], true);
        }
        return $result;
    }

    public static function getJokers(): array {
        $rows = self::pdo()
            ->query('SELECT id, name, rating, avatar, created_at FROM jokers ORDER BY created_at ASC')
            ->fetchAll();

        return array_map(fn($r) => [
            'id'        => $r['id'],
            'name'      => $r['name'],
            'rating'    => (float) $r['rating'],
            'avatar'    => $r['avatar'],
            'createdAt' => self::toIso8601($r['created_at']),
        ], $rows);
    }

    public static function getJoker(string $id): ?array {
        $stmt = self::pdo()->prepare('SELECT id, name, rating, avatar, created_at FROM jokers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id'        => $row['id'],
            'name'      => $row['name'],
            'rating'    => (float) $row['rating'],
            'avatar'    => $row['avatar'],
            'createdAt' => self::toIso8601($row['created_at']),
        ];
    }

    public static function insertJoker(string $id, string $name, float $rating, string $avatar): array {
        $stmt = self::pdo()->prepare(
            'INSERT INTO jokers (id, name, rating, avatar) VALUES (?, ?, ?, ?) RETURNING created_at'
        );
        $stmt->execute([$id, $name, $rating, $avatar]);
        $createdAt = $stmt->fetchColumn();

        return [
            'id'        => $id,
            'name'      => $name,
            'rating'    => $rating,
            'avatar'    => $avatar,
            'createdAt' => self::toIso8601($createdAt),
        ];
    }

    public static function deleteJoker(string $id): bool {
        $stmt = self::pdo()->prepare('DELETE FROM jokers WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public static function insertTeam(string $id, array $composition): array {
        $stmt = self::pdo()->prepare(
            'INSERT INTO teams (id, composition) VALUES (?, ?::jsonb) RETURNING created_at'
        );
        $stmt->execute([$id, json_encode($composition, JSON_UNESCAPED_UNICODE)]);
        $createdAt = $stmt->fetchColumn();

        return array_merge($composition, [
            'id'        => $id,
            'createdAt' => self::toIso8601($createdAt),
        ]);
    }

    /**
     * Atomically mutate a team's composition JSON under a row lock.
     * $mutator receives the decoded composition array and must return the
     * updated composition array. Returns the merged team (like getTeam) or
     * null when the team does not exist.
     */
    public static function mutateTeam(string $id, callable $mutator): ?array {
        return self::transaction(function (PDO $pdo) use ($id, $mutator) {
            $stmt = $pdo->prepare('SELECT composition, created_at FROM teams WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            $composition = json_decode($row['composition'], true);
            $composition = $mutator(is_array($composition) ? $composition : []);

            $update = $pdo->prepare('UPDATE teams SET composition = ?::jsonb WHERE id = ?');
            $update->execute([json_encode($composition, JSON_UNESCAPED_UNICODE), $id]);

            return array_merge($composition, [
                'id'        => $id,
                'createdAt' => self::toIso8601($row['created_at']),
            ]);
        });
    }

    public static function getTeam(string $id): ?array {
        $stmt = self::pdo()->prepare('SELECT id, composition, created_at FROM teams WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $composition = json_decode($row['composition'], true);
        return array_merge(is_array($composition) ? $composition : [], [
            'id'        => $row['id'],
            'createdAt' => self::toIso8601($row['created_at']),
        ]);
    }

    private const HIGHLIGHT_SELECT =
        'SELECT h.id, h.match_id, h.player_id, h.steam_id64, h.player_name, h.round, h.kind, h.score,
                h.tags, h.start_tick, h.end_tick, h.object_key, h.size_bytes, h.is_favorite,
                h.favorited_by, h.favorited_by_name, h.favorited_at, h.created_at,
                m.map, COALESCE(m.match_time, m.added_at) AS match_time
         FROM highlights h
         LEFT JOIN matches m ON m.id = h.match_id';

    /**
     * Insert or refresh a highlight published by the recorder, keyed on
     * (match_id, player_id, round): its clip names are deterministic, so a
     * re-run of the same match updates rows in place. Favourite state is never
     * touched here — a re-render must not quietly drop a favourite.
     *
     * @return array{highlight: array, inserted: bool}
     */
    public static function upsertHighlight(array $h): array {
        $stmt = self::pdo()->prepare(
            'INSERT INTO highlights (match_id, player_id, steam_id64, player_name, round, kind, score,
                                     tags, start_tick, end_tick, object_key, size_bytes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?)
             ON CONFLICT (match_id, player_id, round) DO UPDATE SET
                 steam_id64  = EXCLUDED.steam_id64,
                 player_name = EXCLUDED.player_name,
                 kind        = EXCLUDED.kind,
                 score       = EXCLUDED.score,
                 tags        = EXCLUDED.tags,
                 start_tick  = EXCLUDED.start_tick,
                 end_tick    = EXCLUDED.end_tick,
                 object_key  = EXCLUDED.object_key,
                 size_bytes  = EXCLUDED.size_bytes,
                 updated_at  = now()
             RETURNING id, (xmax = 0)::int AS inserted'
        );
        $stmt->execute([
            $h['matchId'],
            $h['playerId'],
            $h['steamId64'],
            $h['playerName'],
            $h['round'],
            $h['kind'],
            $h['score'],
            json_encode($h['tags'], JSON_UNESCAPED_UNICODE),
            $h['startTick'],
            $h['endTick'],
            $h['objectKey'],
            $h['sizeBytes'],
        ]);
        $row = $stmt->fetch();

        return [
            'highlight' => self::getHighlight((int) $row['id']),
            // xmax is 0 only for a freshly inserted tuple, not an updated one.
            'inserted'  => (int) $row['inserted'] === 1,
        ];
    }

    public static function getHighlight(int $id): ?array {
        $stmt = self::pdo()->prepare(self::HIGHLIGHT_SELECT . ' WHERE h.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::mapHighlight($row) : null;
    }

    /** A match's clips in round order. */
    public static function getHighlightsByMatch(string $matchId): array {
        $stmt = self::pdo()->prepare(self::HIGHLIGHT_SELECT . ' WHERE h.match_id = ? ORDER BY h.round ASC, h.id ASC');
        $stmt->execute([$matchId]);
        return array_map([self::class, 'mapHighlight'], $stmt->fetchAll());
    }

    /** A player's clips, newest match first, rounds in order within a match. */
    public static function getHighlightsByPlayer(string $playerId): array {
        $stmt = self::pdo()->prepare(
            self::HIGHLIGHT_SELECT . ' WHERE h.player_id = ?
             ORDER BY COALESCE(m.match_time, m.added_at, h.created_at) DESC, h.match_id, h.round ASC'
        );
        $stmt->execute([$playerId]);
        return array_map([self::class, 'mapHighlight'], $stmt->fetchAll());
    }

    /**
     * Name and avatar of a player as of their most recent match, looked up
     * straight in match_data — there is no players table. Null when the id
     * never appeared in a stored match.
     */
    public static function findPlayerProfile(string $playerId): ?array {
        $stmt = self::pdo()->prepare(
            "SELECT p->>'name' AS name, p->>'avatar' AS avatar
             FROM matches m
             CROSS JOIN LATERAL jsonb_array_elements(
                 CASE WHEN jsonb_typeof(m.match_data->'teams') = 'array' THEN m.match_data->'teams' ELSE '[]'::jsonb END
             ) AS t
             CROSS JOIN LATERAL jsonb_array_elements(
                 CASE WHEN jsonb_typeof(t->'players') = 'array' THEN t->'players' ELSE '[]'::jsonb END
             ) AS p
             WHERE m.match_data IS NOT NULL AND p->>'playerId' = ?
             ORDER BY COALESCE(m.match_time, m.added_at) DESC
             LIMIT 1"
        );
        $stmt->execute([$playerId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'playerId' => $playerId,
            'name'     => (string) $row['name'],
            'avatar'   => ($row['avatar'] ?? '') !== '' ? $row['avatar'] : null,
        ];
    }

    /**
     * Mark a clip as favourite. Idempotent: an existing favourite keeps whoever
     * favourited it first. Returns the highlight, or null for an unknown id.
     */
    public static function favoriteHighlight(int $id, string $voterId, string $voterName): ?array {
        $stmt = self::pdo()->prepare(
            'UPDATE highlights
             SET is_favorite = true, favorited_by = ?, favorited_by_name = ?, favorited_at = now()
             WHERE id = ? AND NOT is_favorite'
        );
        $stmt->execute([$voterId, $voterName, $id]);
        return self::getHighlight($id);
    }

    /**
     * Remove a clip from favourites, so the next cleanup deletes it.
     * Only whoever favourited it, or an admin, may do that — anyone else gets
     * InvalidArgumentException. Idempotent for a clip that is not a favourite.
     * Returns the highlight, or null for an unknown id.
     */
    public static function unfavoriteHighlight(int $id, string $voterId, bool $isAdmin): ?array {
        $found = self::transaction(function (PDO $pdo) use ($id, $voterId, $isAdmin) {
            $stmt = $pdo->prepare('SELECT is_favorite, favorited_by FROM highlights WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                return false;
            }
            if (!self::pgBool($row['is_favorite'])) {
                return true;
            }
            if (!$isAdmin && $row['favorited_by'] !== $voterId) {
                throw new InvalidArgumentException('Only whoever favourited this clip, or an admin, can remove it from favourites');
            }
            $pdo->prepare(
                'UPDATE highlights
                 SET is_favorite = false, favorited_by = NULL, favorited_by_name = NULL, favorited_at = NULL
                 WHERE id = ?'
            )->execute([$id]);
            return true;
        });

        return $found ? self::getHighlight($id) : null;
    }

    /** How many rows deleteStaleHighlights($cutoff) would remove. */
    public static function countStaleHighlights(string $cutoff): int {
        $stmt = self::pdo()->prepare('SELECT count(*) FROM highlights WHERE NOT is_favorite AND updated_at < ?::timestamptz');
        $stmt->execute([$cutoff]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Cleanup: delete every non-favourite clip last published before
     * $cutoff. A clip the recorder (re)publishes while the cleanup runs is
     * newer than the cutoff and survives.
     */
    public static function deleteStaleHighlights(string $cutoff): int {
        $stmt = self::pdo()->prepare('DELETE FROM highlights WHERE NOT is_favorite AND updated_at < ?::timestamptz');
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }

    /** Object keys that survive a cleanup at $cutoff: favourites plus clips published since. */
    public static function keptHighlightKeys(string $cutoff): array {
        $stmt = self::pdo()->prepare('SELECT object_key FROM highlights WHERE is_favorite OR updated_at >= ?::timestamptz');
        $stmt->execute([$cutoff]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Object keys of stored clips split by favourite state — what the storage
     * report needs to tell kept bytes from reclaimable ones. One entry per row.
     *
     * @return array{favorite: string[], clip: string[]}
     */
    public static function highlightObjectKeys(): array {
        $keys = ['favorite' => [], 'clip' => []];
        foreach (self::pdo()->query('SELECT object_key, is_favorite FROM highlights')->fetchAll() as $row) {
            $keys[self::pgBool($row['is_favorite']) ? 'favorite' : 'clip'][] = $row['object_key'];
        }
        return $keys;
    }

    private static function mapHighlight(array $r): array {
        return [
            'id'              => (int) $r['id'],
            'matchId'         => $r['match_id'],
            'playerId'        => $r['player_id'],
            'steamId64'       => $r['steam_id64'],
            'playerName'      => $r['player_name'],
            'round'           => (int) $r['round'],
            'kind'            => $r['kind'],
            'score'           => $r['score'] !== null ? (float) $r['score'] : null,
            'tags'            => json_decode($r['tags'], true) ?: [],
            'startTick'       => $r['start_tick'] !== null ? (int) $r['start_tick'] : null,
            'endTick'         => $r['end_tick'] !== null ? (int) $r['end_tick'] : null,
            'sizeBytes'       => $r['size_bytes'] !== null ? (int) $r['size_bytes'] : null,
            'objectKey'       => $r['object_key'],
            'map'             => $r['map'],
            'matchTime'       => $r['match_time'] ? self::toIso8601($r['match_time']) : null,
            'isFavorite'      => self::pgBool($r['is_favorite']),
            'favoritedBy'     => $r['favorited_by'],
            'favoritedByName' => $r['favorited_by_name'],
            'favoritedAt'     => $r['favorited_at'] ? self::toIso8601($r['favorited_at']) : null,
            'createdAt'       => self::toIso8601($r['created_at']),
        ];
    }

    /** pdo_pgsql hands booleans back as PHP bools, but older builds use 't'/'f'. */
    private static function pgBool($value): bool {
        return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
    }

    private static function toIso8601($timestamp): string {
        if ($timestamp === null) {
            return '';
        }
        return (new DateTimeImmutable($timestamp))->format('c');
    }
}
