<?php
/**
 * Hexpionage — TrickleDrawLeft state
 * per specs/STATE_MACHINE.md §2.2, §8.2 + DECISIONS.md (D-18)
 *
 * modern BGA: constructor-based GameState replaces legacy property form.
 */

declare(strict_types=1);

namespace Bga\Games\Hexpionage\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Hexpionage\Game;

class TrickleDrawLeft extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 10,
            type: StateType::GAME,
            name: 'trickleDrawLeft',
        );
    }

    public function onEnteringState(): ?string
    {
        $game = $this->game;
        $game->bga->globals->set('phase', PHASE_TRICKLE_DRAW_LEFT);

        $hex = INTEL_ENTRY_HEX_TOP_LEFT;
        $tile_id = $game->drawFromBag();

        if ($tile_id === null) {
            // [D-18] empty-bag is a no-op; emit a skipped notification for UI consistency.
            $game->bga->notify->all(
                'intelDrawn',
                clienttranslate('Bag empty — left-side draw skipped.'),
                [
                    'tile_id' => 0,
                    'type' => 0,
                    'hex' => $hex,
                    'side' => 'left',
                    'new_bag_size' => 0,
                    'skipped' => true,
                ]
            );
            return TrickleDrawRight::class;
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
            clienttranslate('Intel drawn (left): ${type_name} → top-left entry hex.'),
            [
                'tile_id' => $tile_id,
                'type' => $type_id,
                'type_name' => INTEL_TYPES[$type_id],
                'hex' => $hex,
                'side' => 'left',
                'new_bag_size' => $game->getBagSize(),
                'skipped' => false,
            ]
        );

        return TrickleDrawRight::class;
    }
}
