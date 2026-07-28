<?php
/**
 * Hexpionage — Spawn state
 * per specs/STATE_MACHINE.md §2.6 + rulebook §5.2 + §6.1 + §6.2
 *
 * modern BGA: constructor-based GameState replaces legacy property form.
 * Action methods (#[PossibleAction] actSpawnAgent / actPassSpawn) remain on
 * Game.php in this refactor pass; transitions are emitted by Game via
 * $this->gamestate->nextState(...) using the legacy transition keys, which
 * are still wired up via the BGA states.inc-style mapping in Game.
 */

declare(strict_types=1);

namespace Bga\Games\Hexpionage\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Hexpionage\Game;

class Spawn extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 20,
            type: StateType::ACTIVE_PLAYER,
            name: 'spawn',
            description: clienttranslate('${actplayer} may spawn agents'),
            descriptionMyTurn: clienttranslate('${you} may spawn agents on the spawn row, or pass'),
        );
    }

    public function onEnteringState(): ?string
    {
        $game = $this->game;
        $game->bga->globals->set('phase', PHASE_SPAWN);

        // Auto-pass if no legal spawn possible.
        $args = $this->getArgs();
        if (empty($args['available_agents_in_pool']) ||
            empty($args['available_spawn_hexes']) ||
            $args['spawn_cap_remaining'] <= 0) {
            return Actions::class;
        }
        // [BE-65 fix] STATE_MACHINE §5.2 step 1: call undoSavepoint() on state first entry
        // to enable scoped spawn-undo per the spawn-state undo policy.
        if (method_exists($game, 'undoSavepoint')) {
            $game->undoSavepoint();
        }
        return null;
    }

    public function getArgs(): array
    {
        $game = $this->game;
        $active = $game->activePlayerId();

        $pool_rows = $game->getObjectListFromDB(
            "SELECT id, type_id FROM agent WHERE owner = $active AND state = " . AGENT_STATE_IN_POOL);
        $pool = [];
        foreach ($pool_rows as $r) {
            $pool[] = ['agent_id' => (int)$r['id'], 'type_id' => (int)$r['type_id']];
        }

        $on_board = (int)$game->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM agent WHERE owner = $active AND state = " . AGENT_STATE_ON_BOARD);
        $cap_remaining = max(0, 3 - $on_board);

        $legal_hexes = [];
        foreach (hexpionage_spawn_row_hexes() as $hex) {
            if ($game->getAgentAtHex($hex['q'], $hex['r']) !== null) continue;
            if ($game->getBlockadeAtHex($hex['q'], $hex['r']) !== null) continue;
            if (!empty($game->getLooseIntelAtHex($hex['q'], $hex['r']))) continue;
            $legal_hexes[] = $hex;
        }

        return [
            'available_agents_in_pool' => $pool,
            'available_spawn_hexes' => $legal_hexes,
            'current_on_board_count' => $on_board,
            'spawn_cap_remaining' => $cap_remaining,
            'can_pass' => true,
        ];
    }

    public function zombie(): ?string
    {
        return Actions::class;
    }
}
