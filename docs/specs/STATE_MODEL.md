# Hexpionage — Game State Model (A4 Output)

> **Purpose**: canonical backend representation of the game state. Defines DDL, hex coordinate scheme, public/private visibility, `getAllDatas()` shape, derived state, persistence rules, initial-state population, and round-trip rule validation.
>
> **Scope**: server-side state only. State-machine transitions are A5's job (see `docs/specs/STATE_MACHINE.md`); UI is A6's job (see `docs/specs/UI_SPEC.md`).
>
> **Source-of-truth dependency order**: `rulebook.md` → `DECISIONS.md` → this doc. All citations inline as `§N.N` (rulebook), `[D-NN]` (decisions), `TODO(X-NN)` (open items).
>
> **BGA conventions**: InnoDB engine, utf8mb4 charset, every CREATE uses `IF NOT EXISTS`, integer enums (no string types), explicit primary keys, indexes on hot query columns, game-state globals via `bga->globals` (JSON), per-row mutations via `DbQuery`.

---

## 1. Logical Entity Model

The persistent state divides into **5 logical entities** plus a small set of **game-state globals**. The rulebook §3 names these explicitly: agent, intel tile, blockade, pin (modeled as a column on agent per [D-06b]), and game globals. We add the BGA-required `player` table extensions.

### 1.1 Entity inventory

| Entity | Storage | Row count at game start | Lifecycle |
|---|---|---|---|
| `player` | DB table (BGA-provided, extended via `ALTER TABLE`) | 2 | Persists for game duration. |
| `agent` | DB table | 24 (12 per player, [D-10b]) | Created in `setupNewGame()`. Never deleted. State column transitions `in_pool → on_board → removed`. Removed is permanent ([D-10b]). |
| `intel_tile` | DB table | 47 (per §2.4 / [TODO I-02]) | Created in `setupNewGame()`. Never deleted. State cycles `in_bag → on_board → on_agent → (scored \| returned_to_bag)`. `returned_to_bag` re-cycles to `in_bag` on next observation. |
| `blockade` | DB table | 0 | Created on Engineer placement (§6.6). State `on_board → expired`; once expired, the row remains for audit but is excluded from board queries. The owner's "available token" count is derived (3 − `COUNT(state='on_board')`), per [D-04]+[D-07]. |
| Pin | Modeled as `agent.pinned_until_turn` column | n/a | Per [D-06b], at most one pin per agent — no separate row. |
| Game globals | `bga->globals` (JSON) | n/a | Phase, turn_id, active_player, dice_state, actions_remaining, smuggler_boost_used_this_turn, spawned_this_turn, game_winner. |

### 1.2 Entity-relationship sketch

```
                  +-------------+         +------------------+
                  |   player    |<--+  +->|     blockade     |
                  +-------------+   |  |  +------------------+
                  | id (PK)     |   |  |  | id (PK)          |
                  | score       |   |  |  | owner FK player  |
                  | agents_remaining|  |  | hex_q, hex_r     |
                  | blockades_remaining||  | placed_on_turn   |
                  +------+------+   |  |  | state            |
                         |          |  |  +------------------+
                         |  owns    |  |
                         |          |  |
                         v          |  |
                  +------+------+   |  |     +-----------------+
                  |    agent    +---+  +-----+   intel_tile    |
                  +-------------+    holds   +-----------------+
                  | id (PK)     |<-----------+ id (PK)         |
                  | owner FK    |            | type            |
                  | type        |            | score_value     |
                  | state       |            | state           |
                  | hex_q, hex_r|            | hex_q, hex_r    |
                  | pinned_until_turn        | agent_id (FK)   |
                  | spawned_on_turn          | scored_by (FK)  |
                  | hacker_pin_used_this_turn| stack_order     |
                  | hacker_steal_used_this_turn               |
                  +-------------+            +-----------------+
                                              ^
                                              | (loose, on board: agent_id NULL,
                                              |  hex_q/r non-null)

   bga->globals (JSON, no schema):
     phase, turn_id, active_player, dice_state, actions_remaining,
     smuggler_boost_used_this_turn, spawned_this_turn, game_winner
```

Relations:
- `agent.owner → player.id` (24 rows fan-in to 2 players).
- `blockade.owner → player.id`.
- `intel_tile.agent_id → agent.id` (NULL when `state ≠ 'on_agent'`).
- `intel_tile.scored_by → player.id` (NULL except when `state = 'scored'`).
- Hex addressing: `(hex_q, hex_r)` axial coordinates (see §3 below). Both `agent` and `intel_tile` (and `blockade`) carry `(hex_q, hex_r)`. Both columns NULL when the entity is off-board.

Invariants (asserted server-side; see §6 derived state and §9 round-trip queries):
- At most one `agent` with `state='on_board'` per `(hex_q, hex_r)`. [§3.3 board_state.agent_on_hex single-valued]
- At most one `blockade` with `state='on_board'` per `(hex_q, hex_r)`. [§3.3]
- `intel_tile`: exactly one of `(hex_q, hex_r)`, `agent_id`, `scored_by` is non-null, matching the `state` value. [§3.4]
- `intel_tile.type='honeypot'` is **never** in `state='on_agent'`. [§9.4, EDGE I-04]
- For each player: `agent_pool_size + agents_on_board_size + agents_removed_size = 12`. [§4 setup; D-10b]
- For each player: `blockades_on_board_size ≤ 3`. [D-04]+[D-07]
- Sum over `intel_tile` of one-of states equals 47. [§2.4 / TODO I-02]

---

## 2. DDL — `dbmodel.sql`

Every table uses InnoDB and utf8mb4 per BGA Studio's standard. Integer enums are documented inline; the application layer maps these to symbolic names defined in `material.inc.php` (A7 owns that file). Indexes are added to every column queried in the hot path (owner, hex coordinates, type, state).

### 2.1 `player` extensions

BGA provides `player` automatically. We extend it with three game-specific integer columns. `score` would normally use `player_score` from BGA, but we map it through here for clarity and to centralize the schema; A7 may collapse this to BGA's built-in `player_score` column at implementation time without changing this design.

```sql
-- Extend BGA's auto-generated `player` table with Hexpionage columns.
-- player_score is BGA-provided (INT). We additionally track agent and blockade
-- supply-pool sizes for fast access (denormalized; can be re-derived from
-- COUNT(*) over the agent / blockade tables).

ALTER TABLE `player`
    ADD COLUMN `agents_remaining`     TINYINT UNSIGNED NOT NULL DEFAULT 12  COMMENT 'Count of agents in state in_pool. 0..12. [D-10b]',
    ADD COLUMN `blockades_remaining`  TINYINT UNSIGNED NOT NULL DEFAULT 3   COMMENT 'Count of available blockade tokens off-board. 0..3. [D-04]+[D-07]';

-- Note: per [D-11], score is public. BGA's player_score is public by default.
```

#### 2.1.1 `agents_remaining` mutation contract [F-03]

`agents_remaining` is denormalized from the agent table for fast spawn-cap and depletion checks. The canonical source of truth is `COUNT(agent WHERE owner = P AND state = 'in_pool')`, but the column on `player` mirrors that count.

**Mutation rules** (per [D-10b]):

