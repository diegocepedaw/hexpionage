<?php
/**
 * Hexpionage — GameEnd terminal state
 * per specs/STATE_MACHINE.md §2.9
 *
 * BGA reserves id 99 for the terminal state. We provide a class for
 * gameEnded notification emission; the framework handles the end-screen.
 *
 * modern BGA: constructor-based GameState replaces legacy property form.
 */

declare(strict_types=1);

namespace Bga\Games\Hexpionage\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Hexpionage\Game;

class GameEnd extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            // BGA reserves id 99 for its own terminal state; we run our
            // end-of-game notification in id 98 and then hand off to 99.
            id: 98,
            type: StateType::GAME,
            name: 'gameEnd',
        );
    }

    public function onEnteringState(): int
    {
        $game = $this->game;
        $game->bga->globals->set('phase', PHASE_GAME_END);

        $winner_id = $game->bga->globals->get('game_winner');
        if ($winner_id !== null) {
            $winner_id = (int)$winner_id;
        }

        $scores = $game->getCollectionFromDB(
            "SELECT player_id, player_score FROM player", true);
        $final = [];
        foreach ($scores as $pid => $sc) {
            $final[(string)$pid] = (int)$sc;
        }

        // Determine win reason: 20 points vs depletion.
        $win_reason = 'depletion';
        if ($winner_id !== null && (int)($final[(string)$winner_id] ?? 0) >= 20) {
            $win_reason = 'score_20';
        }

        $game->bga->notify->all(
            'gameEnded',
            clienttranslate('Game over — ${player_name} wins (${win_reason_text}).'),
            [
                'winner_id' => $winner_id,
                'win_reason' => $win_reason,
                'win_reason_text' => $win_reason === 'score_20' ? 'reached 20 points' : 'opponent depleted',
                'final_scores' => $final,
                'player_id' => $winner_id,
                'player_name' => $winner_id === null ? '' : $game->getPlayerNameById($winner_id),
            ]
        );

        // Hand off to the framework's reserved terminal state.
        return 99;
    }
}
