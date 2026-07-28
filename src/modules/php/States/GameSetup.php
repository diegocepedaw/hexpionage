<?php
/**
 * Hexpionage — GameSetup state
 * per specs/STATE_MACHINE.md §2.1, §8.1 + STATE_MODEL.md §8 + DECISIONS.md (D-16)
 *
 * modern BGA: constructor-based GameState (id/type/name passed via parent
 * __construct named args) replaces the legacy property-based form per HAL.
 */

declare(strict_types=1);

namespace Bga\Games\Hexpionage\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Hexpionage\Game;

class GameSetup extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            // NOTE: BGA reserves state id 1 (framework gameSetup) and 99 (framework
            // gameEnd). Custom states must use other ids; 5 is free.
            id: 5,
            type: StateType::GAME,
            name: 'gameSetup',
        );
    }

    public function onEnteringState(): ?string
    {
        $game = $this->game;

        // Per CONTRACT §2.1, gameStarted carries first_player_id, agents_per_player, bag_size.
        $first_player = (int)$game->bga->globals->get('active_player_id');
        $bag_size = $game->getBagSize();

        $game->bga->notify->all(
            'gameStarted',
            clienttranslate('Game start — ${player_name} goes first.'),
            [
                'first_player_id' => $first_player,
                'agents_per_player' => 12,
                'bag_size' => $bag_size,
                'turn_id' => 1,
                'player_id' => $first_player,
                'player_name' => $game->getPlayerNameById($first_player),
            ]
        );

        // modern BGA: return next-state class reference instead of transition string.
        return TrickleDrawLeft::class;
    }
}