| Trigger | Effect on `agents_remaining` |
|---|---|
| `act_spawn_agent` (§6.1) succeeds | Decrement by 1 (agent leaves `in_pool` → `on_board`). |
| `act_retire_agent` (§6.5) succeeds | **No change** — the agent transitions `on_board` → `removed`, NOT back to `in_pool` per [D-10b] ("once removed, an agent is gone for good"). |
| Honeypot removal (§9.4 / [D-05b]) | **No change** — agent transitions `on_board` → `removed`. |
| Over-capacity dump (§9.3) | **No change** — agent stays `on_board`; only `intel_held` is mutated. |
| End-of-turn cleanup (§7.4) | **No change** — pin/blockade expiry does not touch `agents_remaining`. |
| Setup (`setupNewGame()`) | Initial value: 12 (per [D-10b]). |

**Critical**: `agents_remaining` is NEVER incremented at runtime. Once decremented, the count stays at the new (lower) value. This is the agent-depletion mechanic that drives [D-17]'s loss condition.

**Source-of-truth handling**: when in doubt, prefer the count query. The denormalized column is a hot-path convenience; A7 must keep them in sync inside the same transaction as the underlying agent state mutation. A defensive sanity check at end-of-turn cleanup may compare the two and abort on mismatch.

> **DERIVED implication**: per [D-17] depletion loss check, `player.has_lost := (agents_remaining == 0 AND total_agents_on_board == 0)`. The denormalized column makes this O(1).

### 2.2 `agent`

```sql
CREATE TABLE IF NOT EXISTS `agent` (
    `id`                          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `owner`                       INT(10)  UNSIGNED NOT NULL                COMMENT 'FK player.player_id',
    `type_id`                     TINYINT  UNSIGNED NOT NULL                COMMENT 'Integer enum: 1=comms_specialist, 2=analyst, 3=smuggler, 4=engineer, 5=hacker, 6=double_agent. See material.inc.php. [§2.2, D-01]',
    `state`                       TINYINT  UNSIGNED NOT NULL DEFAULT 0      COMMENT 'Integer enum: 0=in_pool, 1=on_board, 2=removed. [§2.3, D-10b]',
    `hex_q`                       TINYINT  SIGNED   NULL DEFAULT NULL       COMMENT 'Axial q coord; NULL iff state<>on_board. [§3 of this doc]',
    `hex_r`                       TINYINT  SIGNED   NULL DEFAULT NULL       COMMENT 'Axial r coord; NULL iff state<>on_board.',
    `pinned_until_turn`           INT      UNSIGNED NULL DEFAULT NULL       COMMENT 'NULL = not pinned. Otherwise: turn_id at which the pin clears. Cleared at end_of_turn_cleanup of pinned player''s next turn. [§3.6, §6.10, D-06a, D-06b]',
    `spawned_on_turn`             INT      UNSIGNED NULL DEFAULT NULL       COMMENT 'turn_id of the spawn. Used to enforce "may not retire on turn of spawn." [§6.5, RB Retire Agent]',
    `hacker_pin_used_this_turn`   TINYINT  UNSIGNED NOT NULL DEFAULT 0      COMMENT 'Per-Hacker per-turn flag (only meaningful when type_id=5). 0/1. Reset in end_of_turn_cleanup. [D-15, §3.1, §7.4]',
    `hacker_steal_used_this_turn` TINYINT  UNSIGNED NOT NULL DEFAULT 0      COMMENT 'Per-Hacker per-turn flag (only meaningful when type_id=5). 0/1. Reset in end_of_turn_cleanup. [D-15]',
    PRIMARY KEY (`id`),
    KEY `idx_agent_owner_state`   (`owner`, `state`),
    KEY `idx_agent_hex`           (`hex_q`, `hex_r`),
    KEY `idx_agent_type`          (`type_id`),
    KEY `idx_agent_pinned`        (`pinned_until_turn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Agents — 24 rows total at setup, 12 per player. [§2.1 inventory, D-10b]';
