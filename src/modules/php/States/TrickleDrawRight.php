<?php
/**
 * Hexpionage — TrickleDrawRight state
 * per docs/specs/STATE_MACHINE.md §2.3, §8.3 + DECISIONS.md (D-18)
 *
 * modern BGA: constructor-based GameState replaces legacy property form.
 */

declare(strict_types=1);

namespace Bga\Games\hexpionage\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\hexpionage\Game;

class TrickleDrawRight extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 11,
            type: StateType::GAME,
            name: 'trickleDrawRight',
        );
    }

    public function onEnteringState(): ?string
    {
        $game = $this->game;
        $game->bga->globals->set('phase', PHASE_TRICKLE_DRAW_RIGHT);

        $hex = INTEL_ENTRY_HEX_TOP_RIGHT;
        $tile_id = $game->drawFromBag();

        if ($tile_id === null) {
            $game->bga->notify->all(
                'intelDrawn',
                clienttranslate('Bag empty — right-side draw skipped.'),
                [
                    'tile_id' => 0,
                    'type' => 0,
                    'hex' => $hex,
                    'side' => 'right',
                    'new_bag_size' => 0,
                    'skipped' => true,
                ]
            );
            return TrickleRoll::class;
        }

        $tile = $game->getIntel($tile_id);
        $type_id = (int)$tile['type_id'];
        $game->DbQuery(
            "UPDATE intel_tile SET state = " . INTEL_STATE_ON_BOARD .
            ", hex_q = " . $hex['q'] . ", hex_r = " . $hex['r'] . // NOI18N
            ", agent_id = NULL, scored_by = NULL, stack_order = 0
              WHERE id = $tile_id");

        $game->bga->notify->all(
            'intelDrawn',
            clienttranslate('Intel drawn (right): ${type_name} → top-right entry hex.'),
            [
                'tile_id' => $tile_id,
                'type' => $type_id,
                'type_name' => INTEL_TYPES[$type_id],
                'hex' => $hex,
                'side' => 'right',
                'new_bag_size' => $game->getBagSize(),
                'skipped' => false,
            ]
        );

        return TrickleRoll::class;
    }
}
