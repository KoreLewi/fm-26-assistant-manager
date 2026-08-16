<?php
/**
 * Parse the FM26 reference documents into rows for the fm_ tables.
 *
 * The documents describe the game rather than a career, so these tables are shared by
 * every save and survive a save reset. The parsing rules are mirrored in
 * scripts/import_reference.py; the two must produce identical rows, which CI checks by
 * comparing the databases they build.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const FM_REFERENCE_SYSTEM_PROMPT = 'fm26_ai_system_prompt_v4';
const FM_REFERENCE_ROLE_LOCALE = 'fm26_role_locale_hu';

/** Children deleted before parents, so a foreign key never dangles mid-way. */
const FM_REFERENCE_DELETE_ORDER = [
    'fm_reference', 'fm_role_locale', 'fm_instructions',
    'fm_styles', 'fm_banned_roles', 'fm_roles', 'fm_positions',
];

function fm_reference_load(string $name): array
{
    $path = fm_reference_dir() . '/' . $name . '.json';
    if (!is_file($path)) {
        throw new FmMcpError("Reference document not found: {$path}");
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new FmMcpError("Reference document is not valid JSON: {$path}");
    }

    return $decoded;
}

/** Encode a node the way the Python importer does, so the two rows compare equal. */
function fm_reference_encode(mixed $node): string
{
    return (string) json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** @return array{0: array, 1: array} positions and roles */
function fm_reference_positions_and_roles(array $prompt): array
{
    $section = $prompt['2_pitch_positions_and_roles'];
    $verified = array_flip($prompt['_changelog']['4.1']['verified_codes']);

    // A description covers the codes it names in applies_to: one entry describes both
    // full-back sides, both wings, and so on, so the label has to be spread across them.
    $labels = [];
    foreach ($section['positions'] ?? [] as $key => $body) {
        foreach ($body['applies_to'] ?? explode('_', (string) $key) as $code) {
            $labels[$code] = $body['label'] ?? null;
        }
    }

    $positions = [];
    $roles = [];
    foreach ($section['allowed_roles_index'] as $code => $entry) {
        if (!is_array($entry) || (!isset($entry['in_possession']) && !isset($entry['out_of_possession']))) {
            continue;
        }
        $positions[] = [
            'code' => (string) $code,
            'description' => $labels[$code] ?? null,
            'screenshot_verified' => isset($verified[$code]) ? 1 : 0,
        ];
        foreach (['IP' => 'in_possession', 'OOP' => 'out_of_possession'] as $phase => $key) {
            foreach ($entry[$key] ?? [] as $roleName) {
                $roles[] = ['position_code' => (string) $code, 'phase' => $phase, 'role_name' => $roleName];
            }
        }
    }

    return [$positions, $roles];
}

/**
 * One row per banned name. An entry is either "Name (use Replacement)" or a
 * comma-separated list closing with a note that applies to all of them.
 */
function fm_reference_banned_roles(array $prompt): array
{
    $rows = [];
    $seen = [];
    foreach ($prompt['0_critical_fm26_changes']['banned_legacy_role_names'] as $entry) {
        $parts = explode('(', $entry, 2);
        $head = $parts[0];
        $note = isset($parts[1]) ? trim(rtrim($parts[1], ')')) : '';
        $replacement = null;
        if ($note !== '' && stripos($note, 'use ') === 0) {
            $replacement = trim(substr($note, 4));
            $note = '';
        }
        foreach (explode(',', $head) as $name) {
            $name = trim(explode(' - ', $name)[0]);
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $rows[] = ['role_name' => $name, 'replacement' => $replacement, 'note' => $note !== '' ? $note : null];
        }
    }

    return $rows;
}

function fm_reference_styles(array $prompt): array
{
    $rows = [];
    foreach ($prompt['5_tactical_styles_and_team_instructions']['preset_tactical_styles'] as $nameEn => $body) {
        $body = is_array($body) ? $body : [];
        $rows[] = [
            'name_en' => (string) $nameEn,
            'mentality_lean' => $body['mentality_lean'] ?? null,
            'philosophy' => $body['philosophy'] ?? null,
            'details' => fm_reference_encode($body),
        ];
    }

    return $rows;
}

function fm_reference_instructions(array $prompt): array
{
    $rows = [];
    foreach ($prompt['5_tactical_styles_and_team_instructions']['team_instructions'] as $phase => $groups) {
        if (!is_array($groups)) {
            continue;
        }
        foreach ($groups as $groupName => $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $instructionEn => $body) {
                $rows[] = [
                    'phase' => (string) $phase,
                    'group_name' => (string) $groupName,
                    'instruction_en' => (string) $instructionEn,
                    'instruction_hu' => is_array($body) ? ($body['hu'] ?? null) : null,
                    'options' => is_array($body) ? fm_reference_encode($body) : (string) $body,
                    'note' => null,
                ];
            }
        }
    }

    return $rows;
}

