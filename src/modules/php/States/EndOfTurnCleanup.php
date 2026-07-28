<?php
/**
 * Hexpionage — EndOfTurnCleanup state
 * per specs/STATE_MACHINE.md §2.8, §8.6 + rulebook §7.4 + DECISIONS.md (D-06a, D-07, D-17)
 *
 * modern BGA: constructor-based GameState replaces legacy property form.
 */

declare(strict_types=1);

namespace Bga\Games\Hexpionage\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Hexpionage\Game;

class EndOfTurnCleanup extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 90,
            type: StateType::GAME,
            name: 'endOfTurnCleanup',
        );
    }

    public function onEnteringState(): ?string
    {
        $game = $this->game;
        $game->bga->globals->set('phase', PHASE_END_OF_TURN_CLEANUP);

        $current_turn = (int)$game->bga->globals->get('turn_id');
        $ending_player = $game->activePlayerId();

        // ---- Step 1: Pin expiration (rulebook §7.4 / [D-06a]) -----------
        $pin_rows = $game->getObjectListFromDB(
            "SELECT id, owner FROM agent
              WHERE pinned_until_turn IS NOT NULL
                AND owner = $ending_player
                AND pinned_until_turn <= $current_turn");
        $cleared = [];
        if (!empty($pin_rows)) {
            $ids = implode(',', array_map(fn($r) => (int)$r['id'], $pin_rows));
            $game->DbQuery("UPDATE agent SET pinned_until_turn = NULL WHERE id IN ($ids)");
            foreach ($pin_rows as $r) {
                $cleared[] = ['agent_id' => (int)$r['id'], 'agent_owner' => (int)$r['owner']];
            }
        }
        $game->bga->notify->all(
            'pinExpired',
            empty($cleared) ? '' : clienttranslate('${count} pin(s) expired.'),
            [
                'cleared_agents' => $cleared,
                'count' => count($cleared),
            ]
        );

        // ---- Step 2: Blockade expiration ([D-07]) -----------------------
        $bl_rows = $game->getObjectListFromDB(
            "SELECT id, owner, hex_q, hex_r FROM blockade
              WHERE state = " . BLOCKADE_STATE_ON_BOARD . "
                AND owner != $ending_player
                AND placed_on_turn < $current_turn");
        $cleared_bl = [];
        if (!empty($bl_rows)) {
            $ids = implode(',', array_map(fn($r) => (int)$r['id'], $bl_rows));
            $game->DbQuery("UPDATE blockade SET state = " . BLOCKADE_STATE_EXPIRED . " WHERE id IN ($ids)");
            // Increment owner's blockades_remaining.
            foreach ($bl_rows as $r) {
                $owner = (int)$r['owner'];
                $game->DbQuery("UPDATE player SET blockades_remaining = blockades_remaining + 1 WHERE player_id = $owner");
                $cleared_bl[] = [
                    'blockade_id' => (int)$r['id'],
                    'owner' => $owner,
                    'hex' => ['q' => (int)$r['hex_q'], 'r' => (int)$r['hex_r']],
                ];
            }
        }
        $game->bga->notify->all(
            'blockadeExpired',
            empty($cleared_bl) ? '' : clienttranslate('${count} blockade(s) expired.'),
            [
                'cleared_blockades' => $cleared_bl,
                'count' => count($cleared_bl),
            ]
        );

        // ---- Step 3: Reset per-turn flags --------------------------------
        $game->bga->globals->set('smuggler_boost_used_this_turn', false);
        $game->bga->globals->set('spawned_this_turn', 0);
        $game->bga->globals->set('actions_phase_initialized', 0);
        $game->bga->globals->set('dice_state', new \stdClass());
        $game->DbQuery(
            "UPDATE agent SET hacker_pin_used_this_turn = 0, hacker_steal_used_this_turn = 0
              WHERE state = " . AGENT_STATE_ON_BOARD . "
                AND type_id = " . AGENT_TYPE_HACKER);

        // ---- Step 4: Win check (redundant but specified in §7.4) -------
        $score = (int)$game->getUniqueValueFromDB(
            "SELECT player_score FROM player WHERE player_id = $ending_player");
        if ($score >= 20) {
            $game->bga->globals->set('game_winner', $ending_player);
            return GameEnd::class;
        }

        // ---- Step 5: Depletion check ([D-17]) ---------------------------
        if ($game->checkDepletion() !== null) {
            return GameEnd::class;
        }

        // ---- Step 6: Pass turn -------------------------------------------
        $next_turn = $current_turn + 1;
        $next_active = $game->getOpponent($ending_player);
        $game->bga->globals->set('turn_id', $next_turn);
        $game->bga->globals->set('active_player_id', $next_active);
        $game->bga->globals->set('actions_remaining', 0);
        $game->gamestate->changeActivePlayer($next_active);

        $game->incStat(1, 'turns_total');

        $game->bga->notify->all(
            'turnEnded',
            clienttranslate('Turn ${new_turn_id} — ${player_name} to play.'),
            [
                'ended_player_id' => $ending_player,
                'new_active_player_id' => $next_active,
                'new_turn_id' => $next_turn,
                'player_id' => $next_active,
                'player_name' => $game->getPlayerNameById($next_active),
            ]
        );

        return TrickleDrawLeft::class;
    }
}
