# Hexpionage — Implementation-Grade Rulebook

> **Audience**: developer agents implementing this game. **Not** a player-facing rulebook.
>
> **Sources of authority** (priority high → low):
> 1. **[RB]** Rulebook print art — `final_printing/rulebook/rules_templated_nice_{01,02,03}.png` (the 3 rule pages of the printed booklet).
> 2. **[FAQ]** Rules FAQ — `final_printing/Hexpionage Rules FAQ.md`.
> 3. **[D-NN]** Owner decisions — see `DECISIONS.md`. Decisions override the rulebook/FAQ where explicitly noted.
> 4. **[PP]** Pre-production document — `final_printing/Hexpionage pre-production document.docx`. Print logistics only; not a rules authority.
> 5. **[DERIVED]** Rules logically required by other rules. Always cited as DERIVED to distinguish from explicit text.
>
> Every rule statement carries a citation. **TODO** and **AMBIGUITY** sections call out anything not fully specified.
>
> Conventions:
> - Hex grid is **pointy-top** orientation [DERIVED from RB Trickle directions: "down and to the left" / "down and to the right" → SW / SE neighbors only exist in pointy-top].
> - The 6 hex neighbors of a hex H are referenced as: `NW(H)`, `NE(H)`, `E(H)`, `SE(H)`, `SW(H)`, `W(H)`.
> - Two hexes are **adjacent** iff one is a neighbor of the other.
> - "Up" in the rulebook = `NW` or `NE` (player choice). "Down" = `SW` or `SE` (player choice). The board has no `N` or `S` direct neighbors in pointy-top; "straight up/down" is not a valid direction.
> - Players are referenced as `P1` and `P2`. The active player is `Pa`; the opponent is `Po`.

---

## 1. Game Overview

### 1.1 Objective
Be the first player to score **20 points** by retiring agents that hold scoring Intel. [RB Gameplay]

### 1.2 Number of players
**Exactly 2.** [D-02]

### 1.3 High-level game flow
1. Setup (§4).
2. Players alternate turns. Each turn proceeds through three phases in fixed order: **Trickle Intel → Spawn Agents → Take Actions**. [RB Gameplay]
3. End-game check fires immediately upon any score change. The first player to reach ≥20 points wins instantly. [RB Gameplay]

---

## 2. Components

### 2.1 Authoritative inventory (from [RB Contents])

| Quantity | Component | Properties |
|---|---|---|
| 1 | Game board | Foldable hex grid, 457 × 228 mm unfolded. The playable area ("the Field") is denoted by purple hexes. The bottom row of the Field is denoted by a **✦ (star)** symbol on each hex; only these hexes are valid for spawning and retiring. The two extreme top-corner hexes (`top-left`, `top-right`) are the Intel entry points. |
| 1 | Intel supply bag | Opaque container; holds all unrevealed Intel tiles. Drawing is **uniform random without replacement** until the bag is empty (then refills only as Intel is returned). |
| 47 | Intel tiles | Hex-shaped tiles in 6 distinct colors (one per Intel type). Each tile has a printed numeric **score value** (see §2.4). |
| 6 | Intel dice | Six six-sided dice. Each die's color matches one Intel type/color. A die rolled odd = trickle direction `SW` for that color; rolled even = trickle direction `SE`. [RB Trickle step 3] |
| 24 | Agent tiles | 12 per player × 2 players = 24 total. Each player owns 2 copies of each of the 6 agent types. [D-10b correction; consistent with [RB Contents]] |
| 2 | Score markers | One per player. Track current score (0–20) on the board's score track. [RB Contents] |

### 2.2 Agent types
6 types per player (2 of each, total 12 per player). [RB Contents], [D-01], [D-10b]

| ID | Name | Print-art file (alias) | Color (player-tinted) |
|---|---|---|---|
| `comms_specialist` | Comms Specialist | `specialops_*.png` | Green [VERIFY against print art] |
| `analyst` | Analyst | `analyst_*.png` | Orange |
| `smuggler` | Smuggler | `smuggler_*.png` | Purple |
| `engineer` | Engineer | `engineer_*.png` | Green |
| `hacker` | Hacker | `hacker_*.png` | Red |
| `double_agent` | Double Agent | `doubleagent_*.png` | Black/White (multicolor) |

> **NOTE**: The print-art file `specialops_*.png` is the artwork for the in-game name "Comms Specialist" per [D-01]. The asset manifest must alias the file accordingly.

### 2.3 Agent properties
Every agent token, while on the board, carries the following dynamic properties:

| Property | Type | Notes |
|---|---|---|
| `id` | unique | Identity across the game. |
| `owner` | `P1` \| `P2` | Set at game start; never mutated. |
| `type` | one of the 6 above | Set at game start; never mutated. |
| `state` | `in_pool` \| `on_board` \| `removed` | Lifecycle. |
| `hex` | hex coordinate \| `null` | Defined only when `state = on_board`. |
| `pinned_until` | `(turn_id)` \| `null` | When non-null, agent is pinned. See §6.10 and §9.5. |
| `intel_held` | ordered list of intel-tile-ids | Intel currently possessed by this agent. List length is bounded — see §9.3. |
| `spawned_on_turn` | `turn_id` \| `null` | Used to enforce "may not retire on the same turn it was spawned." [RB Retire Agent] |

### 2.4 Intel types and counts

The rulebook [RB Contents] states **47 Intel tiles**. The 6 distinct types correspond 1:1 to the 6 Intel-die colors. Score values and color mapping per [D-19]:

| ID | Name | Color | Die match | Score value | Asset file |
|---|---|---|---|---|---|
| `honeypot` | Honeypot | **Gray** | gray die | **0** (never scored — see §9.4) | `honeypot.png` / `_back.png` |
| `industrial_tech` | Industrial Tech | **Brown** | brown die | **2** | `industrial_tech.png` / `_back.png` |
| `leaked_email` | Leaked Email | **Purple** | purple die | **2** | `leaked_email.png` / `_back.png` |
| `blackmail` | Blackmail | **Green** | green die | **2** | `blackmail.png` / `_back.png` |
| `security_credential` | Security Credential | **Yellow** | yellow die | **3** | `security_credential.png` / `_back.png` |
| `state_secret` | State Secret | **Cyan** | cyan die | **4** | `state_secret.png` / `_back.png` |

Score values are **non-uniform**: most intel are worth 2, but Security Credential (3) and State Secret (4) are higher-value. Honeypot is 0 and is never voluntarily scored (it removes any agent that gains possession of it; §9.4).

> **TODO(I-02)**: Confirm exact count per intel type. 47 total tiles distributed across 6 types must be enumerated from the punchboard PSDs in the asset audit pass. Implementer should treat per-type counts as a config constant pending audit.

### 2.5 Tokens (from [PP] punchboard 2 inventory)

| Quantity | Token | Notes |
|---|---|---|
| 6 | Blockade triangles | 3 white + 3 black. Print artifact: each player owns 3 *physical* tokens, but per [D-04]+[D-07] the *gameplay* supply is **unlimited**, capped to **at most 3 of one player's blockades on the board simultaneously**. |
| 4 | Pinned-status triangles | 2 white + 2 black. Print artifact: marker placed on a pinned agent. In digital implementation this is a `pinned_until` flag on the agent record (§2.3). |
| 2 | Trickle direction arrows | 1 white + 1 black. Physical aid for showing trickle direction; not modeled in digital state. |

### 2.6 The board

The board is a **hex grid** with three special markings: [RB Trickle, RB Spawn, RB Move Agent]

- **The Field**: hexes shaded purple. Agents may only be located on Field hexes. [RB Move Agent: "An agent **may not** move anywhere outside the field."]
- **Spawn row** (= bottom row of the Field): hexes additionally marked with `✦`. Agents may only spawn on these hexes. Retire actions may only fire when the agent is on a `✦` hex. [RB Spawn, RB Retire Agent]
- **Intel entry hexes**: the **top-left** and **top-right** Intel placement positions used during phase 1 step 1–2. [RB Trickle steps 1–2]

> **TODO(B-01)**: The exact hex layout (row count, columns per row, total Field hex count, position of the score track and player roster) must be derived from `game_board_print.png`. Implementers must publish the canonical coordinate map in `design/BOARD_LAYOUT.md`. Without this, all references to specific hexes in this document use abstract names (`top-left`, `bottom-row[i]`, etc.).

---

## 3. Game State Model

### 3.1 Global game state

```
game_state = {
  phase: enum {
    'setup',
    'trickle_draw_left',
    'trickle_draw_right',
    'trickle_roll',
    'trickle_resolve',
    'spawn',
    'actions',
    'end_of_turn_cleanup',
    'game_end',
  },
  turn_id: integer,                   // increments at start of each turn
  active_player: P1 | P2,
  bag: multiset<intel_tile_id>,       // hidden from all players
  dice_state: map<color, {odd|even} | null>,  // null between turns; set during trickle_roll
  actions_remaining: integer,         // 0..4 during 'actions' phase
  smuggler_boost_used_this_turn: bool,
  spawned_this_turn: integer,         // strictly informational; cap is enforced by §6.7
  game_winner: P1 | P2 | null,
}
```

Hacker per-turn flags (`pin_used_this_turn`, `steal_used_this_turn`) are tracked **per Hacker agent**, not per-player ([D-15]). They live on the `agent` row (§2.3 extension):
```
agent.hacker_pin_used_this_turn: bool   // only meaningful for type='hacker'
agent.hacker_steal_used_this_turn: bool // only meaningful for type='hacker'
```
Both flags reset to `false` during end-of-turn cleanup (§7.4) for every Hacker on the board.

> **DERIVED** state derivations:
> - `actions_remaining` initial value at start of `actions` phase = `3` (or `4` if smuggler_boost_used_this_turn). [RB Take Actions, RB Smuggler]
> - Each Hacker independently has one pin and one steal per turn ([D-15]). Owning N Hackers grants up to N pins and N steals per turn.

### 3.2 Per-player state

```
player_state[P] = {
  player_id: P1 | P2,
  score: integer (0..20+),
  agent_pool: list<agent> where agent.owner == P AND agent.state == 'in_pool',
  agents_on_board: list<agent> where agent.owner == P AND agent.state == 'on_board',
  agents_removed: list<agent> where agent.owner == P AND agent.state == 'removed',
  blockades_on_board: list<blockade> where blockade.owner == P AND blockade.state == 'on_board',
  scored_intel: list<intel_tile_id>,  // tiles taken out of play via retirement; sums to score
}
```

### 3.3 Board state

