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
