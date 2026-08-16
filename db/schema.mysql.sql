-- MySQL / MariaDB schema for the hosted FM26 database.
--
-- Table and column names match db/schema.sql one for one, so the same import payload
-- loads into either engine. Only the types differ, and only where they have to:
--
--   * SQLite TEXT becomes VARCHAR where the column takes part in a key or an index —
--     MySQL cannot index a TEXT column without a prefix length. Free text stays TEXT.
--   * INTEGER PRIMARY KEY AUTOINCREMENT becomes INT AUTO_INCREMENT.
--   * REAL becomes DOUBLE.
--   * Money columns are BIGINT so a transfer value never overflows.
--
-- utf8mb4_unicode_ci is used rather than a newer collation so the file loads on
-- MariaDB 10.x and MySQL 5.7 as well as MySQL 8+.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS game_state (
    id INT NOT NULL PRIMARY KEY,
    current_game_date VARCHAR(32) NOT NULL,
    season VARCHAR(32),
    notes TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teams (
    id INT NOT NULL PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    club_type VARCHAR(64),
    notes TEXT,
    UNIQUE KEY uq_teams_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS players (
    id INT NOT NULL PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    date_of_birth VARCHAR(32),
    nationality VARCHAR(128),
    preferred_foot VARCHAR(191),
    current_team_id INT,
    current_shirt_number INT,
    status VARCHAR(128),
    notes TEXT,
    KEY idx_players_team (current_team_id),
    CONSTRAINT fk_players_team FOREIGN KEY (current_team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_snapshots (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    game_date VARCHAR(32) NOT NULL,
    age_years INT,
    team_id INT,
    shirt_number INT,
    position_text VARCHAR(128),
    condition_text VARCHAR(128),
    role_text VARCHAR(128),
    value_text VARCHAR(128),
    value_min_eur BIGINT,
    value_max_eur BIGINT,
    wage_eur_month BIGINT,
    contract_end VARCHAR(32),
    height_cm INT,
    personality_text VARCHAR(128),
    reputation_text VARCHAR(128),
    current_ability_stars DOUBLE,
    potential_ability_stars DOUBLE,
    source TEXT,
    notes TEXT,
    UNIQUE KEY uq_snapshots_player_date (player_id, game_date),
    KEY idx_snapshots_player_date (player_id, game_date),
    KEY idx_snapshots_team (team_id),
    CONSTRAINT fk_snapshots_player FOREIGN KEY (player_id) REFERENCES players(id),
    CONSTRAINT fk_snapshots_team FOREIGN KEY (team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_attributes (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    game_date VARCHAR(32) NOT NULL,
    attribute_category VARCHAR(64) NOT NULL,
    attribute_name VARCHAR(128) NOT NULL,
    value INT NOT NULL,
    source TEXT,
    UNIQUE KEY uq_attributes_player_date_name (player_id, game_date, attribute_name),
    KEY idx_attributes_player_date (player_id, game_date),
    CONSTRAINT fk_attributes_player FOREIGN KEY (player_id) REFERENCES players(id),
    CONSTRAINT chk_attribute_value CHECK (value BETWEEN 1 AND 20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_roles (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    game_date VARCHAR(32) NOT NULL,
    phase VARCHAR(8) NOT NULL DEFAULT 'IP',
    position_text VARCHAR(64),
    role_text VARCHAR(128) NOT NULL,
    rating_stars DOUBLE,
    source TEXT,
    UNIQUE KEY uq_roles_player_date_phase_pos_role (player_id, game_date, phase, position_text, role_text),
    KEY idx_roles_player_date (player_id, game_date),
    CONSTRAINT fk_roles_player FOREIGN KEY (player_id) REFERENCES players(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_traits (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    game_date VARCHAR(32) NOT NULL,
    trait_text VARCHAR(191) NOT NULL,
    source TEXT,
    UNIQUE KEY uq_traits_player_date_text (player_id, game_date, trait_text),
    KEY idx_traits_player_date (player_id, game_date),
    CONSTRAINT fk_traits_player FOREIGN KEY (player_id) REFERENCES players(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS competitions (
    id INT NOT NULL PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    season VARCHAR(32),
    notes TEXT,
    UNIQUE KEY uq_competitions_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS matches (
    id INT NOT NULL PRIMARY KEY,
    match_date VARCHAR(32) NOT NULL,
    competition_id INT,
    season VARCHAR(32),
    home_team_id INT,
    away_team_id INT,
    opponent VARCHAR(191) NOT NULL,
    home_away VARCHAR(16),
    score_for INT,
    score_against INT,
    xg_for DOUBLE,
    xg_against DOUBLE,
    result VARCHAR(16),
    possession_pct DOUBLE,
    shots INT,
    shots_on_target INT,
    tactical_summary TEXT,
    source TEXT,
    KEY idx_matches_date (match_date),
    KEY idx_matches_competition (competition_id),
    KEY idx_matches_home_team (home_team_id),
    KEY idx_matches_away_team (away_team_id),
    CONSTRAINT fk_matches_competition FOREIGN KEY (competition_id) REFERENCES competitions(id),
    CONSTRAINT fk_matches_home_team FOREIGN KEY (home_team_id) REFERENCES teams(id),
    CONSTRAINT fk_matches_away_team FOREIGN KEY (away_team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS match_players (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    match_id INT NOT NULL,
    player_id INT NOT NULL,
    team_id INT,
    shirt_number_at_match INT,
    starter INT NOT NULL DEFAULT 0,
    minutes INT,
    rating DOUBLE,
    `condition` VARCHAR(128),
    distance_km DOUBLE,
    xg DOUBLE,
    xa DOUBLE,
    goals INT DEFAULT 0,
    assists INT DEFAULT 0,
    source TEXT,
    UNIQUE KEY uq_match_players (match_id, player_id),
    KEY idx_match_players_match (match_id),
    KEY idx_match_players_player (player_id),
    KEY idx_match_players_team (team_id),
    CONSTRAINT fk_match_players_match FOREIGN KEY (match_id) REFERENCES matches(id),
    CONSTRAINT fk_match_players_player FOREIGN KEY (player_id) REFERENCES players(id),
    CONSTRAINT fk_match_players_team FOREIGN KEY (team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pass_map_nodes (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    match_id INT NOT NULL,
    player_id INT NOT NULL,
    shirt_number_at_match INT NOT NULL,
    avg_x DOUBLE,
    avg_y DOUBLE,
    passes_in INT,
    passes_out INT,
    UNIQUE KEY uq_pass_map_nodes (match_id, player_id),
    KEY idx_pass_nodes_match (match_id),
    KEY idx_pass_nodes_player (player_id),
    CONSTRAINT fk_pass_nodes_match FOREIGN KEY (match_id) REFERENCES matches(id),
    CONSTRAINT fk_pass_nodes_player FOREIGN KEY (player_id) REFERENCES players(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pass_map_links (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    match_id INT NOT NULL,
    from_player_id INT NOT NULL,
    to_player_id INT NOT NULL,
    pass_count INT,
    KEY idx_pass_links_match (match_id),
    KEY idx_pass_links_from (from_player_id),
    KEY idx_pass_links_to (to_player_id),
    CONSTRAINT fk_pass_links_match FOREIGN KEY (match_id) REFERENCES matches(id),
    CONSTRAINT fk_pass_links_from FOREIGN KEY (from_player_id) REFERENCES players(id),
    CONSTRAINT fk_pass_links_to FOREIGN KEY (to_player_id) REFERENCES players(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS match_team_stats (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    match_id INT NOT NULL,
    team_id INT NOT NULL,
    stat_name VARCHAR(128) NOT NULL,
    stat_value DOUBLE,
    stat_unit VARCHAR(32),
    source TEXT,
    UNIQUE KEY uq_match_team_stats (match_id, team_id, stat_name),
    KEY idx_match_team_stats_team (team_id),
    CONSTRAINT fk_match_team_stats_match FOREIGN KEY (match_id) REFERENCES matches(id),
    CONSTRAINT fk_match_team_stats_team FOREIGN KEY (team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tactical_observations (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    match_id INT,
    player_id INT,
    category VARCHAR(128) NOT NULL,
    observation TEXT NOT NULL,
    confidence VARCHAR(32) NOT NULL DEFAULT 'confirmed',
    source TEXT,
    KEY idx_tactical_match (match_id),
    KEY idx_tactical_player (player_id),
    CONSTRAINT fk_tactical_match FOREIGN KEY (match_id) REFERENCES matches(id),
    CONSTRAINT fk_tactical_player FOREIGN KEY (player_id) REFERENCES players(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_evaluations (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    evaluation_game_date VARCHAR(32) NOT NULL,
    category VARCHAR(128) NOT NULL,
    observation TEXT NOT NULL,
    confidence VARCHAR(32) NOT NULL DEFAULT 'confirmed',
    source TEXT,
    KEY idx_evaluations_player_date (player_id, evaluation_game_date),
    CONSTRAINT fk_evaluations_player FOREIGN KEY (player_id) REFERENCES players(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS player_season_stats (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    game_date VARCHAR(32) NOT NULL,
    season VARCHAR(32),
    competition_scope VARCHAR(64) NOT NULL DEFAULT 'all',
    matches INT,
    starts INT,
    sub_apps INT,
    goals INT,
    assists INT,
    xg DOUBLE,
    avg_rating DOUBLE,
    yellow_cards INT,
    red_cards INT,
    source TEXT,
    UNIQUE KEY uq_season_stats (player_id, game_date, competition_scope),
    KEY idx_season_stats_player (player_id, game_date),
    CONSTRAINT fk_season_stats_player FOREIGN KEY (player_id) REFERENCES players(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS league_standings (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    game_date VARCHAR(32) NOT NULL,
    competition_id INT,
    season VARCHAR(32),
    position INT NOT NULL,
    team_name VARCHAR(191) NOT NULL,
    played INT,
    won INT,
    drawn INT,
    lost INT,
    goals_for INT,
    goals_against INT,
    goal_difference INT,
    points INT,
    source TEXT,
    UNIQUE KEY uq_standings (game_date, competition_id, team_name),
    KEY idx_standings_date (game_date, position),
    CONSTRAINT fk_standings_competition FOREIGN KEY (competition_id) REFERENCES competitions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scout_reports (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    scout_game_date VARCHAR(32) NOT NULL,
    scout_name VARCHAR(191),
    scouting_context VARCHAR(191),
    current_age INT,
    current_team_id INT,
    current_position VARCHAR(64),
    current_value_text VARCHAR(128),
    recommendation VARCHAR(191),
    report_text TEXT,
    source TEXT,
    KEY idx_scout_reports_player_date (player_id, scout_game_date),
    KEY idx_scout_reports_team (current_team_id),
    CONSTRAINT fk_scout_reports_player FOREIGN KEY (player_id) REFERENCES players(id),
    CONSTRAINT fk_scout_reports_team FOREIGN KEY (current_team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
