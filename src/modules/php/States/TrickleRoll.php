<?php
/**
 * Hexpionage — TrickleRoll state
 * per specs/STATE_MACHINE.md §2.4, §8.4 + rulebook §5.1 step 3
 *
 * modern BGA: constructor-based GameState replaces legacy property form.
 */

declare(strict_types=1);

namespace Bga\Games\Hexpionage\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Hexpionage\Game;

class TrickleRoll extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 12,
            type: StateType::GAME,
            name: 'trickleRoll',
        );
    }

    public function onEnteringState(): ?string
    {
        $game = $this->game;
        $game->bga->globals->set('phase', PHASE_TRICKLE_ROLL);

        // Roll 6 dice; result 1=odd→SW, 2=even→SE per rulebook §5.1.
        $dice = [];
        foreach (INTEL_TYPES as $type_id => $name) {
            $roll = bga_rand(1, 2);
            $dice[$name] = ($roll === 1) ? 'odd' : 'even';
        }
        $game->bga->globals->set('dice_state', $dice);

        $game->bga->notify->all(
            'diceRolled',
            clienttranslate('Trickle dice rolled.'),
            [
                'dice_state' => $dice,
                'turn_id' => (int)$game->bga->globals->get('turn_id'),
            ]
        );

        return TrickleResolve::class;
    }
}
