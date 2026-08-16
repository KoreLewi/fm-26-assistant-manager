PRAGMA foreign_keys = ON;

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
    value_min_eur INTEGER,
    value_max_eur INTEGER,
    wage_eur_month INTEGER,
    contract_end TEXT,
    height_cm INTEGER,
    personality_text TEXT,
    reputation_text TEXT,
    current_ability_stars REAL,
    potential_ability_stars REAL,
    source TEXT,
    notes TEXT,
    FOREIGN KEY(player_id) REFERENCES players(id),
    FOREIGN KEY(team_id) REFERENCES teams(id),
    UNIQUE(player_id, game_date)
);

CREATE TABLE IF NOT EXISTS player_attributes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_id INTEGER NOT NULL,
    game_date TEXT NOT NULL,
    attribute_category TEXT NOT NULL,
    attribute_name TEXT NOT NULL,
    value INTEGER NOT NULL CHECK(value BETWEEN 1 AND 20),
    source TEXT,
    CHECK(attribute_category IN ('technical', 'mental', 'physical', 'goalkeeping')),
    FOREIGN KEY(player_id) REFERENCES players(id),
    UNIQUE(player_id, game_date, attribute_name)
);

CREATE TABLE IF NOT EXISTS player_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_id INTEGER NOT NULL,
    game_date TEXT NOT NULL,
    phase TEXT NOT NULL DEFAULT 'IP',
    position_text TEXT,
    role_text TEXT NOT NULL,
    rating_stars REAL,
    source TEXT,
    FOREIGN KEY(player_id) REFERENCES players(id),
    UNIQUE(player_id, game_date, phase, position_text, role_text)
);

CREATE TABLE IF NOT EXISTS player_traits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_id INTEGER NOT NULL,
    game_date TEXT NOT NULL,
    trait_text TEXT NOT NULL,
    source TEXT,
    FOREIGN KEY(player_id) REFERENCES players(id),
    UNIQUE(player_id, game_date, trait_text)
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
CREATE INDEX IF NOT EXISTS idx_roles_player_date ON player_roles(player_id, game_date);
CREATE INDEX IF NOT EXISTS idx_traits_player_date ON player_traits(player_id, game_date);
CREATE INDEX IF NOT EXISTS idx_match_players_match ON match_players(match_id);
CREATE INDEX IF NOT EXISTS idx_match_players_player ON match_players(player_id);
CREATE INDEX IF NOT EXISTS idx_pass_nodes_match ON pass_map_nodes(match_id);
CREATE INDEX IF NOT EXISTS idx_pass_links_match ON pass_map_links(match_id);
CREATE INDEX IF NOT EXISTS idx_evaluations_player_date ON player_evaluations(player_id, evaluation_game_date);
CREATE INDEX IF NOT EXISTS idx_scout_reports_player_date ON scout_reports(player_id, scout_game_date);

CREATE TABLE IF NOT EXISTS player_season_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_id INTEGER NOT NULL,
    game_date TEXT NOT NULL,
    season TEXT,
    competition_scope TEXT NOT NULL DEFAULT 'all',
    matches INTEGER,
    starts INTEGER,
    sub_apps INTEGER,
    goals INTEGER,
    assists INTEGER,
    xg REAL,
    avg_rating REAL,
    yellow_cards INTEGER,
    red_cards INTEGER,
    source TEXT,
    FOREIGN KEY(player_id) REFERENCES players(id),
    UNIQUE(player_id, game_date, competition_scope)
);

CREATE TABLE IF NOT EXISTS league_standings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    game_date TEXT NOT NULL,
    competition_id INTEGER,
    season TEXT,
    position INTEGER NOT NULL,
    team_name TEXT NOT NULL,
    played INTEGER,
    won INTEGER,
    drawn INTEGER,
    lost INTEGER,
    goals_for INTEGER,
    goals_against INTEGER,
    goal_difference INTEGER,
    points INTEGER,
    source TEXT,
    FOREIGN KEY(competition_id) REFERENCES competitions(id),
    UNIQUE(game_date, competition_id, team_name)
);

CREATE INDEX IF NOT EXISTS idx_season_stats_player ON player_season_stats(player_id, game_date);
CREATE INDEX IF NOT EXISTS idx_standings_date ON league_standings(game_date, position);

-- FM26 knowledge. Generated from data/reference/. These tables describe the game, not
-- a career, so a save reset leaves them alone.

CREATE TABLE IF NOT EXISTS fm_positions (
    code TEXT PRIMARY KEY,
    description TEXT,
    screenshot_verified INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS fm_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    position_code TEXT NOT NULL,
    phase TEXT NOT NULL CHECK(phase IN ('IP', 'OOP')),
    role_name TEXT NOT NULL,
    UNIQUE(position_code, phase, role_name),
    FOREIGN KEY(position_code) REFERENCES fm_positions(code)
);

CREATE TABLE IF NOT EXISTS fm_banned_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    role_name TEXT NOT NULL UNIQUE,
    replacement TEXT,
    note TEXT
);

CREATE TABLE IF NOT EXISTS fm_styles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name_en TEXT NOT NULL UNIQUE,
    mentality_lean TEXT,
    philosophy TEXT,
    details TEXT
);

CREATE TABLE IF NOT EXISTS fm_instructions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    phase TEXT NOT NULL CHECK(phase IN ('in_possession', 'out_of_possession')),
    group_name TEXT NOT NULL,
    instruction_en TEXT NOT NULL,
    instruction_hu TEXT,
    options TEXT,
    note TEXT,
    UNIQUE(phase, group_name, instruction_en)
);

CREATE TABLE IF NOT EXISTS fm_role_locale (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,
    hu TEXT NOT NULL,
    en TEXT NOT NULL,
    UNIQUE(kind, hu, en)
);

CREATE TABLE IF NOT EXISTS fm_reference (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document TEXT NOT NULL,
    path TEXT NOT NULL,
    title TEXT,
    text TEXT NOT NULL,
    UNIQUE(document, path)
);

CREATE INDEX IF NOT EXISTS idx_fm_roles_position ON fm_roles(position_code, phase);
CREATE INDEX IF NOT EXISTS idx_fm_reference_document ON fm_reference(document);