```

Notes:
- `hex_q`/`hex_r` are signed because the axial origin sits at the center of the Field; coordinates may be negative (see §3).
- `idx_agent_owner_state` covers the most common query path: "get all on-board agents for player P" (used by spawn cap, depletion check, retire validation).
- `idx_agent_hex` covers "what is on hex (q, r)" lookups — used by every move/spawn/blockade precondition.
- The two `hacker_*_this_turn` columns are kept on the agent row per [D-15]: each Hacker has its own slots; per-player flags would lose the per-Hacker semantics. They are no-ops for non-Hacker agents (a tiny waste of space, but uniform schema beats `agent_extension` JOINs).

### 2.3 `intel_tile`

```sql
CREATE TABLE IF NOT EXISTS `intel_tile` (
    `id`           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type_id`      TINYINT  UNSIGNED NOT NULL                COMMENT 'Integer enum: 1=honeypot, 2=industrial_tech, 3=leaked_email, 4=blackmail, 5=security_credential, 6=state_secret. [§2.4, D-19]',
    `score_value`  TINYINT  UNSIGNED NOT NULL                COMMENT 'Denormalized for query speed. 0=honeypot, 2=industrial_tech/leaked_email/blackmail, 3=security_credential, 4=state_secret. [D-19]',
    `state`        TINYINT  UNSIGNED NOT NULL DEFAULT 0      COMMENT 'Integer enum: 0=in_bag, 1=on_board, 2=on_agent, 3=scored, 4=returned_to_bag. [§3.4]. Note: returned_to_bag is functionally equivalent to in_bag on next observation; kept distinct for animation/notification clarity.',
    `hex_q`        TINYINT  SIGNED   NULL DEFAULT NULL       COMMENT 'Axial q. Non-null iff state=on_board. [§3.4 invariant]',
    `hex_r`        TINYINT  SIGNED   NULL DEFAULT NULL       COMMENT 'Axial r. Non-null iff state=on_board.',
    `agent_id`     SMALLINT UNSIGNED NULL DEFAULT NULL       COMMENT 'FK agent.id. Non-null iff state=on_agent. [§3.4]',
    `scored_by`    INT(10)  UNSIGNED NULL DEFAULT NULL       COMMENT 'FK player.player_id. Non-null iff state=scored. [§3.4]',
    `stack_order`  TINYINT  UNSIGNED NOT NULL DEFAULT 0      COMMENT 'Visual stacking order on a hex when multiple loose tiles share a hex. 0..N. Used to render fan-out / counter badges per UI_SPEC. Order of Trickle arrival (turn,sub-step) is canonical. [§3.5, §9.1]',
    PRIMARY KEY (`id`),
    KEY `idx_intel_state`         (`state`),
    KEY `idx_intel_hex`           (`hex_q`, `hex_r`),
    KEY `idx_intel_agent`         (`agent_id`),
    KEY `idx_intel_scored_by`     (`scored_by`),
    KEY `idx_intel_type`          (`type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Intel tiles — 47 rows total at setup. [§2.4, TODO(I-02)]';
```

Notes:
- `score_value` is denormalized from `type_id` so retire-scoring sums in a single `SELECT SUM(score_value) WHERE agent_id = X`. The mapping is fixed per [D-19] and never mutates per-row.
- `stack_order` enables deterministic rendering of multi-tile stacks. Trickle resolution sets it on stack entry; Move Agent pickup ignores it (entire stack is taken).
- `state=4` (`returned_to_bag`) is operationally equivalent to `state=0` (`in_bag`). The distinct value lets the trickle resolver emit an animation-friendly notification ("intel returned to bag") without losing the original tile identity. After the cleanup sub-phase the row may be folded back to `state=0`, but A5 (state machine) decides whether to keep the distinction across phases.
- The four "location" columns (`hex_q`, `hex_r`, `agent_id`, `scored_by`) are mutually exclusive per the §3.4 invariant. Server enforcement is via the action handlers (no DB-level CHECK constraints because BGA's MySQL targets do not enforce CHECK in older versions).

### 2.4 `blockade`

```sql
CREATE TABLE IF NOT EXISTS `blockade` (
    `id`              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `owner`           INT(10)  UNSIGNED NOT NULL              COMMENT 'FK player.player_id. [§3.3]',
    `hex_q`           TINYINT  SIGNED   NOT NULL              COMMENT 'Axial q. (Non-NULL: blockades exist only on the board until expired.)',
    `hex_r`           TINYINT  SIGNED   NOT NULL              COMMENT 'Axial r.',
    `placed_on_turn`  INT      UNSIGNED NOT NULL              COMMENT 'turn_id at placement. Used to compute expiry per [D-07]: clears at end_of_turn_cleanup of opponent''s next turn following placement.',
    `state`           TINYINT  UNSIGNED NOT NULL DEFAULT 1    COMMENT 'Integer enum: 1=on_board, 2=expired. [§3.3, D-07]',
    PRIMARY KEY (`id`),
    KEY `idx_blockade_owner_state` (`owner`, `state`),
    KEY `idx_blockade_hex`         (`hex_q`, `hex_r`),
    KEY `idx_blockade_placed`      (`placed_on_turn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Blockades — created on placement; rows persist after expiry for audit. [§3.3, D-04, D-07]';
```

Notes:
- We do **not** delete expired blockade rows; we set `state=2` so an audit query can replay the game. The `idx_blockade_owner_state` covers the "active blockades for player P" lookup used by the `<3` cap precondition (§6.6).
- `placed_on_turn` plus `state` are sufficient to compute expiry: see §6.5 round-trip query #5 below.
- "Token returns to placer's pool" per [D-07]: this is purely visual — the count is derived from `3 - COUNT(state='on_board')` for the owner. The denormalized `player.blockades_remaining` is updated by the same handler that toggles `state=2`.

### 2.5 Game-state globals (`bga->globals`)

These are **not** DB columns. They are JSON-serialized via BGA's `globals` system (`$this->globals->set('phase', ...)`, `$this->globals->get('phase')`). The full keyspace:

| Key | Type | Domain | Source |
|---|---|---|---|
| `phase` | string | `'setup' \| 'trickle_draw_left' \| 'trickle_draw_right' \| 'trickle_roll' \| 'trickle_resolve' \| 'spawn' \| 'actions' \| 'end_of_turn_cleanup' \| 'game_end'` | §3.1 |
| `turn_id` | integer | 1..∞ | §3.1 |
| `active_player_id` | integer | `player.player_id` of one of the 2 players | §3.1 (canonical name `active_player`) |
| `dice_state` | object | `{ honeypot: 'odd'\|'even', industrial_tech: 'odd'\|'even', leaked_email: ..., blackmail: ..., security_credential: ..., state_secret: ... }` or `{}` between turns | §3.1, §5.1 step 3 |
| `actions_remaining` | integer | 0..4 | §3.1, §5.3, §6.7 (boost raises cap) |
| `smuggler_boost_used_this_turn` | bool | `true \| false` | §3.1, [D-08] |
| `spawned_this_turn` | integer | 0..3 (informational; cap enforced by §6.7) | §3.1 |
| `game_winner` | integer or null | `player.player_id \| null` | §3.1, §8 |

Why globals (not a table)? These values mutate every action; storing them in a table generates excess writes. BGA's `globals` JSON is expressly designed for game-wide singletons. Per §7 of this doc, all per-row mutations use `DbQuery`; `globals` writes use `$this->globals->set()`.

Trickle resolution wraps **all of phase 1** in a single transaction (per §7 below) so that `dice_state`, `intel_tile` updates, agent removals (Honeypot), and capacity dumps either all commit or all roll back as one unit.

---

## 3. Hex Coordinate Scheme

### 3.1 Decision: pointy-top axial `(q, r)` with origin at center

The rulebook's directional language ("down and to the left", "down and to the right", "up & left", "up & right") is canonical for pointy-top hexes [§ rulebook conventions, §6.9, §9.6]. Pointy-top is **assumed** until [TODO(G-01)] is resolved by inspection of `game_board_print.png`. If flat-top is confirmed, all directional names rotate by 30° but the coordinate scheme below remains structurally identical (only the neighbor function changes).

**Origin**: `(0, 0)` is the **center hex of the Field**. We choose center over top-left because:
1. The Field is roughly hexagonal-shaped per board art preview; centering minimizes max-magnitude coordinates.
2. The "spawn row" (bottom row, ✦ symbol) and "intel entry hexes" (top-left and top-right corners) are computed by lookup tables in `material.inc.php` rather than hard-coded coordinates.
3. Negative `q`/`r` are tolerated by `TINYINT SIGNED` (-128..127), which is more than the Field will ever need.

### 3.2 Field range

The exact `(q, r)` ranges are **TODO(G-02)**: the asset-audit pass must enumerate the Field's hexes from `game_board_print.png`. As a placeholder for the schema design and for tests, we use the example walkthrough (§14 of rulebook) which references `row 0..6` with column counts approximating a 7-row roughly-hexagonal Field. Concretely we tentatively assume:

- `q` (column-ish axis) ranges: `-4..+4` (9 columns max).
- `r` (row-ish axis) ranges: `-3..+3` (7 rows max).
- Total Field hex count: tentatively ~50–60 hexes; pending TODO(G-02).

The `material.inc.php` file (A7) will host the canonical `IS_FIELD_HEX(q, r)` lookup, the `IS_SPAWN_ROW(q, r)` lookup (✦ row), and the `INTEL_ENTRY_HEXES = { 'top_left': (q,r), 'top_right': (q,r) }` constants.

### 3.3 Neighbor function (pointy-top, axial `(q, r)`)

The 6 neighbors of `(q, r)` are:

```
NW(q, r) = (q,     r - 1)   -- "up and to the left"  [rulebook conventions: §6.9, FAQ]
NE(q, r) = (q + 1, r - 1)   -- "up and to the right"
E (q, r) = (q + 1, r    )   -- "right"
SE(q, r) = (q,     r + 1)   -- "down and to the right" [§7.2 trickle SE on even die]
SW(q, r) = (q - 1, r + 1)   -- "down and to the left"  [§7.2 trickle SW on odd die]
W (q, r) = (q - 1, r    )   -- "left"
```

Two hexes are adjacent iff one is a neighbor of the other. The `is_adjacent(h1, h2)` helper computes this directly: the difference `(q2-q1, r2-r1)` must be one of `{(0,-1), (1,-1), (1,0), (0,1), (-1,1), (-1,0)}`.

Cited support: rulebook conventions block (lines 14–18) explicitly establishes pointy-top via the directional language. §7.2 (trickle algorithm) uses `SW` for odd dice and `SE` for even dice, consistent with this scheme. §6.9 (Comms Specialist) uses `NW`/`NE` for upward moves and `SW`/`SE` for downward moves. §9.6 (blockade redirection) uses the same diagonal pair.

### 3.4 "Up", "Down", and "the bottom row"

- **"Up"** in rulebook prose = NW or NE (player choice when ambiguous). Used in §6.9 (Comms move up).
- **"Down"** = SW or SE. Used in trickle (§7.2) and Comms move down (§6.9.B). Trickle direction per-tile is determined by the die's odd/even outcome (§5.1 step 3).
- **"Bottom row"** (✦) = the spawn row, equivalent to the maximum `r` in the Field per the assumed orientation. Per §5.2 / §6.5, this is where agents spawn and retire. The `IS_SPAWN_ROW(q, r)` lookup encodes this.
- **"Top-left" / "top-right"** corner hexes = the two intel entry points used in `trickle_draw_left` / `trickle_draw_right` (§5.1 steps 1–2). These are constants in `material.inc.php`; the schema does not need to encode them.

### 3.5 Off-board detection

A trickle target hex `(q', r')` is "off the bottom of the board" iff `IS_FIELD_HEX(q', r') == false`. The Trickle resolver (§7.2 step D) returns such tiles to the bag immediately. The same predicate is used by `act_comms_move_intel_down` (§6.9.B precondition + AMBIGUITY C-02 default).

### 3.6 TODO(G-01) note

Pointy-top is **assumed**, not confirmed. Per [TODO(G-01)] in DECISIONS.md, the asset-audit pass must inspect `game_board_print.png` to confirm orientation. If flat-top, the neighbor function rotates by 30°: `N`, `S`, `NE`, `SE`, `NW`, `SW` replace the current set, and the rulebook's "down" maps to two of those. The DDL is unaffected; only `material.inc.php` changes. We proceed under the pointy-top assumption per the rulebook's own derived note.

---

## 4. Public / Private Matrix

For every column on every table, visibility is **public**, **private to one player**, or **server-only**. Public columns are sent in `getAllDatas()` and via `notify->all` (or `notify->players` to both players, equivalent for 2-player). Server-only columns never leave the server and are never sent to any client.

Cited support: §3.7 of rulebook (Hidden vs public information), §10 (Hidden Information Handling), [D-11] (score is public).

### 4.1 `player`

| Column | Visibility | Rationale |
|---|---|---|
| `player_id` | Public | BGA-standard. |
| `player_name` | Public | BGA-standard. |
| `player_color` | Public | BGA-standard. |
| `player_score` | Public | [D-11] explicit. |
| `agents_remaining` | Public | [DERIVED §3.7]: needed for opponent decision-making (spawn cap, depletion threat). |
| `blockades_remaining` | Public | [DERIVED §3.7]: needed for opponent threat assessment. |

### 4.2 `agent`

| Column | Visibility | Rationale |
|---|---|---|
| `id` | Public | Identity needed for action targeting. |
| `owner` | Public | Always visible. |
| `type_id` | Public | Agent abilities (Hacker pin range, Smuggler swap, etc.) require type knowledge. |
| `state` | Public | Board state is public (§3.7). |
| `hex_q`, `hex_r` | Public | Board state is public (§3.7). |
| `pinned_until_turn` | Public | Pin status is required for opponent's legal-action prediction (§9.5). |
| `spawned_on_turn` | Public | Required for retire-legality prediction. |
| `hacker_pin_used_this_turn` | Public | Per §10.6 / [DERIVED]: required for opponent's threat-modeling. |
| `hacker_steal_used_this_turn` | Public | Same as above. |

### 4.3 `intel_tile`

| Column | Visibility | Rationale |
|---|---|---|
| `id` | Conditionally public | Public when `state ∈ {on_board, on_agent, scored}`; **server-only when `state ∈ {in_bag, returned_to_bag}`**. The whole row is suppressed for in-bag tiles. (Implementation: `getAllDatas()` filters `WHERE state NOT IN (0, 4)`; the bag's contents and the identity of in-bag tiles are server-only.) |
| `type_id` | Conditionally public | Public iff `state ≠ in_bag/returned_to_bag`. **Critical**: a tile drawn from the bag reveals its type publicly at draw time (§10.2). [§3.7 row 2]: "Each `intel_tile.id → type` mapping for tiles still in the bag — Hidden until drawn." |
| `score_value` | Conditionally public | Same gating as `type_id` (denormalized from it). |
| `state` | Conditionally public | Public for non-in-bag tiles; the **count** of in-bag tiles is public (`bag_size`), but individual identities are not. |
| `hex_q`, `hex_r` | Public when `state=on_board` | Loose intel locations are public. |
| `agent_id` | Public when `state=on_agent` | Held intel ownership is public per §3.7 / [DERIVED in §10.4]. |
| `scored_by` | Public when `state=scored` | Score derives from these rows; visible. |
| `stack_order` | Public when on board | UI rendering. |

**Server-only computation**: `bag_size = COUNT(intel_tile WHERE state IN (0, 4))`. Only the count is exposed (in `getAllDatas()` and animation notifications); the identities and types of bag-contents tiles are never sent.

### 4.4 `blockade`

| Column | Visibility | Rationale |
|---|---|---|
| `id` | Public | |
| `owner` | Public | Visual color of the blockade marker. |
| `hex_q`, `hex_r` | Public | Board state. |
| `placed_on_turn` | Public | Required for opponent to predict expiry. |
| `state` | Public | Filtered: only `state=on_board` is sent in `getAllDatas()` for the active blockades; expired rows are server-side only (audit trail). |

### 4.5 Game globals

| Key | Visibility | Rationale |
|---|---|---|
| `phase` | Public | UI displays current phase. |
| `turn_id` | Public | |
| `active_player_id` | Public | Standard BGA. |
| `dice_state` | Public during trickle phase; reset to `{}` thereafter | §10.5: "Public for the duration of the trickle phase." |
| `actions_remaining` | Public | §10.6 / [DERIVED]: opponent must predict legal actions. |
| `smuggler_boost_used_this_turn` | Public | Same. |
| `spawned_this_turn` | Public | Informational. |
| `game_winner` | Public when set | Game-end notification. |

### 4.6 Server-only state (never leaves server)

- The contents/identities of `intel_tile` rows with `state IN (in_bag, returned_to_bag)`. Only the count is exposed.
- Random seeds for `bga_rand` (BGA-managed; we never touch them).
- No other entity is server-only. There is **no** "hidden hand" in Hexpionage (held intel is public per §10.4).

### 4.7 Spectators

Spectators see the same public state as players. The bag contents are hidden from spectators per §10.7 (same rule as players).

---

## 5. `getAllDatas()` Shape

This is the canonical payload returned to a client on game load (and after page reload). Its shape is the contract between A4 (this doc) and A7 (backend), A8 (frontend), and A11 (integration review). The schema below is TypeScript-style for clarity.

```typescript
type HexCoord = { q: number; r: number };  // axial; signed; see §3

type GetAllDatas = {
  // Player roster (always 2 entries, keyed by player_id as string).
  players: {
    [player_id: string]: {
      id: number;                           // player_id
      name: string;
      color: string;                        // BGA hex string e.g. "ff0000"
      score: number;                        // [D-11] public
      agents_in_pool: number;               // 0..12; mirrors player.agents_remaining
      blockades_in_pool: number;            // 0..3; 3 - active blockades
    }
  };

  // All agents in any state. State filtering is the client's responsibility for rendering.
  agents: Array<{
    id: number;
    owner: number;                          // player_id
    type: number;                           // type_id; mapped client-side via material
    state: number;                          // 0=in_pool, 1=on_board, 2=removed
    hex: HexCoord | null;                   // null iff state != on_board
    intel_held: number[];                   // ordered list of intel_tile.id; empty iff none
    pinned_until: number | null;            // turn_id when pin clears; null iff not pinned
    spawned_on_turn: number | null;         // null for agents never spawned (in_pool with state=0)
    hacker_pin_used_this_turn: boolean;     // only meaningful when type=hacker
    hacker_steal_used_this_turn: boolean;
  }>;

  // Loose intel currently on the board. Excludes held (those are reachable via agents.intel_held).
  intel_on_board: Array<{
    id: number;
    type: number;                           // type_id; revealed at draw time per §10.2
    hex: HexCoord;                          // non-null
    stack_order: number;
  }>;

  // OPTIONAL convenience: redundant view of held intel keyed by agent.
  // RECOMMENDATION: skip — frontend joins via agents.intel_held[]. Keeps payload small.
  // intel_on_agent: ...;

  // Active blockades. Expired rows are NOT included.
  blockades: Array<{
    id: number;
    owner: number;
    hex: HexCoord;
    placed_on_turn: number;
  }>;

  // Scored intel (for the score breakdown UI, optional).
  // Sums equal each player's score by construction.
  scored_intel: Array<{
    id: number;
    type: number;
    score_value: number;
    scored_by: number;                      // player_id
  }>;

  // Globals.
  phase: string;                            // see §3.1 enum in this doc
  turn_id: number;
  active_player: number;                    // player_id
  actions_remaining: number;                // 0..4
  smuggler_boost_used_this_turn: boolean;
  spawned_this_turn: number;                // 0..3
  dice_state: {
    [color: string]: 'odd' | 'even';        // empty object {} between turns
  };

  // Bag — count only. Per §10.1, contents/identities never leak.
  bag_size: number;                         // 0..47

  // End-of-game signal. null while game is ongoing.
  game_winner: number | null;               // player_id or null
};
```

Implementation notes:
- The `agents.intel_held` field is the single source of truth for held intel; do **not** also include `intel_on_agent` redundantly (per the comment) to keep payload size down. The frontend resolves `intel_held` IDs to type/score via a lookup table that mirrors the `intel_tile` rows already known publicly (state ∈ on_board ∪ on_agent ∪ scored).
- The frontend may join `intel_held[]` against the union of `intel_on_board ∪ scored_intel ∪ (agents flatMap intel_held)` to render. Alternatively A7 can ship a flat `intel_revealed: Array<{id, type, score_value}>` lookup; A11 will pin this in `CONTRACT.md`.
- `bag_size` is computed at request time by the backend; never cached in the DB (avoids stale-counter bugs).
- `dice_state` is `{}` outside the trickle phase. The frontend hides the dice display when empty.

---

## 6. Derived State (Computed, Never Stored)

The following values are derivable from the persistent state above. Storing them duplicates information and risks drift; computing them on demand costs at most one indexed query.

| Derived value | Formula | Used by |
|---|---|---|
| `agent.is_pinned` | `agent.pinned_until_turn IS NOT NULL` | Move/retire/swap legality (§6.3, §6.5, §6.8) |
| `agent.intel_count` | `COUNT(intel_tile WHERE agent_id = agent.id AND state = 'on_agent')` | Over-capacity check (§9.3); equivalently `len(agent.intel_held)` from the join. |
| `player.total_agents_on_board` | `COUNT(agent WHERE owner = P AND state = 'on_board')` | Spawn cap (§6.7), depletion check (§7.4 / [D-17]). |
| `player.has_lost` | `(player.agents_remaining = 0 AND player.total_agents_on_board = 0)` | Loss condition per [D-17]; checked at every retire/honeypot/over-capacity removal site. |
| `bag_size` | `COUNT(intel_tile WHERE state IN ('in_bag', 'returned_to_bag'))` | `getAllDatas()`, public counter. |
| `player.active_blockades` | `COUNT(blockade WHERE owner = P AND state = 'on_board')` | Engineer placement cap (§6.6). Equivalently, `3 - player.blockades_remaining` — kept consistent by handlers. |
| `intel_tile.score_value` | (denormalized — stored, but derivable from `type_id` via [D-19]) | This is a controlled exception: stored for hot-path SUM during retire scoring. The mapping is fixed; no drift possible. |
| `is_adjacent(h1, h2)` | See §3.3 neighbor function | Move, transfer, blockade-adjacent, hacker pin/unpin. |
| `is_field_hex(q, r)` | Lookup table in `material.inc.php` | Move, spawn, comms-down (§3.5). |
| `is_spawn_row_hex(q, r)` | Lookup table in `material.inc.php` | Spawn (§5.2), retire (§6.5). |

**Anti-storage note**: `agent.intel_held` is **not** stored as a list column on `agent`. It is recovered via `SELECT id FROM intel_tile WHERE agent_id = agent.id ORDER BY id` (insertion order = id ascending; we use `id` not insertion-time so no separate column is needed). The "ordered list" wording in §2.3 of rulebook is a UI consideration, not a state-mutation requirement.

### 6.1 Server-side invariants [F-02, F-04, F-20, INVARIANT-PICKUP]

These invariants are asserted by the server at the boundaries of every action / phase transition. Any violation indicates a programming error and must abort the action with state rollback.

| Invariant | Formula | Rationale | Citation |
|---|---|---|---|
| `INVARIANT-ACTIONS-CAP` | `actions_remaining ∈ [0, 4]; actions_remaining ≤ 3 unless smuggler_boost_used_this_turn == true` | Action cap per [D-08] / rulebook §5.3 (max 4 only after Smuggler boost). [F-02] | rulebook §5.3, §6.7, [D-08] |
| `INVARIANT-PICKUP` [D-21] | `SELECT 1 FROM intel_tile WHERE state = 'on_board' AND (hex_q, hex_r) IN (SELECT hex_q, hex_r FROM agent WHERE state = 'on_board')` returns **zero rows** at all times. | Per [D-21] / rulebook §6.3 effect 2: loose intel and an agent never co-occupy a hex; pickup is automatic and immediate at any co-occupation event. | [D-21], rulebook §6.3, §9.4 |
| `INVARIANT-AGENT-COUNT` | `agent_pool_size + agents_on_board_size + agents_removed_size = 12` per player | Per [D-10b]: agents are never created or destroyed at runtime; only `state` transitions. | [D-10b], rulebook §2.1 |
| `INVARIANT-HONEYPOT-HELD` | No `intel_tile` with `type='honeypot'` ever has `state='on_agent'`. | Per §9.4: Honeypots remove their possessor on contact; cannot be held. | rulebook §9.4 |
| `INVARIANT-PIN-CAP` | At most one pin per agent (`pinned_until_turn` is single-valued). | Per [D-06b]. | [D-06b] |
| `INVARIANT-BLOCKADE-CAP` | `COUNT(blockade WHERE owner = P AND state = 'on_board') ≤ 3` for each player P | Per [D-04] + [D-07]. | [D-04], [D-07] |

### 6.2 `pinned_until` setter formula [F-04]

When Hacker pins target T at turn N, the `pinned_until_turn` field is set per the following formula. The pin clears at the end-of-turn cleanup of T's owner's next turn (per [D-06a]).

**Formula**: `pinned_until_turn = current_turn_id + (1 if T.owner == opponent_of_current_active else 2)`

Concretely:
- Hacker abilities only allow pinning **enemy** agents (per §6.11.A precondition: `target.owner != active_player`). So `T.owner == opponent_of_current_active` is always true when `act_hacker_pin` fires successfully.
- Therefore in practice: `pinned_until_turn = current_turn_id + 1`.
- This is the turn_id of T.owner's next turn (which immediately follows the active player's current turn).
- The cleanup query (§7.4 / §9.11) clears pins where `pinned_until_turn <= current_turn_id` AND `owner = ending_player`, so at the end of T.owner's turn (turn_id == `current_turn_id + 1`), the pin clears.

The general form `current_turn_id + (1 if opponent else 2)` is documented for future-proofing in case the rule ever permits self-pin (it currently does not).

> **Implementer note**: do NOT use `pinned_until_turn = current_turn_id + 1` blindly — always derive via `T.owner` to keep the formula resilient to future rule changes.

### 6.3 Derived helper: `is_owned_by_active_player(agent_id)` [F-20]

A frequently-used predicate in action preconditions (move, retire, transfer, retire-bonus, etc.). Computed inline in every relevant action:

**Pseudocode**: `is_owned_by_active_player(agent_id) := (agent.owner == globals.active_player_id)`

Used by §6.3, §6.4, §6.5, §6.6, §6.7, §6.8 (one of two), §6.9, §6.10, §6.11 (varies). Documented here as a single canonical reference; A7 may inline or wrap as a helper at its discretion.

---

## 7. Persistence and Serialization

### 7.1 Per-row mutations: `DbQuery`

All `agent`, `intel_tile`, and `blockade` mutations use BGA's `DbQuery`/`DbQueryAll` API. Single-row updates (move agent, change intel state, expire blockade) are independent SQL statements. Multi-row updates (Trickle batch movement) issue one `UPDATE ... WHERE id IN (...)` per affected entity-class.

### 7.2 `bga->globals` writes

Game-state globals (§2.5 above) live in JSON-serialized BGA globals: `$this->globals->set('phase', 'spawn')`, `$this->globals->get('actions_remaining')`. Multi-key writes within an action handler are not atomic at the DB level but are atomic within the single PHP request (see §7.4 transactionality).

### 7.3 `dice_state` is a global, not a table

Dice state is a 6-key map of `{color → odd|even}` that exists only during phase 1. We store it in `bga->globals` rather than a table. Two reasons:
1. It is a singleton (no per-row identity).
2. It resets to `{}` at `end_of_turn_cleanup` — easier to clear with one `globals->set('dice_state', new stdClass())` than `TRUNCATE`.

### 7.4 Trickle resolution = one transaction

Per the rulebook §7.2 algorithm and §7.5 ("there are no interrupts"), the entire `trickle_resolve` phase resolves as one atomic unit. Implementation: A7 wraps the `stTrickleResolve` state's body in `DbQuery('START TRANSACTION')` ... `DbQuery('COMMIT')`. If any sub-step fails (e.g., constraint violation), the whole phase rolls back. This ensures the FAQ-required ordering (honeypot first, then over-capacity dump) holds even under server crashes.

For the action phase (§5.3), individual actions are their own atomic units. Undo within the action phase is opt-in per BGA's `db_undo_support` flag (PLAN §1.2); A5 specifies which states permit undo.

### 7.5 Notifications are public-state-only

Per §4 of this doc and PLAN §1.2, server-only state (bag contents) never appears in notifications. A11 (`CONTRACT.md`) audits every notification payload for hidden-info leaks.

### 7.6 Serialization formats

- **DB rows**: native MySQL columns. No JSON blobs in rows. (Schema is normalized.)
- **`bga->globals`**: JSON. PHP `stdClass` and arrays serialize natively. Avoid PHP object types other than primitives + arrays + stdClass — they may not survive the JSON round-trip.
- **`getAllDatas()`**: returned as a PHP associative array; BGA serializes to JSON for the client.
- **Notifications**: associative array, JSON-serialized, max ~16KB per BGA limits. The Trickle batched notification is the largest; it carries up to ~10 `(tile_id, from_hex, to_hex, redirect_reason?)` records.

---

## 8. Initial State Population (`setupNewGame()`)

This is the SQL/PHP that runs at game start. The actual implementation lives in `hexpionage.game.php::setupNewGame()` (A7's responsibility), but the contract is fixed here.

### 8.1 `player` extension columns

```sql
-- Already created above via ALTER TABLE; values default to 12 / 3.
-- No extra setup needed.
```

### 8.2 24 `agent` rows

12 per player × 2 players. Per §2.2 / [D-10b]: each player gets 2 of each of the 6 types. PHP-side:

```php
$agent_types = [1, 2, 3, 4, 5, 6]; // comms, analyst, smuggler, engineer, hacker, double_agent
foreach ($players as $player_id => $player) {
    foreach ($agent_types as $type_id) {
        for ($copy = 0; $copy < 2; $copy++) {
            $sql = "INSERT INTO agent (owner, type_id, state, hex_q, hex_r, pinned_until_turn, spawned_on_turn, hacker_pin_used_this_turn, hacker_steal_used_this_turn)
                    VALUES ($player_id, $type_id, 0, NULL, NULL, NULL, NULL, 0, 0)";
            self::DbQuery($sql);
        }
    }
}
// Result: 24 agent rows; all in state=in_pool.
```

### 8.3 47 `intel_tile` rows

Per [TODO(I-02)], the per-type distribution is not yet confirmed. We use this **placeholder** distribution and explicitly mark it for follow-up:

```php
// TODO(I-02): replace this distribution with the audited one from the punchboard PSDs.
// Placeholder: 8 industrial_tech + 8 leaked_email + 8 blackmail + 8 security_credential
//            + 8 state_secret + 7 honeypot = 47 total.
$intel_distribution = [
    1 => 7,  // honeypot           score 0
    2 => 8,  // industrial_tech    score 2
    3 => 8,  // leaked_email       score 2
    4 => 8,  // blackmail          score 2
    5 => 8,  // security_credential score 3
    6 => 8,  // state_secret       score 4
];
$score_by_type = [1 => 0, 2 => 2, 3 => 2, 4 => 2, 5 => 3, 6 => 4]; // [D-19]
foreach ($intel_distribution as $type_id => $count) {
    for ($i = 0; $i < $count; $i++) {
        $sql = "INSERT INTO intel_tile (type_id, score_value, state, hex_q, hex_r, agent_id, scored_by, stack_order)
                VALUES ($type_id, {$score_by_type[$type_id]}, 0, NULL, NULL, NULL, NULL, 0)";
        self::DbQuery($sql);
    }
}
// Total: 47 rows; all in state=in_bag.
```

> **TODO(I-02)** — confirm per-type counts before BGA submission; the placeholder above must be replaced with the audited values. The schema does not change; only the seed counts do.

### 8.4 `blockade` rows

None at setup. Table is empty. Each player's `blockades_remaining` defaults to 3.

### 8.5 Globals

```php
$this->globals->set('phase', 'trickle_draw_left');
$this->globals->set('turn_id', 1);

// First-player: random per [D-16].
$first_player_id = bga_rand(1, count($players)); // returns 1..N; map to player_id
$this->globals->set('active_player_id', $player_ids[$first_player_id - 1]);

$this->globals->set('dice_state', new stdClass()); // empty {}
$this->globals->set('actions_remaining', 0);       // not set until phase 3
$this->globals->set('smuggler_boost_used_this_turn', false);
$this->globals->set('spawned_this_turn', 0);
$this->globals->set('game_winner', null);
```

### 8.6 Sanity assertions

Per §8 setup invariants:

```sql
-- After setup:
SELECT COUNT(*) FROM agent;                                     -- expect 24
SELECT COUNT(*) FROM agent WHERE state = 0;                     -- expect 24 (all in_pool)
SELECT owner, COUNT(*) FROM agent GROUP BY owner;               -- expect (P1, 12), (P2, 12)
SELECT type_id, COUNT(*) FROM agent WHERE owner = P1 GROUP BY type_id;  -- expect (1..6) → 2 each
SELECT COUNT(*) FROM intel_tile;                                -- expect 47
SELECT COUNT(*) FROM intel_tile WHERE state = 0;                -- expect 47
SELECT COUNT(*) FROM blockade;                                  -- expect 0
```

---

## 9. Round-Trip Validation Against rulebook.md

For each rule-class in `rulebook.md` §6, this section gives one or two queries proving the state model can answer the legality/effect question. This is the validation contract per PLAN A4 description: "every rule in the rules spec can be expressed as a query against this schema" (that spec shipped as `docs/rulebook.md`).

### 9.1 §6.1 `act_spawn_agent` — preconditions

> "Is `act_spawn_agent(A, H)` legal? — agent A in pool, target hex is spawn-row, hex empty (no agent/intel/blockade), player has <3 on board."

```sql
-- Precondition: agent in pool AND owned by active player.
SELECT 1 FROM agent
 WHERE id = :agent_id AND owner = :active_player AND state = 0
 LIMIT 1;

-- Precondition: target hex is empty of agent, intel, blockade.
-- (Multi-table check; can be combined into one EXISTS-style query in PHP.)
SELECT (SELECT COUNT(*) FROM agent     WHERE state = 1 AND hex_q = :q AND hex_r = :r) AS agent_count,
       (SELECT COUNT(*) FROM intel_tile WHERE state = 1 AND hex_q = :q AND hex_r = :r) AS intel_count,
       (SELECT COUNT(*) FROM blockade   WHERE state = 1 AND hex_q = :q AND hex_r = :r) AS blockade_count;
-- All three must be 0.

-- Spawn cap.
SELECT COUNT(*) FROM agent WHERE owner = :active_player AND state = 1;
-- Must be < 3.

-- Spawn-row check is via material.inc.php constant lookup (not SQL).
```

### 9.2 §6.3 `act_move_agent` — preconditions and effects

> "Is `act_move_agent(A, H)` legal?"

```sql
-- 1. Owner + on-board + not pinned.
SELECT pinned_until_turn, hex_q, hex_r FROM agent
 WHERE id = :agent_id AND owner = :active_player AND state = 1;
-- pinned_until_turn must be NULL.

-- 2. Target hex is in Field (lookup), is adjacent (PHP-side using §3.3 neighbor function),
--    has no agent, has no blockade. Loose intel is fine — pickup happens.
SELECT (SELECT COUNT(*) FROM agent   WHERE state = 1 AND hex_q = :tq AND hex_r = :tr) AS agent_count,
       (SELECT COUNT(*) FROM blockade WHERE state = 1 AND hex_q = :tq AND hex_r = :tr) AS blockade_count;
-- Both must be 0.

-- Effect: pickup intel.
SELECT id, type_id FROM intel_tile WHERE state = 1 AND hex_q = :tq AND hex_r = :tr ORDER BY stack_order;
-- For each row, set state=2 (on_agent) or trigger Honeypot removal (§9.4).
```

### 9.3 §6.4 `act_transfer_intel` — held-intel ownership

> "What intel does agent X hold?"

```sql
SELECT * FROM intel_tile WHERE state = 2 AND agent_id = :agent_id ORDER BY id;
```

> "Are agents X and Y adjacent?"

PHP-side: fetch both hex coords, run `is_adjacent()` (§3.3).

### 9.4 §6.5 `act_retire_agent` — score all held intel

> "What is the score increment for retiring agent X?"

```sql
-- Sum the score_value of every tile held by X.
SELECT COALESCE(SUM(score_value), 0) AS retire_score
  FROM intel_tile
 WHERE state = 2 AND agent_id = :agent_id;

-- Per [D-14] all of these become scored. Effect:
UPDATE intel_tile
   SET state = 3,           -- scored
       agent_id = NULL,
       scored_by = :active_player
 WHERE agent_id = :agent_id AND state = 2;

UPDATE agent SET state = 2, hex_q = NULL, hex_r = NULL WHERE id = :agent_id;
UPDATE player SET player_score = player_score + :retire_score WHERE player_id = :active_player;
```

> "Is the retire legal? (must be on ✦ hex, not pinned, not spawned this turn)"

```sql
SELECT hex_q, hex_r, pinned_until_turn, spawned_on_turn
  FROM agent
 WHERE id = :agent_id AND owner = :active_player AND state = 1;
-- PHP: is_spawn_row_hex(hex_q, hex_r) must be true.
-- pinned_until_turn must be NULL.
-- spawned_on_turn must be NULL or != current_turn_id.
```

### 9.5 §6.6 `act_engineer_place_blockade_*` — blockade cap

> "How many of player P's blockades are on the board?"

```sql
SELECT COUNT(*) FROM blockade WHERE owner = :player_id AND state = 1;
-- Must be < 3 to place.
```

> "Will blockade B expire at end of this turn?" (§7.4 cleanup logic)

```sql
SELECT id FROM blockade
 WHERE state = 1
   AND owner != :ending_player
   AND placed_on_turn < :current_turn_id;
-- Set these rows to state=2 and increment their owner's blockades_remaining by 1.
```

### 9.6 §6.7 `act_smuggler_boost_actions` — once per turn (player)

This is a global per [D-08]:

```php
// Precondition:
$this->globals->get('smuggler_boost_used_this_turn') === false
  AND $smuggler->owner === $active_player
  AND in_array($paid_intel, $smuggler_intel_ids);

// Effect:
$this->globals->set('smuggler_boost_used_this_turn', true);
$this->globals->set('actions_remaining', $current + 1);
```

### 9.7 §6.8 `act_smuggler_swap_agents` — neither agent pinned

```sql
-- Both agents on-board, neither pinned. (Either may be smuggler; either player.)
SELECT id, owner, hex_q, hex_r, pinned_until_turn FROM agent WHERE id IN (:a, :b) AND state = 1;
-- For both rows: pinned_until_turn IS NULL.
-- Effect: swap hex_q/hex_r between the two rows (transactional).
```

### 9.8 §6.9 Comms moves loose intel — target hex empty of blockade and agent

```sql
-- Precondition: target intel is loose.
SELECT * FROM intel_tile WHERE id = :intel_id AND state = 1;

-- Precondition: target hex has no blockade (§9.6.A) and no agent (per [D-09]).
SELECT (SELECT COUNT(*) FROM agent    WHERE state = 1 AND hex_q = :q AND hex_r = :r) AS agent_count,
       (SELECT COUNT(*) FROM blockade WHERE state = 1 AND hex_q = :q AND hex_r = :r) AS blockade_count;
-- Both 0.

-- Effect: move tile.
UPDATE intel_tile SET hex_q = :q, hex_r = :r WHERE id = :intel_id;
```

### 9.9 §6.11.A `act_hacker_pin` — per-Hacker once per turn

```sql
-- Precondition: this Hacker has not used pin/unpin this turn.
SELECT hacker_pin_used_this_turn FROM agent WHERE id = :hacker_id;
-- Must be 0.

-- Precondition: target is enemy on-board, not already pinned, adjacent.
SELECT id, owner, hex_q, hex_r, pinned_until_turn
  FROM agent
 WHERE id = :target_id AND state = 1;
-- owner != active_player; pinned_until_turn IS NULL; PHP: is_adjacent(hacker.hex, target.hex).

-- Effect:
UPDATE agent SET pinned_until_turn = :pin_clear_turn_id WHERE id = :target_id;
UPDATE agent SET hacker_pin_used_this_turn = 1 WHERE id = :hacker_id;
```

### 9.10 §6.12 `act_analyst_retire_bonus` — empty bag handling

```sql
-- Inside §6.5 retire effect, before scoring:
SELECT COUNT(*) FROM intel_tile WHERE state IN (0, 4);
-- If 0: per [D-18], skip the bonus draw (forfeit).
-- Else: pick one row at random via PHP's bga_rand of all in-bag tiles, draw it.
```

### 9.11 §7.4 `end_of_turn_cleanup` — pin expiration

```sql
-- "Pins on the player whose turn just ended, applied on a previous turn → clear."
UPDATE agent
   SET pinned_until_turn = NULL
 WHERE pinned_until_turn IS NOT NULL
   AND owner = :ending_player
   AND pinned_until_turn <= :current_turn_id;
-- (Owner check matches [D-06a] "end of pinned player's next turn.")
```

### 9.12 §7.4 — reset Hacker per-turn flags

```sql
UPDATE agent
   SET hacker_pin_used_this_turn = 0,
       hacker_steal_used_this_turn = 0
 WHERE state = 1 AND type_id = 5;
-- All on-board Hackers reset.
```

### 9.13 §8.3 — depletion check ([D-17])

```sql
-- For each player after any agent removal:
SELECT
  (SELECT COUNT(*) FROM agent WHERE owner = :pid AND state = 0) AS in_pool,
  (SELECT COUNT(*) FROM agent WHERE owner = :pid AND state = 1) AS on_board;
-- If both 0: opponent wins; set globals.game_winner = opponent.
```

### 9.14 §10.1 — bag size (`getAllDatas()` only count, never contents)

```sql
SELECT COUNT(*) AS bag_size FROM intel_tile WHERE state IN (0, 4);
```

### 9.15 §9.3 — over-capacity dump trigger

```sql
-- After any held-intel mutation, query the holder's count:
SELECT COUNT(*) AS held FROM intel_tile WHERE state = 2 AND agent_id = :agent_id;
-- If > 3: dump.

UPDATE intel_tile SET state = 4, agent_id = NULL WHERE agent_id = :agent_id AND state = 2;
```

### 9.16 Server-side invariants (assertion templates) [F-02, F-21, INVARIANT-PICKUP]

After every action handler completes (and at end-of-phase boundaries), the server SHOULD assert these invariants. Failure indicates a bug; the action must abort and roll back.

```sql
-- INVARIANT-ACTIONS-CAP [F-02]: actions_remaining ∈ [0, 4]; ≤ 3 unless smuggler boost active.
-- (Asserted in PHP using globals.actions_remaining and globals.smuggler_boost_used_this_turn.)
-- Pseudocode: assert(0 <= actions_remaining && actions_remaining <= (smuggler_boost_used_this_turn ? 4 : 3));

-- INVARIANT-PICKUP [D-21]: no loose intel may co-occupy with an agent.
SELECT COUNT(*) FROM intel_tile i
  JOIN agent a ON a.hex_q = i.hex_q AND a.hex_r = i.hex_r
 WHERE i.state = 1 AND a.state = 1;
-- Must be 0.

-- INVARIANT-AGENT-COUNT: per player, sum of state buckets = 12.
SELECT owner, COUNT(*) FROM agent GROUP BY owner;
-- Each player must return 12.

-- INVARIANT-HONEYPOT-HELD: no Honeypot in state=on_agent.
SELECT COUNT(*) FROM intel_tile WHERE type_id = 1 AND state = 2;
-- Must be 0.
```

### 9.17 `bag_size` legality-check uses [F-21]

The derived `bag_size = COUNT(intel_tile WHERE state IN (in_bag, returned_to_bag))` is exposed in `getAllDatas()` (§5) and used as a legality input at the following sites:

- **`trickleDrawLeft` / `trickleDrawRight`** ([D-18]): if `bag_size == 0`, the draw is skipped (no `intelDrawn`); the game proceeds.
- **`act_analyst_retire_bonus`** (§6.12, §9.10 / [D-18]): if `bag_size == 0`, the bonus is forfeited; retirement proceeds without a bonus draw.
- **State `analystBonusDecision` entry** ([D-26]): if `bag_size == 0`, the state is bypassed and `analystBonusSkipped` is emitted instead.
- **Hacker steal cost** (§6.11.C): the paid intel returns to bag (no legality gate; bag size only matters for animation `new_bag_size` payload).
- **Engineer remote, Smuggler boost, Comms move-down, Smuggler swap** (cost-paying actions §6.6.B / §6.7 / §6.8 / §6.9.B): same as Hacker steal — paid intel returns to bag, but no legality gate fires from `bag_size`.

Documented for completeness so legality checks at the above sites consistently reference `bag_size`.

> **Coverage**: the 15 query templates above span **every action class in §6** (spawn, pass-spawn, move, transfer, retire, engineer-near, engineer-far, smuggler-boost, smuggler-swap, comms-up, comms-down, double-agent-transfer, hacker-pin, hacker-unpin, hacker-steal, analyst-bonus, pass-actions). Every one of them is expressible as a small set of indexed queries — none requires a full table scan. The schema in §2 is sufficient.

---

## 10. Summary — How This Doc Maps to rulebook.md

| rulebook.md section | This doc section |
|---|---|
| §2.1 Component inventory | §1.1 entity inventory |
| §2.3 Agent properties | §2.2 agent DDL |
| §2.4 Intel types/counts | §2.3 intel_tile DDL, §8.3 setup |
| §3.1 Global state | §2.5 globals |
| §3.2 Per-player state | §2.1, §2.2 player extensions |
| §3.3 Board state | §2.2/§2.3/§2.4 hex columns |
| §3.4 Intel state | §2.3 intel_tile.state |
| §3.5 Loose vs held | §2.3 (`agent_id` non-null = held) |
| §3.6 Pin state | §2.2 `pinned_until_turn` (per [D-06b]) |
| §3.7 Hidden vs public | §4 public/private matrix |
| §3.8 Derived state | §6 derived state |
| §4 Setup | §8 initial state population |
| §6 Player actions | §9 round-trip queries |
| §7.4 Cleanup | §9.11, §9.12, §9.5 |
| §8 Win/loss | §9.13 depletion, §9.4 retire score |
| §10 Hidden info | §4 visibility matrix |
| §11 Determinism | §7.4 transactionality |

---

## 11. Open Items Forwarded to Other Agents

- **TODO(G-01)** — confirm hex orientation pointy-top vs flat-top by inspection of `game_board_print.png`. Owner: A3. Schema is unaffected; only `material.inc.php`'s neighbor function changes.
- **TODO(G-02)** — enumerate the Field hexes' (q, r) values, including the ✦ spawn row and the two intel-entry corners. Owner: A3. Schema is unaffected; values land in `material.inc.php`.
- **TODO(I-02)** — confirm per-intel-type tile counts. Schema is unaffected; the placeholder distribution in §8.3 must be replaced. Owner: A3.
- **State-machine handoff** — phase transitions and undo policy are A5's responsibility. This doc gives the global keyspace and the table mutations each phase will perform; A5 stitches them together.
- **Notification contract** — public-state delta payloads for each mutation are A11's `CONTRACT.md`. This doc gives the visibility matrix that constrains payload shapes.
- **`material.inc.php`** — A7 owns the agent-type and intel-type integer-enum maps, the `IS_FIELD_HEX`/`IS_SPAWN_ROW` lookups, and the `INTEL_ENTRY_HEXES` constants. This doc references them but does not define their values.

End of `STATE_MODEL.md`.
