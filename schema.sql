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
    rating     NUMERIC(3,1) NOT NULL CHECK (rating >= 0 AND rating <= 5),
    avatar     TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS teams (
    id          TEXT PRIMARY KEY,
    composition JSONB NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
