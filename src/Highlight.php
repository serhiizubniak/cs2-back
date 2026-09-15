<?php

/**
 * Highlight clips published by the cs-highlights recorder. The recorder renders
 * a clip, uploads it to Cloudflare R2 itself and then POSTs only the metadata
 * here (publish-highlight) — video bytes never pass through PHP.
 *
 * Pure and I/O-free, like ExtensionMatch.php, so the payload rules and the
 * cleanup schedule can be unit-tested; the API layer wires it to Db.
 * validate() throws InvalidArgumentException on bad input, which the handler
 * maps to a 400.
 *
 * Identifier notes:
 *   - matchId is the scope.gg match id and may be negative ("-7688…"), so it
 *     stays a string.
 *   - playerId is the Steam account id — the same `playerId` that match data
 *     and statistics use — so clips join onto players with no mapping table.
 *   - steamId64 must arrive as a string: it exceeds 2^53, and a JSON number may
 *     already have lost its tail upstream.
 */
class Highlight
{
    private const MAX_TAGS = 20;

    /**
     * Validate the decoded publish-highlight body. Throws
     * InvalidArgumentException with a human-readable message on the first
     * problem found.
     */
    public static function validate(array $body): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', self::idString($body['matchId'] ?? null))) {
            throw new InvalidArgumentException('matchId is required (letters, digits, "-" or "_")');
        }
        if (!preg_match('/^\d{1,20}$/', self::idString($body['playerId'] ?? null))) {
            throw new InvalidArgumentException('playerId must be a Steam account id');
        }

        $round = $body['round'] ?? null;
        if (!is_int($round) || $round < 0 || $round > 1000) {
            throw new InvalidArgumentException('round must be a non-negative integer');
        }

        $kind = $body['kind'] ?? null;
        if (!is_string($kind) || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $kind)) {
            throw new InvalidArgumentException('kind is required (e.g. "3k" or "1v2")');
        }

        $key = $body['objectKey'] ?? null;
        if (!is_string($key) || !self::isSafeObjectKey($key)) {
            throw new InvalidArgumentException('objectKey must be a relative object key');
        }

        $steam = $body['steamId64'] ?? null;
        if ($steam !== null && (!is_string($steam) || !preg_match('/^\d{1,20}$/', $steam))) {
            throw new InvalidArgumentException('steamId64 must be a string of digits');
        }

        $name = $body['playerName'] ?? null;
        if ($name !== null && (!is_string($name) || strlen($name) > 512)) {
            throw new InvalidArgumentException('playerName must be a string');
        }

        $score = $body['score'] ?? null;
        if ($score !== null && !is_int($score) && !is_float($score)) {
            throw new InvalidArgumentException('score must be a number');
        }

        $tags = $body['tags'] ?? null;
        if ($tags !== null) {
            if (!is_array($tags) || array_values($tags) !== $tags || count($tags) > self::MAX_TAGS) {
                throw new InvalidArgumentException('tags must be an array of at most ' . self::MAX_TAGS . ' strings');
            }
            foreach ($tags as $i => $tag) {
                if (!is_string($tag) || $tag === '' || strlen($tag) > 64 || preg_match('/[\x00-\x1F\x7F]/', $tag)) {
                    throw new InvalidArgumentException("tags[$i] must be a short string");
                }
            }
        }

        foreach (['startTick', 'endTick', 'sizeBytes'] as $field) {
            $value = $body[$field] ?? null;
            if ($value !== null && (!is_int($value) || $value < 0)) {
                throw new InvalidArgumentException("$field must be a non-negative integer");
            }
        }
        if (isset($body['startTick'], $body['endTick']) && $body['endTick'] < $body['startTick']) {
            throw new InvalidArgumentException('endTick must not be before startTick');
        }
    }

    /**
     * Build the record Db::upsertHighlight stores. Assumes validate() passed.
     */
    public static function toRecord(array $body): array
    {
        $name = isset($body['playerName']) ? trim($body['playerName']) : '';

        return [
            'matchId'    => self::idString($body['matchId'] ?? null),
            'playerId'   => self::idString($body['playerId'] ?? null),
            'steamId64'  => $body['steamId64'] ?? null,
            'playerName' => $name !== '' ? $name : null,
            'round'      => (int) $body['round'],
            'kind'       => strtolower($body['kind']),
            'score'      => isset($body['score']) ? round((float) $body['score'], 2) : null,
            'tags'       => array_values($body['tags'] ?? []),
            'startTick'  => $body['startTick'] ?? null,
            'endTick'    => $body['endTick'] ?? null,
            'objectKey'  => $body['objectKey'],
            'sizeBytes'  => $body['sizeBytes'] ?? null,
        ];
    }

    /**
     * Bucket usage broken down by what a cleanup would do with each object:
     * objects of favourite clips stay; objects of other clips and orphans (no
     * clip row points at them) go. $objects is R2Client::listObjects() output;
     * $limitBytes null means no cap.
     */
    public static function storageUsage(array $objects, array $favoriteKeys, array $clipKeys, ?int $limitBytes): array
    {
        $favorite = array_flip($favoriteKeys);
        $clip     = array_flip($clipKeys);

        $groups = [
            'favoriteBytes' => 0, 'favoriteObjects' => 0,
            'clipBytes'     => 0, 'clipObjects'     => 0,
            'orphanBytes'   => 0, 'orphanObjects'   => 0,
        ];
        foreach ($objects as $object) {
            $group = isset($favorite[$object['key']]) ? 'favorite' : (isset($clip[$object['key']]) ? 'clip' : 'orphan');
            $groups[$group . 'Bytes'] += (int) $object['size'];
            $groups[$group . 'Objects']++;
        }

        $used = $groups['favoriteBytes'] + $groups['clipBytes'] + $groups['orphanBytes'];

        return array_merge([
            'limitBytes'  => $limitBytes,
            'usedBytes'   => $used,
            'objectCount' => count($objects),
            'usedPercent' => $limitBytes ? round($used / $limitBytes * 100, 1) : null,
        ], $groups, [
            'reclaimableBytes'   => $groups['clipBytes'] + $groups['orphanBytes'],
            'reclaimableObjects' => $groups['clipObjects'] + $groups['orphanObjects'],
        ]);
    }

    /** Ids arrive as strings; tolerate a JSON integer, reject anything else. */
    private static function idString($value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value)) {
            return (string) $value;
        }
        return '';
    }

    /**
     * A key the recorder could legitimately have written: relative, no empty,
     * "." or ".." segments, no backslashes or control characters. The backend
     * never reads the object, but the key ends up in public URLs.
     */
    private static function isSafeObjectKey(string $key): bool
    {
        if ($key === '' || strlen($key) > 512 || $key[0] === '/' || strpos($key, '\\') !== false
            || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            return false;
        }
        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }
}
