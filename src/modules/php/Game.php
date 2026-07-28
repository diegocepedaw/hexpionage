<?php
/**
 * Hexpionage — main game class (modern BGA framework)
 *
 * per docs/specs/STATE_MODEL.md, docs/specs/STATE_MACHINE.md, docs/specs/CONTRACT.md,
 * docs/specs/BGA_PRIMER.md, rulebook.md, and DECISIONS.md (D-01..D-26).
 *
 * Implementation discipline:
 *   - All RNG via bga_rand($min, $max). NEVER mt_rand / RAND() / shuffle().
 *   - getAllDatas() filters bag-state intel rows server-side per STATE_MODEL §4.3.
 *   - Notifications follow CONTRACT.md exactly. analystBonusDrawn is private
 *     per [D-20]; analystBonusReturned does NOT carry tile_type.
 *   - INVARIANT-PICKUP [D-21] checked at end of every action.
 *   - Modern framework: action methods decorated with #[PossibleAction].
 *
 * Modern BGA refactor (HAL-driven):
 *   - File path: src/hexpionage.game.php → src/modules/php/Game.php.
 *   - declare(strict_types=1); + namespace Bga\Games\Hexpionage.
 *   - Class renamed: hexpionage → Game; extends \Bga\GameFramework\Table.
 *   - PossibleAction attribute moved to Bga\GameFramework\States\PossibleAction.
 *   - User-exception strings migrated to the modern translation marker. // NOI18N
 *   - Score writes routed through the BGA player-counter API. // NOI18N
 *   - setupNewGame returns the first state class reference (GameSetup::class).
 *   - upgradeTableDb stub provided per BGA versioning best practices.
 */

declare(strict_types=1);

namespace Bga\Games\Hexpionage;

// modern BGA: under the namespaced layout, autoload from BGA loads the framework
// classes; material.inc.php sits at project root and defines un-namespaced
// constants/helpers used by both Game.php and the state classes.
require_once __DIR__ . '/../../material.inc.php';

use Bga\GameFramework\Actions\CheckAction;
use Bga\GameFramework\Actions\Types\IntParam;
use Bga\GameFramework\Actions\Types\JsonParam;
use Bga\GameFramework\StateType;

// modern BGA: PossibleAction now lives under States, not Actions.
use Bga\GameFramework\States\PossibleAction;

// State class references for setupNewGame() return + #[PossibleAction] return-types.
use Bga\Games\Hexpionage\States\Actions;
use Bga\Games\Hexpionage\States\AnalystBonusDecision;
use Bga\Games\Hexpionage\States\EndOfTurnCleanup;
use Bga\Games\Hexpionage\States\GameEnd;
use Bga\Games\Hexpionage\States\GameSetup;
use Bga\Games\Hexpionage\States\Spawn;
use Bga\Games\Hexpionage\States\TrickleDrawLeft;
use Bga\Games\Hexpionage\States\TrickleDrawRight;
use Bga\Games\Hexpionage\States\TrickleResolve;
use Bga\Games\Hexpionage\States\TrickleRoll;

// Un-namespaced framework exceptions (BGA still exposes them at the root namespace).
use BgaUserException;
use BgaVisibleSystemException;

class Game extends \Bga\GameFramework\Table
{
    public function __construct()
    {
        parent::__construct();

        // Initialize globals via labels (modern framework also supports
        // $this->bga->globals; both are valid). We rely on bga->globals JSON
        // store per STATE_MODEL §2.5.
        $this->initGameStateLabels([]);
    }

    // ====================================================================
    // SETUP
    // ====================================================================

    /**
     * setupNewGame — runs once at table creation.
     * Per STATE_MODEL §8 + DECISIONS.md (D-10b, D-16, D-19).
     *
     * modern BGA: returns the first state class reference; the framework
     * routes the engine to that state on completion.
     */
    protected function setupNewGame($players, $options = [])
    {
        // ---- Player rows (BGA-managed score, color, etc.) -----------------
        $gameinfos = self::getGameinfos();
        $default_colors = $gameinfos['player_colors'];

        $sql = "INSERT INTO player (player_id, player_color, player_canal, player_name, player_avatar) VALUES ";
        $values = [];
        foreach ($players as $player_id => $player) {
            $color = array_shift($default_colors);
            $values[] = "('$player_id', '$color', '" . $player['player_canal'] . "', '" .
                addslashes($player['player_name']) . "', '" . addslashes($player['player_avatar']) . "')";
        }
        self::DbQuery($sql . implode(',', $values));

        self::reattributeColorsBasedOnPreferences($players, $gameinfos['player_colors']);
        self::reloadPlayersBasicInfos();

        // ---- Initialize player extension columns (per STATE_MODEL §2.1) ---
        // modern BGA: player_score is now BGA-managed via the playerScore counter API
        // (HAL flagged direct UPDATEs to player.player_score). The remaining columns
        // (agents_remaining/blockades_remaining) are game-specific and stay on raw SQL.
        self::DbQuery("UPDATE player SET agents_remaining = 12, blockades_remaining = 3");
        $player_ids = array_keys($players);
        $this->bga->playerScore->initDb($player_ids, initialValue: 0);

        // ---- 24 agents (12 per player; 2 of each of 6 types) [D-10b] -----
        $agent_types = [
            AGENT_TYPE_COMMS_SPECIALIST,
            AGENT_TYPE_ANALYST,
            AGENT_TYPE_SMUGGLER,
            AGENT_TYPE_ENGINEER,
            AGENT_TYPE_HACKER,
            AGENT_TYPE_DOUBLE_AGENT,
        ];
        $rows = [];
        foreach ($players as $player_id => $_p) {
            foreach ($agent_types as $type_id) {
                for ($copy = 0; $copy < 2; $copy++) {
                    $rows[] = "($player_id, $type_id, " . AGENT_STATE_IN_POOL . ", NULL, NULL, NULL, NULL, 0, 0)"; // NOI18N
                }
            }
        }
        self::DbQuery("INSERT INTO agent (owner, type_id, state, hex_q, hex_r, pinned_until_turn, spawned_on_turn, hacker_pin_used_this_turn, hacker_steal_used_this_turn) VALUES " . implode(',', $rows));

        // ---- 47 intel tiles (placeholder distribution per TODO(I-02)) -----
        $intel_rows = [];
        foreach (INTEL_TILE_COUNTS as $type_id => $count) {
            $score = INTEL_SCORE_VALUES[$type_id];
            for ($i = 0; $i < $count; $i++) {
                $intel_rows[] = "($type_id, $score, " . INTEL_STATE_IN_BAG . ", NULL, NULL, NULL, NULL, 0)"; // NOI18N
            }
        }
        self::DbQuery("INSERT INTO intel_tile (type_id, score_value, state, hex_q, hex_r, agent_id, scored_by, stack_order) VALUES " . implode(',', $intel_rows));

        // ---- Globals -----------------------------------------------------
        // Random first player per [D-16]
        $first_idx = bga_rand(1, count($player_ids)); // 1..N
        $first_player = $player_ids[$first_idx - 1];

        $this->bga->globals->set('phase', PHASE_TRICKLE_DRAW_LEFT);
        $this->bga->globals->set('turn_id', 1);
        $this->bga->globals->set('active_player_id', $first_player);
        $this->bga->globals->set('dice_state', new \stdClass());
        $this->bga->globals->set('actions_remaining', 0);
        $this->bga->globals->set('smuggler_boost_used_this_turn', false);
        $this->bga->globals->set('spawned_this_turn', 0);
        $this->bga->globals->set('actions_phase_initialized', 0);
        $this->bga->globals->set('analyst_bonus_pending_tile_id', null);
        $this->bga->globals->set('game_winner', null);

        // Set BGA's internal active player.
        $this->gamestate->changeActivePlayer($first_player);

        // ---- Stats init --------------------------------------------------
        self::initStat('table', 'turns_total', 0);
        self::initStat('table', 'trickle_off_board_returns', 0);
        self::initStat('table', 'honeypot_strikes', 0);

        foreach ($player_ids as $pid) {
            self::initStat('player', 'intel_scored',         0, $pid);
            self::initStat('player', 'agents_retired',       0, $pid);
            self::initStat('player', 'agents_lost_honeypot', 0, $pid);
            self::initStat('player', 'blockades_placed',     0, $pid);
            self::initStat('player', 'pins_applied',         0, $pid);
            self::initStat('player', 'intel_stolen',         0, $pid);
            self::initStat('player', 'smuggler_boosts',      0, $pid);
            self::initStat('player', 'actions_taken',        0, $pid);
            self::initStat('player', 'avg_actions_per_turn', 0, $pid);
        }

        // modern BGA: return the first state class reference. The framework
        // resolves this and dispatches into GameSetup::onEnteringState().
        return GameSetup::class;
    }

    /**
     * upgradeTableDb — invoked by BGA when bumping the deployed schema.
     *
     * Per BGA versioning best practices (BGA_PRIMER §2 / §10): bump
     * `game_version` in gameinfos.jsonc on each release; this method runs
     * any required ALTER TABLE / data migrations between the prior and
     * current version. As of game_version 1 the schema in dbmodel.sql is
     * canonical and no migrations are needed; this method is a no-op stub.
     *
     * @param int $from_version The deployed schema's version number.
     */
    public function upgradeTableDb($from_version): void
    {
        // No migrations required at game_version 1.
        // Future versions: switch on $from_version and run ALTER TABLE here.
    }

    // ====================================================================
    // CLIENT STATE — getAllDatas()
    // ====================================================================

