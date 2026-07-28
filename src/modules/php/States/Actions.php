<?php
/**
 * Hexpionage — Actions state
 * per specs/STATE_MACHINE.md §2.7, §11.4 + rulebook §5.3
 *
 * Discriminator (F-09 / F-18 fix): a per-turn flag `actions_phase_initialized`
 * (an integer holding the current turn_id once initialized). This prevents the
 * "actions_remaining == 0" misuse on self-loop entries.
 *
 * modern BGA: constructor-based GameState replaces legacy property form.
 * Action methods remain on Game.php for this refactor pass.
 */

declare(strict_types=1);

namespace Bga\Games\Hexpionage\States;

use Bga\GameFramework\States\GameState;
use Bga\GameFramework\StateType;
use Bga\Games\Hexpionage\Game;

class Actions extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 30,
            type: StateType::ACTIVE_PLAYER,
            name: 'actions',
            description: clienttranslate('${actplayer} must take actions'),
            descriptionMyTurn: clienttranslate('${you} have ${actions_remaining} action(s) left'),
        );
    }

    public function onEnteringState(): ?string
    {
        $game = $this->game;
        $game->bga->globals->set('phase', PHASE_ACTIONS);

        $turn = (int)$game->bga->globals->get('turn_id');
        $initialized = (int)$game->bga->globals->get('actions_phase_initialized');

        if ($initialized !== $turn) {
            // First entry of the actions phase this turn — set actions_remaining = 3
            // per rulebook §6.2 and STATE_MACHINE §11.4.
            $game->bga->globals->set('actions_remaining', 3);
            $game->bga->globals->set('actions_phase_initialized', $turn);
            // [BE-65 fix] STATE_MACHINE §5.2 step 1 + §11.4: call undoSavepoint() on first
            // entry of the actions phase so the player can undo back to the start of the phase.
            if (method_exists($game, 'undoSavepoint')) {
                $game->undoSavepoint();
            }
        }
        return null;
    }

    public function getArgs(): array
    {
        $game = $this->game;
        $active = $game->activePlayerId();
        $actions_remaining = (int)$game->bga->globals->get('actions_remaining');
        $boost_used = (bool)$game->bga->globals->get('smuggler_boost_used_this_turn');
        $turn = (int)$game->bga->globals->get('turn_id');

        // [BE-39 fix] Per STATE_MACHINE §7.2 build per-agent legal-action affordances.
        // Each entry has shape `{name: 'actX', ...per-action fields}` and is included
        // iff at least one legal invocation exists. This is the canonical schema; the
        // FE uses these to drive button-bar enablement and target highlighting.
        $legal = $this->buildLegalActions($game, $active, $actions_remaining, $boost_used, $turn);

        return [
            'actions_remaining' => $actions_remaining,
            'smuggler_boost_used_this_turn' => $boost_used,
            'active_player_id' => $active,
            'can_pass' => true,
            'legal_actions' => $legal,
        ];
    }

    /**
     * [BE-39 fix] Compute STATE_MACHINE §7.2 legal_actions schema.
     */
    private function buildLegalActions($game, int $active, int $actions_remaining, bool $boost_used, int $turn): array
    {
        $legal = [];

        // Active player's on-board agents.
        $own_agents = $game->getObjectListFromDB(
            "SELECT id, type_id, hex_q, hex_r, pinned_until_turn, spawned_on_turn,
                    hacker_pin_used_this_turn, hacker_steal_used_this_turn
               FROM agent
              WHERE owner = $active AND state = " . AGENT_STATE_ON_BOARD);
        $enemy_agents = $game->getObjectListFromDB(
            "SELECT id, type_id, hex_q, hex_r, pinned_until_turn
               FROM agent
              WHERE owner != $active AND state = " . AGENT_STATE_ON_BOARD);

        // Held intel by friendly agents (id → type_id; agent_id → [tile_ids]).
        $held_rows = $game->getObjectListFromDB(
            "SELECT i.id, i.type_id, i.agent_id
               FROM intel_tile i
               JOIN agent a ON a.id = i.agent_id
              WHERE i.state = " . INTEL_STATE_ON_AGENT . "
                AND a.owner = $active");
        $intel_by_agent = [];
        foreach ($held_rows as $r) {
            $aid = (int)$r['agent_id'];
            $intel_by_agent[$aid] = $intel_by_agent[$aid] ?? [];
            // Honeypot intel never appears here per INVARIANT-HONEYPOT-HELD; defensive filter.
            if ((int)$r['type_id'] !== INTEL_TYPE_HONEYPOT) {
                $intel_by_agent[$aid][] = (int)$r['id'];
            }
        }

        // Held intel on enemy agents (for steal targeting).
        $enemy_held_rows = $game->getObjectListFromDB(
            "SELECT i.id, i.type_id, i.agent_id
               FROM intel_tile i
               JOIN agent a ON a.id = i.agent_id
              WHERE i.state = " . INTEL_STATE_ON_AGENT . "
                AND a.owner != $active");
        $intel_by_enemy = [];
        foreach ($enemy_held_rows as $r) {
            $aid = (int)$r['agent_id'];
            $intel_by_enemy[$aid] = $intel_by_enemy[$aid] ?? [];
            if ((int)$r['type_id'] !== INTEL_TYPE_HONEYPOT) {
                $intel_by_enemy[$aid][] = (int)$r['id'];
            }
        }

        // ---- actMoveAgent ----
        if ($actions_remaining >= 1) {
            $move_entries = [];
            foreach ($own_agents as $a) {
                if ($a['pinned_until_turn'] !== null) continue;
                $aq = (int)$a['hex_q']; $ar = (int)$a['hex_r'];
                $targets = [];
                foreach (hexpionage_hex_neighbors($aq, $ar) as $n) {
                    $nq = $n['q']; $nr = $n['r'];
                    if (!$game->isFieldHex($nq, $nr)) continue;
                    if ($game->getAgentAtHex($nq, $nr) !== null) continue;
                    if ($game->getBlockadeAtHex($nq, $nr) !== null) continue;
                    $targets[] = ['q' => $nq, 'r' => $nr];
                }
                if (!empty($targets)) {
                    $move_entries[] = [
                        'agent_id' => (int)$a['id'],
                        'legal_targets' => $targets,
                    ];
                }
            }
            if (!empty($move_entries)) {
                $legal[] = ['name' => 'actMoveAgent', 'agents' => $move_entries];
            }
        }

        // ---- actTransferIntel: friendly adjacent pairs with intel on source ----
        if ($actions_remaining >= 1) {
            $transfers = [];
            foreach ($own_agents as $src) {
                $sid = (int)$src['id'];
                $src_intel = $intel_by_agent[$sid] ?? [];
                if (empty($src_intel)) continue;
                $sq = (int)$src['hex_q']; $sr = (int)$src['hex_r'];
                foreach ($own_agents as $tgt) {
                    $tid = (int)$tgt['id'];
                    if ($tid === $sid) continue;
                    if (!$game->isAdjacent($sq, $sr, (int)$tgt['hex_q'], (int)$tgt['hex_r'])) continue;
                    $transfers[] = [
                        'source_agent_id' => $sid,
                        'target_agent_id' => $tid,
                        'transferable_intel_ids' => $src_intel,
                    ];
                }
            }
            if (!empty($transfers)) {
                $legal[] = ['name' => 'actTransferIntel', 'transfers' => $transfers];
            }
        }

        // ---- actRetireAgent: free; on ✦ hex; not pinned; not spawned this turn ----
        $retire_entries = [];
        foreach ($own_agents as $a) {
            if ($a['pinned_until_turn'] !== null) continue;
            if ($a['spawned_on_turn'] !== null && (int)$a['spawned_on_turn'] === $turn) continue;
            $aq = (int)$a['hex_q']; $ar = (int)$a['hex_r'];
            if (!$game->isSpawnRowHex($aq, $ar)) continue;
            $aid = (int)$a['id'];
            $is_analyst = ((int)$a['type_id']) === AGENT_TYPE_ANALYST;
            $held = $intel_by_agent[$aid] ?? [];
            $expected = 0;
            foreach ($held as $hid) {
                $row = $game->getIntel($hid);
                $expected += (int)$row['score_value'];
            }
            $retire_entries[] = [
                'agent_id' => $aid,
                'is_analyst_with_3_intel' => $is_analyst && count($held) === 3,
                'expected_score_delta' => $expected,
            ];
        }
        if (!empty($retire_entries)) {
            $legal[] = ['name' => 'actRetireAgent', 'agents' => $retire_entries];
        }

        // ---- Engineers: blockade cap check ----
        $blockade_count = (int)$game->getUniqueValueFromDB(
            "SELECT COUNT(*) FROM blockade WHERE owner = $active AND state = " . BLOCKADE_STATE_ON_BOARD);
        $cap_ok = $blockade_count < 3;
        if ($cap_ok && $actions_remaining >= 1) {
            $eng_adj = [];
            foreach ($own_agents as $a) {
                if ((int)$a['type_id'] !== AGENT_TYPE_ENGINEER) continue;
                $aq = (int)$a['hex_q']; $ar = (int)$a['hex_r'];
                $targets = [];
                foreach (hexpionage_hex_neighbors($aq, $ar) as $n) {
                    $nq = $n['q']; $nr = $n['r'];
                    if (!$game->isFieldHex($nq, $nr)) continue;
                    if ($game->getAgentAtHex($nq, $nr) !== null) continue;
                    if ($game->getBlockadeAtHex($nq, $nr) !== null) continue;
                    $targets[] = ['q' => $nq, 'r' => $nr];
                }
                if (!empty($targets)) {
                    $eng_adj[] = ['agent_id' => (int)$a['id'], 'legal_target_hexes' => $targets];
                }
            }
            if (!empty($eng_adj)) {
                $legal[] = ['name' => 'actEngineerPlaceBlockadeAdjacent', 'engineers' => $eng_adj];
            }
        }

        // ---- actEngineerPlaceBlockadeAnywhere: free, costs intel ----
        if ($cap_ok) {
            $eng_any = [];
            foreach ($own_agents as $a) {
                if ((int)$a['type_id'] !== AGENT_TYPE_ENGINEER) continue;
                $aid = (int)$a['id'];
                $intel_paid = $intel_by_agent[$aid] ?? [];
                if (empty($intel_paid)) continue;
                $hexes = [];
                foreach (hexpionage_field_hex_list() as $hex) {
                    $q = $hex['q']; $r = $hex['r'];
                    if ($game->getAgentAtHex($q, $r) !== null) continue;
                    if ($game->getBlockadeAtHex($q, $r) !== null) continue;
                    $hexes[] = $hex;
                }
                if (!empty($hexes)) {
                    $eng_any[] = [
                        'agent_id' => $aid,
                        'intel_paid_options' => $intel_paid,
                        'legal_target_hexes' => $hexes,
                    ];
                }
            }
            if (!empty($eng_any)) {
                $legal[] = ['name' => 'actEngineerPlaceBlockadeAnywhere', 'engineers' => $eng_any];
            }
        }

        // ---- actSmugglerBoostActions ----
        if (!$boost_used) {
            $boost_entries = [];
            foreach ($own_agents as $a) {
                if ((int)$a['type_id'] !== AGENT_TYPE_SMUGGLER) continue;
                $aid = (int)$a['id'];
                $intel_paid = $intel_by_agent[$aid] ?? [];
                if (empty($intel_paid)) continue;
                $boost_entries[] = ['agent_id' => $aid, 'intel_paid_options' => $intel_paid];
            }
            if (!empty($boost_entries)) {
                $legal[] = ['name' => 'actSmugglerBoostActions', 'smugglers' => $boost_entries];
            }
        }

        // ---- actSmugglerSwapAgents ----
        if ($actions_remaining >= 1) {
            $unpinned_all = [];
            foreach ($own_agents as $a) {
                if ($a['pinned_until_turn'] === null) $unpinned_all[] = (int)$a['id'];
            }
            foreach ($enemy_agents as $a) {
                if ($a['pinned_until_turn'] === null) $unpinned_all[] = (int)$a['id'];
            }
            $swap_entries = [];
            foreach ($own_agents as $a) {
                if ((int)$a['type_id'] !== AGENT_TYPE_SMUGGLER) continue;
                $aid = (int)$a['id'];
                $intel_paid = $intel_by_agent[$aid] ?? [];
                if (empty($intel_paid)) continue;
                $pairs = [];
                $n = count($unpinned_all);
                for ($i = 0; $i < $n; $i++) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        $pairs[] = [$unpinned_all[$i], $unpinned_all[$j]];
                    }
                }
                if (!empty($pairs)) {
                    $swap_entries[] = [
                        'agent_id' => $aid,
                        'intel_paid_options' => $intel_paid,
                        'legal_pairs' => $pairs,
                    ];
                }
            }
            if (!empty($swap_entries)) {
                $legal[] = ['name' => 'actSmugglerSwapAgents', 'smugglers' => $swap_entries];
            }
        }

        // ---- actCommsMoveIntelUp / Down ----
        $loose_intel = $game->getObjectListFromDB(
            "SELECT id, hex_q, hex_r FROM intel_tile WHERE state = " . INTEL_STATE_ON_BOARD);
        if ($actions_remaining >= 1) {
            $up_moves = [];
            $down_moves = [];
            foreach ($own_agents as $a) {
                if ((int)$a['type_id'] !== AGENT_TYPE_COMMS_SPECIALIST) continue;
                $cid = (int)$a['id'];
                $own_intel_ids = $intel_by_agent[$cid] ?? [];
                foreach ($loose_intel as $li) {
                    $iid = (int)$li['id'];
                    $sq = (int)$li['hex_q']; $sr = (int)$li['hex_r'];
                    $neighbors = hexpionage_hex_neighbors($sq, $sr);
                    // Up = NW/NE
                    $up_targets = [];
                    foreach (['NW', 'NE'] as $dir) {
                        $nq = $neighbors[$dir]['q']; $nr = $neighbors[$dir]['r'];
                        if (!$game->isFieldHex($nq, $nr)) continue;
                        if ($game->getAgentAtHex($nq, $nr) !== null) continue;
                        if ($game->getBlockadeAtHex($nq, $nr) !== null) continue;
                        $up_targets[] = ['q' => $nq, 'r' => $nr];
                    }
                    if (!empty($up_targets)) {
                        $up_moves[] = [
                            'comms_agent_id' => $cid,
                            'intel_id' => $iid,
                            'legal_targets' => $up_targets,
                        ];
                    }
                    // Down = SW/SE
                    if (!empty($own_intel_ids)) {
                        $down_targets = [];
                        foreach (['SW', 'SE'] as $dir) {
                            $nq = $neighbors[$dir]['q']; $nr = $neighbors[$dir]['r'];
                            if (!$game->isFieldHex($nq, $nr)) continue;
                            if ($game->getAgentAtHex($nq, $nr) !== null) continue;
                            if ($game->getBlockadeAtHex($nq, $nr) !== null) continue;
                            $down_targets[] = ['q' => $nq, 'r' => $nr];
                        }
                        if (!empty($down_targets)) {
                            $down_moves[] = [
                                'comms_agent_id' => $cid,
                                'intel_paid_options' => $own_intel_ids,
                                'intel_id' => $iid,
                                'legal_targets' => $down_targets,
                            ];
                        }
                    }
                }
            }
            if (!empty($up_moves)) {
                $legal[] = ['name' => 'actCommsMoveIntelUp', 'moves' => $up_moves];
            }
            if (!empty($down_moves)) {
                $legal[] = ['name' => 'actCommsMoveIntelDown', 'moves' => $down_moves];
            }
        }

        // ---- actDoubleAgentTransfer (no adjacency) ----
        if ($actions_remaining >= 1) {
            $da_entries = [];
            foreach ($own_agents as $a) {
                if ((int)$a['type_id'] !== AGENT_TYPE_DOUBLE_AGENT) continue;
                $aid = (int)$a['id'];
                $own = $intel_by_agent[$aid] ?? [];
                if (empty($own)) continue;
                $targets = [];
                foreach ($own_agents as $oa) {
                    $oid = (int)$oa['id'];
                    if ($oid !== $aid) $targets[] = $oid;
                }
                foreach ($enemy_agents as $ea) {
                    $targets[] = (int)$ea['id'];
                }
                if (!empty($targets)) {
                    $da_entries[] = [
                        'agent_id' => $aid,
                        'transferable_intel_ids' => $own,
                        'legal_target_agents' => $targets,
                    ];
                }
            }
            if (!empty($da_entries)) {
                $legal[] = ['name' => 'actDoubleAgentTransfer', 'double_agents' => $da_entries];
            }
        }

        // ---- actHackerPin / Unpin (per-Hacker pin slot) ----
        if ($actions_remaining >= 1) {
            $pin_entries = [];
            $unpin_entries = [];
            foreach ($own_agents as $a) {
                if ((int)$a['type_id'] !== AGENT_TYPE_HACKER) continue;
                if ((int)$a['hacker_pin_used_this_turn'] === 1) continue;
                $hid = (int)$a['id'];
                $hq = (int)$a['hex_q']; $hr = (int)$a['hex_r'];
                $pin_targets = [];
                foreach ($enemy_agents as $ea) {
                    if ($ea['pinned_until_turn'] !== null) continue;
                    if (!$game->isAdjacent($hq, $hr, (int)$ea['hex_q'], (int)$ea['hex_r'])) continue;
                    $pin_targets[] = (int)$ea['id'];
                }
                if (!empty($pin_targets)) {
                    $pin_entries[] = ['agent_id' => $hid, 'legal_target_agents' => $pin_targets];
                }
                $unpin_targets = [];
                foreach ($own_agents as $oa) {
                    if ((int)$oa['id'] === $hid) continue;
                    if ($oa['pinned_until_turn'] === null) continue;
                    if (!$game->isAdjacent($hq, $hr, (int)$oa['hex_q'], (int)$oa['hex_r'])) continue;
                    $unpin_targets[] = (int)$oa['id'];
                }
                if (!empty($unpin_targets)) {
                    $unpin_entries[] = ['agent_id' => $hid, 'legal_target_agents' => $unpin_targets];
                }
            }
            if (!empty($pin_entries)) {
                $legal[] = ['name' => 'actHackerPin', 'hackers' => $pin_entries];
            }
            if (!empty($unpin_entries)) {
                $legal[] = ['name' => 'actHackerUnpin', 'hackers' => $unpin_entries];
            }
        }

        // ---- actHackerStealIntel (free; per-Hacker steal slot; adjacent pinned enemy) ----
        $steal_entries = [];
        foreach ($own_agents as $a) {
            if ((int)$a['type_id'] !== AGENT_TYPE_HACKER) continue;
            if ((int)$a['hacker_steal_used_this_turn'] === 1) continue;
            $hid = (int)$a['id'];
            $own = $intel_by_agent[$hid] ?? [];
            if (empty($own)) continue;
            $hq = (int)$a['hex_q']; $hr = (int)$a['hex_r'];
            $targets = [];
            foreach ($enemy_agents as $ea) {
                if ($ea['pinned_until_turn'] === null) continue;
                if (!$game->isAdjacent($hq, $hr, (int)$ea['hex_q'], (int)$ea['hex_r'])) continue;
                $stealable = $intel_by_enemy[(int)$ea['id']] ?? [];
                if (empty($stealable)) continue;
                $targets[] = [
                    'target_agent_id' => (int)$ea['id'],
                    'stealable_intel_ids' => $stealable,
                ];
            }
            if (!empty($targets)) {
                $steal_entries[] = [
                    'agent_id' => $hid,
                    'intel_paid_options' => $own,
                    'legal_targets' => $targets,
                ];
            }
        }
        if (!empty($steal_entries)) {
            $legal[] = ['name' => 'actHackerStealIntel', 'hackers' => $steal_entries];
        }

        return $legal;
    }

    public function zombie(): ?string
    {
        $game = $this->game;
        $game->bga->globals->set('actions_remaining', 0);
        return EndOfTurnCleanup::class;
    }
}