```
board_state = {
  intel_on_hex: map<hex, list<intel_tile_id>>,  // stack of intel on a hex (may include held by agent on that hex; see §3.5)
  agent_on_hex: map<hex, agent_id | null>,       // at most one agent per hex
  blockade_on_hex: map<hex, blockade | null>,    // at most one blockade per hex
}

blockade = {
  id: unique,
  owner: P1 | P2,
  hex: hex coordinate,
  placed_on_turn: turn_id,
  state: 'on_board' | 'expired',
}
```

### 3.4 Intel state

Each intel tile has identity:
```
intel_tile = {
  id: unique,
  type: one of 6 intel types,
  state: 'in_bag' | 'on_board' | 'on_agent' | 'scored' | 'returned_to_bag',
  hex: hex coordinate | null,        // when 'on_board' (loose, on a hex)
  agent_id: agent_id | null,         // when 'on_agent' (held by agent; agent's own hex is the location)
  scored_by: P1 | P2 | null,         // when 'scored'
}
```

Invariant: at most one of `hex`, `agent_id`, `scored_by` is non-null, matching the corresponding `state` value.

### 3.5 Loose vs held intel

Intel on the board is in one of two storage modes:
- **Loose**: `state = 'on_board'`, sits on a hex with no agent (or on a hex with a blockade — possible during placement; see §6.6).
- **Held**: `state = 'on_agent'`, owned by a specific agent. Travels with the agent.

When an agent moves onto a hex that has loose intel, the agent immediately picks up **all** loose intel on that hex. [RB Move Agent: "If an agent moves onto a piece of intel it immediately takes possession of that intel"]

Multiple intel pieces may be **stacked** loose on a single hex. [FAQ Movement of Intel point 1] All stacked intel share the hex; agents picking up the hex pick up all. Different intel colors may trickle in different directions on the next turn. [FAQ Movement of Intel points 1, 5]

### 3.6 Pin state

A pin is **not** a separate object; it lives as `agent.pinned_until` (a turn_id) on the pinned agent record. [DERIVED from §6.10] The pin expires at end-of-turn cleanup of the **pinned player's** following turn (§6.6, [D-06a]).

There is at most **one pin per agent** [D-06b].

### 3.7 Hidden vs public information

