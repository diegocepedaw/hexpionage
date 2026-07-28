<?php
/**
 * Hexpionage — TrickleResolve state
 * per docs/specs/STATE_MACHINE.md §2.5, §8.5 + rulebook §7.2 + DECISIONS.md (D-21, D-23, D-24)
 *
 * Resolution algorithm (rulebook §7.2 + §9.3 EDGE O-01 + [D-24]):
 *   Step A: compute intended targets per dice
 *   Step B: blockade redirect (own-direction → other diagonal → bag-if-off-board → no-move-if-both-blocked)
 *   Step C: simultaneous apply
 *   Step D: off-board → returned_to_bag
 *   Step E: agent possession (Honeypot first, then over-capacity dump)
 *   Step F: emit ONE batched trickleResolved notification
 *
 * Runs within the framework-managed request transaction (STATE_MODEL §7.4).
 *
 * modern BGA: constructor-based GameState replaces legacy property form.
 */

declare(strict_types=1);

namespace Bga\Games\hexpionage\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\hexpionage\Game;

class TrickleResolve extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 13,
            type: StateType::GAME,
            name: 'trickleResolve',
        );
    }

    public function onEnteringState(): ?string
    {
        $game = $this->game;
        $game->bga->globals->set('phase', PHASE_TRICKLE_RESOLVE);

        $dice = $game->bga->globals->get('dice_state');
        if (is_object($dice)) {
            $dice = (array)$dice;
        }

        // ---- Step A: compute intended moves -------------------------------
        $loose = $game->getObjectListFromDB(
            "SELECT id, type_id, hex_q, hex_r FROM intel_tile WHERE state = " . INTEL_STATE_ON_BOARD);

        $plans = []; // [tile_id => ['from'=>['q','r'],'to'=>...,'kind'=>'move|return|stay','redirected'=>bool]]
        foreach ($loose as $tile) {
            $tid = (int)$tile['id'];
            $type_id = (int)$tile['type_id'];
            $sq = (int)$tile['hex_q'];
            $sr = (int)$tile['hex_r'];
            $color_key = INTEL_TYPES[$type_id];
            $face = $dice[$color_key] ?? 'odd';
            // Direction: odd → SW (q-1, r+1); even → SE (q, r+1)
            if ($face === 'odd') {
                $dq = -1; $dr = 1; $other_dq = 0; $other_dr = 1;
            } else {
                $dq = 0; $dr = 1; $other_dq = -1; $other_dr = 1;
            }
            $tq = $sq + $dq;
            $tr = $sr + $dr;
            $oq = $sq + $other_dq;
            $or = $sr + $other_dr;

            $plans[$tid] = [
                'tile_id' => $tid,
                'type_id' => $type_id,
                'from' => ['q' => $sq, 'r' => $sr],
                'to' => ['q' => $tq, 'r' => $tr],
                'kind' => 'move',
                'redirected' => false,
                'off_board' => false,
            ];

            // [G-02] Per design/BOARD_LAYOUT.md, the board consists of Field hexes
            // (lavender, agents-allowed) AND orange "intel rain" hexes (intel transits).
            // Trickle "off-board" means leaving the union of both regions.
            $is_on_board = function (int $q, int $r) use ($game): bool {
                return $game->isFieldHex($q, $r) || hexpionage_is_orange_hex($q, $r);
            };

            // ---- Step B: blockade redirect ------------------------------------
            $blockade_main = $game->getBlockadeAtHex($tq, $tr);
            if ($blockade_main !== null) {
                $blockade_other = $game->getBlockadeAtHex($oq, $or);
                if ($blockade_other !== null) {
                    // Both diagonals blockaded → no_move §9.6.D.
                    $plans[$tid]['kind'] = 'stay';
                    $plans[$tid]['to'] = ['q' => $sq, 'r' => $sr];
                    continue;
                }
                if (!$is_on_board($oq, $or)) {
                    // Redirect succeeded but lands off-board → bag per [D-24].
                    $plans[$tid]['kind'] = 'return';
                    $plans[$tid]['redirected'] = true;
                    $plans[$tid]['off_board'] = true;
                    $plans[$tid]['to'] = ['q' => $oq, 'r' => $or];
                    continue;
                }
                $plans[$tid]['to'] = ['q' => $oq, 'r' => $or];
                $plans[$tid]['redirected'] = true;
                continue;
            }

            // ---- Step C: off-board check (§9.2) -------------------------------
            if (!$is_on_board($tq, $tr)) {
                $plans[$tid]['kind'] = 'return';
                $plans[$tid]['off_board'] = true;
            }
        }

        // ---- Step C/D apply: simultaneous mutation ---------------------------
        $moves_emit = [];
        $stat_off_board = 0;
        foreach ($plans as $tid => $plan) {
            if ($plan['kind'] === 'stay') {
                // Tiles that can't move are NOT included in moves[] per CONTRACT §2.5.
                continue;
            }
            if ($plan['kind'] === 'return') {
                $game->returnTileToBag($tid);
                $moves_emit[] = [
                    'tile_id' => $tid,
                    'from_hex' => $plan['from'],
                    'to_hex' => $plan['to'],
                    'redirected' => $plan['redirected'],
                    'off_board' => true,
                ];
                $stat_off_board++;
                continue;
            }
            // 'move'
            $tq = $plan['to']['q'];
            $tr = $plan['to']['r'];
            $game->DbQuery(
                "UPDATE intel_tile SET hex_q = $tq, hex_r = $tr WHERE id = $tid");
            $moves_emit[] = [
                'tile_id' => $tid,
                'from_hex' => $plan['from'],
                'to_hex' => $plan['to'],
                'redirected' => $plan['redirected'],
                'off_board' => false,
            ];
        }

        // ---- Step E: agent possession (Honeypot first, then capacity) -------
        // Group arrivals by destination hex; for each agent occupying that hex
        // (per [D-21] never co-occupies before; we resolve at-rest via pickup),
        // run honeypot-first / dump-second.
        $honeypot_removals = [];
        $over_capacity_dumps = [];

        // Re-fetch all agents on-board to find any at arrival hexes.
        $agents = $game->getObjectListFromDB(
            "SELECT id, owner, type_id, hex_q, hex_r FROM agent WHERE state = " . AGENT_STATE_ON_BOARD);

        foreach ($agents as $a) {
            $aid = (int)$a['id'];
            $aq = (int)$a['hex_q'];
            $ar = (int)$a['hex_r'];

            // Loose intel arriving at this hex (now after step C/D).
            $arrivals = $game->getObjectListFromDB(
                "SELECT id, type_id FROM intel_tile WHERE state = " . INTEL_STATE_ON_BOARD .
                " AND hex_q = $aq AND hex_r = $ar");
            if (empty($arrivals)) {
                continue;
            }

            // Honeypot first
            $has_honeypot = false;
            foreach ($arrivals as $arr) {
                if ((int)$arr['type_id'] === INTEL_TYPE_HONEYPOT) {
                    $has_honeypot = true;
                    break;
                }
            }

            if ($has_honeypot) {
                // Per §9.4 + [D-23]: agent removed; held + ALL arrivals (including any second Honeypot) → bag.
                $returned = [];
                $held = $game->getObjectListFromDB(
                    "SELECT id FROM intel_tile WHERE state = " . INTEL_STATE_ON_AGENT . " AND agent_id = $aid");
                foreach ($held as $h) {
                    $returned[] = (int)$h['id'];
                }
                foreach ($arrivals as $arr) {
                    $returned[] = (int)$arr['id'];
                }
                foreach ($returned as $tid) {
                    $game->returnTileToBag($tid);
                }
                $game->DbQuery(
                    "UPDATE agent SET state = " . AGENT_STATE_REMOVED .
                    ", hex_q = NULL, hex_r = NULL WHERE id = $aid");

                $honeypot_removals[] = [
                    'agent_id' => $aid,
                    'agent_owner' => (int)$a['owner'],
                    'agent_type' => (int)$a['type_id'],
                    'hex' => ['q' => $aq, 'r' => $ar],
                    'intel_returned' => $returned,
                ];
                continue;
            }

            // No honeypot — pick up all arrivals.
            $arrival_ids = array_map(fn($r) => (int)$r['id'], $arrivals);
            $ids_csv = implode(',', $arrival_ids);
            $game->DbQuery(
                "UPDATE intel_tile SET state = " . INTEL_STATE_ON_AGENT .
                ", agent_id = $aid, hex_q = NULL, hex_r = NULL, stack_order = 0
                  WHERE id IN ($ids_csv)");

            $held_count = (int)$game->getUniqueValueFromDB(
                "SELECT COUNT(*) FROM intel_tile WHERE state = " . INTEL_STATE_ON_AGENT . " AND agent_id = $aid");
            if ($held_count > 3) {
                $rows = $game->getObjectListFromDB(
                    "SELECT id FROM intel_tile WHERE state = " . INTEL_STATE_ON_AGENT . " AND agent_id = $aid");
                $dumped = [];
                foreach ($rows as $rr) {
                    $tid = (int)$rr['id'];
                    $game->returnTileToBag($tid);
                    $dumped[] = $tid;
                }
                $over_capacity_dumps[] = [
                    'agent_id' => $aid,
                    'dumped_intel' => $dumped,
                ];
            }
        }

        // ---- Defensive depletion check (§7.4 step 5 / [D-17]) --------------
        if (!empty($honeypot_removals)) {
            $game->incStat(count($honeypot_removals), 'honeypot_strikes');
            // Increment per-player stat for agents lost
            foreach ($honeypot_removals as $hr) {
                $game->incStat(1, 'agents_lost_honeypot', (int)$hr['agent_owner']);
            }
        }
        if ($stat_off_board > 0) {
            $game->incStat($stat_off_board, 'trickle_off_board_returns');
        }

        // [BE-44 fix] STATE_MODEL §2.3: stack_order encodes deterministic UI rendering of
        // multi-tile stacks ("trickle resolution sets it on stack entry"). After the apply
        // loop, recompute stack_order per hex for all on-board tiles so multi-tile stacks
        // render deterministically.
        $hex_groups = $game->getObjectListFromDB(
            "SELECT id, hex_q, hex_r FROM intel_tile WHERE state = " . INTEL_STATE_ON_BOARD .
            " ORDER BY hex_q, hex_r, id");
        $per_hex_idx = [];
        foreach ($hex_groups as $row) {
            $key = ((int)$row['hex_q']) . ',' . ((int)$row['hex_r']);
            $idx = $per_hex_idx[$key] ?? 0;
            $tid = (int)$row['id'];
            $game->DbQuery("UPDATE intel_tile SET stack_order = $idx WHERE id = $tid");
            $per_hex_idx[$key] = $idx + 1;
        }

        // [BE-28 fix] STATE_MODEL §6.1: assert the pickup invariant BEFORE emitting the
        // trickleResolved notification so an abort doesn't leave the FE with a stale
        // notification flushed against rolled-back DB state.
        $game->assertPickupInvariant();

        // ---- Step F: emit batched notification -----------------------------
        $game->bga->notify->all(
            'trickleResolved',
            clienttranslate('Trickle resolved: ${moves_count} tiles moved, ${removals_count} agent(s) removed, ${dumps_count} dump(s).'),
            [
                'moves' => $moves_emit,
                'honeypot_removals' => $honeypot_removals,
                'over_capacity_dumps' => $over_capacity_dumps,
                'new_bag_size' => $game->getBagSize(),
                'moves_count' => count($moves_emit),
                'removals_count' => count($honeypot_removals),
                'dumps_count' => count($over_capacity_dumps),
            ]
        );

        // Depletion check
        if ($game->checkDepletion() !== null) {
            return GameEnd::class;
        }
        return Spawn::class;
    }
}
