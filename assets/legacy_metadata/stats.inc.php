<?php
/**
 * Hexpionage — statistics declarations
 * per specs/BGA_CHECKLIST.md (alpha gate; meaningful per-player stats)
 */

$stats_type = [
    // Table-level stats
    'table' => [
        'turns_total' => [
            'id' => 10,
            'name' => totranslate('Total turns played'),
            'type' => 'int',
        ],
        'trickle_off_board_returns' => [
            'id' => 11,
            'name' => totranslate('Intel that trickled off the board'),
            'type' => 'int',
        ],
        'honeypot_strikes' => [
            'id' => 12,
            'name' => totranslate('Honeypot agent removals'),
            'type' => 'int',
        ],
    ],

    // Per-player stats
    'player' => [
        'intel_scored' => [
            'id' => 20,
            'name' => totranslate('Intel scored'),
            'type' => 'int',
        ],
        'agents_retired' => [
            'id' => 21,
            'name' => totranslate('Agents retired'),
            'type' => 'int',
        ],
        'agents_lost_honeypot' => [
            'id' => 22,
            'name' => totranslate('Agents lost to honeypots'),
            'type' => 'int',
        ],
        'blockades_placed' => [
            'id' => 23,
            'name' => totranslate('Blockades placed'),
            'type' => 'int',
        ],
        'pins_applied' => [
            'id' => 24,
            'name' => totranslate('Pins applied'),
            'type' => 'int',
        ],
        'intel_stolen' => [
            'id' => 25,
            'name' => totranslate('Intel stolen via Hacker'),
            'type' => 'int',
        ],
        'smuggler_boosts' => [
            'id' => 26,
            'name' => totranslate('Smuggler boosts used'),
            'type' => 'int',
        ],
        'actions_taken' => [
            'id' => 27,
            'name' => totranslate('Total actions taken'),
            'type' => 'int',
        ],
        'avg_actions_per_turn' => [
            'id' => 28,
            'name' => totranslate('Average actions per turn'),
            'type' => 'float',
        ],
    ],
];
