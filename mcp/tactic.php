<?php
/**
 * Parse a tactic file into rows for the tactic tables.
 *
 * A tactic belongs to a career and is dated like every other historical record. The
 * parsing rules are mirrored in scripts/import_tactic.py.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Encode a node the way scripts/import_tactic.py does, so the two rows compare equal. */
function fm_tactic_encode(mixed $node): string
{
    return (string) json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** A slot names a side as well as a position: DCL and DCR are both DC. */
function fm_tactic_position_code(string $slot, array $knownCodes): ?string
{
    if (in_array($slot, $knownCodes, true)) {
        return $slot;
    }
    $trimmed = substr($slot, 0, -1);
    if (strlen($slot) > 2 && in_array($trimmed, $knownCodes, true)) {
        return $trimmed;
    }

    return null;
}

/**
 * Match a line-up label to exactly one player, or to nobody. The shirt number decides
 * when it identifies one player; otherwise the name has to. An ambiguous label is left
 * unresolved rather than guessed.
 */
function fm_tactic_resolve_player(string $rawLabel, array $players): ?int
{
    $name = trim($rawLabel);
    $shirt = null;
    if (preg_match('/^(.*?)\s*\((\d+)\)\s*$/u', $rawLabel, $m)) {
        $name = trim($m[1]);
        $shirt = (int) $m[2];
    }

    if ($shirt !== null) {
        $hits = array_values(array_filter(
            $players,
            static fn ($p) => (int) $p['current_shirt_number'] === $shirt
        ));
        if (count($hits) === 1) {
            return (int) $hits[0]['id'];
        }
    }

    $lowered = mb_strtolower($name);
    $hits = array_values(array_filter(
        $players,
        static fn ($p) => str_contains(mb_strtolower((string) $p['name']), $lowered)
    ));

    return count($hits) === 1 ? (int) $hits[0]['id'] : null;
}

/** @return array<string, array<int, array<string, mixed>>> */
function fm_tactic_rows(string $path, array $players, array $knownCodes): array
{
    $payload = json_decode((string) file_get_contents($path), true);
    if (!is_array($payload)) {
        throw new FmMcpError('The tactic file is not valid JSON: ' . basename($path));
    }

    $meta = $payload['_meta'] ?? [];
    $style = $payload['style'] ?? [];
    $shape = $payload['shape'] ?? [];

    $tactic = [
        'name' => $meta['name'] ?? pathinfo($path, PATHINFO_FILENAME),
        'game_date' => $meta['game_date'] ?? null,
        'style_en' => $style['tactical_style_en'] ?? null,
        'style_hu' => $style['tactical_style_hu'] ?? null,
        'mentality_en' => $style['mentality_en'] ?? null,
        'mentality_hu' => $style['mentality_hu'] ?? null,
        'shape_ip' => $shape['in_possession'] ?? null,
        'shape_oop' => $shape['out_of_possession'] ?? null,
        'in_game_slot' => $meta['in_game_tactic_slot'] ?? null,
        'source' => $meta['source'] ?? null,
        'notes' => !empty($payload['asymmetries']) ? fm_tactic_encode($payload['asymmetries']) : null,
    ];

    $slots = [];
    foreach ($payload['slots'] ?? [] as $slot) {
        $slots[] = [
            'slot' => $slot['slot'],
            'position_code' => fm_tactic_position_code((string) $slot['slot'], $knownCodes),
            'ui_label' => $slot['ui_label'] ?? null,
            'ip_role' => $slot['in_possession']['en'] ?? null,
            'oop_role' => $slot['out_of_possession']['en'] ?? null,
        ];
    }

    $instructions = [];
    foreach ($payload['team_instructions'] ?? [] as $phase => $groups) {
        if (!is_array($groups)) {
            continue;
        }
        foreach ($groups as $groupName => $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $instruction => $value) {
                $instructions[] = [
                    'phase' => (string) $phase,
                    'group_name' => (string) $groupName,
                    'instruction' => (string) $instruction,
                    'value_en' => is_array($value) ? ($value['en'] ?? null) : (string) $value,
                    'value_hu' => is_array($value) ? ($value['hu'] ?? null) : null,
                    'source' => $meta['source'] ?? null,
                ];
            }
        }
    }

    $lineups = [];
    foreach ($payload['observed_lineups'] ?? [] as $lineup) {
        foreach ($lineup['players'] ?? [] as $slot => $rawLabel) {
            $lineups[] = [
                'label' => $lineup['label'] ?? null,
                'slot' => (string) $slot,
                'player_id' => fm_tactic_resolve_player((string) $rawLabel, $players),
                'raw_label' => (string) $rawLabel,
            ];
        }
    }

    return [
        'tactics' => [$tactic],
        'tactic_slots' => $slots,
        'tactic_instructions' => $instructions,
        'tactic_lineups' => $lineups,
    ];
}

/** Load one tactic file. Returns rows written per table. */
function fm_tactic_import(PDO $pdo, string $path): array
{
    $players = $pdo->query('SELECT id, name, current_shirt_number FROM players')->fetchAll();
    $knownCodes = array_column($pdo->query('SELECT code FROM fm_positions')->fetchAll(), 'code');

    $rows = fm_tactic_rows($path, $players, $knownCodes);
    $replace = fm_driver() === 'mysql' ? 'REPLACE INTO' : 'INSERT OR REPLACE INTO';

    $tactic = $rows['tactics'][0];
    $columns = array_keys($tactic);
    $stmt = $pdo->prepare(
        $replace . ' ' . fm_ident('tactics') . ' (' . implode(',', array_map('fm_ident', $columns))
        . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')'
    );
    $stmt->execute(array_values($tactic));

    $lookup = $pdo->prepare('SELECT id FROM tactics WHERE name = ? AND game_date = ?');
    $lookup->execute([$tactic['name'], $tactic['game_date']]);
    $tacticId = (int) $lookup->fetchColumn();

    $written = ['tactics' => 1];
    foreach (['tactic_slots', 'tactic_instructions', 'tactic_lineups'] as $table) {
        foreach ($rows[$table] as $row) {
            $row = array_merge(['tactic_id' => $tacticId], $row);
            $columns = array_keys($row);
            $stmt = $pdo->prepare(
                $replace . ' ' . fm_ident($table) . ' (' . implode(',', array_map('fm_ident', $columns))
                . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')'
            );
            $stmt->execute(array_values($row));
        }
        $written[$table] = count($rows[$table]);
    }

    return $written;
}