    /**
     * getAllDatas — returns the canonical client payload per CONTRACT.md §1.
     * Filters bag intel rows (state IN (in_bag, returned_to_bag)) per
     * STATE_MODEL §4.3.
     */
    protected function getAllDatas(): array
    {
        $current_player_id = (int)self::getCurrentPlayerId();

        $players = self::getCollectionFromDB(
            "SELECT player_id id, player_score score, player_color color, player_name name,
                    agents_remaining, blockades_remaining
               FROM player"
        );
        // Augment with derived counts.
        foreach ($players as $pid => &$p) {
            $on_board = (int)self::getUniqueValueFromDB(
                "SELECT COUNT(*) FROM agent WHERE owner = $pid AND state = " . AGENT_STATE_ON_BOARD);
            $removed = (int)self::getUniqueValueFromDB(
                "SELECT COUNT(*) FROM agent WHERE owner = $pid AND state = " . AGENT_STATE_REMOVED);
            $p['id'] = (int)$p['id'];
            $p['score'] = (int)$p['score'];
            $p['agents_in_pool'] = (int)$p['agents_remaining'];
            $p['agents_on_board'] = $on_board;
            $p['agents_removed'] = $removed;
            $p['blockades_in_pool'] = (int)$p['blockades_remaining'];
            unset($p['agents_remaining'], $p['blockades_remaining']);
        }
        unset($p);

        // ---- Agents (24 rows, all states) -------------------------------
        $agent_rows = self::getObjectListFromDB(
            "SELECT id, owner, type_id, state, hex_q, hex_r, pinned_until_turn,
                    spawned_on_turn, hacker_pin_used_this_turn, hacker_steal_used_this_turn
               FROM agent");
        $agents = [];
        $agent_to_intel = [];
        foreach ($agent_rows as $r) {
            $agents[] = [
                'id' => (int)$r['id'],
                'owner' => (int)$r['owner'],
                'type' => (int)$r['type_id'],
                'state' => (int)$r['state'],
                'hex' => $r['hex_q'] === null ? null : ['q' => (int)$r['hex_q'], 'r' => (int)$r['hex_r']],
                'intel_held' => [],
                'pinned_until_turn' => $r['pinned_until_turn'] === null ? null : (int)$r['pinned_until_turn'],
                'spawned_on_turn' => $r['spawned_on_turn'] === null ? null : (int)$r['spawned_on_turn'],
                'hacker_pin_used_this_turn' => ((int)$r['hacker_pin_used_this_turn']) === 1,
                'hacker_steal_used_this_turn' => ((int)$r['hacker_steal_used_this_turn']) === 1,
            ];
            $agent_to_intel[(int)$r['id']] = [];
        }

        // ---- Intel rows (excluding bag/returned-to-bag) ------------------
        $intel_rows = self::getObjectListFromDB(
            "SELECT id, type_id, score_value, state, hex_q, hex_r, agent_id, scored_by, stack_order
               FROM intel_tile
              WHERE state NOT IN (" . INTEL_STATE_IN_BAG . "," . INTEL_STATE_RETURNED_TO_BAG . ")");

        $intel_on_board = [];
        $intel_revealed = [];
        $scored_intel = [];
        foreach ($intel_rows as $r) {
            $tile_id = (int)$r['id'];
            $type_id = (int)$r['type_id'];
            $score_value = (int)$r['score_value'];
            $state = (int)$r['state'];

            $intel_revealed[] = [
                'id' => $tile_id,
                'type' => $type_id,
                'score_value' => $score_value,
            ];

            if ($state === INTEL_STATE_ON_BOARD) {
                $intel_on_board[] = [
                    'id' => $tile_id,
                    'type' => $type_id,
                    'score_value' => $score_value,
                    'hex' => ['q' => (int)$r['hex_q'], 'r' => (int)$r['hex_r']],
                    'stack_order' => (int)$r['stack_order'],
                ];
            } elseif ($state === INTEL_STATE_ON_AGENT) {
                $aid = (int)$r['agent_id'];
                if (isset($agent_to_intel[$aid])) {
                    $agent_to_intel[$aid][] = $tile_id;
                }
            } elseif ($state === INTEL_STATE_SCORED) {
                $scored_intel[] = [
                    'id' => $tile_id,
                    'type' => $type_id,
                    'score_value' => $score_value,
                    'scored_by' => (int)$r['scored_by'],
                ];
            }
        }
        // Apply held intel back to agents.
        foreach ($agents as &$a) {
            $a['intel_held'] = $agent_to_intel[$a['id']] ?? [];
        }
        unset($a);

        // ---- Blockades (active only) ------------------------------------
        $blockade_rows = self::getObjectListFromDB(
            "SELECT id, owner, hex_q, hex_r, placed_on_turn FROM blockade WHERE state = " . BLOCKADE_STATE_ON_BOARD);
        $blockades = [];
        foreach ($blockade_rows as $r) {
            $blockades[] = [
                'id' => (int)$r['id'],
                'owner' => (int)$r['owner'],
                'hex' => ['q' => (int)$r['hex_q'], 'r' => (int)$r['hex_r']],
                'placed_on_turn' => (int)$r['placed_on_turn'],
            ];
        }

        $bag_size = (int)self::getUniqueValueFromDB(
            "SELECT COUNT(*) FROM intel_tile WHERE state IN (" . INTEL_STATE_IN_BAG . "," . INTEL_STATE_RETURNED_TO_BAG . ")");

        // Globals
        $dice_state = $this->bga->globals->get('dice_state');
        if ($dice_state === null) {
            $dice_state = new \stdClass();
        }

        return [
            'players' => $players,
            'agents' => $agents,
            'intel_on_board' => $intel_on_board,
            'intel_revealed' => $intel_revealed,
            'blockades' => $blockades,
            'scored_intel' => $scored_intel,
            'phase' => (string)$this->bga->globals->get('phase'),
            'turn_id' => (int)$this->bga->globals->get('turn_id'),
            'active_player' => (int)$this->bga->globals->get('active_player_id'),
            'actions_remaining' => (int)$this->bga->globals->get('actions_remaining'),
            'smuggler_boost_used_this_turn' => (bool)$this->bga->globals->get('smuggler_boost_used_this_turn'),
            'spawned_this_turn' => (int)$this->bga->globals->get('spawned_this_turn'),
            'dice_state' => $dice_state,
            'bag_size' => $bag_size,
            // [BE-33 fix] CONTRACT §1.1: game_winner is `number | null`. Coerce to int|null
            // for type consistency since other casts in this function are explicit.
            'game_winner' => (($v = $this->bga->globals->get('game_winner')) === null) ? null : (int)$v,
            // [G-02 fix / FE-12 fix] Ship the canonical board layout so the FE click
            // overlay (`hexpionage.js::_setupHexOverlay`) can render every hex without
            // hard-coding coordinates. Source: design/BOARD_LAYOUT.md + material.inc.php.
            'board_layout' => [
                'field_hexes' => hexpionage_field_hex_list(),
                'orange_hexes' => array_map(fn($h) => $h, ALL_ORANGE_HEXES),
                'spawn_row_hexes' => hexpionage_spawn_row_hexes(),
                'intel_entry_top_left' => INTEL_ENTRY_HEX_TOP_LEFT,
                'intel_entry_top_right' => INTEL_ENTRY_HEX_TOP_RIGHT,
            ],
        ];
    }

    /**
     * getGameProgression — 0..100 per BGA_PRIMER §2.
     */
    public function getGameProgression(): int
    {
        $rows = self::getCollectionFromDB("SELECT player_id, player_score FROM player", true);
        $max = 0;
        foreach ($rows as $score) {
            $max = max($max, (int)$score);
        }
        return (int)floor($max / 20 * 100);
    }

    // ====================================================================
    // HELPERS — adjacency, field membership, depletion, win
    // ====================================================================

    /** @return array{q:int,r:int}[] */
    public function hexNeighbors(int $q, int $r): array
    {
        return array_values(hexpionage_hex_neighbors($q, $r));
    }

    public function isFieldHex(int $q, int $r): bool
    {
        return hexpionage_is_field_hex($q, $r);
    }

    public function isSpawnRowHex(int $q, int $r): bool
    {
        return hexpionage_is_spawn_row_hex($q, $r);
    }

    public function isAdjacent(int $q1, int $r1, int $q2, int $r2): bool
    {
        return hexpionage_is_adjacent($q1, $r1, $q2, $r2);
    }

    /**
     * Resolve the next-turn turn_id at which the pinned agent's pin clears,
     * per [D-06a] / STATE_MODEL §6.2 (formula F-04).
     */
    public function pinClearTurnFor(int $target_owner): int
    {
        $current_turn = (int)$this->bga->globals->get('turn_id');
        $active = (int)$this->bga->globals->get('active_player_id');
        // Hacker only pins enemy agents per §6.11.A precondition; offset = 1.
        return $current_turn + ($target_owner === $active ? 2 : 1);
    }

    /** Get agent row or throw. */
    public function getAgent(int $id): array
    {
        $r = self::getNonEmptyObjectFromDB("SELECT * FROM agent WHERE id = $id");
        return $r;
    }

    public function getIntel(int $id): array
    {
        return self::getNonEmptyObjectFromDB("SELECT * FROM intel_tile WHERE id = $id");
    }

    /** Returns agent row at hex or null. */
    public function getAgentAtHex(int $q, int $r): ?array
    {
        $rows = self::getObjectListFromDB(
            "SELECT * FROM agent WHERE state = " . AGENT_STATE_ON_BOARD .
            " AND hex_q = $q AND hex_r = $r LIMIT 1");
        return empty($rows) ? null : $rows[0];
    }

    public function getBlockadeAtHex(int $q, int $r): ?array
    {
        $rows = self::getObjectListFromDB(
            "SELECT * FROM blockade WHERE state = " . BLOCKADE_STATE_ON_BOARD .
            " AND hex_q = $q AND hex_r = $r LIMIT 1");
        return empty($rows) ? null : $rows[0];
    }

    /** @return int[] tile ids loose at hex. */
    public function getLooseIntelAtHex(int $q, int $r): array
    {
        $rows = self::getObjectListFromDB(
            "SELECT id FROM intel_tile WHERE state = " . INTEL_STATE_ON_BOARD .
            " AND hex_q = $q AND hex_r = $r ORDER BY stack_order, id");
        return array_map(fn($r) => (int)$r['id'], $rows);
    }

    public function getBagSize(): int
    {
        return (int)self::getUniqueValueFromDB(
            "SELECT COUNT(*) FROM intel_tile WHERE state IN (" .
            INTEL_STATE_IN_BAG . "," . INTEL_STATE_RETURNED_TO_BAG . ")");
    }

    /** Pick a random in-bag tile id; returns null if empty. */
    public function drawFromBag(): ?int
    {
        $bag_ids = self::getObjectListFromDB(
            "SELECT id FROM intel_tile WHERE state IN (" .
            INTEL_STATE_IN_BAG . "," . INTEL_STATE_RETURNED_TO_BAG . ") ORDER BY id");
        $n = count($bag_ids);
        if ($n === 0) {
            return null;
        }
        $idx = bga_rand(1, $n);
        return (int)$bag_ids[$idx - 1]['id'];
    }

    /** Return tile to bag (state=returned_to_bag). */
    public function returnTileToBag(int $tile_id): void
    {
        self::DbQuery(
            "UPDATE intel_tile
                SET state = " . INTEL_STATE_RETURNED_TO_BAG . ",
                    hex_q = NULL, hex_r = NULL,
                    agent_id = NULL, scored_by = NULL, stack_order = 0
              WHERE id = $tile_id");
    }

    /**
     * Universal pickup invariant [D-21] — assert no on-board loose intel
     * shares a hex with an on-board agent.
     */
    public function assertPickupInvariant(): void
    {
        $bad = (int)self::getUniqueValueFromDB(
            "SELECT COUNT(*) FROM intel_tile i
               JOIN agent a ON a.hex_q = i.hex_q AND a.hex_r = i.hex_r
              WHERE i.state = " . INTEL_STATE_ON_BOARD . "
                AND a.state = " . AGENT_STATE_ON_BOARD);
        if ($bad > 0) {
            throw new BgaVisibleSystemException(
                "INVARIANT-PICKUP violation [D-21]: loose intel co-occupies an agent hex.");
        }
    }

    /**
     * Win check after any score mutation.
     */
    public function checkWinByScore(int $player_id): bool
    {
        $score = (int)self::getUniqueValueFromDB(
            "SELECT player_score FROM player WHERE player_id = $player_id");
        if ($score >= 20) {
            $this->bga->globals->set('game_winner', $player_id);
            return true;
        }
        return false;
    }

    /**
     * Depletion check per [D-17]. Returns winner player_id if a player has
     * been depleted, else null.
     */
    public function checkDepletion(): ?int
    {
        $players = self::getObjectListFromDB(
            "SELECT player_id FROM player");
        // Iterate active player first per STATE_MACHINE §12.3 simultaneous rule.
        $active = (int)$this->bga->globals->get('active_player_id');
        usort($players, function ($a, $b) use ($active) {
            $aa = (int)$a['player_id'];
            $bb = (int)$b['player_id'];
            if ($aa === $active) return -1;
            if ($bb === $active) return 1;
            return 0;
        });
        foreach ($players as $row) {
            $pid = (int)$row['player_id'];
            $pool = (int)self::getUniqueValueFromDB(
                "SELECT COUNT(*) FROM agent WHERE owner = $pid AND state = " . AGENT_STATE_IN_POOL);
            $on_board = (int)self::getUniqueValueFromDB(
                "SELECT COUNT(*) FROM agent WHERE owner = $pid AND state = " . AGENT_STATE_ON_BOARD);
            if ($pool === 0 && $on_board === 0) {
                $opponent = $this->getOpponent($pid);
                $this->bga->globals->set('game_winner', $opponent);
                return $opponent;
            }
        }
        return null;
    }

    public function getOpponent(int $pid): int
    {
        $rows = self::getObjectListFromDB("SELECT player_id FROM player WHERE player_id <> $pid");
        return (int)$rows[0]['player_id'];
    }

    /** Pickup all loose intel at agent's hex (handles Honeypot). Returns array describing what happened. */
    public function applyPickupAt(array $agent): array
    {
        $aid = (int)$agent['id'];
        $q = (int)$agent['hex_q'];
        $r = (int)$agent['hex_r'];
        $picked = [];
        $honeypot_tile_id = null;

        $loose = self::getObjectListFromDB(
            "SELECT id, type_id FROM intel_tile WHERE state = " . INTEL_STATE_ON_BOARD .
            " AND hex_q = $q AND hex_r = $r");
        foreach ($loose as $tile) {
            $tile_id = (int)$tile['id'];
            $type_id = (int)$tile['type_id'];
            if ($type_id === INTEL_TYPE_HONEYPOT) {
                $honeypot_tile_id = $tile_id;
            } else {
                $picked[] = $tile_id;
            }
        }

        if ($honeypot_tile_id !== null) {
            // §9.4 fires: agent removed; everything (held + Honeypot + other arrivals) → bag.
            $held = self::getObjectListFromDB(
                "SELECT id FROM intel_tile WHERE state = " . INTEL_STATE_ON_AGENT . " AND agent_id = $aid");
            $returned = [$honeypot_tile_id];
            foreach ($held as $h) {
                $returned[] = (int)$h['id'];
            }
            // Other non-Honeypot loose tiles on same hex also return per §9.4 step 3 / [D-23].
            foreach ($picked as $tid) {
                $returned[] = $tid;
            }
            foreach ($returned as $tid) {
                $this->returnTileToBag($tid);
            }
            self::DbQuery(
                "UPDATE agent SET state = " . AGENT_STATE_REMOVED .
                ", hex_q = NULL, hex_r = NULL WHERE id = $aid");
            // [BE-08 fix] Per CONTRACT §3.1 step 1: Honeypot move's agentMoved.picked_up_intel
            // must include the Honeypot tile id (and any other loose-tile arrivals at that hex)
            // so the FE can animate pickup before removal.
            $picked_up_with_honeypot = array_merge([$honeypot_tile_id], $picked);
            return [
                'picked_up' => $picked_up_with_honeypot,
                'honeypot_removal' => [
                    'agent_id' => $aid,
                    'returned' => $returned,
                ],
            ];
        }

        // Non-honeypot pickup: move loose tiles to agent.
        if (!empty($picked)) {
            $ids = implode(',', $picked);
            self::DbQuery(
                "UPDATE intel_tile SET state = " . INTEL_STATE_ON_AGENT .
                ", agent_id = $aid, hex_q = NULL, hex_r = NULL, stack_order = 0
                  WHERE id IN ($ids)");
        }

        // Over-capacity check §9.3
        $held_count = (int)self::getUniqueValueFromDB(
            "SELECT COUNT(*) FROM intel_tile WHERE state = " . INTEL_STATE_ON_AGENT . " AND agent_id = $aid");
        $dumped = [];
        if ($held_count > 3) {
            $rows = self::getObjectListFromDB(
                "SELECT id FROM intel_tile WHERE state = " . INTEL_STATE_ON_AGENT . " AND agent_id = $aid");
            foreach ($rows as $rrow) {
                $tid = (int)$rrow['id'];
                $this->returnTileToBag($tid);
                $dumped[] = $tid;
            }
        }

        return [
            'picked_up' => $picked,
            'over_capacity_dump' => $dumped,
        ];
    }

    // ====================================================================
    // SHARED ACTION HELPERS
    // ====================================================================

    public function ensurePhaseIsActions(): void
    {
        $phase = (string)$this->bga->globals->get('phase');
        if ($phase !== PHASE_ACTIONS) {
            // modern BGA: clienttranslate vs legacy self::_()
            throw new BgaUserException(clienttranslate("Action only legal in actions phase"));
        }
        // [BE-65 fix] STATE_MACHINE §5.2 step 2: per-action undo savepoint at the
        // start of each undoable handler in the actions phase (single-step undo).
        $this->maybeUndoSavepoint();
    }

    public function ensurePhaseIsSpawn(): void
    {
        $phase = (string)$this->bga->globals->get('phase');
        if ($phase !== PHASE_SPAWN) {
            throw new BgaUserException(clienttranslate("Action only legal in spawn phase"));
        }
        // [BE-65 fix] STATE_MACHINE §5.2 step 2: per-action savepoint within the spawn phase.
        $this->maybeUndoSavepoint();
    }

    /**
     * [BE-65 fix] Defensive wrapper around undoSavepoint() in case the framework
     * version under test does not expose it directly.
     */
    public function maybeUndoSavepoint(): void
    {
        if (method_exists($this, 'undoSavepoint')) {
            try {
                $this->undoSavepoint();
            } catch (\Throwable $e) {
                // Silent — undo is a UI affordance, not a correctness invariant.
            }
        }
    }

    public function decrementActions(): int
    {
        $cur = (int)$this->bga->globals->get('actions_remaining');
        if ($cur <= 0) {
            throw new BgaUserException(clienttranslate("No actions remaining"));
        }
        $cur -= 1;
        $this->bga->globals->set('actions_remaining', $cur);
        return $cur;
    }

    /** Verify intel id is held by agent and return type. Throws on violation. */
    public function ensureIntelHeldBy(int $intel_id, int $agent_id): array
    {
        $row = self::getNonEmptyObjectFromDB(
            "SELECT id, type_id FROM intel_tile
              WHERE id = $intel_id AND state = " . INTEL_STATE_ON_AGENT . " AND agent_id = $agent_id");
        return $row;
    }

    /**
     * Spend one held intel by returning it to the bag. Returns its type_id.
     */
    public function spendIntel(int $intel_id, int $agent_id): int
    {
        $row = $this->ensureIntelHeldBy($intel_id, $agent_id);
        $this->returnTileToBag($intel_id);
        return (int)$row['type_id'];
    }

    public function activePlayerId(): int
    {
        return (int)$this->bga->globals->get('active_player_id');
    }

    public function logTypeName(int $type_id): string
    {
        return INTEL_TYPES[$type_id] ?? 'unknown';
    }

    // ====================================================================
    // ACTION HANDLERS — Spawn phase
    // ====================================================================

    #[PossibleAction]
    public function actSpawnAgent(int $agent_id, int $q, int $r): ?string
    {
        $this->ensurePhaseIsSpawn();
        $active = $this->activePlayerId();

        $a = $this->getAgent($agent_id);
        if ((int)$a['owner'] !== $active) {
            throw new BgaUserException(clienttranslate("Agent not owned by active player"));
        }
        if ((int)$a['state'] !== AGENT_STATE_IN_POOL) {
            throw new BgaUserException(clienttranslate("Agent not in pool"));
        }
        if (!$this->isSpawnRowHex($q, $r)) {
            throw new BgaUserException(clienttranslate("Target hex is not a spawn-row hex"));
        }
        if ($this->getAgentAtHex($q, $r) !== null) {
            throw new BgaUserException(clienttranslate("Hex occupied by an agent"));
        }
        if ($this->getBlockadeAtHex($q, $r) !== null) {
            throw new BgaUserException(clienttranslate("Hex blockaded"));
        }
        $loose = $this->getLooseIntelAtHex($q, $r);
        if (!empty($loose)) {
            throw new BgaUserException(clienttranslate("Spawn hex has loose intel"));
        }

        // Spawn cap §6.1
        $on_board = (int)self::getUniqueValueFromDB(
            "SELECT COUNT(*) FROM agent WHERE owner = $active AND state = " . AGENT_STATE_ON_BOARD);
        if ($on_board >= 3) {
            throw new BgaUserException(clienttranslate("Spawn cap reached (3 on board)"));
        }

        $turn = (int)$this->bga->globals->get('turn_id');
        self::DbQuery(
            "UPDATE agent SET state = " . AGENT_STATE_ON_BOARD .
            ", hex_q = $q, hex_r = $r, spawned_on_turn = $turn,
              pinned_until_turn = NULL, hacker_pin_used_this_turn = 0,
              hacker_steal_used_this_turn = 0
              WHERE id = $agent_id");
        self::DbQuery("UPDATE player SET agents_remaining = agents_remaining - 1 WHERE player_id = $active");
        $this->bga->globals->inc('spawned_this_turn', 1);

        $pool = (int)self::getUniqueValueFromDB(
            "SELECT agents_remaining FROM player WHERE player_id = $active");
        $on_board_now = $on_board + 1;

        $this->bga->notify->all('agentSpawned',
            clienttranslate('${player_name} spawns a ${type_name} on (${q}, ${r}).'),
            [
                'agent_id' => $agent_id,
                'type' => (int)$a['type_id'],
                'type_name' => AGENT_TYPES[(int)$a['type_id']],
                'owner' => $active,
                'player_id' => $active,
                'player_name' => self::getPlayerNameById($active),
                'hex' => ['q' => $q, 'r' => $r],
                'q' => $q,
                'r' => $r,
                'spawned_on_turn' => $turn,
                'agents_in_pool' => $pool,
                'agents_on_board' => $on_board_now,
            ]);

        $this->assertPickupInvariant();
        // Self-loop within Spawn state.
        return Spawn::class;
    }

    #[PossibleAction]
    public function actPassSpawn(): ?string
    {
        $this->ensurePhaseIsSpawn();
        return Actions::class;
    }

    // ====================================================================
    // ACTION HANDLERS — Actions phase
    // ====================================================================

    #[PossibleAction]
    public function actMoveAgent(int $agent_id, int $q, int $r): ?string
    {
        $this->ensurePhaseIsActions();
        $active = $this->activePlayerId();

        $a = $this->getAgent($agent_id);
        if ((int)$a['owner'] !== $active) {
            throw new BgaUserException(clienttranslate("Agent not owned by active player"));
        }
        if ((int)$a['state'] !== AGENT_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Agent not on board"));
        }
        if ($a['pinned_until_turn'] !== null) {
            throw new BgaUserException(clienttranslate("Pinned agents cannot move"));
        }
        if (!$this->isFieldHex($q, $r)) {
            throw new BgaUserException(clienttranslate("Target hex is outside the Field"));
        }
        if (!$this->isAdjacent((int)$a['hex_q'], (int)$a['hex_r'], $q, $r)) {
            throw new BgaUserException(clienttranslate("Target hex is not adjacent"));
        }
        if ($this->getAgentAtHex($q, $r) !== null) {
            throw new BgaUserException(clienttranslate("Target hex occupied"));
        }
        if ($this->getBlockadeAtHex($q, $r) !== null) {
            throw new BgaUserException(clienttranslate("Target hex blockaded"));
        }
        if ((int)$this->bga->globals->get('actions_remaining') < 1) {
            throw new BgaUserException(clienttranslate("No actions remaining"));
        }

        $from_hex = ['q' => (int)$a['hex_q'], 'r' => (int)$a['hex_r']];
        self::DbQuery("UPDATE agent SET hex_q = $q, hex_r = $r WHERE id = $agent_id");

        $a['hex_q'] = $q;
        $a['hex_r'] = $r;
        $pickup = $this->applyPickupAt($a);
        $remaining = $this->decrementActions();

        $this->bga->notify->all('agentMoved',
            clienttranslate('${player_name} moves agent to (${to_q}, ${to_r}).'),
            [
                'agent_id' => $agent_id,
                'from_hex' => $from_hex,
                'to_hex' => ['q' => $q, 'r' => $r],
                'to_q' => $q,
                'to_r' => $r,
                'picked_up_intel' => $pickup['picked_up'] ?? [],
                'actions_remaining' => $remaining,
                'player_id' => $active,
                'player_name' => self::getPlayerNameById($active),
            ]);

        if (isset($pickup['honeypot_removal'])) {
            self::incStat(1, 'agents_lost_honeypot', $active);
            self::incStat(1, 'honeypot_strikes');
            $this->bga->notify->all('agentRemovedHoneypot',
                clienttranslate('${player_name}\'s ${type_name} hits a Honeypot and is removed.'),
                [
                    'agent_id' => $agent_id,
                    'agent_owner' => $active,
                    'agent_type' => (int)$a['type_id'],
                    'type_name' => AGENT_TYPES[(int)$a['type_id']],
                    'hex' => ['q' => $q, 'r' => $r],
                    'intel_returned' => $pickup['honeypot_removal']['returned'],
                    'trigger' => 'move',
                    'new_bag_size' => $this->getBagSize(),
                    'player_id' => $active,
                    'player_name' => self::getPlayerNameById($active),
                ]);
            // Depletion check
            if ($this->checkDepletion() !== null) {
                return GameEnd::class;
            }
        } elseif (!empty($pickup['over_capacity_dump'])) {
            $this->bga->notify->all('agentDumpedOvercapacity',
                clienttranslate('Agent #${agent_id} exceeds capacity — ${count} intel dumped to bag.'),
                [
                    'agent_id' => $agent_id,
                    'agent_owner' => $active,
                    'dumped_intel' => $pickup['over_capacity_dump'],
                    'count' => count($pickup['over_capacity_dump']),
                    'trigger' => 'move',
                    'new_bag_size' => $this->getBagSize(),
                ]);
        }

        $this->assertPickupInvariant();
        self::incStat(1, 'actions_taken', $active);
        return Actions::class;
    }

    #[PossibleAction]
    public function actTransferIntel(int $source_agent_id, int $target_agent_id, int $intel_id): ?string
    {
        $this->ensurePhaseIsActions();
        $active = $this->activePlayerId();

        if ($source_agent_id === $target_agent_id) {
            throw new BgaUserException(clienttranslate("Source and target must differ"));
        }
        $src = $this->getAgent($source_agent_id);
        $tgt = $this->getAgent($target_agent_id);
        if ((int)$src['owner'] !== $active || (int)$tgt['owner'] !== $active) {
            throw new BgaUserException(clienttranslate("Both agents must be owned by you"));
        }
        if ((int)$src['state'] !== AGENT_STATE_ON_BOARD || (int)$tgt['state'] !== AGENT_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Both agents must be on board"));
        }
        if (!$this->isAdjacent((int)$src['hex_q'], (int)$src['hex_r'],
                               (int)$tgt['hex_q'], (int)$tgt['hex_r'])) {
            throw new BgaUserException(clienttranslate("Agents must be adjacent"));
        }
        $intel = $this->ensureIntelHeldBy($intel_id, $source_agent_id);
        if ((int)$this->bga->globals->get('actions_remaining') < 1) {
            throw new BgaUserException(clienttranslate("No actions remaining"));
        }

        self::DbQuery(
            "UPDATE intel_tile SET agent_id = $target_agent_id WHERE id = $intel_id");

        // Honeypot held invariant should hold; defensive: assert
        if ((int)$intel['type_id'] === INTEL_TYPE_HONEYPOT) {
            throw new BgaVisibleSystemException("INVARIANT-HONEYPOT-HELD violated");
        }

        // Over-capacity check
        $held = (int)self::getUniqueValueFromDB(
            "SELECT COUNT(*) FROM intel_tile WHERE state = " . INTEL_STATE_ON_AGENT . " AND agent_id = $target_agent_id");
        $dumped = [];
        if ($held > 3) {
            $rows = self::getObjectListFromDB(
                "SELECT id FROM intel_tile WHERE state = " . INTEL_STATE_ON_AGENT . " AND agent_id = $target_agent_id");
            foreach ($rows as $rr) {
                $tid = (int)$rr['id'];
                $this->returnTileToBag($tid);
                $dumped[] = $tid;
            }
        }

        $remaining = $this->decrementActions();

        $this->bga->notify->all('intelTransferred',
            clienttranslate('${player_name} transfers ${type_name} to agent #${to_agent_id}.'),
            [
                'intel_id' => $intel_id,
                'type_name' => INTEL_TYPES[(int)$intel['type_id']],
                'from_agent_id' => $source_agent_id,
                'to_agent_id' => $target_agent_id,
                'via' => 'transfer',
                'actions_remaining' => $remaining,
                'player_id' => $active,
                'player_name' => self::getPlayerNameById($active),
            ]);

        if (!empty($dumped)) {
            $this->bga->notify->all('agentDumpedOvercapacity',
                clienttranslate('Agent #${agent_id} exceeds capacity — ${count} intel dumped to bag.'),
                [
                    'agent_id' => $target_agent_id,
                    'agent_owner' => (int)$tgt['owner'],
                    'dumped_intel' => $dumped,
                    'count' => count($dumped),
                    'trigger' => 'transfer',
                    'new_bag_size' => $this->getBagSize(),
                ]);
        }

        $this->assertPickupInvariant();
        self::incStat(1, 'actions_taken', $active);
        return Actions::class;
    }

    #[PossibleAction]
    public function actRetireAgent(int $agent_id): ?string
    {
        $this->ensurePhaseIsActions();
        $active = $this->activePlayerId();
        $turn = (int)$this->bga->globals->get('turn_id');

        $a = $this->getAgent($agent_id);
        if ((int)$a['owner'] !== $active) {
            throw new BgaUserException(clienttranslate("Agent not yours"));
        }
        if ((int)$a['state'] !== AGENT_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Agent not on board"));
        }
        $q = (int)$a['hex_q'];
        $r = (int)$a['hex_r'];
        if (!$this->isSpawnRowHex($q, $r)) {
            throw new BgaUserException(clienttranslate("Retire requires spawn-row hex"));
        }
        if ($a['pinned_until_turn'] !== null) {
            throw new BgaUserException(clienttranslate("Pinned agents cannot retire"));
        }
        if ($a['spawned_on_turn'] !== null && (int)$a['spawned_on_turn'] === $turn) {
            throw new BgaUserException(clienttranslate("Cannot retire on the same turn as spawn"));
        }

        // Score all held intel per [D-14]
        $held = self::getObjectListFromDB(
            "SELECT id, type_id, score_value FROM intel_tile
              WHERE state = " . INTEL_STATE_ON_AGENT . " AND agent_id = $agent_id");
        $score_delta = 0;
        $scored = [];
        foreach ($held as $h) {
            $tid = (int)$h['id'];
            $type_id = (int)$h['type_id'];
            if ($type_id === INTEL_TYPE_HONEYPOT) {
                throw new BgaVisibleSystemException("INVARIANT-HONEYPOT-HELD violated at retire");
            }
            $score_delta += (int)$h['score_value'];
            $scored[] = [
                'id' => $tid,
                'type' => $type_id,
                'score_value' => (int)$h['score_value'],
            ];
            self::DbQuery(
                "UPDATE intel_tile SET state = " . INTEL_STATE_SCORED .
                ", agent_id = NULL, scored_by = $active WHERE id = $tid");
        }

        $is_analyst = ((int)$a['type_id']) === AGENT_TYPE_ANALYST;
        $bonus_eligible = $is_analyst && count($held) === 3;

        // Remove agent (does NOT return to pool per [D-10b])
        self::DbQuery(
            "UPDATE agent SET state = " . AGENT_STATE_REMOVED .
            ", hex_q = NULL, hex_r = NULL WHERE id = $agent_id");
        // Score update — modern BGA: route through playerScore counter API
        // (HAL flagged direct UPDATE player SET player_score = ... at this site).
        if ($score_delta > 0) {
            $this->bga->playerScore->inc($active, $score_delta);
        }

        $new_score = (int)self::getUniqueValueFromDB(
            "SELECT player_score FROM player WHERE player_id = $active");

        self::incStat(1, 'agents_retired', $active);
        self::incStat(count($scored), 'intel_scored', $active);

        $this->bga->notify->all('agentRetired',
            clienttranslate('${player_name} retires ${type_name} for ${score_delta} points (total: ${new_score}).'),
            [
                'agent_id' => $agent_id,
                'agent_type' => (int)$a['type_id'],
                'type_name' => AGENT_TYPES[(int)$a['type_id']],
                'agent_owner' => $active,
                'hex' => ['q' => $q, 'r' => $r],
                'scored_intel' => $scored,
                'score_delta' => $score_delta,
                'new_score' => $new_score,
                'analyst_bonus_pending' => $bonus_eligible,
                'player_id' => $active,
                'player_name' => self::getPlayerNameById($active),
            ]);
        if ($score_delta > 0) {
            $this->bga->notify->all('scoreUpdated',
                clienttranslate('${player_name}: ${new_score} points (+${delta}).'),
                [
                    'player_id' => $active,
                    'player_name' => self::getPlayerNameById($active),
                    'new_score' => $new_score,
                    'delta' => $score_delta,
                ]);
        }

        // [BE-01 + BE-17 fix] rulebook §6.5 step ordering: bonus (step 2) → … → win (step 7) → depletion (step 8).
        // [D-26 step 7] win + depletion checks belong in the bonus state's resolver for the bonus path.
        // For the bonus path: transition FIRST; AnalystBonusDecision::actAnalystKeep/Return performs win+depletion.
        // For the non-bonus path: run win + depletion here (final score is settled).
        if ($bonus_eligible) {
            $this->assertPickupInvariant();
            // Transition to AnalystBonusDecision; that state's onEnteringState
            // performs the draw or the [D-18] empty-bag skip.
            return AnalystBonusDecision::class;
        }

        // Non-bonus path: rulebook §6.5 step 7 (win) then step 8 (depletion).
        if ($this->checkWinByScore($active)) {
            return GameEnd::class;
        }
        if ($this->checkDepletion() !== null) {
            return GameEnd::class;
        }

        $this->assertPickupInvariant();
        self::incStat(1, 'actions_taken', $active);
        return Actions::class;
    }

    #[PossibleAction]
    public function actEngineerPlaceBlockadeAdjacent(int $engineer_id, int $q, int $r): ?string
    {
        $this->ensurePhaseIsActions();
        $active = $this->activePlayerId();
        $eng = $this->getAgent($engineer_id);
        if ((int)$eng['owner'] !== $active || (int)$eng['type_id'] !== AGENT_TYPE_ENGINEER ||
            (int)$eng['state'] !== AGENT_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Engineer not eligible"));
        }
        if (!$this->isFieldHex($q, $r) ||
            !$this->isAdjacent((int)$eng['hex_q'], (int)$eng['hex_r'], $q, $r)) {
            throw new BgaUserException(clienttranslate("Target hex must be in Field and adjacent"));
        }
        if ($this->getAgentAtHex($q, $r) !== null) {
            throw new BgaUserException(clienttranslate("Target hex has agent"));
        }
        if ($this->getBlockadeAtHex($q, $r) !== null) {
            throw new BgaUserException(clienttranslate("Target hex has blockade"));
        }
        $active_blockades = (int)self::getUniqueValueFromDB(
            "SELECT COUNT(*) FROM blockade WHERE owner = $active AND state = " . BLOCKADE_STATE_ON_BOARD);
        if ($active_blockades >= 3) {
            throw new BgaUserException(clienttranslate("Blockade cap reached"));
        }
        if ((int)$this->bga->globals->get('actions_remaining') < 1) {
            throw new BgaUserException(clienttranslate("No actions remaining"));
        }

        $turn = (int)$this->bga->globals->get('turn_id');
        self::DbQuery(
            "INSERT INTO blockade (owner, hex_q, hex_r, placed_on_turn, state)
              VALUES ($active, $q, $r, $turn, " . BLOCKADE_STATE_ON_BOARD . ")");
        $blockade_id = (int)self::DbGetLastId();
        self::DbQuery("UPDATE player SET blockades_remaining = blockades_remaining - 1 WHERE player_id = $active");
        $remaining = $this->decrementActions();

        self::incStat(1, 'blockades_placed', $active);

        $this->bga->notify->all('blockadePlaced',
            clienttranslate('${player_name} places a blockade on (${q}, ${r}).'),
            [
                'blockade_id' => $blockade_id,
                'owner' => $active,
                'hex' => ['q' => $q, 'r' => $r],
                'q' => $q,
                'r' => $r,
                'placed_on_turn' => $turn,
                'via' => 'engineer_adjacent',
                'intel_spent' => null,
                'blockades_in_pool' => 3 - ($active_blockades + 1),
                'actions_remaining' => $remaining,
                'player_id' => $active,
                'player_name' => self::getPlayerNameById($active),
            ]);

        $this->assertPickupInvariant();
        self::incStat(1, 'actions_taken', $active);
        return Actions::class;
    }

    #[PossibleAction]
    public function actEngineerPlaceBlockadeAnywhere(int $engineer_id, int $intel_id, int $q, int $r): ?string
    {
        $this->ensurePhaseIsActions();
        $active = $this->activePlayerId();
        $eng = $this->getAgent($engineer_id);
        if ((int)$eng['owner'] !== $active || (int)$eng['type_id'] !== AGENT_TYPE_ENGINEER ||
            (int)$eng['state'] !== AGENT_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Engineer not eligible"));
        }
        if (!$this->isFieldHex($q, $r)) {
            throw new BgaUserException(clienttranslate("Target hex outside Field"));
        }
        if ($this->getAgentAtHex($q, $r) !== null) {
            throw new BgaUserException(clienttranslate("Target hex has agent"));
        }
        if ($this->getBlockadeAtHex($q, $r) !== null) {
            throw new BgaUserException(clienttranslate("Target hex has blockade"));
        }
        $active_blockades = (int)self::getUniqueValueFromDB(
            "SELECT COUNT(*) FROM blockade WHERE owner = $active AND state = " . BLOCKADE_STATE_ON_BOARD);
        if ($active_blockades >= 3) {
            throw new BgaUserException(clienttranslate("Blockade cap reached"));
        }
        $intel_row = $this->ensureIntelHeldBy($intel_id, $engineer_id);
        $intel_type = (int)$intel_row['type_id'];
        $this->returnTileToBag($intel_id);

        $turn = (int)$this->bga->globals->get('turn_id');
        self::DbQuery(
            "INSERT INTO blockade (owner, hex_q, hex_r, placed_on_turn, state)
              VALUES ($active, $q, $r, $turn, " . BLOCKADE_STATE_ON_BOARD . ")");
        $blockade_id = (int)self::DbGetLastId();
        self::DbQuery("UPDATE player SET blockades_remaining = blockades_remaining - 1 WHERE player_id = $active");

        self::incStat(1, 'blockades_placed', $active);

        $this->bga->notify->all('blockadePlaced',
            clienttranslate('${player_name} places a blockade on (${q}, ${r}).'),
            [
                'blockade_id' => $blockade_id,
                'owner' => $active,
                'hex' => ['q' => $q, 'r' => $r],
                'q' => $q,
                'r' => $r,
                'placed_on_turn' => $turn,
                'via' => 'engineer_anywhere',
                'intel_spent' => ['id' => $intel_id, 'type' => $intel_type],
                'blockades_in_pool' => 3 - ($active_blockades + 1),
                'actions_remaining' => (int)$this->bga->globals->get('actions_remaining'),
                'player_id' => $active,
                'player_name' => self::getPlayerNameById($active),
            ]);

        $this->assertPickupInvariant();
        return Actions::class;
    }

    #[PossibleAction]
    public function actSmugglerBoostActions(int $smuggler_id, int $intel_id): ?string
    {
        $this->ensurePhaseIsActions();
        $active = $this->activePlayerId();
        $sm = $this->getAgent($smuggler_id);
        if ((int)$sm['owner'] !== $active || (int)$sm['type_id'] !== AGENT_TYPE_SMUGGLER ||
            (int)$sm['state'] !== AGENT_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Smuggler not eligible"));
        }
        if ((bool)$this->bga->globals->get('smuggler_boost_used_this_turn')) {
            throw new BgaUserException(clienttranslate("Smuggler boost already used this turn"));
        }
        $intel_row = $this->ensureIntelHeldBy($intel_id, $smuggler_id);
        $intel_type = (int)$intel_row['type_id'];
        $this->returnTileToBag($intel_id);

        $this->bga->globals->set('smuggler_boost_used_this_turn', true);
        $new_actions = (int)$this->bga->globals->get('actions_remaining') + 1;
        // [BE-07 fix] QA F-02 / STATE_MODEL §6.1 INVARIANT-ACTIONS-CAP: actions_remaining
        // must be 0..4 (4 only when boost active per [D-08]). Defensive assertion.
        if ($new_actions < 0 || $new_actions > 4) {
            throw new BgaVisibleSystemException(
                "INVARIANT-ACTIONS-CAP violated post-boost: $new_actions");
        }
        $this->bga->globals->set('actions_remaining', $new_actions);

        self::incStat(1, 'smuggler_boosts', $active);

        $this->bga->notify->all('actionsBoosted',
            clienttranslate('${player_name}\'s Smuggler boosts: action cap raised to 4.'),
            [
                'smuggler_id' => $smuggler_id,
                'smuggler_owner' => $active,
                'intel_spent' => ['id' => $intel_id, 'type' => $intel_type],
                'new_actions_remaining' => $new_actions,
                'smuggler_boost_used_this_turn' => true,
                'new_bag_size' => $this->getBagSize(),
                'player_id' => $active,
                'player_name' => self::getPlayerNameById($active),
            ]);

        $this->assertPickupInvariant();
        return Actions::class;
    }

    #[PossibleAction]
    public function actSmugglerSwapAgents(int $smuggler_id, int $intel_id, int $agent_a_id, int $agent_b_id): ?string
    {
        $this->ensurePhaseIsActions();
        $active = $this->activePlayerId();
        $sm = $this->getAgent($smuggler_id);
        // [BE-02 fix] rulebook §6.8 precondition: Smuggler must be on board.
        if ((int)$sm['owner'] !== $active || (int)$sm['type_id'] !== AGENT_TYPE_SMUGGLER ||
            (int)$sm['state'] !== AGENT_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Smuggler not eligible"));
        }
        if ($agent_a_id === $agent_b_id) {
            throw new BgaUserException(clienttranslate("Cannot swap agent with itself"));
        }
        $intel_row = $this->ensureIntelHeldBy($intel_id, $smuggler_id);
        $intel_type = (int)$intel_row['type_id'];

        $a = $this->getAgent($agent_a_id);
        $b = $this->getAgent($agent_b_id);
        if ((int)$a['state'] !== AGENT_STATE_ON_BOARD || (int)$b['state'] !== AGENT_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Both agents must be on board"));
        }
        if ($a['pinned_until_turn'] !== null || $b['pinned_until_turn'] !== null) {
            throw new BgaUserException(clienttranslate("Pinned agents cannot be swapped"));
        }
        if ((int)$this->bga->globals->get('actions_remaining') < 1) {
            throw new BgaUserException(clienttranslate("No actions remaining"));
        }

        $this->returnTileToBag($intel_id);
        $aq = (int)$a['hex_q']; $ar = (int)$a['hex_r'];
        $bq = (int)$b['hex_q']; $br = (int)$b['hex_r'];

        // Use a temporary off-board state to swap atomically without colliding
        // on the unique-position invariant. We move A → null, B → A's hex,
        // then A → B's hex.
        self::DbQuery("UPDATE agent SET hex_q = NULL, hex_r = NULL WHERE id = $agent_a_id");
        self::DbQuery("UPDATE agent SET hex_q = $aq, hex_r = $ar WHERE id = $agent_b_id");
        self::DbQuery("UPDATE agent SET hex_q = $bq, hex_r = $br WHERE id = $agent_a_id");

        $remaining = $this->decrementActions();

        $this->bga->notify->all('agentSwapped',
            clienttranslate('${player_name}\'s Smuggler swaps agents #${agent_a_id} and #${agent_b_id}.'),
            [
                'smuggler_id' => $smuggler_id,
                'agent_a_id' => $agent_a_id,
                'agent_a_old_hex' => ['q' => $aq, 'r' => $ar],
                'agent_a_new_hex' => ['q' => $bq, 'r' => $br],
                'agent_b_id' => $agent_b_id,
                'agent_b_old_hex' => ['q' => $bq, 'r' => $br],
                'agent_b_new_hex' => ['q' => $aq, 'r' => $ar],
                'intel_spent' => ['id' => $intel_id, 'type' => $intel_type],
                'new_bag_size' => $this->getBagSize(),
                'actions_remaining' => $remaining,
                'player_id' => $active,
                'player_name' => self::getPlayerNameById($active),
            ]);

        // [BE-15 fix] rulebook §6.8 effect 4 + [D-21] universal pickup invariant:
        // if either agent's new hex contains loose intel post-swap, pickup fires;
        // if Honeypot, §9.4 fires. Per [D-21] this state is structurally impossible
        // at rest, but we apply pickup defensively rather than aborting via the
        // invariant assertion (which would leave the swap half-applied).
        $a_after = $this->getAgent($agent_a_id);
        if ((int)$a_after['state'] === AGENT_STATE_ON_BOARD) {
            $this->applyPickupAt($a_after);
        }
        $b_after = $this->getAgent($agent_b_id);
        if ((int)$b_after['state'] === AGENT_STATE_ON_BOARD) {
            $this->applyPickupAt($b_after);
        }
        $this->assertPickupInvariant();
        self::incStat(1, 'actions_taken', $active);
        return Actions::class;
    }

    #[PossibleAction]
    public function actCommsMoveIntelUp(int $comms_id, int $intel_id, int $q, int $r): ?string
    {
        $this->ensurePhaseIsActions();
        $active = $this->activePlayerId();
        $comms = $this->getAgent($comms_id);
        if ((int)$comms['owner'] !== $active || (int)$comms['type_id'] !== AGENT_TYPE_COMMS_SPECIALIST ||
            (int)$comms['state'] !== AGENT_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Comms Specialist not eligible"));
        }
        if ((int)$this->bga->globals->get('actions_remaining') < 1) {
            throw new BgaUserException(clienttranslate("No actions remaining"));
        }
        $intel = $this->getIntel($intel_id);
        if ((int)$intel['state'] !== INTEL_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Intel not loose on board"));
        }
        $sq = (int)$intel['hex_q'];
        $sr = (int)$intel['hex_r'];
        // [BE-13/14 fix] use canonical hexpionage_hex_neighbors() per STATE_MODEL §3.3
        // instead of hardcoding axial offsets, so a future coordinate-scheme change
        // (TODO G-01) only needs to update the helper.
        $neighbors = hexpionage_hex_neighbors($sq, $sr);
        $direction = null;
        foreach (['NW', 'NE'] as $key) {
            if ($neighbors[$key]['q'] === $q && $neighbors[$key]['r'] === $r) {
                $direction = $key;
                break;
            }
        }
        if ($direction === null) {
            throw new BgaUserException(clienttranslate("Comms-up target must be NW or NE neighbor"));
        }
        if (!$this->isFieldHex($q, $r)) {
            throw new BgaUserException(clienttranslate("Target hex outside Field [D-25]"));
        }
        if ($this->getBlockadeAtHex($q, $r) !== null) {
            throw new BgaUserException(clienttranslate("Target hex blockaded"));
        }
        if ($this->getAgentAtHex($q, $r) !== null) {
            throw new BgaUserException(clienttranslate("Target hex occupied [D-09]"));
        }
        // Blockade-pair vertical check §9.6.D: if both NW and NE blockaded → illegal.
        $nw_n = $neighbors['NW'];
        $ne_n = $neighbors['NE'];
        $nw = $this->getBlockadeAtHex($nw_n['q'], $nw_n['r']);
        $ne = $this->getBlockadeAtHex($ne_n['q'], $ne_n['r']);
        if ($nw !== null && $ne !== null) {
            throw new BgaUserException(clienttranslate("Blockade pair blocks vertical move"));
        }

        self::DbQuery(
            "UPDATE intel_tile SET hex_q = $q, hex_r = $r WHERE id = $intel_id");
        $remaining = $this->decrementActions();
        $this->bga->notify->all('intelMoved',
            clienttranslate('${player_name}\'s Comms moves ${type_name} ${direction}.'),
            [
                'intel_id' => $intel_id,
                'intel_type' => (int)$intel['type_id'],
                'type_name' => INTEL_TYPES[(int)$intel['type_id']],
                'comms_id' => $comms_id,
                'from_hex' => ['q' => $sq, 'r' => $sr],
                'to_hex' => ['q' => $q, 'r' => $r],
                'direction' => $direction,
                'intel_spent' => null,
                'new_bag_size' => $this->getBagSize(),
                'actions_remaining' => $remaining,
                'player_id' => $active,
                'player_name' => self::getPlayerNameById($active),
            ]);

        $this->assertPickupInvariant();
        self::incStat(1, 'actions_taken', $active);
        return Actions::class;
    }

    #[PossibleAction]
    public function actCommsMoveIntelDown(int $comms_id, int $paid_intel_id, int $target_intel_id, int $q, int $r): ?string
    {
        $this->ensurePhaseIsActions();
        $active = $this->activePlayerId();
        $comms = $this->getAgent($comms_id);
        if ((int)$comms['owner'] !== $active || (int)$comms['type_id'] !== AGENT_TYPE_COMMS_SPECIALIST ||
            (int)$comms['state'] !== AGENT_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Comms Specialist not eligible"));
        }
        if ($paid_intel_id === $target_intel_id) {
            throw new BgaUserException(clienttranslate("Cannot pay with the intel being moved"));
        }
        if ((int)$this->bga->globals->get('actions_remaining') < 1) {
            throw new BgaUserException(clienttranslate("No actions remaining"));
        }
        $intel = $this->getIntel($target_intel_id);
        if ((int)$intel['state'] !== INTEL_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Target intel not loose on board"));
        }
        $sq = (int)$intel['hex_q'];
        $sr = (int)$intel['hex_r'];
        // [BE-13/14 fix] use canonical hexpionage_hex_neighbors() per STATE_MODEL §3.3.
        $neighbors = hexpionage_hex_neighbors($sq, $sr);
        $direction = null;
        foreach (['SE', 'SW'] as $key) {
            if ($neighbors[$key]['q'] === $q && $neighbors[$key]['r'] === $r) {
                $direction = $key;
                break;
            }
        }
        if ($direction === null) {
            throw new BgaUserException(clienttranslate("Comms-down target must be SW or SE neighbor"));
        }
        if (!$this->isFieldHex($q, $r)) {
            throw new BgaUserException(clienttranslate("Target hex outside Field [C-02 default]"));
        }
        if ($this->getBlockadeAtHex($q, $r) !== null) {
            throw new BgaUserException(clienttranslate("Target hex blockaded"));
        }
        if ($this->getAgentAtHex($q, $r) !== null) {
            throw new BgaUserException(clienttranslate("Target hex occupied [D-09]"));
        }
        // Blockade-pair §9.6.D: if both SW and SE blockaded → illegal.
        $sw_n = $neighbors['SW'];
        $se_n = $neighbors['SE'];
        $sw = $this->getBlockadeAtHex($sw_n['q'], $sw_n['r']);
        $se = $this->getBlockadeAtHex($se_n['q'], $se_n['r']);
        if ($sw !== null && $se !== null) {
            throw new BgaUserException(clienttranslate("Blockade pair blocks vertical move"));
        }

        $paid = $this->ensureIntelHeldBy($paid_intel_id, $comms_id);
        $paid_type = (int)$paid['type_id'];
        $this->returnTileToBag($paid_intel_id);

        self::DbQuery(
            "UPDATE intel_tile SET hex_q = $q, hex_r = $r WHERE id = $target_intel_id");
        $remaining = $this->decrementActions();
        $this->bga->notify->all('intelMoved',
            clienttranslate('${player_name}\'s Comms moves ${type_name} ${direction}.'),
            [
                'intel_id' => $target_intel_id,
                'intel_type' => (int)$intel['type_id'],
                'type_name' => INTEL_TYPES[(int)$intel['type_id']],
                'comms_id' => $comms_id,
                'from_hex' => ['q' => $sq, 'r' => $sr],
                'to_hex' => ['q' => $q, 'r' => $r],
                'direction' => $direction,
                'intel_spent' => ['id' => $paid_intel_id, 'type' => $paid_type],
                'new_bag_size' => $this->getBagSize(),
                'actions_remaining' => $remaining,
                'player_id' => $active,
                'player_name' => self::getPlayerNameById($active),
            ]);

        $this->assertPickupInvariant();
        self::incStat(1, 'actions_taken', $active);
        return Actions::class;
    }

    #[PossibleAction]
    public function actDoubleAgentTransfer(int $double_agent_id, int $target_agent_id, int $intel_id): ?string
    {
        $this->ensurePhaseIsActions();
        $active = $this->activePlayerId();
        if ($double_agent_id === $target_agent_id) {
            throw new BgaUserException(clienttranslate("Source and target must differ"));
        }
        $da = $this->getAgent($double_agent_id);
        $tgt = $this->getAgent($target_agent_id);
        if ((int)$da['owner'] !== $active || (int)$da['type_id'] !== AGENT_TYPE_DOUBLE_AGENT ||
            (int)$da['state'] !== AGENT_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Double Agent not eligible"));
        }
        if ((int)$tgt['state'] !== AGENT_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Target agent not on board"));
        }
        $intel = $this->ensureIntelHeldBy($intel_id, $double_agent_id);
        if ((int)$intel['type_id'] === INTEL_TYPE_HONEYPOT) {
            throw new BgaVisibleSystemException("INVARIANT-HONEYPOT-HELD violated");
        }
        if ((int)$this->bga->globals->get('actions_remaining') < 1) {
            throw new BgaUserException(clienttranslate("No actions remaining"));
        }

        self::DbQuery("UPDATE intel_tile SET agent_id = $target_agent_id WHERE id = $intel_id");

        // Over-capacity
        $held = (int)self::getUniqueValueFromDB(
            "SELECT COUNT(*) FROM intel_tile WHERE state = " . INTEL_STATE_ON_AGENT . " AND agent_id = $target_agent_id");
        $dumped = [];
        if ($held > 3) {
            $rows = self::getObjectListFromDB(
                "SELECT id FROM intel_tile WHERE state = " . INTEL_STATE_ON_AGENT . " AND agent_id = $target_agent_id");
            foreach ($rows as $rr) {
                $tid = (int)$rr['id'];
                $this->returnTileToBag($tid);
                $dumped[] = $tid;
            }
        }

        $remaining = $this->decrementActions();

        $this->bga->notify->all('intelTransferred',
            clienttranslate('${player_name} transfers ${type_name} to agent #${to_agent_id}.'),
            [
                'intel_id' => $intel_id,
                'type_name' => INTEL_TYPES[(int)$intel['type_id']],
                'from_agent_id' => $double_agent_id,
                'to_agent_id' => $target_agent_id,
                'via' => 'double_agent',
                'actions_remaining' => $remaining,
                'player_id' => $active,
                'player_name' => self::getPlayerNameById($active),
            ]);

        if (!empty($dumped)) {
            $this->bga->notify->all('agentDumpedOvercapacity',
                clienttranslate('Agent #${agent_id} exceeds capacity — ${count} intel dumped to bag.'),
                [
                    'agent_id' => $target_agent_id,
                    'agent_owner' => (int)$tgt['owner'],
                    'dumped_intel' => $dumped,
                    'count' => count($dumped),
                    'trigger' => 'double_agent_transfer',
                    'new_bag_size' => $this->getBagSize(),
                ]);
        }

        $this->assertPickupInvariant();
        self::incStat(1, 'actions_taken', $active);
        return Actions::class;
    }

    #[PossibleAction]
    public function actHackerPin(int $hacker_id, int $target_agent_id): ?string
    {
        $this->ensurePhaseIsActions();
        $active = $this->activePlayerId();
        $h = $this->getAgent($hacker_id);
        if ((int)$h['owner'] !== $active || (int)$h['type_id'] !== AGENT_TYPE_HACKER ||
            (int)$h['state'] !== AGENT_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Hacker not eligible"));
        }
        if ((int)$h['hacker_pin_used_this_turn'] === 1) {
            throw new BgaUserException(clienttranslate("Hacker has already used pin/unpin this turn"));
        }
        $tgt = $this->getAgent($target_agent_id);
        if ((int)$tgt['state'] !== AGENT_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Target not on board"));
        }
        if ((int)$tgt['owner'] === $active) {
            throw new BgaUserException(clienttranslate("Cannot pin your own agent"));
        }
        // [BE-52 fix] Defensive coercion: DB driver may return strings even for INT columns.
        // Compare against int 0 after explicit cast to handle "0"/null/int-0 uniformly.
        if (((int)($tgt['pinned_until_turn'] ?? 0)) !== 0) {
            throw new BgaUserException(clienttranslate("Already pinned [D-06b]"));
        }
        if (!$this->isAdjacent((int)$h['hex_q'], (int)$h['hex_r'],
                               (int)$tgt['hex_q'], (int)$tgt['hex_r'])) {
            throw new BgaUserException(clienttranslate("Hacker must be adjacent to target"));
        }
        if ((int)$this->bga->globals->get('actions_remaining') < 1) {
            throw new BgaUserException(clienttranslate("No actions remaining"));
        }

        $clear_turn = $this->pinClearTurnFor((int)$tgt['owner']);
        self::DbQuery("UPDATE agent SET pinned_until_turn = $clear_turn WHERE id = $target_agent_id");
        self::DbQuery("UPDATE agent SET hacker_pin_used_this_turn = 1 WHERE id = $hacker_id");
        $remaining = $this->decrementActions();

        self::incStat(1, 'pins_applied', $active);

        $this->bga->notify->all('agentPinned',
            clienttranslate('${player_name}\'s Hacker pins agent #${target_agent_id} until turn ${pinned_until_turn}.'),
            [
                'hacker_id' => $hacker_id,
                'target_agent_id' => $target_agent_id,
                'target_owner' => (int)$tgt['owner'],
                'pinned_until_turn' => $clear_turn,
                'actions_remaining' => $remaining,
                'player_id' => $active,
                'player_name' => self::getPlayerNameById($active),
            ]);

        $this->assertPickupInvariant();
        self::incStat(1, 'actions_taken', $active);
        return Actions::class;
    }

    #[PossibleAction]
    public function actHackerUnpin(int $hacker_id, int $target_agent_id): ?string
    {
        $this->ensurePhaseIsActions();
        $active = $this->activePlayerId();
        $h = $this->getAgent($hacker_id);
        if ((int)$h['owner'] !== $active || (int)$h['type_id'] !== AGENT_TYPE_HACKER ||
            (int)$h['state'] !== AGENT_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Hacker not eligible"));
        }
        if ((int)$h['hacker_pin_used_this_turn'] === 1) {
            throw new BgaUserException(clienttranslate("Hacker has already used pin/unpin this turn"));
        }
        $tgt = $this->getAgent($target_agent_id);
        if ((int)$tgt['owner'] !== $active || (int)$tgt['state'] !== AGENT_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Target must be your own on-board agent"));
        }
        if ($tgt['pinned_until_turn'] === null) {
            throw new BgaUserException(clienttranslate("Target is not pinned"));
        }
        if (!$this->isAdjacent((int)$h['hex_q'], (int)$h['hex_r'],
                               (int)$tgt['hex_q'], (int)$tgt['hex_r'])) {
            throw new BgaUserException(clienttranslate("Hacker must be adjacent to target"));
        }
        if ((int)$this->bga->globals->get('actions_remaining') < 1) {
            throw new BgaUserException(clienttranslate("No actions remaining"));
        }

        self::DbQuery("UPDATE agent SET pinned_until_turn = NULL WHERE id = $target_agent_id");
        self::DbQuery("UPDATE agent SET hacker_pin_used_this_turn = 1 WHERE id = $hacker_id");
        $remaining = $this->decrementActions();

        $this->bga->notify->all('agentUnpinned',
            clienttranslate('${player_name}\'s Hacker unpins agent #${target_agent_id}.'),
            [
                'hacker_id' => $hacker_id,
                'target_agent_id' => $target_agent_id,
                'target_owner' => (int)$tgt['owner'],
                'actions_remaining' => $remaining,
                'player_id' => $active,
                'player_name' => self::getPlayerNameById($active),
            ]);

        $this->assertPickupInvariant();
        self::incStat(1, 'actions_taken', $active);
        return Actions::class;
    }

    #[PossibleAction]
    public function actHackerStealIntel(int $hacker_id, int $paid_intel_id, int $target_agent_id, int $stolen_intel_id): ?string
    {
        $this->ensurePhaseIsActions();
        $active = $this->activePlayerId();
        $h = $this->getAgent($hacker_id);
        if ((int)$h['owner'] !== $active || (int)$h['type_id'] !== AGENT_TYPE_HACKER ||
            (int)$h['state'] !== AGENT_STATE_ON_BOARD) {
            throw new BgaUserException(clienttranslate("Hacker not eligible"));
        }
        if ((int)$h['hacker_steal_used_this_turn'] === 1) {
            throw new BgaUserException(clienttranslate("Hacker has already stolen this turn"));
        }
        $tgt = $this->getAgent($target_agent_id);
        if ((int)$tgt['state'] !== AGENT_STATE_ON_BOARD || (int)$tgt['owner'] === $active) {
            throw new BgaUserException(clienttranslate("Target must be enemy on-board"));
        }
        if ($tgt['pinned_until_turn'] === null) {
            throw new BgaUserException(clienttranslate("Target must be pinned"));
        }
        // [BE-03 fix] rulebook §6.11.C / STATE_MODEL §9.9: Hacker must be adjacent to target for steal.
        if (!$this->isAdjacent((int)$h['hex_q'], (int)$h['hex_r'],
                               (int)$tgt['hex_q'], (int)$tgt['hex_r'])) {
            throw new BgaUserException(clienttranslate("Hacker must be adjacent to target"));
        }
        $paid = $this->ensureIntelHeldBy($paid_intel_id, $hacker_id);
        $paid_type = (int)$paid['type_id'];
        $stolen = $this->ensureIntelHeldBy($stolen_intel_id, $target_agent_id);
        $stolen_type = (int)$stolen['type_id'];
        if ($stolen_type === INTEL_TYPE_HONEYPOT) {
            throw new BgaVisibleSystemException("INVARIANT-HONEYPOT-HELD violated");
        }

        $this->returnTileToBag($paid_intel_id);
        self::DbQuery("UPDATE intel_tile SET agent_id = $hacker_id WHERE id = $stolen_intel_id");

        // Over-capacity check on hacker
        $held = (int)self::getUniqueValueFromDB(
            "SELECT COUNT(*) FROM intel_tile WHERE state = " . INTEL_STATE_ON_AGENT . " AND agent_id = $hacker_id");
        $dumped = [];
        if ($held > 3) {
            $rows = self::getObjectListFromDB(
                "SELECT id FROM intel_tile WHERE state = " . INTEL_STATE_ON_AGENT . " AND agent_id = $hacker_id");
            foreach ($rows as $rr) {
                $tid = (int)$rr['id'];
                $this->returnTileToBag($tid);
                $dumped[] = $tid;
            }
        }

        self::DbQuery("UPDATE agent SET hacker_steal_used_this_turn = 1 WHERE id = $hacker_id");

        self::incStat(1, 'intel_stolen', $active);

        $this->bga->notify->all('intelStolen',
            clienttranslate('${player_name}\'s Hacker steals ${type_name} from agent #${target_agent_id}.'),
            [
                'hacker_id' => $hacker_id,
                'target_agent_id' => $target_agent_id,
                'target_owner' => (int)$tgt['owner'],
                'stolen_intel' => [
                    'id' => $stolen_intel_id,
                    'type' => $stolen_type,
                    'score_value' => INTEL_SCORE_VALUES[$stolen_type],
                ],
                'type_name' => INTEL_TYPES[$stolen_type],
                'intel_spent' => ['id' => $paid_intel_id, 'type' => $paid_type],
                'new_bag_size' => $this->getBagSize(),
                // [BE-10 fix] CONTRACT §2.16/§2.23 mandates the actions_remaining echo for UI consistency
                // even though steal is free per [D-15] (value is unchanged).
                'actions_remaining' => (int)$this->bga->globals->get('actions_remaining'),
                'player_id' => $active,
                'player_name' => self::getPlayerNameById($active),
            ]);

        if (!empty($dumped)) {
            $this->bga->notify->all('agentDumpedOvercapacity',
                clienttranslate('Agent #${agent_id} exceeds capacity — ${count} intel dumped to bag.'),
                [
                    'agent_id' => $hacker_id,
                    'agent_owner' => $active,
                    'dumped_intel' => $dumped,
                    'count' => count($dumped),
                    'trigger' => 'steal',
                    'new_bag_size' => $this->getBagSize(),
                ]);
        }

        $this->assertPickupInvariant();
        return Actions::class;
    }

    #[PossibleAction]
    public function actAnalystKeep(): ?string
    {
        $phase = (string)$this->bga->globals->get('phase');
        if ($phase !== 'analyst_bonus') {
            throw new BgaUserException(clienttranslate("Action only legal in Analyst bonus state"));
        }
        $active = $this->activePlayerId();
        $tile_id = $this->bga->globals->get('analyst_bonus_pending_tile_id');
        if ($tile_id === null) {
            throw new BgaVisibleSystemException(clienttranslate("No pending Analyst bonus tile"));
        }
        $tile_id = (int)$tile_id;
        $tile = $this->getIntel($tile_id);
        $type_id = (int)$tile['type_id'];
        $score_value = (int)$tile['score_value'];

        self::DbQuery(
            "UPDATE intel_tile SET state = " . INTEL_STATE_SCORED .
            ", scored_by = $active WHERE id = $tile_id");
        // modern BGA: route player_score increment through the playerScore counter API
        // (HAL flagged direct UPDATE player SET player_score = ... at this site).
        if ($score_value > 0) {
            $this->bga->playerScore->inc($active, $score_value);
        }

        $new_score = (int)self::getUniqueValueFromDB(
            "SELECT player_score FROM player WHERE player_id = $active");
        self::incStat(1, 'intel_scored', $active);

        $this->bga->notify->all('analystBonusKept',
            clienttranslate('${player_name} keeps the Analyst bonus (${type_name}, +${score_delta}).'),
            [
                'player_id' => $active,
                'player_name' => self::getPlayerNameById($active),
                'tile_id' => $tile_id,
                'tile_type' => $type_id,
                'type_name' => INTEL_TYPES[$type_id],
                'score_value' => $score_value,
                'score_delta' => $score_value,
                'new_score' => $new_score,
                'new_bag_size' => $this->getBagSize(),
            ]);
        if ($score_value > 0) {
            $this->bga->notify->all('scoreUpdated',
                clienttranslate('${player_name}: ${new_score} points (+${delta}).'),
                [
                    'player_id' => $active,
                    'player_name' => self::getPlayerNameById($active),
                    'new_score' => $new_score,
                    'delta' => $score_value,
                ]);
        }

        $this->bga->globals->set('analyst_bonus_pending_tile_id', null);

        if ($this->checkWinByScore($active)) {
            return GameEnd::class;
        }
        if ($this->checkDepletion() !== null) {
            return GameEnd::class;
        }
        $this->assertPickupInvariant();
        return Actions::class;
    }

    #[PossibleAction]
    public function actAnalystReturn(): ?string
    {
        $phase = (string)$this->bga->globals->get('phase');
        if ($phase !== 'analyst_bonus') {
            throw new BgaUserException(clienttranslate("Action only legal in Analyst bonus state"));
        }
        $active = $this->activePlayerId();
        $tile_id = $this->bga->globals->get('analyst_bonus_pending_tile_id');
        if ($tile_id === null) {
            throw new BgaVisibleSystemException(clienttranslate("No pending Analyst bonus tile"));
        }
        $tile_id = (int)$tile_id;
        $this->returnTileToBag($tile_id);
        $this->bga->globals->set('analyst_bonus_pending_tile_id', null);

        // Public notification carries NO tile_type per [D-20].
        $this->bga->notify->all('analystBonusReturned',
            clienttranslate('${player_name} returns the Analyst bonus to the bag.'),
            [
                'player_id' => $active,
                'player_name' => self::getPlayerNameById($active),
                'new_bag_size' => $this->getBagSize(),
            ]);

        if ($this->checkDepletion() !== null) {
            return GameEnd::class;
        }
        $this->assertPickupInvariant();
        return Actions::class;
    }

    #[PossibleAction]
    public function actPassActions(): ?string
    {
        $this->ensurePhaseIsActions();
        return EndOfTurnCleanup::class;
    }
}
