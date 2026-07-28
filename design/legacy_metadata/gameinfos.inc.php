<?php
/**
 * Hexpionage — game metadata
 * per docs/specs/BGA_CHECKLIST.md + DECISIONS.md (D-02, D-12b, D-13)
 */

$gameinfos = [
    // Game identity
    'game_name' => 'Hexpionage',
    'designers' => [ 'Hexpionage Designer' ], // TODO(metadata): replace with verified designer credit
    'artists'   => [ 'Hexpionage Artist'   ], // TODO(metadata): replace with verified artist credit
    'developers' => [ [ 'name' => 'BGA Adapter', 'website' => '' ] ],
    'publishers' => [
        [
            'name'      => 'Self-published',
            'website'   => '',
            'bgg_id'    => 0, // TODO(metadata): publisher BGG id
        ],
    ],

    // BGG canonical id [D-12b]
    'bgg_id' => 307967,

    // Player count [D-02]
    'players' => [ 2 ],

    // Suggestions / categories
    'suggest_player_number' => [ 2 ],
    'not_recommend_player_number' => [],

    'estimated_duration' => 30,
    'fast_additional_time' => 30,
    'medium_additional_time' => 60,
    'slow_additional_time' => 90,

    'tie_breaker_description' => totranslate('Active player wins when both would cross 20 simultaneously [D-03]'),

    // Modern undo + replay support [BGA_PRIMER §2]
    'db_undo_support' => true,

    // Categories / mechanics (BGG-style)
    'losers_not_ranked' => false,
    'solo_mode_ranked' => false,
    'is_coop' => 0,
    'language_dependency' => false,

    'complexity' => 3,
    'strategy' => 4,
    'diplomacy' => 1,
    'luck' => 3,

    'player_colors' => [ 'ffffff', '000000' ],

    'favorite_colors_support' => true,
    'disable_player_order_swap_on_rematch' => false,

    'game_interface_width' => [
        'min' => 740,
        'max' => 1200,
    ],

    'game_version' => 1,
];
