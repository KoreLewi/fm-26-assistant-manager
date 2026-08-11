-- Common analysis queries. These are read-only examples.

-- Player age on every historical snapshot, calculated from DOB + in-game date.
SELECT
    p.name,
    ps.game_date,
    p.date_of_birth,
    CAST(strftime('%Y', ps.game_date) AS INTEGER)
      - CAST(strftime('%Y', p.date_of_birth) AS INTEGER)
      - CASE WHEN strftime('%m-%d', ps.game_date) < strftime('%m-%d', p.date_of_birth) THEN 1 ELSE 0 END
      AS calculated_age,
    ps.age_years AS screenshot_age
FROM player_snapshots ps
JOIN players p ON p.id = ps.player_id
ORDER BY ps.game_date, p.name;

-- Latest known attributes for a player.
SELECT p.name, a.attribute_name, a.value, a.game_date
FROM player_attributes a
JOIN players p ON p.id = a.player_id
WHERE a.player_id = :player_id
  AND a.game_date = (
      SELECT MAX(a2.game_date)
      FROM player_attributes a2
      WHERE a2.player_id = a.player_id
        AND a2.attribute_name = a.attribute_name
  )
ORDER BY a.attribute_name;

-- Match performance history for one player.
SELECT
    m.match_date,
    m.opponent,
    m.home_away,
    m.score_for,
    m.score_against,
    mp.minutes,
    mp.rating,
    mp.distance_km,
    mp.xg,
    mp.xa,
    mp.goals,
    mp.assists
FROM match_players mp
JOIN matches m ON m.id = mp.match_id
WHERE mp.player_id = :player_id
ORDER BY m.match_date;

-- Pass-map position + identity. Shirt number is the number shown in that match.
SELECT
    m.match_date,
    m.opponent,
    pm.shirt_number_at_match,
    p.name,
    pm.avg_x,
    pm.avg_y,
    pm.passes_in,
    pm.passes_out
FROM pass_map_nodes pm
JOIN matches m ON m.id = pm.match_id
JOIN players p ON p.id = pm.player_id
WHERE p.id = :player_id
ORDER BY m.match_date;

-- Passing relationships involving one player.
SELECT
    m.match_date,
    m.opponent,
    pf.name AS from_player,
    pt.name AS to_player,
    pl.pass_count
FROM pass_map_links pl
JOIN matches m ON m.id = pl.match_id
JOIN players pf ON pf.id = pl.from_player_id
JOIN players pt ON pt.id = pl.to_player_id
WHERE pl.from_player_id = :player_id OR pl.to_player_id = :player_id
ORDER BY m.match_date, pl.pass_count DESC;
