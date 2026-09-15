CREATE TABLE IF NOT EXISTS matches (
    id         TEXT PRIMARY KEY,
    url        TEXT NOT NULL,
    map        TEXT,
    score      JSONB NOT NULL,
    match_data JSONB,
    match_time TIMESTAMPTZ,
    added_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

ALTER TABLE matches ADD COLUMN IF NOT EXISTS match_time TIMESTAMPTZ;

CREATE INDEX IF NOT EXISTS matches_added_at_idx  ON matches (added_at DESC);
CREATE INDEX IF NOT EXISTS matches_match_time_idx ON matches (match_time DESC);

CREATE TABLE IF NOT EXISTS jokers (
    id         TEXT PRIMARY KEY,
    name       TEXT NOT NULL,
    rating     NUMERIC(3,2) NOT NULL CHECK (rating >= 0 AND rating <= 5),
    avatar     TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS teams (
    id          TEXT PRIMARY KEY,
    composition JSONB NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Receipt log for the Chrome extension (Scope Tap) ingest webhook. One row per
-- received POST, whether it stored a match or failed. Lets us tell "the
-- extension never knocked" apart from "it knocked but the request was rejected".
CREATE TABLE IF NOT EXISTS ingest_log (
    id          BIGSERIAL PRIMARY KEY,
    match_id    TEXT,
    status      TEXT NOT NULL,          -- ok | duplicate | invalid | unauthorized | error
    error       TEXT,
    received_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS ingest_log_received_at_idx ON ingest_log (received_at DESC);

-- Highlight clips from the cs-highlights recorder. The video itself lives in
-- Cloudflare R2 under object_key (the recorder uploads it directly); this is
-- only its metadata. No foreign key to matches on purpose: clear-matches
-- TRUNCATEs that table, and a clip can be published before its match is
-- ingested. Clips nobody favourited are deleted by cleanup-highlights (the
-- Cleanup button in the Admin tab).
CREATE TABLE IF NOT EXISTS highlights (
    id                BIGSERIAL PRIMARY KEY,
    match_id          TEXT NOT NULL,
    player_id         TEXT NOT NULL,          -- scope.gg playerId = Steam account id
    steam_id64        TEXT,
    player_name       TEXT,
    round             INT NOT NULL,
    kind              TEXT NOT NULL,          -- 3k | 4k | 5k | 1v2 | 1v3 | ...
    score             NUMERIC(10,2),
    tags              JSONB NOT NULL DEFAULT '[]'::jsonb,
    start_tick        INT,
    end_tick          INT,
    object_key        TEXT NOT NULL,
    size_bytes        BIGINT,
    is_favorite       BOOLEAN NOT NULL DEFAULT false,
    favorited_by      TEXT,                   -- voter key: playerId, or the name for players without one
    favorited_by_name TEXT,
    favorited_at      TIMESTAMPTZ,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- The recorder keeps at most one clip per (player, round), and its clip
    -- names are deterministic, so a re-run upserts on this instead of piling up.
    UNIQUE (match_id, player_id, round)
);

CREATE INDEX IF NOT EXISTS highlights_player_idx ON highlights (player_id, created_at DESC);