/** Every Hungarian-to-English pair, tagged with what kind of term it is. */
function fm_reference_role_locale(array $locale): array
{
    $rows = [];
    $seen = [];

    $walk = function ($node, string $kind) use (&$walk, &$rows, &$seen): void {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if (is_string($value)) {
                $triple = $kind . "\0" . $key . "\0" . $value;
                if (!isset($seen[$triple])) {
                    $seen[$triple] = true;
                    $rows[] = ['kind' => $kind, 'hu' => (string) $key, 'en' => $value];
                }
            } else {
                $walk($value, $kind);
            }
        }
    };

    foreach ([
        'position' => 'position_codes',
        'phase' => 'phase_labels',
        'trait' => 'role_trait_labels',
        'instruction' => 'team_instruction_labels',
        'role' => 'observed_role_lists',
    ] as $kind => $key) {
        $walk($locale[$key] ?? [], $kind);
    }

    return $rows;
}

/** One row per container node, addressed by a dot path, holding it as text. */
function fm_reference_sections(string $document, array $payload): array
{
    $rows = [];

    $walk = function ($node, array $path) use (&$walk, &$rows, $document): void {
        if (!is_array($node)) {
            return;
        }
        $rows[] = [
            'document' => $document,
            'path' => implode('.', $path),
            'title' => $path[count($path) - 1],
            'text' => fm_reference_encode($node),
        ];
        if (!array_is_list($node)) {
            foreach ($node as $key => $value) {
                $walk($value, array_merge($path, [(string) $key]));
            }
        }
    };

    $walk($payload, [$document]);

    return $rows;
}

/** @return array<string, array<int, array<string, mixed>>> table name => rows */
function fm_reference_rows(): array
{
    $promptDocument = fm_reference_load(FM_REFERENCE_SYSTEM_PROMPT);
    $prompt = $promptDocument['FM26_AI_SYSTEM_PROMPT'];
    $locale = fm_reference_load(FM_REFERENCE_ROLE_LOCALE);

    [$positions, $roles] = fm_reference_positions_and_roles($prompt);

    return [
        'fm_positions' => $positions,
        'fm_roles' => $roles,
        'fm_banned_roles' => fm_reference_banned_roles($prompt),
        'fm_styles' => fm_reference_styles($prompt),
        'fm_instructions' => fm_reference_instructions($prompt),
        'fm_role_locale' => fm_reference_role_locale($locale),
        'fm_reference' => array_merge(
            fm_reference_sections(FM_REFERENCE_SYSTEM_PROMPT, $promptDocument),
            fm_reference_sections(FM_REFERENCE_ROLE_LOCALE, $locale)
        ),
    ];
}

/** Replace the contents of the fm_ tables from the reference documents. */
function fm_reference_import(PDO $pdo): array
{
    foreach (FM_REFERENCE_DELETE_ORDER as $table) {
        $pdo->exec('DELETE FROM ' . fm_ident($table));
    }

    $written = [];
    $replace = fm_driver() === 'mysql' ? 'REPLACE INTO' : 'INSERT OR REPLACE INTO';
    foreach (fm_reference_rows() as $table => $rows) {
        if ($rows === []) {
            continue;
        }
        $columns = array_keys($rows[0]);
        $quoted = implode(',', array_map('fm_ident', $columns));
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $stmt = $pdo->prepare($replace . ' ' . fm_ident($table) . " ({$quoted}) VALUES ({$placeholders})");
        foreach ($rows as $row) {
            $stmt->execute(array_values($row));
        }
        $written[$table] = count($rows);
    }

    return $written;
}
