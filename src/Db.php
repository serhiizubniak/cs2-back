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

    public static function getMatches(): array {
        $rows = self::pdo()
            ->query('SELECT id, url, map, score, added_at FROM matches ORDER BY added_at ASC')
            ->fetchAll();

        return array_map(fn($r) => [
            'id'      => $r['id'],
            'url'     => $r['url'],
            'map'     => $r['map'],
            'score'   => json_decode($r['score'], true),
            'addedAt' => self::toIso8601($r['added_at']),
        ], $rows);
    }

    public static function matchExists(string $id): bool {
        $stmt = self::pdo()->prepare('SELECT 1 FROM matches WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    public static function insertMatch(string $id, string $url, ?string $map, array $score, ?array $matchData): array {
        $stmt = self::pdo()->prepare(
            'INSERT INTO matches (id, url, map, score, match_data) VALUES (?, ?, ?, ?::jsonb, ?::jsonb) RETURNING added_at'
        );
        $stmt->execute([
            $id,
            $url,
            $map,
            json_encode($score, JSON_UNESCAPED_UNICODE),
            $matchData !== null ? json_encode($matchData, JSON_UNESCAPED_UNICODE) : null,
        ]);
        $addedAt = $stmt->fetchColumn();

        return [
            'id'      => $id,
            'url'     => $url,
            'map'     => $map,
            'score'   => $score,
            'addedAt' => self::toIso8601($addedAt),
        ];
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

    public static function getMatchData(array $ids): array {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = self::pdo()->prepare(
            "SELECT id, match_data FROM matches WHERE id IN ($placeholders) AND match_data IS NOT NULL"
        );
        $stmt->execute(array_values($ids));

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

    private static function toIso8601($timestamp): string {
        if ($timestamp === null) {
            return '';
        }
        return (new DateTimeImmutable($timestamp))->format('c');
    }
}
