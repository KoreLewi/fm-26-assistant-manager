PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS players (
    id INTEGER PRIMARY KEY,
    shirt_number INTEGER,
    name TEXT NOT NULL,
    position TEXT,
    foot TEXT,
    date_of_birth TEXT,
    nationality TEXT,
    status TEXT,
    notes TEXT
);

CREATE TABLE IF NOT EXISTS player_attributes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_id INTEGER NOT NULL,
    snapshot_date TEXT NOT NULL,
    attribute_name TEXT NOT NULL,
    value INTEGER NOT NULL CHECK(value BETWEEN 1 AND 20),
    source TEXT,
    FOREIGN KEY(player_id) REFERENCES players(id),
    UNIQUE(player_id, snapshot_date, attribute_name)
);

CREATE TABLE IF NOT EXISTS matches (
    id INTEGER PRIMARY KEY,
    match_date TEXT NOT NULL,
    competition TEXT,
    opponent TEXT NOT NULL,
    home_away TEXT,
    score_for INTEGER,
    score_against INTEGER,
    xg_for REAL,
    xg_against REAL,
    result TEXT,
    tactical_summary TEXT,
    source TEXT
);

CREATE TABLE IF NOT EXISTS match_players (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id INTEGER NOT NULL,
    player_id INTEGER NOT NULL,
    shirt_number_at_match INTEGER,
    starter INTEGER NOT NULL DEFAULT 0,
    minutes INTEGER,
    rating REAL,
    condition TEXT,
    distance_km REAL,
    xg REAL,
    xa REAL,
    goals INTEGER DEFAULT 0,
    assists INTEGER DEFAULT 0,
    source TEXT,
    FOREIGN KEY(match_id) REFERENCES matches(id),
    FOREIGN KEY(player_id) REFERENCES players(id),
    UNIQUE(match_id, player_id)
);

CREATE TABLE IF NOT EXISTS pass_map_nodes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id INTEGER NOT NULL,
    player_id INTEGER NOT NULL,
    shirt_number_at_match INTEGER NOT NULL,
    avg_x REAL,
    avg_y REAL,
    passes_in INTEGER,
    passes_out INTEGER,
    FOREIGN KEY(match_id) REFERENCES matches(id),
    FOREIGN KEY(player_id) REFERENCES players(id),
    UNIQUE(match_id, player_id)
);

CREATE TABLE IF NOT EXISTS pass_map_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id INTEGER NOT NULL,
    from_player_id INTEGER NOT NULL,
    to_player_id INTEGER NOT NULL,
    pass_count INTEGER,
    FOREIGN KEY(match_id) REFERENCES matches(id),
    FOREIGN KEY(from_player_id) REFERENCES players(id),
    FOREIGN KEY(to_player_id) REFERENCES players(id)
);

CREATE TABLE IF NOT EXISTS tactical_observations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id INTEGER,
    category TEXT NOT NULL,
    observation TEXT NOT NULL,
    confidence TEXT NOT NULL DEFAULT 'confirmed',
    source TEXT,
    FOREIGN KEY(match_id) REFERENCES matches(id)
);

CREATE TABLE IF NOT EXISTS player_evaluations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_id INTEGER NOT NULL,
    evaluation_date TEXT NOT NULL,
    category TEXT NOT NULL,
    observation TEXT NOT NULL,
    source TEXT,
    FOREIGN KEY(player_id) REFERENCES players(id)
);

CREATE INDEX IF NOT EXISTS idx_attributes_player_date ON player_attributes(player_id, snapshot_date);
CREATE INDEX IF NOT EXISTS idx_match_players_match ON match_players(match_id);
CREATE INDEX IF NOT EXISTS idx_pass_nodes_match ON pass_map_nodes(match_id);
CREATE INDEX IF NOT EXISTS idx_pass_links_match ON pass_map_links(match_id);
