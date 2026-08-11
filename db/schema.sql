PRAGMA foreign_keys = ON;

-- The game calendar is independent from the real-world calendar.
CREATE TABLE IF NOT EXISTS game_state (
    id INTEGER PRIMARY KEY CHECK(id = 1),
    current_game_date TEXT NOT NULL,
    season TEXT,
    notes TEXT
);

CREATE TABLE IF NOT EXISTS teams (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    club_type TEXT,
    notes TEXT
);

CREATE TABLE IF NOT EXISTS players (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    date_of_birth TEXT,
    nationality TEXT,
    preferred_foot TEXT,
    current_team_id INTEGER,
    current_shirt_number INTEGER,
    status TEXT,
    notes TEXT,
    FOREIGN KEY(current_team_id) REFERENCES teams(id)
);

-- Historical player snapshot: age, team, shirt number and positions at a specific
-- in-game date. Never overwrite an older snapshot.
CREATE TABLE IF NOT EXISTS player_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_id INTEGER NOT NULL,
    game_date TEXT NOT NULL,
    age_years INTEGER,
    team_id INTEGER,
    shirt_number INTEGER,
    position_text TEXT,
    condition_text TEXT,
    role_text TEXT,
    value_text TEXT,
    reputation_text TEXT,
    source TEXT,
    notes TEXT,
    FOREIGN KEY(player_id) REFERENCES players(id),
    FOREIGN KEY(team_id) REFERENCES teams(id),
    UNIQUE(player_id, game_date)
);

-- Full 1-20 attribute snapshots. Attributes are tied to the in-game date, not
-- to the date when the screenshot was uploaded.
CREATE TABLE IF NOT EXISTS player_attributes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_id INTEGER NOT NULL,
    game_date TEXT NOT NULL,
    attribute_name TEXT NOT NULL,
    value INTEGER NOT NULL CHECK(value BETWEEN 1 AND 20),
    source TEXT,
    FOREIGN KEY(player_id) REFERENCES players(id),
    UNIQUE(player_id, game_date, attribute_name)
);

CREATE TABLE IF NOT EXISTS competitions (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    season TEXT,
    notes TEXT
);

CREATE TABLE IF NOT EXISTS matches (
    id INTEGER PRIMARY KEY,
    match_date TEXT NOT NULL,
    competition_id INTEGER,
    season TEXT,
    home_team_id INTEGER,
    away_team_id INTEGER,
    opponent TEXT NOT NULL,
    home_away TEXT,
    score_for INTEGER,
    score_against INTEGER,
    xg_for REAL,
    xg_against REAL,
    result TEXT,
    possession_pct REAL,
    shots INTEGER,
    shots_on_target INTEGER,
    tactical_summary TEXT,
    source TEXT,
    FOREIGN KEY(competition_id) REFERENCES competitions(id),
    FOREIGN KEY(home_team_id) REFERENCES teams(id),
    FOREIGN KEY(away_team_id) REFERENCES teams(id)
);

CREATE TABLE IF NOT EXISTS match_players (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id INTEGER NOT NULL,
    player_id INTEGER NOT NULL,
    team_id INTEGER,
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
    FOREIGN KEY(team_id) REFERENCES teams(id),
    UNIQUE(match_id, player_id)
);

-- Player location on the pass map. The shirt number is stored as it appeared
-- in that exact match, so historical shirt-number changes cannot corrupt identity.
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

-- General match statistics that may be visible on the FM screenshot.
CREATE TABLE IF NOT EXISTS match_team_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id INTEGER NOT NULL,
    team_id INTEGER NOT NULL,
    stat_name TEXT NOT NULL,
    stat_value REAL,
    stat_unit TEXT,
    source TEXT,
    FOREIGN KEY(match_id) REFERENCES matches(id),
    FOREIGN KEY(team_id) REFERENCES teams(id),
    UNIQUE(match_id, team_id, stat_name)
);

-- Tactical conclusions derived from screenshots. Keep confirmed observations
-- separate from interpretation so we never present an inference as raw data.
CREATE TABLE IF NOT EXISTS tactical_observations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id INTEGER,
    player_id INTEGER,
    category TEXT NOT NULL,
    observation TEXT NOT NULL,
    confidence TEXT NOT NULL DEFAULT 'confirmed',
    source TEXT,
    FOREIGN KEY(match_id) REFERENCES matches(id),
    FOREIGN KEY(player_id) REFERENCES players(id)
);

-- Long-term player evaluation, separate from raw attributes and match ratings.
CREATE TABLE IF NOT EXISTS player_evaluations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_id INTEGER NOT NULL,
    evaluation_game_date TEXT NOT NULL,
    category TEXT NOT NULL,
    observation TEXT NOT NULL,
    confidence TEXT NOT NULL DEFAULT 'confirmed',
    source TEXT,
    FOREIGN KEY(player_id) REFERENCES players(id)
);

-- Scouted players can be stored even when they are not Valencia players.
CREATE TABLE IF NOT EXISTS scout_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_id INTEGER NOT NULL,
    scout_game_date TEXT NOT NULL,
    scout_name TEXT,
    scouting_context TEXT,
    current_age INTEGER,
    current_team_id INTEGER,
    current_position TEXT,
    current_value_text TEXT,
    recommendation TEXT,
    report_text TEXT,
    source TEXT,
    FOREIGN KEY(player_id) REFERENCES players(id),
    FOREIGN KEY(current_team_id) REFERENCES teams(id)
);

CREATE INDEX IF NOT EXISTS idx_attributes_player_date ON player_attributes(player_id, game_date);
CREATE INDEX IF NOT EXISTS idx_snapshots_player_date ON player_snapshots(player_id, game_date);
CREATE INDEX IF NOT EXISTS idx_match_players_match ON match_players(match_id);
CREATE INDEX IF NOT EXISTS idx_match_players_player ON match_players(player_id);
CREATE INDEX IF NOT EXISTS idx_pass_nodes_match ON pass_map_nodes(match_id);
CREATE INDEX IF NOT EXISTS idx_pass_links_match ON pass_map_links(match_id);
CREATE INDEX IF NOT EXISTS idx_evaluations_player_date ON player_evaluations(player_id, evaluation_game_date);
CREATE INDEX IF NOT EXISTS idx_scout_reports_player_date ON scout_reports(player_id, scout_game_date);