| State | Visibility |
|---|---|
| `bag` (multiset of tile IDs and their types) | **Hidden from all players** [DERIVED: bag must be sealed for the game to be playable]. Server-only. |
| Each `intel_tile.id → type` mapping for tiles still in the bag | **Hidden** until drawn. |
| All board state (`intel_on_hex`, `agent_on_hex`, `blockade_on_hex`) | **Public** [D-11; RB game design treats board as public]. |
| Each agent's `intel_held` list (full type breakdown) | **Public** [DERIVED: necessary for Hacker steal and Smuggler swap targeting; rules give no indication of hidden hands]. |
| `score` per player | **Public at all times** [D-11]. |
| `dice_state` (current turn's roll outcomes) | **Public** for the duration of the trickle phase. |
| `actions_remaining`, `smuggler_boost_used_this_turn`, `hacker_pin_used_this_turn`, etc. | **Public** [DERIVED: necessary for opponents to predict legal actions]. |

### 3.8 Derived state

The following are not stored but computed on demand:
- `agent.intel_count = len(agent.intel_held)` — used in the over-capacity check (§9.3).
- `agent.is_pinned = (agent.pinned_until is not null)`.
- Player's `total_agents_on_board = len(agents_on_board)` — used in spawn cap (§6.7).

---

## 4. Setup

[RB Setup]

1. **Place all 47 Intel tiles into the Intel supply bag**, face-down/concealed, then mix thoroughly. [RB Setup step 1]
2. **Assign player colors**: each player chooses or is assigned `P1` (white) or `P2` (black). Each player receives their full **12-agent pool** (2 of each of the 6 agent types). All 12 agents start in `state = 'in_pool'`. [RB Setup step 2; D-10b]
3. **Determine first player** [D-16]: select uniformly at random (`bga_rand`) from `{P1, P2}`. The rulebook's "most secrets" wording is replaced for digital play.
4. **Initialize board state**:
   - All hexes empty (no intel, no agents, no blockades).
   - Each player's score = `0`.
   - `turn_id = 1`, `active_player = first_player`.
   - `dice_state = {}` (no dice rolled yet).
   - `phase = 'trickle_draw_left'` (the first player begins the first phase of their first turn).
   - `smuggler_boost_used_this_turn = false`.
   - All Hacker per-Hacker flags = false (initially no Hackers exist on the board, so this is trivially satisfied; see §7.4 for ongoing reset).
   - `spawned_this_turn = 0`, `actions_remaining = 0`.

> **NOTE**: there is no "preliminary turn" or starting board state with pre-placed intel. The first player begins by drawing 2 intel tiles immediately. [DERIVED from RB ordering]

---

## 5. Turn Structure

A turn comprises three phases in order, executed by `active_player`. [RB Gameplay]

| # | Phase | Sub-phases | Active player decisions? |
|---|---|---|---|
| 1 | **Trickle Intel** | `trickle_draw_left` → `trickle_draw_right` → `trickle_roll` → `trickle_resolve` | None — fully automatic [DERIVED] |
| 2 | **Spawn Agents** | `spawn` | Yes — chooses how many and which agents to spawn, and on which spawn-row hexes |
| 3 | **Take Actions** | `actions` (interactive; can fire 0..4 actions, see §6) | Yes — chooses each action |

After phase 3, an `end_of_turn_cleanup` step fires (§7.4), then control passes to the opponent and `turn_id` increments.

### 5.1 Phase 1 — Trickle Intel (sub-phase order)

Steps fire in this exact order. None require player input. [RB Trickle steps 1–3]

1. **`trickle_draw_left`**: draw 1 tile uniformly at random from the bag and place it on the **top-left** Intel entry hex. If `len(bag) == 0`, **skip** this placement [D-18].
2. **`trickle_draw_right`**: draw 1 tile uniformly at random from the bag and place it on the **top-right** Intel entry hex. If `len(bag) == 0`, **skip** [D-18].
3. **`trickle_roll`**: roll all 6 Intel dice. Each die produces an `odd` or `even` outcome.
4. **`trickle_resolve`**: see §7.2 for the full resolution algorithm.

> **NOTE (D-18)**: Empty-bag draws are no-ops. The corresponding entry hex remains unchanged that turn. The game continues normally; no game-end trigger fires from bag depletion.

### 5.2 Phase 2 — Spawn Agents

[RB Spawn]: "As long as you have fewer than 3 agents on the field you may spawn new agents from your roster until you have the maximum of 3 agents. Agents can only spawn on bottom row tiles (denoted by the ✦ symbol) that are completely empty (no agents, intel, or blockades)."

- The spawning cap is **3 agents simultaneously on the board for the active player**, not "3 spawns per turn."
- The active player may spawn `0..(3 - current_agents_on_board)` agents this phase.
- Each spawn chooses (a) an agent from `agent_pool` and (b) a `✦` hex that is empty (no agent, no intel, no blockade).
- Spawned agents start with `intel_held = []` and `spawned_on_turn = current_turn_id`.

> **NOTE re D-10a**: The user's D-10a answer ("up to 3 per turn") is functionally identical to the rulebook's "max 3 simultaneously" because the active player can never enter spawn phase with >3 agents on the board (any over-3 state is impossible). Both formulations produce the same set of legal spawns. The rulebook's wording is canonical.

### 5.3 Phase 3 — Take Actions

[RB Take Actions]: "A player can spend up to **3 Actions** in a turn. Agents can **only** spend Intel they possess to pay for abilities."

- `actions_remaining` is initialized to **3**.
- The active player executes 0..N actions, where N is bounded by `actions_remaining` and intel-constraints. The player explicitly ends the phase (or it auto-ends when no legal actions remain).
- The Smuggler boost (§6.9) increases the cap to 4 by spending 1 Intel; this is the **only** way to exceed 3 actions. [D-08]

Within phase 3, sub-phases for individual action resolution may be implemented as state-machine transitions, but the rulebook does not formalize them.

### 5.4 Active player rules
- All decisions in phases 2 and 3 are made by `active_player` only.
- Phase 1 decisions: none. (Random draws and dice rolls are server-resolved.)
- Opponent has no decision input during the active player's turn.

### 5.5 Phase transition triggers
| From | To | Trigger |
|---|---|---|
| `setup` | `trickle_draw_left` | After §4 setup completes; turn 1 begins. |
| `trickle_draw_left` | `trickle_draw_right` | After top-left intel placement (or skip if bag empty). |
| `trickle_draw_right` | `trickle_roll` | After top-right intel placement (or skip if bag empty). |
| `trickle_roll` | `trickle_resolve` | After all 6 dice are rolled. |
| `trickle_resolve` | `spawn` | After all trickle effects (§6.13) resolve. |
| `spawn` | `actions` | When player passes spawning OR when no legal spawn remains (`agent_pool` empty OR no empty ✦ hex). |
| `actions` | `end_of_turn_cleanup` | When player passes OR `actions_remaining == 0` AND no free action (Retire) AND no intel-only ability is legal. |
| `end_of_turn_cleanup` | `trickle_draw_left` (next player) | After cleanup (§7.4); switch active_player; increment turn_id. |
| Any phase | `game_end` | If §8 win condition fires. |

---

## 6. Player Actions

This section enumerates every legal player input, with full preconditions and effects. **Server must validate every precondition.** [DERIVED: BGA security model.]

### 6.0 Action enumeration

Each action belongs to one of three categories:

- **Phase-2 actions** (Spawn phase only):
  - `act_spawn_agent` (§6.1)
  - `act_pass_spawn` (§6.2)
- **Phase-3 actions** (Actions phase only):
  - **Standard actions** (always available to any agent):
    - `act_move_agent` (§6.3) — costs 1 Action
    - `act_transfer_intel` (§6.4) — costs 1 Action
    - `act_retire_agent` (§6.5) — FREE
  - **Agent abilities** (each tied to an agent type):
    - `act_engineer_place_blockade_adjacent` (§6.6.A) — 1 Action
    - `act_engineer_place_blockade_anywhere` (§6.6.B) — 1 Intel
    - `act_smuggler_boost_actions` (§6.7) — 1 Intel
    - `act_smuggler_swap_agents` (§6.8) — 1 Intel + 1 Action
    - `act_comms_move_intel_up` (§6.9.A) — 1 Action
    - `act_comms_move_intel_down` (§6.9.B) — 1 Intel + 1 Action
    - `act_double_agent_transfer` (§6.10) — 1 Action
    - `act_hacker_pin` (§6.11.A) — 1 Action
    - `act_hacker_unpin` (§6.11.B) — 1 Action
    - `act_hacker_steal_intel` (§6.11.C) — 1 Intel
    - `act_analyst_retire_bonus` (§6.12) — Free, triggered (not player-initiated)
  - **Pass**:
    - `act_pass_actions` (§6.13)

---

### 6.1 `act_spawn_agent`
Place an agent from the active player's pool onto a spawn-row hex.

- **When allowed**: phase = `spawn`.
- **Inputs**: `(agent_id_from_pool, target_hex)`.
- **Preconditions**:
  - `agent_id_from_pool` belongs to active player AND `state = 'in_pool'`.
  - `target_hex` is a `✦` (spawn-row) hex.
  - `target_hex` has no agent, no loose intel, and no blockade.
  - `len(active_player.agents_on_board) < 3`. [RB Spawn]
- **Effects**:
  1. Set `agent.state = 'on_board'`, `agent.hex = target_hex`, `agent.spawned_on_turn = turn_id`, `agent.intel_held = []`, `agent.pinned_until = null`.
  2. Update `board_state.agent_on_hex[target_hex] = agent_id`.
  3. Increment `spawned_this_turn` (informational).
- **Postconditions**:
  - `len(active_player.agents_on_board) <= 3`.
  - Phase remains `spawn` until player passes or no legal spawn remains.
- **Failure conditions**: any precondition violated.

### 6.2 `act_pass_spawn`
End the spawn phase.

- **When allowed**: phase = `spawn`.
- **Inputs**: none.
- **Preconditions**: phase = `spawn`.
- **Effects**: transition to phase `actions`; set `actions_remaining = 3`.
- **Postconditions**: phase = `actions`.

### 6.3 `act_move_agent`
Move a friendly agent to an adjacent Field hex.

- **When allowed**: phase = `actions`, `actions_remaining >= 1`.
- **Inputs**: `(agent_id, target_hex)`.
- **Preconditions**:
  - `agent.owner == active_player` AND `agent.state == 'on_board'`.
  - `agent.is_pinned == false` [RB Hacker: "a pinned agent cannot move, retire, or switch out"].
  - `target_hex` is in the Field (purple).
  - `target_hex` is adjacent to `agent.hex` (one of the 6 hex-neighbors).
  - `target_hex` has no agent.
  - `target_hex` has no blockade.
  - **Note**: `target_hex` MAY contain loose intel (the agent will pick it up — see effect 3).
- **Effects**:
  1. Set `agent.hex = target_hex`. Update `board_state.agent_on_hex` accordingly.
  2. **Universal pickup invariant** [D-21]: at any moment where loose intel and an agent would co-occupy a hex, the intel is immediately picked up by that agent. This applies to Move, trickle resolution, Smuggler swap, and any other mechanic that creates co-occupation. For each `intel_tile` with `state = 'on_board' AND hex == target_hex`:
     - If `intel_tile.type == 'honeypot'`: invoke §9.4 (agent-on-honeypot removal). [DERIVED + RB Trickle: "If an agent gains possession of a 'Honeypot' intel, permanently remove the agent from the game and return the Honeypot to the intel supply." This rule applies to any "gain of possession," including via Move per [D-05b], swap per [D-21], and any other co-occupation event.]
     - Else: set `intel_tile.state = 'on_agent'`, `intel_tile.agent_id = agent.id`, `intel_tile.hex = null`. Append to `agent.intel_held`.
  3. After pickup, **invoke §9.3 (over-capacity check)** on `agent`.
  4. Decrement `actions_remaining` by 1.
- **Postconditions**: agent is on `target_hex` (or removed if Honeypot encountered); intel ownership transferred.
- **Failure conditions**: any precondition violated; in particular, target hex has agent or blockade or is outside the Field.

### 6.4 `act_transfer_intel`
Transfer one intel tile from one of your agents to an adjacent agent you also control.

- **When allowed**: phase = `actions`, `actions_remaining >= 1`.
- **Inputs**: `(source_agent_id, target_agent_id, intel_tile_id)`.
- **Preconditions**:
  - `source_agent.owner == active_player` AND `target_agent.owner == active_player`.
  - Both agents have `state = 'on_board'`.
  - **`source_agent.id != target_agent.id`** [F-27] — explicit precondition; cannot transfer to self. (Previously this was derived from §9.12 only; now stated as a precondition for clarity.)
  - `source_agent.hex` and `target_agent.hex` are adjacent.
  - `intel_tile_id ∈ source_agent.intel_held`.
  - `source_agent.is_pinned` does **not** prevent transfer per [RB Hacker] (pinned agents can still use abilities; transfer is an action of the source agent's owner). However, see [FAQ Agents point 3]: "When an agent is pinned, its abilities can still be used."
  - `target_agent.is_pinned` — pinned agents may still receive intel (§9.5).
- **Effects**:
  1. Move `intel_tile` from `source_agent.intel_held` to `target_agent.intel_held`. Update `intel_tile.agent_id = target_agent.id`.
  2. **Invoke §9.3 (over-capacity check)** on `target_agent`.
  3. **If `intel_tile.type == 'honeypot'`** [EDGE(I-04)]: §9.4 fires on `target_agent` (Honeypot possession removes agent). However, see TODO(I-04) in §13 — Honeypots should be removed at the trickle resolution where they were drawn-onto-agent, so this case can only arise if a Honeypot is loose on a hex and an agent picked it up, meaning §9.4 already fired during pickup. A Honeypot held by an agent and survived to a transfer action is **impossible** under correct enforcement. Implementer must assert this invariant.
  4. Decrement `actions_remaining` by 1.
- **Postconditions**: intel held by target agent; over-capacity may have triggered intel dump.
- **Failure conditions**: any precondition violated.

### 6.5 `act_retire_agent`
Remove an agent from play and score **all** of its Intel tiles.

- **When allowed**: phase = `actions`. **No action cost.** [RB Retire Agent: "cost: Free"]
- **Inputs**: `(agent_id)` — the active player chooses which agent to retire. No intel selection input — all held intel scores [D-14].
- **Preconditions**:
  - `agent.owner == active_player` AND `agent.state == 'on_board'`.
  - `agent.hex` is a `✦` (bottom-row) spawn hex. [RB Retire Agent]
  - `agent.spawned_on_turn != turn_id`. [RB Retire Agent: "You may not retire an agent on the same turn it was spawned."]
  - `agent.is_pinned == false`. [RB Hacker]
- **Effects**:
  1. **Score all held intel** [D-14]: for every `intel_tile` in `agent.intel_held`:
     - Set `intel_tile.state = 'scored'`, `intel_tile.scored_by = active_player`, `intel_tile.agent_id = null`.
     - Add `intel_tile.score_value` to `active_player.score`.
     - (Honeypots cannot be in `intel_held` per §9.4 invariant; if encountered, the implementation has a bug — assert and abort.)
  2. **Analyst bonus** (if `agent.type == 'analyst'` AND `len(agent.intel_held) == 3` at the moment of retirement, **measured before step 1 mutates the list**): immediately invoke §6.12 — draw 1 intel from bag; player chooses keep (= scored) or return.
  3. Clear `agent.intel_held = []`.
  4. Set `agent.state = 'removed'`, `agent.hex = null`.
  5. Update `board_state.agent_on_hex[hex] = null`.
  6. Per [D-10b]: agent does **not** return to the pool. The `removed` state is permanent.
  7. **Invoke §8 (win check)**: if `active_player.score >= 20`, set `game_winner = active_player` and transition to `game_end`.
  8. **Invoke §8.3 (depletion check)** on the just-emptied agent's owner: if `len(agent_pool) + len(agents_on_board) == 0` for active_player, opponent wins ([D-17]). (In practice, retiring your own last agent without first scoring 20 would lose the game.)
- **Postconditions**: agent is `removed`; all of its previously-held intel is scored; player score increased by sum of held-intel values (plus possibly +1 from Analyst bonus).
- **Failure conditions**: any precondition violated; in particular not on `✦` hex, or agent is pinned, or agent was spawned this turn.

> **Score range per retire**: minimum 0 (retiring an agent with no intel) up to 12 (retiring with 3× State Secret = 4+4+4) plus up to 4 from Analyst bonus = 16 max single-retire score.

### 6.6 Engineer abilities

#### 6.6.A `act_engineer_place_blockade_adjacent`
- **When allowed**: phase = `actions`, `actions_remaining >= 1`.
- **Inputs**: `(engineer_agent_id, target_hex)`.
- **Preconditions**:
  - Engineer agent is owned by active player, on board.
  - `target_hex` is in the Field, adjacent to `engineer.hex`, has no agent and no blockade.
  - `target_hex` MAY contain loose intel — see [FAQ Agents point 5]: "Engineers may place blockades on spaces that have intel."
  - Active player has `< 3` blockades on the board ([D-04]+[D-07]).
  - Engineer is not pinned-prevented from using abilities. [FAQ: pinned agents may still use abilities.]
- **Effects**:
  1. Create new `blockade` with `owner = active_player`, `hex = target_hex`, `placed_on_turn = turn_id`, `state = 'on_board'`.
  2. Decrement `actions_remaining` by 1.
- **Postconditions**: new blockade on `target_hex`; player has `<= 3` blockades on board.
- **Failure conditions**: any precondition violated.

#### 6.6.B `act_engineer_place_blockade_anywhere`
- **When allowed**: phase = `actions`. **No action cost.** Pure intel cost.
- **Inputs**: `(engineer_agent_id, intel_tile_id, target_hex)`.
- **Preconditions**:
  - Engineer agent owned by active player, on board.
  - `intel_tile_id ∈ engineer.intel_held` (the intel paid as cost is from this Engineer).
  - `target_hex` is in the Field; has no agent and no blockade.
  - Active player has `< 3` blockades on the board.
- **Effects**:
  1. Spend intel: move `intel_tile` to bag (`state = 'returned_to_bag'`, `agent_id = null`).
  2. Create blockade as in §6.6.A.
  3. **No action decrement.**
- **Postconditions**: same as §6.6.A; one intel returned to bag.
- **Failure conditions**: any precondition violated.

> **NOTE on blockade & intel**: per [FAQ Agents point 5], blockades placed on hexes with intel **prevent that intel from trickling and prevent other intel from moving into the hex.** This is a runtime board rule (§9.6), not a placement rule.

### 6.7 `act_smuggler_boost_actions`
- **When allowed**: phase = `actions`. **No action cost.** Pure intel cost. Once per turn (cap [D-08]).
- **Inputs**: `(smuggler_agent_id, intel_tile_id)`.
- **Preconditions**:
  - `smuggler.owner == active_player`, `state = 'on_board'`, `type == 'smuggler'`.
  - `intel_tile_id ∈ smuggler.intel_held`.
  - `smuggler_boost_used_this_turn == false`. [D-08]
- **Effects**:
  1. Spend intel: tile → bag.
  2. Set `smuggler_boost_used_this_turn = true`.
  3. Increase action cap: `actions_remaining += 1` (effective cap from 3 to 4). [RB Smuggler: "Increase the maximum number of Actions you can take this turn to 4"]
- **Postconditions**: `actions_remaining` increased; boost flag set.
- **Failure conditions**: any precondition violated; in particular, boost already used this turn.

### 6.8 `act_smuggler_swap_agents`
- **When allowed**: phase = `actions`, `actions_remaining >= 1`.
- **Inputs**: `(smuggler_agent_id, intel_tile_id, agent_a_id, agent_b_id)`.
- **Preconditions**:
  - Smuggler agent owned by active player, on board.
  - `intel_tile_id ∈ smuggler.intel_held`.
  - `agent_a` and `agent_b` are both `state = 'on_board'`. They may belong to either player.
  - **Neither `agent_a` nor `agent_b` is pinned.** [FAQ Agents point 4: "Smugglers cannot swap pinned agents."]
  - `agent_a != agent_b`.
  - `agent_a` and `agent_b` MAY include the Smuggler itself. (Rulebook does not forbid swapping the smuggler with another agent.) **AMBIGUITY(S-01)**: see §13.
- **Effects**:
  1. Spend intel.
  2. Swap hex positions: `tmp = agent_a.hex; agent_a.hex = agent_b.hex; agent_b.hex = tmp;`. Update `board_state.agent_on_hex` for both hexes.
  3. **Held intel travels with each agent** — no transfer. [RB Smuggler: "possessed intel moves together with switched agents"]
  4. **Universal pickup invariant** [D-21]: if either agent's new hex contains loose intel after the swap (i.e., the swap creates co-occupation with loose intel), pickup fires immediately for that agent. If the loose intel is a Honeypot, §9.4 fires (agent removed; intel returns to bag). Otherwise the intel is added to `intel_held` and §9.3 over-capacity check runs. **Note**: structurally this can occur only if loose intel and an agent could have co-occupied beforehand; per the universal pickup invariant ([D-21]), this state is impossible at rest, so this clause is defensive/canonical for any future mechanic that bypasses ordinary placement constraints.
  5. Decrement `actions_remaining` by 1.
- **Postconditions**: positions swapped; intel still held by respective agents; pickup invariant ([D-21]) preserved.
- **Failure conditions**: any precondition violated; in particular either agent is pinned.

### 6.9 Comms Specialist abilities

The Comms Specialist moves **loose** intel only — never intel held by an agent. [RB Comms Specialist: "This **does not** apply to Intel that is already possessed by an agent."], [D-09]

#### 6.9.A `act_comms_move_intel_up`
- **When allowed**: phase = `actions`, `actions_remaining >= 1`.
- **Inputs**: `(comms_agent_id, intel_tile_id, target_hex)`.
- **Preconditions** [F-06, F-07 — all enumerated explicitly; do not rely on inheritance]:
  - Comms agent owned by active player, on board (per [D-09]).
  - **No adjacency required** between Comms agent and the source intel or the target hex. [F-06]
  - `intel_tile.state == 'on_board'`. (Loose, not held — per [D-09] Comms cannot target intel held by an agent.)
  - `intel_tile.hex` is the source hex.
  - `target_hex == NW(intel_tile.hex)` OR `target_hex == NE(intel_tile.hex)`. [FAQ: "up & left or up & right"]
  - `target_hex` is in the Field.
  - `target_hex` has no blockade. [FAQ Agents point 6: "A Comms Specialist cannot move intel into a blockaded space."]
  - **`target_hex` has no agent.** [D-09; F-06] (Explicit precondition; no implicit inheritance.)
  - **Blockade-pair vertical block**: if both `NW(source)` and `NE(source)` are blockaded — wait, this is for vertical blockade pairs. The FAQ says: "if a Comms Specialist attempts to move intel vertically, and both adjacent spaces in the direction of movement are blockaded." This is the SAME blockade-pair rule that affects trickle (§9.6). For an upward Comms move, "both adjacent spaces in the direction of movement" is ambiguous — see AMBIGUITY(C-01) in §13.
- **Effects**:
  1. Move intel: `intel_tile.hex = target_hex`. Update board state.
  2. If `target_hex` had stacked loose intel, the moved intel joins the stack (multiple intel may share the hex). [FAQ Movement of Intel point 1]
  3. Decrement `actions_remaining` by 1.
- **Postconditions**: intel moved up one space.
- **Failure conditions**: any precondition violated.

#### 6.9.B `act_comms_move_intel_down`
- **When allowed**: phase = `actions`, `actions_remaining >= 1`.
- **Inputs**: `(comms_agent_id, paid_intel_tile_id, target_intel_tile_id, target_hex)`.
- **Preconditions** [F-07 — all enumerated explicitly; do not rely on inheritance from §6.9.A]:
  - Comms agent owned by active player, on board.
  - **No adjacency required** between Comms agent and the source intel or the target hex. [F-06]
  - `target_intel.state == 'on_board'`. (Loose, not held — per [D-09].)
  - `target_intel.hex` is the source hex.
  - `target_hex == SW(target_intel.hex)` OR `target_hex == SE(target_intel.hex)`. [FAQ: "down & left or down & right"]
  - `target_hex` is in the Field. (Off-Field default per AMBIGUITY(C-02) in §13: illegal.)
  - `target_hex` has no blockade. [FAQ Agents point 6]
  - **`target_hex` has no agent.** [D-09; F-07] (Explicit precondition; not inherited from §6.9.A.)
  - `paid_intel_tile_id ∈ comms_agent.intel_held` — the cost.
  - `paid_intel_tile_id != target_intel_tile_id`. (Cannot pay with the same intel you are moving.)
  - **Blockade-pair vertical block** (downward variant of §9.6.D): if both `SW(target_intel.hex)` and `SE(target_intel.hex)` are blockaded, the move is illegal regardless of the chosen `target_hex`.
- **Effects**:
  1. Spend `paid_intel`: tile → bag.
  2. Move target intel one space `SW` or `SE` as in §6.9.A.
  3. **If `target_hex` is off the bottom of the board** (no SE/SW exists): rulebook is silent. **AMBIGUITY(C-02)**: see §13. **Default proposed**: this is illegal — the target hex must exist within the Field.
  4. Decrement `actions_remaining` by 1.
- **Postconditions**: target intel moved down one space; one intel tile spent.
- **Failure conditions**: any precondition violated.

### 6.10 `act_double_agent_transfer`
Transfer one intel tile from a Double Agent to **any** other agent in play (own or opponent's). [RB Double Agent]

- **When allowed**: phase = `actions`, `actions_remaining >= 1`.
- **Inputs**: `(double_agent_id, target_agent_id, intel_tile_id)`.
- **Preconditions**:
  - `double_agent.owner == active_player`, `state == 'on_board'`, `type == 'double_agent'`.
  - `target_agent.state == 'on_board'`. May belong to either player.
  - `target_agent.id != double_agent.id`.
  - `intel_tile_id ∈ double_agent.intel_held`.
  - **No adjacency requirement** (unlike §6.4). [RB Double Agent: "ANY other agent in play"]
- **Effects**:
  1. Move intel: `intel_tile.agent_id = target_agent.id`; remove from `double_agent.intel_held`; append to `target_agent.intel_held`.
  2. **Invoke §9.3 (over-capacity check)** on `target_agent`.
  3. Honeypot held-by-agent invariant applies (see §6.4 effect 3 — Honeypots cannot be held).
  4. Decrement `actions_remaining` by 1.
- **Postconditions**: intel ownership transferred (possibly to opponent).
- **Failure conditions**: any precondition violated.

### 6.11 Hacker abilities

#### 6.11.A `act_hacker_pin`
Pin an adjacent enemy agent.

- **When allowed**: phase = `actions`, `actions_remaining >= 1`. **Once per turn per individual Hacker** [D-15].
- **Inputs**: `(hacker_agent_id, target_agent_id)`.
- **Preconditions**:
  - `hacker.owner == active_player`, `hacker.type == 'hacker'`, `hacker.state == 'on_board'`.
  - `target_agent.state == 'on_board'`, `target_agent.owner != active_player` (enemy).
  - `target_agent.hex` is adjacent to `hacker.hex`.
  - `target_agent.is_pinned == false`. [D-06b: max one pin per agent]
  - `hacker.hacker_pin_used_this_turn == false`. [D-15: per-Hacker counter]
- **Effects**:
  1. Set `target_agent.pinned_until = T*`, where `T*` is the turn_id at which the pin must clear: end of pinned player's next turn cleanup. Concretely, pin clears during the next `end_of_turn_cleanup` whose ending turn is owned by `target_agent.owner`. [D-06a; RB Hacker]
  2. Set `hacker.hacker_pin_used_this_turn = true`.
  3. Decrement `actions_remaining` by 1.
- **Postconditions**: target agent is pinned; this Hacker has used its pin/unpin slot this turn (other Hackers owned by the same player are unaffected).
- **Failure conditions**: any precondition violated.

#### 6.11.B `act_hacker_unpin`
Unpin a friendly pinned agent.

- **When allowed**: phase = `actions`, `actions_remaining >= 1`. **Shares the same once-per-turn slot as §6.11.A on this individual Hacker.** [D-15; RB Hacker]
- **Inputs**: `(hacker_agent_id, target_agent_id)`.
- **Preconditions**:
  - `hacker.owner == active_player`, `hacker.state == 'on_board'`.
  - `target_agent.owner == active_player`, `target_agent.state == 'on_board'`.
  - `target_agent.hex` is adjacent to `hacker.hex`.
  - `target_agent.is_pinned == true`.
  - `hacker.hacker_pin_used_this_turn == false`.
- **Effects**:
  1. Set `target_agent.pinned_until = null`.
  2. Set `hacker.hacker_pin_used_this_turn = true`.
  3. Decrement `actions_remaining` by 1.
- **Postconditions**: target agent no longer pinned.
- **Failure conditions**: any precondition violated.

#### 6.11.C `act_hacker_steal_intel`
Steal one intel from a pinned opponent agent.

- **When allowed**: phase = `actions`. **No action cost.** Pure intel cost. **Once per turn per individual Hacker** [D-15].
- **Inputs**: `(hacker_agent_id, paid_intel_tile_id, target_agent_id, stolen_intel_tile_id)`.
- **Preconditions**:
  - `hacker.owner == active_player`, `hacker.state == 'on_board'`.
  - `paid_intel_tile_id ∈ hacker.intel_held`.
  - `target_agent.state == 'on_board'`, `target_agent.owner != active_player` (enemy).
  - `target_agent.is_pinned == true`. [RB Hacker]
  - `stolen_intel_tile_id ∈ target_agent.intel_held`.
  - `hacker.hacker_steal_used_this_turn == false`. [D-15: per-Hacker counter]
  - **Adjacency**: rulebook is silent on whether the Hacker must be adjacent to the pinned target. **AMBIGUITY(H-02)**: see §13. **Default proposed**: no adjacency requirement.
- **Effects**:
  1. Spend `paid_intel`: tile → bag.
  2. Move `stolen_intel`: from `target_agent.intel_held` to `hacker.intel_held`. Update `intel_tile.agent_id`.
  3. **Invoke §9.3** on `hacker` (over-capacity check).
  4. Honeypot invariant applies (Honeypots cannot be held — so cannot be stolen).
  5. Set `hacker.hacker_steal_used_this_turn = true`.
- **Postconditions**: intel transferred from enemy to Hacker; this Hacker has used its steal slot this turn.
- **Failure conditions**: any precondition violated; in particular target not pinned.

### 6.12 `act_analyst_retire_bonus` (two-step sub-state per [D-26])
- **When triggered**: as a sub-step of §6.5 (Retire Agent), iff retiring agent has `type == 'analyst'` AND `len(intel_held) == 3` at the moment of retirement (measured before §6.5 step 1 zeroes the list).

**Two-step flow per [D-26]**:

**Step 1 — Server draws (or skips on empty bag)**:
1. **If `len(bag) == 0`** [D-18]: bonus is forfeited; skip the remainder of §6.12. Retirement proceeds normally; server fires `analystBonusSkipped` (public). Control returns to §6.5 effect 7 (win check).
2. Otherwise, server draws 1 tile uniformly at random from bag. The drawn tile is held in transient state (NOT yet scored, NOT yet returned).
3. Server fires `analystBonusDrawn` **privately to the active player** [D-20] — tile type is revealed only to the active player. Opponents and spectators do NOT see the type.
4. Server transitions to the new BGA state `analystBonusDecision` (per [D-26] / STATE_MACHINE §2.7b).

**Step 2 — Player chooses keep or return**:
- The active player sees the drawn tile in a modal (UI_SPEC §3.7b) and chooses one of:
  - **`actAnalystKeep`** [D-26] — scored to active player. Set `intel_tile.state = 'scored'`, `intel_tile.scored_by = active_player`. Add `intel_tile.score_value` to `active_player.score`. (If the drawn tile is a Honeypot, score increment is 0; the tile is still consumed.) Server fires `analystBonusKept` (public; reveals type per [D-20]).
  - **`actAnalystReturn`** [D-26] — tile → bag (`state = 'returned_to_bag'`). Server fires `analystBonusReturned` (public; carries NO `tile_type` per [D-20] — opponent does not learn the type).
- Server transitions back to `actions` state (or to `gameEnd` via win/depletion check). Control returns to §6.5 effect 7 (win check).

> **Why a two-step flow [D-26]**: a single-step "blind pre-commit" (the player choosing keep/return BEFORE seeing the drawn tile) would deprive the player of an informed decision — see F-13 / D-26-CANDIDATE in `docs/specs/QA_SPEC_REVIEW.md`. Splitting into a sub-state with a private reveal preserves both the strategic choice and the bag-composition privacy on `return` (per [D-20]).

> **Failure conditions**:
> - Step 1: server-side invariant failure (e.g., bag count and bag query disagree) — abort.
> - Step 2: invalid action (anything other than `actAnalystKeep` or `actAnalystReturn`) — server rejects.
> - Step 2: zombie/timeout — server auto-fires `actAnalystReturn` (the safer default; no score change, no leak).

### 6.13 `act_pass_actions`
End the actions phase voluntarily.

- **When allowed**: phase = `actions`.
- **Inputs**: none.
- **Effects**: transition to `end_of_turn_cleanup`.
- **Postconditions**: phase = `end_of_turn_cleanup`.

> **Auto-pass rule** [DERIVED]: if `actions_remaining == 0` AND no intel-only ability is currently legal AND no Retire is legal, the server auto-fires `act_pass_actions`.

---

## 7. Game Flow and State Transitions

### 7.1 Top-level loop (pseudocode)
```
setup()
turn_id = 1
while game_winner is null:
    run_phase('trickle_draw_left')
    run_phase('trickle_draw_right')
    run_phase('trickle_roll')
    run_phase('trickle_resolve')   // §7.2
    run_phase('spawn')             // §5.2
    run_phase('actions')           // §5.3
    run_phase('end_of_turn_cleanup')  // §7.4
    if game_winner is null:
        active_player = opponent(active_player)
        turn_id += 1
emit('game_end', winner=game_winner)
```

### 7.2 Trickle resolution algorithm (`trickle_resolve`)
This is the most algorithmically complex phase. Rules cited: [RB Trickle], [FAQ Movement of Intel], [D-05a].

Inputs (from prior sub-phases):
- Top-left and top-right intel tiles placed (already on the board as loose intel).
- `dice_state` map: for each color C, either `'odd'` or `'even'` (= `SW` or `SE` direction).

Algorithm:

```
# Step A: compute intended moves for every loose intel tile (held intel does not trickle [RB Trickle step 3])
moves = []
for tile in loose_intel_on_board:
    direction = SW if dice_state[tile.color] == 'odd' else SE
    target = direction(tile.hex)
    moves.append((tile, target))

# Step B: apply blockade redirection per-tile (§9.6 single-blockade redirect)
# Precedence per [D-24]:
#   1. Compute intended direction D from dice.
#   2. If D(hex) is blockaded:
#        a. Compute the other diagonal D'(hex).
#        b. If D'(hex) is also blockaded → tile stays this turn (no_move).
#        c. Else if D'(hex) is off the Field → tile returns to bag per §9.2 [D-24].
#        d. Else → tile moves to D'(hex).
#   3. Else if D(hex) is off the Field → tile returns to bag per §9.2.
#   4. Else → tile moves to D(hex).
for (tile, target) in moves (mutable):
    direction = SW if dice_state[tile.color] == 'odd' else SE
    other_dir = SE if direction == SW else SW
    if blockade_at(target):
        # Redirect to the OTHER diagonal (§9.6 "redirect the intel towards the open direction")
        other_target = other_dir(tile.hex)
        if blockade_at(other_target):
            # Both diagonals blockaded → tile does not trickle this turn (§9.6 last clause)
            mark tile as 'no_move'
        elif not is_field_hex(other_target):
            # Redirect succeeds, but redirect target is off the Field → return to bag per §9.2 [D-24].
            mark tile as 'return_to_bag'
        else:
            target = other_target
    elif not is_field_hex(target):
        # Default direction off-board → return to bag per §9.2.
        mark tile as 'return_to_bag'
    # else: target stays as-is; tile will move there in step C.

# Step C: simultaneous resolution [FAQ Movement of Intel point 3: "move all pieces simultaneously"]
# All non-'no_move' tiles move to their (possibly redirected) target hexes in one step.

# Step D: check for off-board trickles — any tile whose target is "off the bottom of the Field" returns to bag immediately. [RB Trickle: "Trickle off the board"]

# Step E: resolve agent possession
for agent in all_agents_on_board:
    intel_arriving_to_agent_hex = [tile for (tile, target) in moves if target == agent.hex]
    for tile in intel_arriving_to_agent_hex:
        if tile.type == 'honeypot':
            # Honeypot trickling onto agent: agent is removed; Honeypot returns to bag; all of agent's intel returns to bag.
            invoke §9.4 honeypot-removal
            break  # agent is gone; further iteration meaningless
        else:
            tile.agent_id = agent.id; tile.state = 'on_agent'; tile.hex = null
            agent.intel_held.append(tile)
    # After all arrivals processed (and agent not removed):
    invoke §9.3 over-capacity check on agent

# Step F: stacking on empty hexes
# Multiple tiles arriving at the same empty hex stack. [FAQ Movement of Intel point 1]
# No further action required; board_state.intel_on_hex naturally accumulates.
```

> **DERIVED** ordering note: the FAQ ([FAQ Movement of Intel point 4]) suggests "trickle intel row by row starting from the bottom to help keep track." This is a **bookkeeping** suggestion, not a rule. The simultaneous-move rule (FAQ point 3) is canonical: all moves happen simultaneously, then agent and capacity checks apply. The bottom-up ordering only exists as a **mental aid** for human players. The server may iterate however it likes, provided the final state is identical to the simultaneous-move outcome.

> **FAQ vs per-agent iteration reconciliation [F-23]**: the FAQ point 3 phrasing — "Then any agents with honeypots are removed from the game ... Then all intel belonging to agents with over three intel return their intel to the supply" — could be read as TWO global passes: (1) all Honeypot removals across ALL agents, then (2) all over-capacity dumps across ALL agents. The §7.2 step E algorithm uses a per-agent loop instead: for each agent receiving arrivals, run Honeypot check then capacity dump locally before moving to the next agent.
>
> **Both formulations produce the same outcome** because Honeypot removal and over-capacity dump are local to a single agent — neither affects another agent's state. Iterating per-agent or in two global passes yields identical final state. The per-agent loop is preferred for server implementation simplicity. The FAQ's "all honeypots first then all over-capacity" wording is a bookkeeping convenience for humans, not a different algorithm.

### 7.3 Stacked-intel trickle direction
[FAQ Movement of Intel point 1]: "All stacked intel trickles like normal, and different intel colors can trickle in different downward directions."

- A stack of intel of mixed colors on a single hex resolves per-color, per-die: each tile takes its color's die direction independently.
- After resolution, tiles of the same stack may end up on different hexes.

### 7.4 End-of-turn cleanup (`end_of_turn_cleanup`)
[DERIVED + D-06a + D-07]

Run in this order at the end of every turn:
1. **Pin expiration**: for every agent with `pinned_until != null`: if the player whose turn just ended is the agent's owner (`agent.owner == active_player`), AND the pin was applied on a previous turn (`pinned_until <= turn_id`), set `pinned_until = null`. [D-06a]
2. **Blockade expiration**: for every blockade with `state == 'on_board'`: if the player whose turn just ended is the blockade's **opponent** (`blockade.owner != active_player`), AND the blockade was placed on a previous turn (`blockade.placed_on_turn < turn_id`), wait — re-read [D-07].

   [D-07] says: "Blockades expire at end of the *opponent's* next turn." Concretely: if `blockade.placed_on_turn = T` by player `P`, the blockade clears at the end of `P_opponent`'s turn following turn `T`. That is, at end-of-turn cleanup of turn `T+1` if `T` was P's turn. **Generalized rule**: clear blockade at end-of-turn cleanup where `(active_player.id != blockade.owner) AND (turn_id > blockade.placed_on_turn)`.

   On clear: set `blockade.state = 'expired'`; remove from `board_state.blockade_on_hex`; the physical token returns to `blockade.owner`'s available supply.
3. **Reset per-turn flags**: `smuggler_boost_used_this_turn = false`; for every Hacker on the board, `agent.hacker_pin_used_this_turn = false` and `agent.hacker_steal_used_this_turn = false`; `spawned_this_turn = 0`.
4. **Win check** (redundant — also fires inline in §6.5): if `active_player.score >= 20`, set `game_winner = active_player`.
5. **Depletion check** [D-17]: for each player P, if `len(P.agent_pool) + len(P.agents_on_board) == 0`, set `game_winner = opponent(P)`. (Active-player retire that empties their roster ends the game in opponent's favor.)

### 7.5 Interruptions
There are **no interrupts** — all actions resolve atomically; there are no reactive abilities. [DERIVED: rulebook contains no language about interrupts, reactions, or instant-speed abilities. Honeypot removal and over-capacity dump are server-fired triggers within other action effects, not opponent reactions.]

---

## 8. Win / Loss Conditions

### 8.1 Win condition
**The first player to reach a score of 20 points immediately wins the game.** [RB Gameplay: "The first player to reach 20 POINTS immediately WINS the game!"]

- Score is mutated in two places only: §6.5 (Retire Agent) and §6.12 (Analyst bonus, if "keep").
- Win check fires synchronously inside §6.5 effect 6 and §6.12 effect 2.
- Once `game_winner` is set, all subsequent actions are blocked; phase transitions to `game_end`.

### 8.2 Tie-breaker
[D-03]: **Active player wins** if both players would cross 20 in the same turn. In practice this is impossible because score only mutates on the active player's actions, but the rule is recorded for completeness.

### 8.3 Loss condition (secondary) — agent depletion [D-17]
A player loses immediately if **both** conditions hold simultaneously for that player:
- `len(agent_pool) == 0` (no agents in reserve), AND
- `len(agents_on_board) == 0` (no agents on the board).

Note: a player is **never forced to spawn**, so depletion can occur. The loss only triggers when both reserve and board are empty — having reserves but no on-board agents is not a loss (player may simply choose to spawn or pass).

**Trigger sites**: depletion check (§7.4 step 5) fires at end-of-turn cleanup. Additionally, §6.5 fires it inline after retirement (since retiring is the most direct path to depletion).

**Effect**: opponent is declared `game_winner`; transition to `game_end`.

**Edge case**: if both players become depleted in the same instant, the player whose turn just ended (i.e., the active player who triggered the cascade) loses; opponent wins. This follows from the trigger order in §7.4.

### 8.4 Game termination
The documented termination conditions:
1. A player reaches **≥20 points** → that player wins. [RB Gameplay]
2. A player has **0 agents in pool AND on board** → opponent wins. [D-17]

There is **no maximum turn limit**. [DERIVED: rulebook does not mention one.]

---

## 9. Edge Cases and Special Rules

### 9.1 Stacked intel on the same hex
[FAQ Movement of Intel point 1]: any number of intel tiles may share a hex. All trickle independently next turn. An agent moving onto or being trickled-onto picks up the entire stack.

### 9.2 Trickling off the board
[RB Trickle: "Trickle off the board"]: any intel whose trickle destination is outside the Field (off the bottom) immediately returns to bag (`state = 'returned_to_bag'`).

### 9.3 Over-capacity dump (the >3 rule)
[RB Trickle: "If an Agent possesses more than 3 pieces of Intel, immediately return all of that Agent's Intel to the Intel supply."], [FAQ Movement of Intel point 3]

- **Trigger**: any time an agent's `len(intel_held)` exceeds 3.
- **Effect**: every tile in `agent.intel_held` returns to bag (`state = 'returned_to_bag'`, `agent_id = null`). `agent.intel_held = []`. Agent itself remains on board (not removed).
- **Trigger sites**:
  - End of `trickle_resolve` (§7.2 step E).
  - End of `act_move_agent` (§6.3 effect 3).
  - End of `act_transfer_intel` (§6.4 effect 2).
  - End of `act_double_agent_transfer` (§6.10 effect 2).
  - End of `act_hacker_steal_intel` (§6.11.C effect 3).
  - End of `act_smuggler_swap_agents` (§6.8) — *not normally needed since intel travels with agents, but if game state was already at 4 due to a server bug, fire as defensive check.*

> **EDGE(O-01)** [generalized per F-10]: ordering of capacity-dump vs Honeypot-removal applies to **any pickup event** (trickle, move, transfer, swap, steal, double-agent transfer, or any future mechanic). The order is:
>
> 1. **Honeypot trigger fires FIRST**: if any incoming intel is a Honeypot, §9.4 fires — the agent is removed; held intel + the Honeypot return to bag. Over-capacity check on this agent is then a no-op (the agent is gone).
> 2. **Over-capacity check fires SECOND** (only if the agent survives step 1): if `len(intel_held) > 3`, dump every held tile to bag per §9.3.
>
> This canonical ordering matches [FAQ Movement of Intel point 3] for trickle resolution: "Then any agents with honeypots are removed from the game ... Then all intel belonging to agents with over three intel return their intel to the supply." It generalizes to all action-phase triggers as well [F-10].
>
> **Action-phase note**: per [D-21] (universal pickup invariant) and §9.4 (Honeypots cannot be held by a surviving agent), Honeypots can be involved in action-phase pickups (e.g., Move onto a hex with a loose Honeypot per [D-05b], or a Smuggler swap creating co-occupation per [D-21]). In every such case the Honeypot-first / dump-second ordering applies.

### 9.4 Honeypot resolution
[RB Trickle: "If an agent gains possession of a 'Honeypot' intel, permanently remove the agent from the game and return the Honeypot to the intel supply."]

- **Trigger**: **any pickup event** where an agent gains possession of a Honeypot tile, regardless of mechanism — trickle resolution, Move (§6.3 / [D-05b]), Smuggler swap creating co-occupation (§6.8 / [D-21]), Transfer (§6.4), Steal (§6.11.C), Double-Agent transfer (§6.10), or any future mechanic. Per [D-21] the universal pickup invariant means a Honeypot can never be loose on a hex with an agent at rest; the trigger fires at the moment of co-occupation. Per [D-05b], this explicitly includes Action-phase movement.
- **Effect**:
  1. Remove agent from board: `agent.state = 'removed'`, `agent.hex = null`. Update `board_state.agent_on_hex`.
  2. Per [D-10b], the agent is **permanently removed** from the pool — it does not return.
  3. Return all of `agent.intel_held` to the bag, **including the Honeypot itself**. [RB Trickle: "return the Honeypot to the intel supply"]
  4. Set `agent.intel_held = []`.
- **Note**: a Honeypot can never be held by a surviving agent. The held-intel state is illegal for `intel_tile.type == 'honeypot'` and the server must invariant-check.

### 9.5 Pinned agents
[RB Hacker]: "a pinned agent cannot move, retire, or switch out"
[FAQ Agents point 3]: "When an agent is pinned, its abilities can still be used."
[FAQ Agents point 4]: "Smugglers cannot swap pinned agents."

A pinned agent:
- **Cannot** move (`act_move_agent` illegal).
- **Cannot** retire (`act_retire_agent` illegal).
- **Cannot** be swapped (`act_smuggler_swap_agents` illegal as either swap target).
- **CAN** use abilities. (Pinned Engineer can place blockades; pinned Hacker can pin/steal; pinned Smuggler can boost; pinned Comms can move loose intel; pinned Double Agent can transfer; pinned agent can also receive intel transfers and donate intel via §6.4 — see AMBIGUITY(P-01) §13.)
- **Can** be unpinned by an adjacent friendly Hacker (§6.11.B).
- Pin clears at end-of-turn cleanup of the **pinned player's** following turn (§7.4).
- Max one pin per agent. [D-06b]

### 9.6 Blockade interactions

#### 9.6.A Blockade on an empty hex
- Prevents agents from moving onto the hex (§6.3 precondition).
- Prevents loose intel from being placed by Comms moves (§6.9 precondition).
- Causes trickle redirection (see 9.6.C).

#### 9.6.B Blockade placed on a hex with intel
[FAQ Agents point 5]: "Engineers may place blockades on spaces that have intel. Blockades that are placed on spaces with intel prevent that intel from trickling down and other intel may not move into a blockaded space."

- The intel on a blockaded hex is **frozen**: it does not trickle, and other intel cannot enter the hex (so cannot stack onto it).
- When/if the blockade expires (§7.4), the intel resumes normal trickling on subsequent turns.

#### 9.6.C Trickle redirection
[RB Trickle: "Trickling onto a blockade"]: "If a Blockade is in the way of a trickling Intel, redirect the intel towards the open direction."

For a tile T trickling from hex `H` in direction `D` (`SW` or `SE`), let `D'` be the *other* diagonal (`SE` if `D == SW`, else `SW`).

**Resolution precedence per [D-24]** (matches §7.2 step B):
1. If `D(H)` is blockaded:
   - If `D'(H)` is also blockaded → tile does **not** trickle this turn (`no_move`). [RB Trickle: "If a blockade is present in both of the spaces below an intel **or on an inlet**, intel does not trickle that turn."]
   - Else if `D'(H)` is off the Field → **tile returns to bag per §9.2** [D-24]. The redirect succeeded; it just terminated off-board.
   - Else → tile redirects to `D'(H)`.
2. Else if `D(H)` is off the Field → tile returns to bag per §9.2.
3. Else → tile moves to `D(H)`.

> **[D-24] resolution**: when the redirect target is off-board, the canonical behavior is **return to bag**, not "stay on hex." The "tile stays" outcome applies only when **both diagonals are blockaded** (the §13 B-02 inlet case is preserved for that specific scenario).

> **NOTE on "or on an inlet"**: the rulebook text "or on an inlet" remains the §13 B-02 default. An "inlet" is a Field hex with only one valid downward neighbor (an edge case at the side of the board); blockading the single available diagonal stops trickling. The off-board case [D-24] is distinct: redirect succeeded, but landed off-Field → §9.2 (return to bag).

#### 9.6.D Blockade-pair vertical block (FAQ-only rule)
[FAQ Agents point 7]: "If two adjacent spaces are blockaded, then intel directly above and between the two blockaded spaces does not trickle down. The same applies if a Comms Specialist attempts to move intel vertically, and both adjacent spaces in the direction of movement are blockaded."

- Define `pair_below(H)`: the two hexes `SW(H)` and `SE(H)`.
- If both `pair_below(H)` are blockaded, intel on `H` cannot trickle down OR be Comms-moved down. [Same logic for `pair_above(H)` = `NW(H), NE(H)` for upward Comms moves.]
- This is the "blockade pair" rule and is the **same** as §9.6.C "both diagonals blockaded." It is restated in the FAQ as an edge case of the redirection rule.

### 9.7 Intel-color → die-color mapping
Each die's color matches one Intel type's color exactly. [RB Trickle step 3, RB Contents] Implementer must establish the canonical 6-way mapping during asset audit (see TODO(I-01)).

### 9.8 Spawn-row hex requirements
A spawn-row hex (`✦`) is valid for spawning iff:
- No agent on the hex.
- No loose intel on the hex.
- No blockade on the hex.
[RB Spawn]

### 9.9 Retirement-hex requirements
Retire is legal iff `agent.hex` is a `✦` hex, regardless of whether the hex contains intel (intel is held by the agent itself, not loose). [RB Retire Agent]

### 9.10 Concurrent abilities
A player may use **any number** of abilities in their turn (§5.3, [RB Take Actions: "A player can use any number of abilities..."]) subject to:
- Resource constraints (intel and/or actions).
- Per-Hacker per-turn caps on pin/unpin (one slot, shared) and steal (separate slot). [D-15]
- Per-turn cap on Smuggler boost (one per turn per player, [D-08]).

### 9.11 Conflict resolution priority

When multiple effects fire from a single triggering event, resolve in this order [DERIVED]:
1. Direct rule-text effects (e.g., the move itself).
2. Honeypot possession check.
3. Over-capacity dump check.
4. Win check.

### 9.12 Self-targeting and degenerate inputs
- A player may not transfer intel from agent X to agent X (§6.4 inputs require distinct agents).
- A player may not swap an agent with itself (§6.8 requires `agent_a != agent_b`).
- A Hacker may not pin its own agents (§6.11.A precondition `target.owner != active_player`).
- A Hacker may not unpin enemy agents (§6.11.B precondition).
- Comms Specialist may not pay with the intel it is moving (§6.9.B).

---

## 10. Hidden Information Handling

### 10.1 Bag
- The composition of the bag (which tiles remain undrawn) is **hidden from all players**.
- The bag is a server-side multiset.
- Drawing is uniform random without replacement.
- Returns to the bag (over-capacity, off-board, Honeypot triggers, retirement non-scored intel) re-mix the tile back into the multiset.

### 10.2 Tile identity
Each `intel_tile.id` is a unique server-side identifier. Tiles in the bag have `state = 'in_bag'`, but their `type` is **only visible to the server** until drawn.

When a tile is drawn (placed at top-left or top-right in `trickle_draw_*`), the type becomes public (the tile is shown face-up on the board).

### 10.3 Score
Public always. [D-11]

### 10.4 Held intel
Public always. [DERIVED, §3.7] — required for opponent decision-making in pin/swap/steal targeting.

### 10.5 Dice rolls
Public for the duration of the trickle phase. After `end_of_turn_cleanup` the dice are reset.

### 10.6 Per-turn ability flags
(`smuggler_boost_used_this_turn`, etc.) are public to support legal-action prediction by both players. [DERIVED]

### 10.7 Spectators
All public state is visible to spectators. The bag is hidden from spectators (same as players). [DERIVED + D-11 norm]

---

## 11. Determinism and Randomness

### 11.1 Sources of randomness
1. **Bag draws**: `trickle_draw_left` and `trickle_draw_right` (§5.1 steps 1–2).
2. **Dice rolls**: `trickle_roll` (§5.1 step 3) — 6 independent rolls per turn.
3. **Analyst bonus draw**: §6.12 effect 1.
4. **Setup**: shuffle of bag contents at game start (§4 step 1).

All four use uniform random selection without replacement (for bag) or independent uniform 1-of-6 (for dice).

### 11.2 RNG implementation requirement
[DERIVED: BGA security model] All RNG must use the platform-provided `bga_rand` (server-side, cryptographically seeded). MySQL `RAND()`, PHP `mt_rand`, etc. are forbidden. Replays must reproduce identically from the seed.

### 11.3 Deterministic outcomes
Given a fixed seed and identical action inputs, the entire game is deterministic. There are no further hidden state mutations.

### 11.4 Outcome resolution timing
- Bag draws resolve at the moment the action fires (server reveals the tile).
- Dice rolls all resolve in one batch in `trickle_roll`; their outcomes are then applied in `trickle_resolve`.
- The order of dice rolls within `trickle_roll` is irrelevant since they are independent.

---

## 12. Constraints for Digital Implementation

### 12.1 Server-validated rules
Every action in §6 has a precondition list. The server **must** validate every precondition before applying effects. Specifically:

- **Phase guards**: action only legal in stated phase.
- **Ownership**: agents/intel/blockades referenced match `active_player`'s ownership where required.
- **Adjacency**: `act_move_agent`, `act_transfer_intel`, `act_engineer_place_blockade_adjacent`, `act_hacker_pin`, `act_hacker_unpin` require adjacency.
- **Resource availability**: `actions_remaining > 0` for action-cost actions; `intel_tile_id ∈ agent.intel_held` for intel-cost actions.
- **Spawn cap**: `len(active_player.agents_on_board) < 3` before spawning.
- **Pin cap**: target agent not already pinned.
- **Blockade cap**: `len(active_player.blockades_on_board) < 3`.
- **Per-turn ability caps**: Smuggler boost flag, Hacker pin flag, Hacker steal flag.
- **Pinned constraints**: pinned agents cannot move/retire/be-swapped.

Failure → server rejects the action with an error code; **no state mutation** occurs.

### 12.2 UI-supported requirements
- **Action counter**: visible "X/3" or "X/4" tracker; updates every action.
- **Intel-on-agent display**: each agent on board shows count and types of held intel (e.g., colored chips on agent token).
- **Pin marker**: pinned agents visually marked.
- **Blockade marker**: blockaded hexes visually marked with the owning player's color.
- **Bottom-row spawn indicator**: `✦` symbol visible on bottom hexes.
- **Field shading**: purple Field hexes visually distinguishable from non-Field areas.
- **Dice display**: 6 dice with current roll outcomes (or arrow indicators for direction) during trickle phase.
- **Score track**: visible 0–20 progress for both players.
- **Turn indicator**: which player is active; current phase.
- **Highlighting**: legal-target hexes highlighted on hover when an action is mid-input (e.g., after the player picks an agent to move, all legal `target_hex` candidates are highlighted).
- **Tooltip help**: each agent type has a tooltip explaining its abilities.

### 12.3 Easily-misimplemented rules (call-outs)
1. **Honeypot trigger generality** — must fire on ANY possession gain (trickle, move, transfer, swap-derived possession), not only trickle.
2. **Trickle simultaneity** — must compute all destinations before applying any move.
3. **Capacity-dump ordering** — honeypot removal before over-capacity dump in the trickle phase.
4. **Comms cost asymmetry** — UP is 1 Action; DOWN is 1 Intel + 1 Action.
5. **Engineer remote placement** — costs 1 Intel and **NO Action**.
6. **Hacker steal** — costs 1 Intel and **NO Action** (intel-only ability); per-Hacker once per turn [D-15].
7. **Hacker pin/unpin** — share one slot per Hacker per turn [D-15]. Hacker steal is a separate slot.
8. **Smuggler boost** — costs 1 Intel and **NO Action**, but bumps action cap to 4. Per-player once per turn [D-08].
9. **Blockade & pin expiration timing** — at end of opponent's next turn (not arbitrary turn count).
10. **Retire is FREE** — does not consume an action point.
11. **Retire scores ALL held intel** [D-14], not just one. Sum the score values.
12. **Same-turn-spawn retire prohibition** — must check `spawned_on_turn != turn_id`.
13. **Pinned agents can still use abilities** — only movement/retire/swap is blocked.
14. **Held intel travels** — Move and Smuggler swap both carry intel with the agent.
15. **Held intel doesn't trickle** — only loose intel trickles. [RB Trickle step 3: "move all pieces of intel not already possessed by an agent"]
16. **Comms moves loose intel only** — never held intel.
17. **Double Agent has no adjacency** — any agent in play, anywhere on the board.
18. **Non-uniform intel scoring** [D-19] — Security Credential = 3, State Secret = 4, others = 2 (Honeypot = 0, never scored).
19. **Agent depletion is a loss condition** [D-17] — must check `pool + on_board == 0` after every removal.
20. **Empty-bag draws are no-ops** [D-18] — never block phase progression.

---

## 13. Ambiguities / Open Questions

### Resolved (record only)

- ✅ **F-01** → [D-16]: random first player.
- ✅ **B-01** → [D-18]: empty bag → skip draw, continue normally.
- ✅ **A-01** → [D-18]: empty bag during Analyst bonus → bonus forfeited, retire continues.
- ✅ **A-02**: "Keep" in Analyst bonus = score it. [§6.12 effect 3]
- ✅ **I-01** → [D-19]: color → intel-type mapping fixed (gray=Honeypot, brown=Industrial Tech, purple=Leaked Email, green=Blackmail, yellow=Security Credential, cyan=State Secret).
- ✅ **I-03** → [D-19]: score values are non-uniform (0/2/2/2/3/4).
- ✅ **H-01** → [D-15]: Hacker abilities per-Hacker once-per-turn (not per-player).
- ✅ **R-01** → [D-14]: retire scores **all** held intel, not just one.
- ✅ **D-10c** → [D-17]: player loses if `agent_pool + agents_on_board == 0`.

### Open (default-locked; not blocking)

These items have proposed defaults the implementation can use; owner may revise during validation.

#### B-02 — "or on an inlet" — partially resolved per [D-24]
[RB Trickle]: "If a blockade is present in both of the spaces below an intel **or on an inlet**, intel does not trickle that turn." The phrase "or on an inlet" is grammatically unclear.

**Default for the inlet (both-diagonals-blocked) case**: an "inlet" is a Field-edge hex where only one downward diagonal is in the Field (the other is off-board). Blockading the single available diagonal stops trickling for that hex. Locked unless owner overrides.

**Off-board redirect case resolved per [D-24]**: when one diagonal is blockaded and the redirect target is off the Field, the tile returns to bag per §9.2 (NOT "stays on hex"). The "tile stays" semantics apply only when both diagonals are blockaded (the inlet case above).

#### I-02 — Per-type intel counts
47 total intel distributed across 6 types. The per-type distribution is not stated in any rulebook source. **Action**: asset audit pass must read punchboard PSDs. Implementer should treat per-type counts as a config constant pending audit.

#### I-04 — Honeypot held by an agent (invariant, not ambiguity)
Honeypots cannot be held by an agent because possession triggers immediate removal (§9.4). Server must invariant-check that no `intel_tile` with `type == 'honeypot'` is ever in `state == 'on_agent'`. Listed for completeness.

#### S-01 — Smuggler swapping itself
Rulebook does not forbid a Smuggler swapping itself with another agent. **Default**: legal.

#### C-01 — Comms vertical move + blockade-pair (resolved)
For upward Comms move, if both `NW(source)` and `NE(source)` are blockaded, the move is illegal. Confirmed in §6.9 preconditions and §9.6.D.

#### C-02 — Comms moving intel off the bottom
**Default**: illegal. `target_hex` must be a valid hex within the Field.

#### H-02 — Hacker steal adjacency
Rulebook silent on whether Hacker must be adjacent to pinned target. **Default**: no adjacency requirement (steal is remote).

#### P-01 — Pinned agent giving intel
**Default**: legal. `act_transfer_intel` with a pinned source is allowed because the source agent does not move; only the intel moves. Pinned agents may also be the *target* of transfers (intel arrives at them).

#### G-01 — Hex orientation
Inferred pointy-top from rulebook's "down and to the left/right" language. **Action**: confirm from `game_board_print.png` during asset audit. If flat-top, all directional rules shift by 30°.

#### G-02 — Field shape and exact hex count
Total hex count of the Field and row layout needed for state-table sizing. **Action**: asset audit pass on `game_board_print.png`.

#### B-01-rev — Bag empty AND tiles permanently scored
With [D-14] (retire scores all held intel), tiles can leave the system permanently (`state = 'scored'`). Combined with [D-18] (empty bag → no-op), it is theoretically possible for the bag and on-board intel to both run out, leaving no scoring potential. The depletion-via-no-intel case is **not** itself a loss condition; only [D-17] agent depletion ends the game. If neither player can reach 20 due to scarcity, the game can stall. **AMBIGUITY**: should there be a stall-detection rule? **Default**: no — game continues until [D-17] or 20-point trigger fires; in practice agent depletion will resolve it.

---

## 14. Example Game Flow

This walkthrough demonstrates one full turn from a mid-game state. Coordinates are abstract (`hex(row, col)` with `row=0` at the bottom). Field is assumed to span `row 0..6`.

### State before turn 7 (P1 active)

```
turn_id = 7
active_player = P1
P1.score = 8
P2.score = 6

# Agents on board
agent_1 (P1, hacker)        on hex(2, 3)  intel_held=[]                 not pinned
agent_2 (P1, comms)         on hex(0, 4)  intel_held=[]                 not pinned   spawned_on_turn=7  ← cannot retire this turn
agent_3 (P1, engineer)      on hex(3, 2)  intel_held=[secret_t1]        not pinned
agent_5 (P2, smuggler)      on hex(2, 4)  intel_held=[creds_t1, blackmail_t1]   not pinned
agent_6 (P2, double_agent)  on hex(1, 1)  intel_held=[]                 not pinned

# Loose intel
hex(4, 3): [leaked_email_t1]  (color: green)
hex(5, 2): [tech_t1]          (color: brown)
hex(3, 4): [honeypot_t1]      (color: gray)

# Blockades
P2 owns blockade at hex(2, 5)  placed_on_turn=6  → expires at end of P1.turn(7) cleanup

# Per-turn flags
smuggler_boost_used = false
agent_1.hacker_pin_used_this_turn = false        # Hacker (P1) on hex(2,3)
agent_1.hacker_steal_used_this_turn = false
spawned_this_turn = 0
actions_remaining = 0  (not in actions phase yet)

# Bag has 30 tiles remaining; types hidden
```

### Phase 1 — Trickle (turn 7, P1)

**Step 1 (`trickle_draw_left`)**: server draws `creds_t2` (color: cyan) from bag. Place on top-left = `hex(6, 0)`.

State change: `creds_t2.state = 'on_board'`, `creds_t2.hex = hex(6, 0)`. Bag size 29.

**Step 2 (`trickle_draw_right`)**: server draws `blackmail_t2` (color: yellow). Place on top-right = `hex(6, 6)`.

Bag size 28.

**Step 3 (`trickle_roll`)**: dice produce:
- gray = odd → SW
- brown = even → SE
- green = odd → SW
- purple = even → SE
- yellow = odd → SW
- cyan = even → SE

**Step 4 (`trickle_resolve`)**:

Loose intel and their pre-redirect targets:
- `leaked_email_t1` (green) at `hex(4, 3)` → SW → `hex(3, 3)` (empty)
- `tech_t1` (brown) at `hex(5, 2)` → SE → `hex(4, 3)` (empty after `leaked_email_t1` leaves)
- `honeypot_t1` (gray) at `hex(3, 4)` → SW → `hex(2, 4)` (occupied by `agent_5`!)
- `creds_t2` (cyan) at `hex(6, 0)` → SE → `hex(5, 1)` (empty)
- `blackmail_t2` (yellow) at `hex(6, 6)` → SW → `hex(5, 6)` (empty)

Blockade check: `hex(2, 5)` has a blockade, but no targets above it produce trickle into it this turn. No redirection needed.

Apply moves simultaneously. Now resolve agent possession:
- `agent_5` (P2) on `hex(2, 4)` receives `honeypot_t1`. **Honeypot trigger** fires (§9.4):
  - `agent_5.state = 'removed'`. Update `board_state.agent_on_hex[hex(2, 4)] = null`.
  - All of `agent_5.intel_held = [creds_t1, blackmail_t1]` → bag. `agent_5.intel_held = []`.
  - `honeypot_t1.state = 'returned_to_bag'`.
  - Bag size 28 + 3 (creds, blackmail, honeypot) = 31. (Then minus the 2 drawn earlier = 29 effective.)
- No other agent receives intel this turn.

Capacity-dump check: no agent has >3 intel. No-op.

Phase 1 complete.

### Phase 2 — Spawn

P1 has 3 agents on the board (`agent_1`, `agent_2`, `agent_3`). `len(P1.agents_on_board) == 3`, so spawn cap is reached. **No spawns possible.**

P1 fires `act_pass_spawn` (or server auto-fires).

Phase 2 complete. `actions_remaining = 3`.

### Phase 3 — Take Actions

**Action 1**: P1 fires `act_move_agent(agent_1, hex(2, 4))`.
- Preconditions: `agent_1.hex = hex(2, 3)`; `hex(2, 4)` is adjacent (E-neighbor); empty (after Honeypot resolution); in Field. Pass.
- Effect: `agent_1.hex = hex(2, 4)`. No intel pickup (hex empty post-Honeypot).
- `actions_remaining = 2`.

**Action 2**: P1 fires `act_move_agent(agent_3, hex(4, 3))`.
- Preconditions: `agent_3.hex = hex(3, 2)`; `hex(4, 3)` is adjacent (NE); has loose intel `tech_t1`; no agent or blockade. Pass.
- Effect: `agent_3.hex = hex(4, 3)`. Pickup: `tech_t1.state = 'on_agent'`, `tech_t1.agent_id = agent_3.id`. `agent_3.intel_held = [secret_t1, tech_t1]`.
- Capacity check: 2 ≤ 3. No-op.
- `actions_remaining = 1`.

**Action 3**: P1 fires `act_engineer_place_blockade_adjacent(agent_3, hex(5, 3))`.
- Preconditions: `agent_3.hex = hex(4, 3)`; `hex(5, 3)` is adjacent (NE); empty; P1 has 0 blockades on board; in Field. Pass.
- Effect: new blockade `blockade_2 (P1, hex(5, 3), placed_on_turn=7)`.
- `actions_remaining = 0`.

P1 has no actions remaining and no intel-only ability options matter (no agents have unused intel for Engineer-far or similar). Server auto-fires `act_pass_actions`.

### Phase 4 — End-of-turn cleanup

1. Pin expiration: no pinned P1 agents (it's P1's turn ending, so we'd clear pins on P1 agents; none exist).
2. Blockade expiration: P2's `blockade_1` at `hex(2, 5)` placed_on_turn=6. Owner=P2, active_player ending turn=P1. P1 is opponent of P2; condition `(active_player != blockade.owner) AND (turn_id > placed_on_turn)` = `(P1 != P2) AND (7 > 6)` = true → expire. `blockade_1.state = 'expired'`. Token returns to P2's pool.
3. Reset per-turn flags.
4. Win check: P1.score = 8 < 20. No win.

### Turn passes to P2; turn_id = 8.

---

## Final-Check Verification

- ✅ Every action in §6 has explicit preconditions, inputs, effects, postconditions, and failure conditions.
- ✅ Every phase transition in §5.5 is documented.
- ✅ Win condition and tie-breaker are explicit (§8).
- ✅ Loss condition has a TODO with default proposal (§8.3).
- ✅ Every FAQ point is incorporated:
  - Stacking: §3.5, §9.1
  - Trickling off-board: §9.2
  - Simultaneous trickle: §7.2 step C
  - Honeypot removal generality: §9.4
  - Over-capacity ordering vs Honeypot: §9.3 EDGE(O-01)
  - Bottom-up bookkeeping note: §7.2 DERIVED note
  - Comms direction freedom: §6.9
  - Pinned ability use: §9.5
  - Smuggler vs pinned: §6.8
  - Engineer & intel: §6.6, §9.6.B
  - Comms & blockade: §6.9.A precondition, §9.6.D
  - Blockade-pair vertical block: §9.6.D
- ✅ Hidden-info handling addressed (§10).
- ✅ Randomness sources enumerated (§11).
- ✅ Ambiguities listed without invention (§13).
- ✅ Example game flow shows full turn including Honeypot trigger (§14).
- ✅ Owner has resolved blocking ambiguities via D-14 through D-19 (consolidated in [DECISIONS.md](docs/DECISIONS.md)):
  - **D-14**: retire scores all held intel.
  - **D-15**: Hacker abilities per-Hacker once-per-turn.
  - **D-16**: random first player.
  - **D-17**: agent depletion is a loss condition.
  - **D-18**: empty-bag draws are no-ops.
  - **D-19**: intel color/value mapping (non-uniform: 0/2/2/2/3/4).
- ⚠️ Remaining open items have **default-locked** behavior in this spec (B-02, I-02, I-04, S-01, C-02, H-02, P-01, G-01, G-02, B-01-rev). Implementer should follow the defaults; owner may revise during validation phase. None block implementation.

End of rulebook.
