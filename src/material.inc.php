<?php
/**
 * Hexpionage — static material constants
 * per docs/specs/STATE_MODEL.md §3 + DECISIONS.md (D-01, D-19) + rulebook §2
 *
 * Notes:
 *   - Hex coordinate scheme: pointy-top axial (q, r). [STATE_MODEL §3]
 *   - G-01 / G-02 are RESOLVED. The hex tables below are the canonical
 *     44-hex layout read off final_printing/game board/game_board_print.png
 *     and recorded in design/BOARD_LAYOUT.md:
 *       30 lavender Field hexes (r=0..3, rows of 6/7/8/9)
 *       14 orange "intel rain" hexes (r=-4..-1, rows of 2/3/4/5)
 *        9 spawn-row hexes (the r=3 Field row)
 *     Still open: TODO(I-02), the per-type intel tile distribution below.
 */

// ---- Agent type IDs (rulebook §2.2 / [D-01]) ---------------------------------

const AGENT_TYPE_COMMS_SPECIALIST = 1;
const AGENT_TYPE_ANALYST          = 2;
const AGENT_TYPE_SMUGGLER         = 3;
const AGENT_TYPE_ENGINEER         = 4;
const AGENT_TYPE_HACKER           = 5;
const AGENT_TYPE_DOUBLE_AGENT     = 6;

const AGENT_TYPES = [
    AGENT_TYPE_COMMS_SPECIALIST => 'comms_specialist',
    AGENT_TYPE_ANALYST          => 'analyst',
    AGENT_TYPE_SMUGGLER         => 'smuggler',
    AGENT_TYPE_ENGINEER         => 'engineer',
    AGENT_TYPE_HACKER           => 'hacker',
    AGENT_TYPE_DOUBLE_AGENT     => 'double_agent',
];

const AGENT_STATE_IN_POOL  = 0;
const AGENT_STATE_ON_BOARD = 1;
const AGENT_STATE_REMOVED  = 2;

// ---- Intel type IDs (rulebook §2.4 / [D-19]) --------------------------------

const INTEL_TYPE_HONEYPOT             = 1;
const INTEL_TYPE_INDUSTRIAL_TECH      = 2;
const INTEL_TYPE_LEAKED_EMAIL         = 3;
const INTEL_TYPE_BLACKMAIL            = 4;
const INTEL_TYPE_SECURITY_CREDENTIAL  = 5;
const INTEL_TYPE_STATE_SECRET         = 6;

const INTEL_TYPES = [
    INTEL_TYPE_HONEYPOT             => 'honeypot',
    INTEL_TYPE_INDUSTRIAL_TECH      => 'industrial_tech',
    INTEL_TYPE_LEAKED_EMAIL         => 'leaked_email',
    INTEL_TYPE_BLACKMAIL            => 'blackmail',
    INTEL_TYPE_SECURITY_CREDENTIAL  => 'security_credential',
    INTEL_TYPE_STATE_SECRET         => 'state_secret',
];

// Score values per [D-19]: 0/2/2/2/3/4
const INTEL_SCORE_VALUES = [
    INTEL_TYPE_HONEYPOT             => 0,
    INTEL_TYPE_INDUSTRIAL_TECH      => 2,
    INTEL_TYPE_LEAKED_EMAIL         => 2,
    INTEL_TYPE_BLACKMAIL            => 2,
    INTEL_TYPE_SECURITY_CREDENTIAL  => 3,
    INTEL_TYPE_STATE_SECRET         => 4,
];

// Per-type tile counts (placeholder per TODO(I-02), STATE_MODEL §8.3).
// Total must equal 47 per rulebook §2.4. Asset audit replaces these counts.
const INTEL_TILE_COUNTS = [
    INTEL_TYPE_HONEYPOT             => 7,
    INTEL_TYPE_INDUSTRIAL_TECH      => 8,
    INTEL_TYPE_LEAKED_EMAIL         => 8,
    INTEL_TYPE_BLACKMAIL            => 8,
    INTEL_TYPE_SECURITY_CREDENTIAL  => 8,
    INTEL_TYPE_STATE_SECRET         => 8,
];

const INTEL_STATE_IN_BAG          = 0;
const INTEL_STATE_ON_BOARD        = 1;
const INTEL_STATE_ON_AGENT        = 2;
const INTEL_STATE_SCORED          = 3;
const INTEL_STATE_RETURNED_TO_BAG = 4;

