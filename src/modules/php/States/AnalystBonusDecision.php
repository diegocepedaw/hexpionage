<?php
/**
 * Hexpionage — AnalystBonusDecision state [D-26]
 * per specs/STATE_MACHINE.md §2.7b + DECISIONS.md (D-18, D-20, D-26)
 *
 * Two-step Analyst flow:
 *   onEnteringState — server draws bonus tile (or skips per [D-18]); fires
 *   `analystBonusDrawn` PRIVATELY to active player per [D-20].
 *   Then waits for actAnalystKeep / actAnalystReturn.
 *
 * modern BGA: constructor-based GameState replaces legacy property form.
 */

declare(strict_types=1);

namespace Bga\Games\Hexpionage\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Hexpionage\Game;

class AnalystBonusDecision extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 35,
            type: StateType::ACTIVE_PLAYER,
            name: 'analystBonusDecision',
            description: clienttranslate('${actplayer} is resolving the Analyst bonus draw'),
            descriptionMyTurn: clienttranslate('${you} must keep or return the bonus intel tile'),
        );
    }

    public function onEnteringState(): ?string
    {
        $game = $this->game;
        $game->bga->globals->set('phase', 'analyst_bonus');
        $active = $game->activePlayerId();

        // [D-18] Empty-bag → skip.
        $tile_id = $game->drawFromBag();
        if ($tile_id === null) {
            $game->bga->notify->all(
                'analystBonusSkipped',
                clienttranslate('Bag empty — Analyst bonus forfeited.'),
                ['player_id' => $active]
            );
            // Bypass: transition back to actions immediately.
            return Actions::class;
        }

        // Move tile out of bag into transient state. We use stack_order=255 as
        // sentinel that this tile is "pending Analyst decision" and not on
        // the board nor on an agent. Conceptually we keep it in_bag with the
        // pending id captured in globals so accidental observers do not see it
        // in `getAllDatas()` (filtered out per STATE_MODEL §4.3 since it
        // remains in INTEL_STATE_IN_BAG).
        // For correctness we store the tile id in globals; the row stays in
        // state=in_bag but the count remains the same until commit.
        // Since drawFromBag() above only picked an id, no DB mutation has
        // happened yet — we just record it.
        $game->bga->globals->set('analyst_bonus_pending_tile_id', $tile_id);

        // [BE-65 fix] STATE_MACHINE §5.2 step 3: undoSavepoint() AFTER the bga_rand bonus
        // draw so the random draw becomes irreversible (re-rolling on undo would leak info).
        $game->maybeUndoSavepoint();

        $tile = $game->getIntel($tile_id);
        $type_id = (int)$tile['type_id'];
        $score_value = (int)$tile['score_value'];

        // Private notification per [D-20].
        $game->bga->notify->player(
            $active,
            'analystBonusDrawn',
            clienttranslate('Analyst bonus drawn: ${type_name} — keep or return?'),
            [
                'tile_id' => $tile_id,
                'type' => $type_id,
                'type_name' => INTEL_TYPES[$type_id],
                'score_value' => $score_value,
                'new_bag_size' => $game->getBagSize() - 1, // would-be size if kept/returned
            ]
        );

        return null;
    }

    public function onLeavingState(): void
    {
        $game = $this->game;
        $game->bga->globals->set('analyst_bonus_pending_tile_id', null);
    }

    public function getArgs(): array
    {
        // [BE-42 fix] STATE_MACHINE §2.7b: tile data is private (sent via the
        // analystBonusDrawn private notification). On F5 reload mid-decision the
        // active player loses the drawn-tile context, so expose tile_id/type/score_value
        // through BGA's private-args mechanism (the `_private[<player_id>]` key) so
        // the keep/return modal can re-render. Public args carry only player_id.
        $game = $this->game;
        $active = $game->activePlayerId();
        $args = [
            'player_id' => $active,
        ];
        $tile_id = $game->bga->globals->get('analyst_bonus_pending_tile_id');
        if ($tile_id !== null) {
            $tile = $game->getIntel((int)$tile_id);
            $type_id = (int)$tile['type_id'];
            $args['_private'] = [
                $active => [
                    'tile_id' => (int)$tile_id,
                    'type' => $type_id,
                    'type_name' => INTEL_TYPES[$type_id],
                    'score_value' => (int)$tile['score_value'],
                ],
            ];
        }
        return $args;
    }

    public function zombie(): ?string
    {
        // Auto-fire actAnalystReturn (safer default per [D-26]).
        $game = $this->game;
        $tile_id = $game->bga->globals->get('analyst_bonus_pending_tile_id');
        if ($tile_id !== null) {
            $game->returnTileToBag((int)$tile_id);
            $game->bga->globals->set('analyst_bonus_pending_tile_id', null);
            $game->bga->notify->all(
                'analystBonusReturned',
                clienttranslate('${player_name} returns the Analyst bonus to the bag.'),
                [
                    'player_id' => $game->activePlayerId(),
                    'player_name' => $game->getPlayerNameById($game->activePlayerId()),
                    'new_bag_size' => $game->getBagSize(),
                ]
            );
        }
        return Actions::class;
    }
}