const BLOCKADE_STATE_ON_BOARD = 1;
const BLOCKADE_STATE_EXPIRED  = 2;

// Die-color → intel-type mapping is 1:1 per [D-19]; we reuse INTEL_TYPES.

// ---- Hex coordinate constants (G-02 resolved) -------------------------------
// Canonical board layout per design/BOARD_LAYOUT.md.
//   - 30 lavender Field hexes (r=0..3) — agents may move/spawn/retire here.
//   - 14 orange "intel rain" hexes (r=-4..-1) — agents NOT allowed; intel transits.
//   -  9 spawn-row hexes (✦ row, r=3, q=-2..6).
//   -  2 intel-entry hexes at the top of the orange zone (r=-4).
//
// Coordinate scheme: pointy-top axial (q, r) per STATE_MODEL §3.3. G-01 confirmed.

/**
 * Canonical Field hex enumeration per design/BOARD_LAYOUT.md.
 * Bottom row (r=3) is the spawn row (9 hexes); top of Field is r=0 (6 hexes).
 */
const ALL_FIELD_HEXES = [
    // r=0 (top of Field, 6 hexes)
    ['q' =>  0, 'r' => 0], ['q' => 1, 'r' => 0], ['q' => 2, 'r' => 0],
    ['q' =>  3, 'r' => 0], ['q' => 4, 'r' => 0], ['q' => 5, 'r' => 0],
    // r=1 (7 hexes)
    ['q' => -1, 'r' => 1], ['q' => 0, 'r' => 1], ['q' => 1, 'r' => 1],
    ['q' =>  2, 'r' => 1], ['q' => 3, 'r' => 1], ['q' => 4, 'r' => 1],
    ['q' =>  5, 'r' => 1],
    // r=2 (8 hexes)
    ['q' => -1, 'r' => 2], ['q' => 0, 'r' => 2], ['q' => 1, 'r' => 2],
    ['q' =>  2, 'r' => 2], ['q' => 3, 'r' => 2], ['q' => 4, 'r' => 2],
    ['q' =>  5, 'r' => 2], ['q' => 6, 'r' => 2],
    // r=3 (bottom, spawn row, 9 hexes)
    ['q' => -2, 'r' => 3], ['q' => -1, 'r' => 3], ['q' => 0, 'r' => 3],
    ['q' =>  1, 'r' => 3], ['q' =>  2, 'r' => 3], ['q' => 3, 'r' => 3],
    ['q' =>  4, 'r' => 3], ['q' =>  5, 'r' => 3], ['q' => 6, 'r' => 3],
];

/**
 * Orange "intel rain" hexes — agents NOT allowed; loose intel transits.
 * Includes the 2 intel-entry hexes at r=-4.
 */
const ALL_ORANGE_HEXES = [
    // r=-4 (top, 2 entry hexes; gap at q=2 — no hex)
    ['q' => 1, 'r' => -4],  // entry "1"
    ['q' => 3, 'r' => -4],  // entry "2"
    // r=-3 (3 hexes)
    ['q' => 1, 'r' => -3], ['q' => 2, 'r' => -3], ['q' => 3, 'r' => -3],
    // r=-2 (4 hexes)
    ['q' => 0, 'r' => -2], ['q' => 1, 'r' => -2], ['q' => 2, 'r' => -2], ['q' => 3, 'r' => -2],
    // r=-1 (5 hexes; bottom of orange)
    ['q' => 0, 'r' => -1], ['q' => 1, 'r' => -1], ['q' => 2, 'r' => -1],
    ['q' => 3, 'r' => -1], ['q' => 4, 'r' => -1],
];

/**
 * Spawn row (✦) — bottom of the Field. Used by spawn/retire eligibility.
 */
const ALL_SPAWN_ROW_HEXES = [
    ['q' => -2, 'r' => 3], ['q' => -1, 'r' => 3], ['q' => 0, 'r' => 3],
    ['q' =>  1, 'r' => 3], ['q' =>  2, 'r' => 3], ['q' => 3, 'r' => 3],
    ['q' =>  4, 'r' => 3], ['q' =>  5, 'r' => 3], ['q' => 6, 'r' => 3],
];

/**
 * Intel entry hexes per rulebook §5.1 + design/BOARD_LAYOUT.md.
 * Trickle entry sites; visible labels "1" and "2" on the printed art.
 */
const INTEL_ENTRY_HEX_TOP_LEFT  = ['q' => 1, 'r' => -4]; // labeled "1"
const INTEL_ENTRY_HEX_TOP_RIGHT = ['q' => 3, 'r' => -4]; // labeled "2"

/**
 * Field hex test (lavender; agents may stand here). Per rulebook §2.6.
 * Table-driven per design/BOARD_LAYOUT.md (G-02 resolved).
 */
function hexpionage_is_field_hex(int $q, int $r): bool
{
    foreach (ALL_FIELD_HEXES as $hex) {
        if ($hex['q'] === $q && $hex['r'] === $r) {
            return true;
        }
    }
    return false;
}

/**
 * Enumerate all Field hexes as a list of {q, r} pairs.
 */
function hexpionage_field_hex_list(): array
{
    return ALL_FIELD_HEXES;
}

/**
 * Spawn-row test: bottom row (✦) per rulebook §5.2.
 */
function hexpionage_is_spawn_row_hex(int $q, int $r): bool
{
    foreach (ALL_SPAWN_ROW_HEXES as $hex) {
        if ($hex['q'] === $q && $hex['r'] === $r) {
            return true;
        }
    }
    return false;
}

/**
 * Enumerate spawn-row hexes (✦ row).
 */
function hexpionage_spawn_row_hexes(): array
{
    return ALL_SPAWN_ROW_HEXES;
}

/**
 * Orange "intel rain" hex test (agent-forbidden; intel transits per §7.2).
 */
function hexpionage_is_orange_hex(int $q, int $r): bool
{
    foreach (ALL_ORANGE_HEXES as $hex) {
        if ($hex['q'] === $q && $hex['r'] === $r) {
            return true;
        }
    }
    return false;
}

/**
 * Intel entry hex test — true iff (q, r) is one of the 2 entry hexes at r=-4.
 */
function hexpionage_is_intel_entry_hex(int $q, int $r): bool
{
    return ($q === INTEL_ENTRY_HEX_TOP_LEFT['q']  && $r === INTEL_ENTRY_HEX_TOP_LEFT['r'])
        || ($q === INTEL_ENTRY_HEX_TOP_RIGHT['q'] && $r === INTEL_ENTRY_HEX_TOP_RIGHT['r']);
}

/**
 * Enumerate every board hex (Field + Orange = 44 total).
 */
function hexpionage_all_board_hexes(): array
{
    return array_merge(ALL_FIELD_HEXES, ALL_ORANGE_HEXES);
}

/**
 * Pointy-top axial neighbors per STATE_MODEL §3.3.
 * Returns array of 6 [q,r] tuples.
 */
function hexpionage_hex_neighbors(int $q, int $r): array
{
    return [
        'NW' => ['q' => $q,     'r' => $r - 1],
        'NE' => ['q' => $q + 1, 'r' => $r - 1],
        'E'  => ['q' => $q + 1, 'r' => $r    ],
        'SE' => ['q' => $q,     'r' => $r + 1],
        'SW' => ['q' => $q - 1, 'r' => $r + 1],
        'W'  => ['q' => $q - 1, 'r' => $r    ],
    ];
}

/**
 * Adjacency check per STATE_MODEL §3.3.
 */
function hexpionage_is_adjacent(int $q1, int $r1, int $q2, int $r2): bool
{
    $dq = $q2 - $q1;
    $dr = $r2 - $r1;
    static $valid = [
        [0, -1], [1, -1], [1, 0],
        [0, 1], [-1, 1], [-1, 0],
    ];
    foreach ($valid as $d) {
        if ($d[0] === $dq && $d[1] === $dr) {
            return true;
        }
    }
    return false;
}

// ---- Phase enum (mirrors STATE_MODEL §2.5 globals) --------------------------

const PHASE_SETUP                = 'setup';
const PHASE_TRICKLE_DRAW_LEFT    = 'trickle_draw_left';
const PHASE_TRICKLE_DRAW_RIGHT   = 'trickle_draw_right';
const PHASE_TRICKLE_ROLL         = 'trickle_roll';
const PHASE_TRICKLE_RESOLVE      = 'trickle_resolve';
const PHASE_SPAWN                = 'spawn';
const PHASE_ACTIONS              = 'actions';
const PHASE_END_OF_TURN_CLEANUP  = 'end_of_turn_cleanup';
const PHASE_GAME_END             = 'game_end';
