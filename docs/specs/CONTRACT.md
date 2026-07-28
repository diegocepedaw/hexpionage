# Hexpionage — Notification Contract (A11 Output)

> **Purpose**: canonical client-server message contract for Hexpionage on BGA. Defines the `getAllDatas()` payload and every notification type emitted by the server. Once signed off, A7 (backend) and A8 (frontend) implement against this document.
>
> **Scope**: data shapes only. State-machine transitions live in `docs/specs/STATE_MACHINE.md`; storage in `docs/specs/STATE_MODEL.md`; UI rendering in `docs/specs/UI_SPEC.md`.
>
> **Source-of-truth dependency order**: `rulebook.md` → `DECISIONS.md` → `docs/specs/STATE_MODEL.md` → `docs/specs/STATE_MACHINE.md` → `docs/specs/UI_SPEC.md` → this doc.
>
> **Citations**: `§N.N` for `rulebook.md`; `[D-NN]` for `DECISIONS.md`; `STATE_MODEL §N`, `STATE_MACHINE §N`, `UI_SPEC §N`, `BGA_PRIMER §N` for cross-references.
>
> **Hard limits**: BGA notification bundles are capped at **128KB per action** (BGA_PRIMER §4 / §6.2). All payloads in this doc are estimated to stay well under the cap; the only payload approaching the cap in the worst case is `trickleResolved` (§2.5). `getAllDatas()` has no documented hard cap but should remain reasonable; estimated max <30KB at any game state.

---

## 1. `getAllDatas()` payload

This is the canonical payload returned to a client on game load and on F5 reload (BGA_PRIMER §2). The schema below is the union of `STATE_MODEL §5` (entity-shape source of truth) and the visibility filtering of `STATE_MODEL §4`.

### 1.1 TypeScript-style schema

```typescript
type HexCoord = { q: number; r: number };  // axial; signed; STATE_MODEL §3.

type AgentTypeId  = 1 | 2 | 3 | 4 | 5 | 6;
// 1=comms_specialist, 2=analyst, 3=smuggler, 4=engineer, 5=hacker, 6=double_agent  [D-01, STATE_MODEL §2.2]

type IntelTypeId  = 1 | 2 | 3 | 4 | 5 | 6;
// 1=honeypot, 2=industrial_tech, 3=leaked_email, 4=blackmail, 5=security_credential, 6=state_secret  [D-19]

type AgentState   = 0 | 1 | 2;       // 0=in_pool, 1=on_board, 2=removed     [STATE_MODEL §2.2]
type IntelState   = 0 | 1 | 2 | 3 | 4; // 0=in_bag, 1=on_board, 2=on_agent, 3=scored, 4=returned_to_bag [STATE_MODEL §2.3]
type Phase = 'setup' | 'trickle_draw_left' | 'trickle_draw_right' | 'trickle_roll'
           | 'trickle_resolve' | 'spawn' | 'actions' | 'end_of_turn_cleanup' | 'game_end';
type DieFace = 'odd' | 'even';
type IntelDieKey = 'honeypot' | 'industrial_tech' | 'leaked_email'
                 | 'blackmail' | 'security_credential' | 'state_secret';
// Per FINDING-01 the keying is by intel-type-name (matches D-19's 1:1 die-color↔intel-type map).

type GetAllDatas = {
  // Player roster (always 2 entries, keyed by player_id as string per BGA convention).
  players: {
    [player_id: string]: {
      id: number;                       // player_id
      name: string;                     // BGA-provided
      color: string;                    // BGA hex (e.g., "ff0000")
      score: number;                    // 0..20+; public per [D-11]
      agents_in_pool: number;           // 0..12; mirrors player.agents_remaining
      agents_on_board: number;          // 0..3; derived [STATE_MODEL §6]
      agents_removed: number;           // 0..12; derived
      blockades_in_pool: number;        // 0..3; derived as 3 - active blockades
    }
  };

  // Every agent in any state (24 rows). State filtering for rendering is the client's responsibility.
  agents: Array<{
    id: number;
    owner: number;                      // player_id
    type: AgentTypeId;
    state: AgentState;
    hex: HexCoord | null;               // non-null iff state=on_board (1)
    intel_held: number[];               // ordered list of intel_tile.id; empty iff none
    pinned_until_turn: number | null;   // null iff not pinned; else the turn_id at which pin clears
    spawned_on_turn: number | null;     // null iff state != on_board OR pre-spawn lifetime
    hacker_pin_used_this_turn: boolean; // only meaningful when type=5 (hacker); else informational
    hacker_steal_used_this_turn: boolean;
  }>;

  // Loose intel currently on the board (state=on_board only).
  // Held intel is reachable via agents[].intel_held; in-bag intel is server-only (only count exposed).
  intel_on_board: Array<{
    id: number;
    type: IntelTypeId;                  // revealed at draw time per §10.2
    score_value: number;                // 0/2/2/2/3/4 per [D-19]; denormalized
    hex: HexCoord;                      // non-null
    stack_order: number;                // 0..N; for multi-tile hex rendering
  }>;

  // Lookup table mapping any client-known intel_id (held + on-board + scored) to its type and score.
  // Recommended companion to agents[].intel_held to spare the frontend from joining manually.
  // Excludes in-bag identities (server-only per STATE_MODEL §4.6).
  intel_revealed: Array<{
    id: number;
    type: IntelTypeId;
    score_value: number;
  }>;

  // Active blockades only (state=on_board=1). Expired rows excluded.
  blockades: Array<{
    id: number;
    owner: number;                      // player_id
    hex: HexCoord;
    placed_on_turn: number;             // for opponent expiry prediction
  }>;

  // Scored intel rows; sums by scored_by equal each player's score by construction.
  scored_intel: Array<{
    id: number;
    type: IntelTypeId;
    score_value: number;
    scored_by: number;                  // player_id
  }>;

  // Globals (STATE_MODEL §2.5).
  phase: Phase;
  turn_id: number;                      // 1..∞
  active_player: number;                // player_id
  actions_remaining: number;            // 0..4 (cap 4 only when smuggler boost active per [D-08])
  smuggler_boost_used_this_turn: boolean;
  spawned_this_turn: number;            // 0..3 (informational)
  dice_state: Partial<Record<IntelDieKey, DieFace>>;  // {} between turns; populated during trickle

  // Bag — count only. Identities of in-bag tiles are server-only (STATE_MODEL §4.3, §4.6).
  bag_size: number;                     // 0..47

  // End-of-game signal.
  game_winner: number | null;           // player_id once set; null while ongoing

  // Canonical board hex enumeration (resolves G-02). The FE click overlay
  // (`hexpionage.js::_setupHexOverlay`, FE-12) renders hexes from this list
  // rather than hard-coding coordinates. Source-of-truth: design/BOARD_LAYOUT.md.
  board_layout: {
    field_hexes: HexCoord[];            // 30 lavender hexes (r=0..3)
    orange_hexes: HexCoord[];           // 14 "intel rain" hexes (r=-4..-1) incl. entries
    spawn_row_hexes: HexCoord[];        // 9 ✦ hexes at r=3
    intel_entry_top_left: HexCoord;     // labeled "1" on the print art
    intel_entry_top_right: HexCoord;    // labeled "2" on the print art
  };
};
```

### 1.2 Visibility filters and notes

- **Bag identities never shipped**: rows with `intel_tile.state IN (in_bag=0, returned_to_bag=4)` are excluded from `intel_on_board`, `intel_revealed`, `scored_intel`, and any held-intel join. Only `bag_size` is sent. Per `STATE_MODEL §4.3`, `STATE_MODEL §4.6`, `QA_SPEC_REVIEW §5 F-30/F-31`.
- **`intel_revealed` design rationale**: held intel `id`s appear in `agents[].intel_held` (a list of integer IDs). Without a lookup, the client cannot resolve `id → type`. `intel_revealed` is that lookup; it includes only revealed tiles (current `state ∈ {on_board, on_agent, scored}` plus any tile that has *ever* been revealed and currently sits in the bag — see §1.3). A7 may also include `state` per row for client-side state-tracking, optional.
- **`agents` always 24 rows**: per `STATE_MODEL §1.1` and `[D-10b]`. Client filters by state for display.
- **`scored_intel` invariant**: `SUM(score_value) GROUP BY scored_by == players[scored_by].score` (per `STATE_MODEL §6`). Client can use this to render score breakdowns.
- **`intel_on_board` ordering**: not guaranteed; client groups by `hex` and orders by `stack_order` for rendering (`STATE_MODEL §2.3`, `UI_SPEC §6.1` `intelStacked`).
- **Spectators see the same payload** as players (`STATE_MODEL §4.7`).

### 1.3 Open question on `intel_revealed` vs returned-to-bag

A tile's identity becomes public on draw (`§10.2`). If it later returns to the bag (Honeypot trickle, over-capacity dump, Smuggler boost, Engineer remote, Comms-down payment), the previously-revealed identity is technically still in player memory. The schema models that as **a returned-to-bag tile remains absent from `intel_revealed`** — i.e., the server treats the bag as a black box on F5 reload. Note: `analystBonusReturned` per [D-20] does NOT reveal the tile type publicly, so an Analyst-bonus return is the one return path that does not even share the type with opponents.

**Recommendation**: ship the conservative version (returned tiles excluded from `intel_revealed`). Players use the in-game log for memory. Reconsider only if playtest shows confusion.

### 1.4 Payload size estimate

Worst case mid-game: 24 agents (~120 bytes each = ~2.9KB) + 47 intel rows distributed across `intel_on_board`/`intel_revealed`/`scored_intel` (~50 bytes each = ~2.4KB) + 6 blockades + globals + 2 players ≈ **<10KB total**. Well under any practical cap.

---

## 2. Notification catalog

Each notification row gives the canonical contract for a single event. **Format**:

- `name`: camelCase identifier (becomes `notify_<name>` JS handler suffix per BGA_PRIMER §4).
- `recipients`: `all` (use `notify->all`), `player(P)` (use `notify->player($pid, ...)`), or `spectators_only` (none in Hexpionage).
- `payload`: TypeScript-style schema.
- `payload max size`: bytes (rough; multi-row payloads sum element estimates).
- `triggering state(s)`: source state from `STATE_MACHINE §2`.
- `client handler`: matching animation row from `UI_SPEC §6.1`.
- `hidden info?`: YES (intentional reveal cited) / NO.
- `log message template`: BGA log line; `${var}` placeholders interpolate from payload; wrap in `clienttranslate()`.

> **Convention**: all payloads include a server-emitted `notif_id` on the BGA side that is auto-managed; not modeled here. Numeric fields are PHP `int` → JS `number`; booleans are PHP `bool` → JS `boolean`.

### 2.1 `gameStarted`

| Field | Spec |
|---|---|
| name | `gameStarted` |
| recipients | `all` |
| payload | `{ first_player_id: number; agents_per_player: number; bag_size: number; turn_id: 1; }` |
| payload max size | ~80 bytes |
| triggering state(s) | `gameSetup` (STATE_MACHINE §2.1, §8.1) |
| client handler | `gameStartedSplash` (UI_SPEC §6.1; 1000ms) |
| hidden info? | NO — first-player ID is public; bag composition not shipped (STATE_MODEL §4.6) |
| log message template | `clienttranslate('Game start — ${player_name} goes first.')` (`first_player_id` carried as `player_id` to auto-color `${player_name}` per BGA_PRIMER §4) |

### 2.2 `intelDrawn`

| Field | Spec |
|---|---|
| name | `intelDrawn` |
| recipients | `all` |
| payload | `{ tile_id: number; type: IntelTypeId; hex: HexCoord; side: 'left' \| 'right'; new_bag_size: number; skipped: boolean; }` |
| payload max size | ~100 bytes |
| triggering state(s) | `trickleDrawLeft`, `trickleDrawRight` (STATE_MACHINE §2.2, §2.3, §8.2, §8.3) |
| client handler | `intelDrawn` (UI_SPEC §6.1; 250ms) |
| hidden info? | YES — intentional per §10.2 ("when a tile is drawn, the type becomes public"). When `skipped=true` (`bag_size==0` per [D-18]), `tile_id`/`type`/`hex` are unused; client shows the `Bag empty — no draw this turn` banner per UI_SPEC §3.2 |
| log message template | `clienttranslate('Intel drawn (${side}): ${type_name} → top-${side} entry hex.')` (when not skipped); `clienttranslate('Bag empty — ${side}-side draw skipped.')` (when skipped per [D-18]) |

### 2.3 `diceRolled`

| Field | Spec |
|---|---|
| name | `diceRolled` |
| recipients | `all` |
| payload | `{ dice_state: Record<IntelDieKey, DieFace>; turn_id: number; }` (all 6 keys present) |
| payload max size | ~200 bytes |
| triggering state(s) | `trickleRoll` (STATE_MACHINE §2.4, §8.4) |
| client handler | `diceRolled` (UI_SPEC §6.1; 600ms) |
| hidden info? | YES — intentional per §10.5 ("public for the duration of the trickle phase") |
| log message template | `clienttranslate('Trickle dice rolled.')` (compact; client shows the per-die SW/SE arrows visually) |

### 2.4 `agentSpawned`

| Field | Spec |
|---|---|
| name | `agentSpawned` |
| recipients | `all` |
| payload | `{ agent_id: number; type: AgentTypeId; owner: number; hex: HexCoord; spawned_on_turn: number; agents_in_pool: number; agents_on_board: number; }` |
| payload max size | ~150 bytes |
| triggering state(s) | `spawn` (STATE_MACHINE §2.6) |
| client handler | `agentSpawned` (UI_SPEC §6.1; 250ms) |
| hidden info? | NO — pool composition is fully public (STATE_MODEL §4.2) |
| log message template | `clienttranslate('${player_name} spawns a ${type_name} on (${q}, ${r}).')` (`owner` carried as `player_id`) |

### 2.5 `trickleResolved`

| Field | Spec |
|---|---|
| name | `trickleResolved` |
| recipients | `all` |
| payload | `{ moves: Array<{ tile_id: number; from_hex: HexCoord; to_hex: HexCoord; redirected: boolean; off_board: boolean; }>; honeypot_removals: Array<{ agent_id: number; agent_owner: number; agent_type: AgentTypeId; hex: HexCoord; intel_returned: number[]; }>; over_capacity_dumps: Array<{ agent_id: number; dumped_intel: number[]; }>; new_bag_size: number; }` |
| payload max size | ~3KB worst case (≤47 tile entries × ~50 bytes + per-removal/dump entries) |
| triggering state(s) | `trickleResolve` (STATE_MACHINE §2.5, §8.5) |
| client handler | `trickleResolved` composite (UI_SPEC §6.1, §6.2; ≤800ms typical, ~1100ms worst case) |
| hidden info? | YES — Honeypot identities resolve here (already revealed at `intelDrawn`); intentional per §10.2. **Critical**: this notification MUST be batched (one notify per `trickleResolve` invocation). Per-tile streaming would leak bag-size ordering and the order in which Honeypots are evaluated against agents (BGA_PATTERNS pattern 3, STATE_MACHINE §8.5 implementation note). |
| log message template | `clienttranslate('Trickle resolved: ${moves_count} tiles moved, ${removals_count} agent(s) removed, ${dumps_count} dump(s).')` (counts derived client-side from payload) |

> **`trickleResolved` payload structure detail**:
> - `moves[].redirected = true` iff the tile took a non-default diagonal due to a single blockade (§9.6.C). `moves[].off_board = true` iff the destination is outside the Field — in that case `to_hex` is the conceptual edge target and the tile transitions to `state=returned_to_bag` (§9.2). `redirected=true AND off_board=true` is **the locked behavior per [D-24]** (redirect-then-apply, off-board → bag, per rulebook §7.2 step B and §9.6.C).
> - `honeypot_removals[].intel_returned` lists every tile id that returned to bag because of this removal: held intel (§9.4 step 3) + the Honeypot itself + any other arrivals to that hex (per [D-23] resolved interpretation (a) — see DECISIONS.md "Lower-priority adjudications").
> - `over_capacity_dumps[].dumped_intel`: every tile id that returned to bag due to §9.3.
> - `new_bag_size = bag_size_before + |returned_total|` where returned_total spans off-board moves + honeypot returns + over-capacity dumps.
> - **Tiles with no movement** (blocked-pair per §9.6.D, or "no_move" per STATE_MACHINE §8.5 Step B) are NOT included in `moves[]`. Client infers a tile stayed in place by absence (alternative: include with `from==to`).

### 2.6 `agentMoved`

| Field | Spec |
|---|---|
| name | `agentMoved` |
| recipients | `all` |
| payload | `{ agent_id: number; from_hex: HexCoord; to_hex: HexCoord; picked_up_intel: number[]; actions_remaining: number; }` |
| payload max size | ~150 bytes (max picked-up = stack at target) |
| triggering state(s) | `actions` (STATE_MACHINE §2.7; rule §6.3) |
| client handler | `agentMoved` (UI_SPEC §6.1; 250ms; UI_SPEC §4.2 mid-animation Honeypot interrupt if applicable) |
| hidden info? | NO — picked-up intel was loose & public; agent move is public state |
| log message template | `clienttranslate('${player_name} moves agent to (${to_q}, ${to_r}).')` (intel pickup detail rendered visually) |

> **Honeypot move interaction [D-05b]**: when the picked-up set includes a Honeypot, two additional notifications fire after `agentMoved`: `agentRemovedHoneypot` (§2.20) and the Honeypot/intel return is reflected in that notification. See §3 sequencing rules.

### 2.7 `intelTransferred`

| Field | Spec |
|---|---|
| name | `intelTransferred` |
| recipients | `all` |
| payload | `{ intel_id: number; from_agent_id: number; to_agent_id: number; via: 'transfer' \| 'double_agent'; actions_remaining: number; }` |
| payload max size | ~120 bytes |
| triggering state(s) | `actions` — `actTransferIntel` (§6.4) and `actDoubleAgentTransfer` (§6.10) |
| client handler | `intelTransferred` (UI_SPEC §6.1; 200ms) |
| hidden info? | NO — held intel is public per §3.7 / §10.4 |
| log message template | `clienttranslate('${player_name} transfers ${type_name} to agent #${to_agent_id}.')` |

> **Note on `agentDoubleAgentTransferred`**: A11 collapses the two cited notifications into one with a `via` discriminator. Client animations (`UI_SPEC §6.1` `intelTransferred`, 200ms) are visually identical; the only distinction is `via='double_agent'` skips the adjacency assertion. `STATE_MACHINE §9` lists separate row names; reconcile by reading `via` field.

### 2.8 `agentRetired`

| Field | Spec |
|---|---|
| name | `agentRetired` |
| recipients | `all` |
| payload | `{ agent_id: number; agent_type: AgentTypeId; agent_owner: number; hex: HexCoord; scored_intel: Array<{ id: number; type: IntelTypeId; score_value: number; }>; score_delta: number; new_score: number; analyst_bonus_pending: boolean; }` |
| payload max size | ~400 bytes (max 3 scored_intel entries) |
| triggering state(s) | `actions` (STATE_MACHINE §2.7; rule §6.5; [D-14]) |
| client handler | `agentRetired` (UI_SPEC §6.1; 250ms + N×200ms) |
| hidden info? | NO — held intel public per §3.7 / §10.4; score public per [D-11] |
| log message template | `clienttranslate('${player_name} retires ${type_name} for ${score_delta} points (total: ${new_score}).')` |

> **`scored_intel` field contract per [D-14]**: array contains every tile that was on the agent at retire time. Sum of `score_value` equals `score_delta` (modulo Analyst bonus, which fires as a separate `analystBonusKept` per §2.10). `analyst_bonus_pending=true` signals the client to expect a follow-up `analystBonusDrawn` / `analystBonusKept` notification before any subsequent action notification.

### 2.9 `analystBonusDrawn` [D-20, D-26]

| Field | Spec |
|---|---|
| name | `analystBonusDrawn` |
| recipients | **`player(active_player)`** (private — locked per [D-20]) |
| payload | `{ tile_id: number; type: IntelTypeId; score_value: number; new_bag_size: number; }` (private payload — opponent never sees this) |
| payload max size | ~100 bytes |
| triggering state(s) | `analystBonusDecision` (`onEnteringState`, fired immediately after the bag draw per [D-26] step 4) |
| client handler | `analystBonusDrawn` (UI_SPEC §6.1; 250ms) |
| hidden info? | YES — reveals one bag tile **to the active player only** per [D-20]. Opponent and spectators do NOT receive this notification; they see only the public companion (`analystBonusKept` or `analystBonusReturned`). The `tile_type` is intentionally omitted from any public payload to avoid a bag-composition leak when the player chooses `return`. |
| log message template | (private to active player) `clienttranslate('Analyst bonus drawn: ${type_name} — keep or return?')` |

> **Locked per [D-20] / [D-26]**: this notification is private. The flow is:
> 1. Client sends `actRetireAgent({ agent_id })` with NO `analyst_keep_decision` (the field is removed per [D-26]).
> 2. Server validates retire, scores held intel, transitions to `analystBonusDecision` per [D-26].
> 3. Server's `analystBonusDecision.onEnteringState` draws the bonus tile (or skips if `bag_size == 0` per [D-18], firing `analystBonusSkipped` instead).
> 4. Server fires `analystBonusDrawn` privately to the active player.
> 5. Client renders modal (UI_SPEC §3.x); player picks `actAnalystKeep` or `actAnalystReturn`.
> 6. Server fires the matching public notification (`analystBonusKept` reveals type per §2.10; `analystBonusReturned` per §2.10b carries NO type per [D-20]).
> 7. Server transitions back to `actions` (or `gameEnd` via `gameWin`).

### 2.10 `analystBonusKept` [D-20, D-26]

| Field | Spec |
|---|---|
| name | `analystBonusKept` |
| recipients | `all` (public) |
| payload | `{ player_id: number; tile_id: number; tile_type: IntelTypeId; score_value: number; score_delta: number; new_score: number; new_bag_size: number; }` (type publicly revealed at this point per [D-20]) |
| payload max size | ~150 bytes |
| triggering state(s) | `analystBonusDecision` (on `actAnalystKeep` per [D-26]) |
| client handler | `analystBonusKept` (UI_SPEC §6.1; 250ms; slide-to-score-zone) |
| hidden info? | YES — intentional reveal: the kept tile is publicly scored, so its type is exposed to all (consistent with `intelDrawn` semantics). |
| log message template | `clienttranslate('${player_name} keeps the Analyst bonus (${type_name}, +${score_delta}).')` |

### 2.10b `analystBonusReturned` [D-20, D-26]

| Field | Spec |
|---|---|
| name | `analystBonusReturned` |
| recipients | `all` (public) |
| payload | `{ player_id: number; new_bag_size: number; }` — **NO `tile_type`, `tile_id`, or `score_value`** per [D-20]. The opponent must not learn the returned tile's type (would leak bag composition). |
| payload max size | ~60 bytes |
| triggering state(s) | `analystBonusDecision` (on `actAnalystReturn` per [D-26]) |
| client handler | `analystBonusReturned` (UI_SPEC §6.1; 250ms; slide-back-to-bag) |
| hidden info? | NO public reveal — only the active player ever knew the type (private `analystBonusDrawn`); on return, the type stays hidden from opponent/spectators. |
| log message template | `clienttranslate('${player_name} returns the Analyst bonus to the bag.')` |

### 2.10c `analystBonusSkipped` [D-18, D-26]

| Field | Spec |
|---|---|
| name | `analystBonusSkipped` |
| recipients | `all` (public) |
| payload | `{ player_id: number; }` |
| payload max size | ~40 bytes |
| triggering state(s) | `analystBonusDecision` (`onEnteringState`, fires when `bag_size == 0` per [D-18]; the state is bypassed and control returns to `actions`) |
| client handler | (UI banner: `Bag empty — bonus forfeited`) |
| hidden info? | NO |
| log message template | `clienttranslate('Bag empty — Analyst bonus forfeited.')` |

### 2.11 `blockadePlaced`

| Field | Spec |
|---|---|
| name | `blockadePlaced` |
| recipients | `all` |
| payload | `{ blockade_id: number; owner: number; hex: HexCoord; placed_on_turn: number; via: 'engineer_adjacent' \| 'engineer_anywhere'; intel_spent: { id: number; type: IntelTypeId } \| null; blockades_in_pool: number; actions_remaining: number; }` |
| payload max size | ~200 bytes |
| triggering state(s) | `actions` — `actEngineerPlaceBlockadeAdjacent` (§6.6.A), `actEngineerPlaceBlockadeAnywhere` (§6.6.B) |
| client handler | `blockadePlaced` (UI_SPEC §6.1; 250ms) |
| hidden info? | NO — board state public; intel_spent was held intel (public) |
| log message template | `clienttranslate('${player_name} places a blockade on (${q}, ${r}).')` (with optional `'(spent ${type_name})'` suffix when `intel_spent != null`) |

### 2.12 `blockadeExpired`

| Field | Spec |
|---|---|
| name | `blockadeExpired` |
| recipients | `all` |
| payload | `{ cleared_blockades: Array<{ blockade_id: number; owner: number; hex: HexCoord; }>; }` (possibly empty) |
| payload max size | ~300 bytes (max ~6 blockades — 3 per player) |
| triggering state(s) | `endOfTurnCleanup` (STATE_MACHINE §2.8, §8.6 step 2; rule §7.4 / [D-07]) |
| client handler | `blockadeExpired` (UI_SPEC §6.1; 250ms each) |
| hidden info? | NO |
| log message template | `clienttranslate('${count} blockade(s) expired.')` (skipped when empty) |

### 2.13 `agentPinned`

| Field | Spec |
|---|---|
| name | `agentPinned` |
| recipients | `all` |
| payload | `{ hacker_id: number; target_agent_id: number; target_owner: number; pinned_until_turn: number; actions_remaining: number; }` |
| payload max size | ~120 bytes |
| triggering state(s) | `actions` — `actHackerPin` (§6.11.A, [D-15]) |
| client handler | `agentPinned`/`Unpinned` (UI_SPEC §6.1; 200ms) |
| hidden info? | NO — pin state public per `STATE_MODEL §4.2`; needed for opponent threat-modeling |
| log message template | `clienttranslate('${player_name}\\'s Hacker pins agent #${target_agent_id} until turn ${pinned_until_turn}.')` |

> **Setter formula [FINDING-04]**: `pinned_until_turn = current_turn_id + (active_player == target_owner ? 2 : 1)`. Since Hacker can only pin enemy agents, the formula simplifies to `pinned_until_turn = current_turn_id + 1` (the pinned agent's next turn ends one turn later, and cleanup at that turn end runs `pinned_until_turn <= current_turn_id` per `STATE_MODEL §9.11`). **A7 must implement this formula explicitly**; A11 records it here for the contract.

### 2.14 `agentUnpinned`

| Field | Spec |
|---|---|
| name | `agentUnpinned` |
| recipients | `all` |
| payload | `{ hacker_id: number; target_agent_id: number; target_owner: number; actions_remaining: number; }` |
| payload max size | ~100 bytes |
| triggering state(s) | `actions` — `actHackerUnpin` (§6.11.B; shares slot with pin per [D-15]) |
| client handler | `agentPinned`/`Unpinned` (UI_SPEC §6.1; 200ms) |
| hidden info? | NO |
| log message template | `clienttranslate('${player_name}\\'s Hacker unpins agent #${target_agent_id}.')` |

### 2.15 `pinExpired`

| Field | Spec |
|---|---|
| name | `pinExpired` |
| recipients | `all` |
| payload | `{ cleared_agents: Array<{ agent_id: number; agent_owner: number; }>; }` (possibly empty) |
| payload max size | ~300 bytes |
| triggering state(s) | `endOfTurnCleanup` (STATE_MACHINE §2.8, §8.6 step 1; rule §7.4 / [D-06a]) |
| client handler | `pinExpired` (UI_SPEC §6.1; 200ms each) |
| hidden info? | NO |
| log message template | `clienttranslate('${count} pin(s) expired.')` (skipped when empty) |

### 2.16 `intelStolen`

| Field | Spec |
|---|---|
| name | `intelStolen` |
| recipients | `all` |
| payload | `{ hacker_id: number; target_agent_id: number; target_owner: number; stolen_intel: { id: number; type: IntelTypeId; score_value: number; }; intel_spent: { id: number; type: IntelTypeId; }; new_bag_size: number; }` |
| payload max size | ~200 bytes |
| triggering state(s) | `actions` — `actHackerStealIntel` (§6.11.C, [D-15]) |
| client handler | `intelStolen` (UI_SPEC §6.1; 250ms) |
| hidden info? | NO — held intel public per §3.7 |
| log message template | `clienttranslate('${player_name}\\'s Hacker steals ${type_name} from agent #${target_agent_id}.')` |

### 2.17 `agentSwapped`

| Field | Spec |
|---|---|
| name | `agentSwapped` |
| recipients | `all` |
| payload | `{ smuggler_id: number; agent_a_id: number; agent_a_old_hex: HexCoord; agent_a_new_hex: HexCoord; agent_b_id: number; agent_b_old_hex: HexCoord; agent_b_new_hex: HexCoord; intel_spent: { id: number; type: IntelTypeId; }; new_bag_size: number; actions_remaining: number; }` |
| payload max size | ~300 bytes |
| triggering state(s) | `actions` — `actSmugglerSwapAgents` (§6.8) |
| client handler | `agentsSwapped` (UI_SPEC §6.1; 300ms) |
| hidden info? | NO |
| log message template | `clienttranslate('${player_name}\\'s Smuggler swaps agents #${agent_a_id} and #${agent_b_id}.')` |

> **`STATE_MACHINE §9`** names this `agentsSwapped`. A11 standardizes to `agentSwapped` (singular noun + past participle, consistent with `agentMoved`, `agentPinned`, `agentRetired`). A7 must use this exact name. **[D-21] locked**: under the universal pickup invariant, a swap creating co-occupation with loose intel triggers immediate pickup; if the loose intel is a Honeypot, §9.4 fires. `agentSwapped` may carry `picked_up_intel: number[]` if any pickup occurred during the swap; a follow-up `agentDumpedOvercapacity` (or `agentRemovedHoneypot`) fires per the universal rules. In practice, the pickup invariant means loose intel and an agent never co-occupy at rest, so this clause is defensive.

### 2.18 `agentDoubleAgentTransferred`

> **Note**: per §2.7 above, A11 collapses Double-Agent transfer into the `intelTransferred` notification with `via='double_agent'`. This row is preserved here as a cross-reference: A7 fires `intelTransferred` (§2.7), not a separate `agentDoubleAgentTransferred`. `STATE_MACHINE §9` lists no separate notification either.

### 2.19 `agentRemovedHoneypot`

| Field | Spec |
|---|---|
| name | `agentRemovedHoneypot` |
| recipients | `all` |
| payload | `{ agent_id: number; agent_owner: number; agent_type: AgentTypeId; hex: HexCoord; intel_returned: number[]; trigger: 'trickle' \| 'move'; new_bag_size: number; }` |
| payload max size | ~200 bytes |
| triggering state(s) | `trickleResolve` (already batched into `trickleResolved.honeypot_removals[]`); `actions` (`actMoveAgent` per [D-05b]) |
| client handler | `agentRemoved` (UI_SPEC §6.1; 300ms) |
| hidden info? | NO — Honeypot identity already revealed at `intelDrawn` |
| log message template | `clienttranslate('${player_name}\\'s ${type_name} (#${agent_id}) hits a Honeypot and is removed.')` |

> **Sequencing for `actMoveAgent` onto Honeypot**: `agentMoved` fires first (the move happens), then `agentRemovedHoneypot` fires within the same action handler. Client animation: agent slides to target, then fades out. See §3.
>
> **For trickle**: this notification is **NOT fired separately** during `trickleResolve` — it is folded into `trickleResolved.honeypot_removals[]`. `STATE_MACHINE §8.5` calls out the implementation note: "A7 may emit fine-grained notifications in addition to the batched `trickleResolved` for animation flexibility. The batched form is canonical for hidden-info safety." A11 enforces: in `trickleResolve`, fire ONLY the batched form; in `actions`, fire `agentRemovedHoneypot` directly.

### 2.20 `agentDumpedOvercapacity`

| Field | Spec |
|---|---|
| name | `agentDumpedOvercapacity` |
| recipients | `all` |
| payload | `{ agent_id: number; agent_owner: number; dumped_intel: number[]; trigger: 'trickle' \| 'transfer' \| 'double_agent_transfer' \| 'steal'; new_bag_size: number; }` |
| payload max size | ~200 bytes (max 4 dumped_intel ids, since dump fires when held becomes 4) |
| triggering state(s) | `trickleResolve` (batched into `trickleResolved.over_capacity_dumps[]`); `actions` (any held-intel-mutating action: §6.4 transfer, §6.10 double-agent transfer, §6.11.C steal — per §9.3 and FINDING-10) |
| client handler | `agentDumped` (UI_SPEC §6.1; 300ms) |
| hidden info? | NO — dumped tiles were held intel (public) |
| log message template | `clienttranslate('Agent #${agent_id} exceeds capacity — ${count} intel dumped to bag.')` |

> **Like §2.19, batched into `trickleResolved` during trickle; emitted standalone during `actions` only.**

### 2.21 `actionsBoosted`

| Field | Spec |
|---|---|
| name | `actionsBoosted` |
| recipients | `all` |
| payload | `{ smuggler_id: number; smuggler_owner: number; intel_spent: { id: number; type: IntelTypeId; }; new_actions_remaining: number; smuggler_boost_used_this_turn: true; new_bag_size: number; }` |
| payload max size | ~150 bytes |
| triggering state(s) | `actions` — `actSmugglerBoostActions` (§6.7, [D-08]) |
| client handler | `actionsBoosted` (UI_SPEC §6.1; 200ms) |
| hidden info? | NO |
| log message template | `clienttranslate('${player_name}\\'s Smuggler boosts: action cap raised to 4.')` |

### 2.22 `intelMoved`

| Field | Spec |
|---|---|
| name | `intelMoved` |
| recipients | `all` |
| payload | `{ intel_id: number; intel_type: IntelTypeId; comms_id: number; from_hex: HexCoord; to_hex: HexCoord; direction: 'NW' \| 'NE' \| 'SW' \| 'SE'; intel_spent: { id: number; type: IntelTypeId } \| null; new_bag_size: number; actions_remaining: number; }` |
| payload max size | ~200 bytes |
| triggering state(s) | `actions` — `actCommsMoveIntelUp` (§6.9.A; `intel_spent=null`), `actCommsMoveIntelDown` (§6.9.B; `intel_spent` populated) |
| client handler | `intelMovedUp`/`intelMovedDown` (UI_SPEC §6.1; 250ms; client picks per `direction` value) |
| hidden info? | NO |
| log message template | `clienttranslate('${player_name}\\'s Comms moves ${intel_type_name} ${direction}.')` |

> **`STATE_MACHINE §9`** lists `intelMovedUp` and `intelMovedDown` separately. A11 collapses into one with `direction` and `intel_spent`. The client picks the matching animation by `direction ∈ {NW, NE}` (up) vs `{SW, SE}` (down).

### 2.23 `actionsRemaining`

> **Decision**: A11 does **NOT** add a standalone `actionsRemaining` notification. The `actions_remaining` field is included in the payload of every action that mutates it (`agentMoved`, `intelTransferred`, `agentRetired`, `blockadePlaced`, `actionsBoosted`, `agentSwapped`, `intelMoved`, `agentPinned`, `agentUnpinned`). `actHackerStealIntel` carries the value too (rule: free, no decrement; payload still echoes for UI consistency). State-args refresh on every self-loop entry to `actions` per `STATE_MACHINE §11.4`. Adding a standalone `actionsRemaining` would double the notification count without adding information.
>
> Per the agent prompt's request for "server-state echo, fires on every action consumption": the echo is **embedded** in each action notification's payload, not a separate notification. This keeps notification count low (BGA_PRIMER §4 guidance) and respects the 128KB/action cap.

### 2.24 `scoreUpdated`

| Field | Spec |
|---|---|
| name | `scoreUpdated` |
| recipients | `all` |
| payload | `{ player_id: number; new_score: number; delta: number; }` |
| payload max size | ~80 bytes |
| triggering state(s) | `actions` — fired alongside `agentRetired` and `analystBonusKept` whenever `score_delta != 0`. **Always paired** with the originating notification; carried separately so BGA's auto-score-display reflects immediately. |
| client handler | `scoreUpdated` (UI_SPEC §6.1; 300ms) |
| hidden info? | NO — score public per [D-11] |
| log message template | `clienttranslate('${player_name}: ${new_score} points (+${delta}).')` |

### 2.25 `turnEnded`

| Field | Spec |
|---|---|
| name | `turnEnded` |
| recipients | `all` |
| payload | `{ ended_player_id: number; new_active_player_id: number; new_turn_id: number; }` |
| payload max size | ~80 bytes |
| triggering state(s) | `endOfTurnCleanup` (STATE_MACHINE §2.8, §8.6 step 6) |
| client handler | `turnEnded` (UI_SPEC §6.1; 300ms) |
| hidden info? | NO |
| log message template | `clienttranslate('Turn ${new_turn_id} — ${player_name} to play.')` (using `new_active_player_id`) |

### 2.26 `gameEnded`

| Field | Spec |
|---|---|
| name | `gameEnded` |
| recipients | `all` |
| payload | `{ winner_id: number; win_reason: 'score_20' \| 'depletion'; final_scores: Record<string /* player_id */, number>; }` |
| payload max size | ~150 bytes |
| triggering state(s) | `gameEnd` (STATE_MACHINE §2.9; from `actions`/`trickleResolve`/`endOfTurnCleanup` via `gameWin` transition per §4) |
| client handler | `gameEnded` (UI_SPEC §6.1, §3.9; 800ms) |
| hidden info? | NO |
| log message template | `clienttranslate('Game over — ${player_name} wins (${win_reason_text}).')` (`win_reason_text` resolved client-side: 'reached 20 points' or 'opponent depleted') |

---

## 3. Sequencing rules

For multi-notification action sequences, the server emits notifications in the order below. The client processes them sequentially (BGA's notification dispatch is in-order; modern frontends use `setupPromiseNotifications` to chain animations per BGA_PRIMER §5).

### 3.1 `actMoveAgent` (§6.3)

**Standard move (no Honeypot pickup)**:
1. `agentMoved` (move + pickup-non-Honeypot intel; `actions_remaining` decremented).
2. `agentDumpedOvercapacity` (only if pickup pushed held > 3, per §9.3 and FINDING-10).

**Honeypot move per [D-05b]**:
1. `agentMoved` — slides to target hex (carries the picked-up Honeypot in `picked_up_intel[]`).
2. `agentRemovedHoneypot` — removes agent; lists all returned intel (held + Honeypot). `trigger='move'`.

> Per FINDING-10, Honeypot check fires before over-capacity dump on every action-phase trigger. So if the move's pickup includes a Honeypot AND would have pushed held > 3, `agentRemovedHoneypot` fires; `agentDumpedOvercapacity` does NOT (agent already gone).

### 3.2 `actRetireAgent` (§6.5, [D-14])

**Plain retire**:
1. `agentRetired` — with `analyst_bonus_pending=false`.
2. `scoreUpdated` — same active player.
3. (If win) `gameEnded` (§2.26); state transitions to `gameEnd` via `gameWin`.
4. (Else, if depletion per [D-17]) `gameEnded` with `win_reason='depletion'`.

**Analyst retire with 3 intel + non-empty bag** [D-26 two-step flow]:
1. `agentRetired` — with `analyst_bonus_pending=true`.
2. `scoreUpdated` — for the held-intel scoring.
3. (Server transitions to `analystBonusDecision` per [D-26].)
4. `analystBonusDrawn` (private to active player per §2.9 and [D-20]).
5. (Player chooses `actAnalystKeep` or `actAnalystReturn`.)
6. On `keep`: `analystBonusKept` (public; reveals type per §2.10) → `scoreUpdated` for the bonus → (if win) `gameEnded`.
7. On `return`: `analystBonusReturned` (public; carries NO type per [D-20] / §2.10b) → no score change.
8. (Server transitions back to `actions`, or to `gameEnd` if win/depletion fired.)

**Analyst retire with 3 intel + empty bag** (per [D-18]):
1. `agentRetired` — with `analyst_bonus_pending=true`.
2. `scoreUpdated` — for the held-intel scoring.
3. (Server briefly transitions to `analystBonusDecision`; `onEnteringState` detects empty bag.)
4. `analystBonusSkipped` (public; per §2.10c).
5. (Server transitions back to `actions`.) No bonus draw or decision occurs.

### 3.2b `actAnalystKeep` / `actAnalystReturn` [D-26]

These two actions are legal only in the `analystBonusDecision` state (per [D-26]). Inputs: none beyond confirmation (the action name itself encodes the choice).

**`actAnalystKeep`** sequencing:
1. `analystBonusKept` (public; reveals tile type per [D-20] / §2.10).
2. `scoreUpdated` (for the bonus's `score_value`).
3. (If win) `gameEnded` (transition `analystBonusDecision → gameEnd` via `gameWin`).
4. (Else, if depletion) `gameEnded` with `win_reason='depletion'`.
5. (Else) Server transitions back to `actions`.

**`actAnalystReturn`** sequencing:
1. `analystBonusReturned` (public; carries NO type per [D-20] / §2.10b).
2. (If depletion) `gameEnded` with `win_reason='depletion'`.
3. (Else) Server transitions back to `actions`.

> Neither action is undoable per STATE_MACHINE §2.7b and §5.1 (would re-roll the random draw).

### 3.3 `actEngineerPlaceBlockadeAnywhere` (§6.6.B)

1. `blockadePlaced` — with `via='engineer_anywhere'` and `intel_spent` populated. (Spent intel returns to bag — reflected in `new_bag_size`.)

### 3.4 `actSmugglerBoostActions` (§6.7)

1. `actionsBoosted` — single notification; intel-to-bag, action cap raised, boost flag set.

### 3.5 `actSmugglerSwapAgents` (§6.8)

1. `agentSwapped` — single notification.

> Per **[D-21] locked** (universal pickup invariant): if a swap creates co-occupation with loose intel, `agentSwapped.picked_up_intel` populated; potential `agentDumpedOvercapacity` or `agentRemovedHoneypot` follow-up. In practice this clause is defensive (the invariant prevents the precondition state).

### 3.6 `actCommsMoveIntelUp` / `actCommsMoveIntelDown` (§6.9)

1. `intelMoved` — direction differentiates up vs down; `intel_spent` non-null only for down.

### 3.7 `actHackerPin` / `actHackerUnpin` / `actHackerStealIntel` (§6.11)

1. `agentPinned` / `agentUnpinned` / `intelStolen` (one per action).
2. (`intelStolen` only) `agentDumpedOvercapacity` — only if stealing pushes held > 3 (impossible if Hacker has just paid intel; would require Hacker to have started at exactly 3, paid one — net zero — and then stolen — net +1 = 4. **Edge case**: H starts at 3; pays 1 (3→2); steals 1 (2→3). Never exceeds 3. Dump cannot fire here. Listed for completeness; A7 may omit the check defensively.)

### 3.8 `actDoubleAgentTransfer` (§6.10)

1. `intelTransferred` — with `via='double_agent'`.
2. `agentDumpedOvercapacity` — only if target's held became > 3 (recipient over-capacity per §9.3).

### 3.9 `endOfTurnCleanup` (STATE_MACHINE §8.6)

1. `pinExpired` (always; payload may be empty).
2. `blockadeExpired` (always; payload may be empty).
3. (If `gameWin` triggered by depletion check, step 5) `gameEnded` — and skip the rest.
4. `turnEnded` — flips active player, increments turn_id.

### 3.10 `trickleResolve` (STATE_MACHINE §8.5)

Single notification: `trickleResolved`. All sub-events are batched into the `moves[] / honeypot_removals[] / over_capacity_dumps[]` arrays. Per BGA_PATTERNS pattern 3 and STATE_MACHINE §8.5 implementation note, **NO per-piece notifications fire** during trickle resolution. The client decomposes the batch into UI animations per `UI_SPEC §6.2`.

If the trickle ends the game (depletion per [D-17]), `gameEnded` fires AFTER `trickleResolved`.

### 3.11 General order

Within a single PHP action handler (one HTTP request), notifications are queued and dispatched atomically when the handler returns (BGA_PRIMER §4). The client's `setupPromiseNotifications` orders animations strictly by emit order. Server MUST emit in the order specified above to avoid client-side reordering.

### 3.12 Intentional reveal assertions [F-32, F-34]

Several public notifications carry information that was previously hidden but is **intentionally revealed** at the trigger point. These are documented as intentional reveals (rule-cited), not leaks:

- **`intelDrawn`** [F-32]: the drawn tile's type is revealed publicly. **Intentional reveal; rule-cited per rulebook §10.2 ("when a tile is drawn ... the type becomes public") — the tile is placed face-up on the entry hex and remains visible to both players.** This is the rulebook's canonical contract; downstream consumers should not treat it as a leak.
- **`agentPinned.pinned_until_turn`** [F-34]: the integer turn at which a pin clears is exposed publicly. **Intentional reveal; rule-cited per rulebook §3.7 / §10 / STATE_MODEL §4.2 — opponents must know which agents are pinned and for how long to plan around them (legal-action prediction).** Hidden pin-expiry would prevent legal opponent planning.
- `diceRolled.dice_state`: all 6 dice outcomes — intentional per rulebook §10.5 (public during trickle).
- `trickleResolved.honeypot_removals`: which trickled tile was a Honeypot — intentional; the Honeypot's type was already public from `intelDrawn`.
- `analystBonusKept.tile_type`: revealed publicly when the bonus is kept (the kept tile is scored). Intentional per [D-20].

`analystBonusReturned` deliberately omits `tile_type` per [D-20] — see §2.10b.

### 3.13 Explicit ordering of `intelDrawn` notifications [F-39]

Per STATE_MACHINE §2.2 / §2.3 and §8.2 / §8.3, the trickle phase fires `intelDrawn` **twice in sequence**: first for `trickleDrawLeft` (top-left entry hex), then for `trickleDrawRight` (top-right entry hex). The ordering is locked: **top-left first, then top-right**, never reversed, never parallel.

Rationale:
1. The two states are sequential in `STATE_MACHINE §1` (state diagram): `trickleDrawLeft → trickleDrawRight → trickleRoll`.
2. The client's `setupPromiseNotifications` chains animations strictly by emit order (BGA_PRIMER §5); the player sees the left tile slide-and-reveal before the right.
3. The `intelDrawn` payload carries `side: 'left' | 'right'` to disambiguate, but consumers that ignore `side` may rely on order alone.
4. Empty-bag case [D-18]: if `bag_size == 0` at either draw, that draw's notification is fired with `skipped=true` — the order is preserved (left's skip notification still precedes right's draw or skip).

A7 MUST emit `intelDrawn` in this order; A8 MUST process them in receipt order without reordering.

---

## 4. Hidden-info filtering rules

This section is the audit pass. It walks every notification and `getAllDatas()` field and verifies no hidden state leaks.

### 4.1 Per-notification filter

| Notification | Recipient | Hidden info present in payload? | Filter |
|---|---|---|---|
| `gameStarted` | all | none | bag_size only (count) |
| `intelDrawn` | all | type of drawn tile (intentional reveal §10.2) | none — type was hidden, becomes public on draw |
| `diceRolled` | all | dice outcomes (intentional reveal §10.5) | none |
| `agentSpawned` | all | none | n/a |
| `trickleResolved` | all | Honeypot identity (already public from `intelDrawn`) | **batched** form prevents per-piece order leaks |
| `agentMoved` | all | none | n/a |
| `intelTransferred` | all | none — held intel public | n/a |
| `agentRetired` | all | none — held intel public; score public [D-11] | n/a |
| `analystBonusDrawn` | **player(active)** | type of one bag tile (only active player can see it) | `notify->player($active_player_id, ...)`. **Locked private per [D-20]**. Spectators and the opponent never see this notification. |
| `analystBonusKept` | all | tile type (intentional reveal — kept tile is scored) | n/a |
| `analystBonusReturned` | all | none — `tile_type` intentionally omitted per [D-20] | payload contains only `{ player_id, new_bag_size }` |
| `analystBonusSkipped` | all | none ([D-18] empty-bag case) | n/a |
| `blockadePlaced` | all | none | n/a |
| `blockadeExpired` | all | none | n/a |
| `agentPinned` | all | none | n/a |
| `agentUnpinned` | all | none | n/a |
| `pinExpired` | all | none | n/a |
| `intelStolen` | all | none — held intel public | n/a |
| `agentSwapped` | all | none | n/a |
| `agentRemovedHoneypot` | all | Honeypot identity (already public) | n/a |
| `agentDumpedOvercapacity` | all | none — dumped tiles were held intel (public) | n/a |
| `actionsBoosted` | all | none | n/a |
| `intelMoved` | all | none | n/a |
| `scoreUpdated` | all | none — score public [D-11] | n/a |
| `turnEnded` | all | none | n/a |
| `gameEnded` | all | none — final scores public | n/a |

### 4.2 `getAllDatas()` filter audit

Per `STATE_MODEL §4`:
- Bag tile rows (`state IN {0, 4}`) excluded from `intel_on_board`, `intel_revealed`, `scored_intel`, `agents[].intel_held`. Only `bag_size` shipped. ✅
- All agent state public per `STATE_MODEL §4.2`. ✅
- Score public per `[D-11]`. ✅
- `dice_state` empty `{}` outside trickle phase. ✅
- `bga_rand` seeds never shipped (BGA-managed). ✅

### 4.2b `intelDrawn` ordering [F-39]

Per §3.13, the two `intelDrawn` notifications fired during the trickle phase MUST be emitted in this exact order: **top-left first, then top-right**. The order is established by the sequential states `trickleDrawLeft → trickleDrawRight` (STATE_MACHINE §1) and is preserved in BGA's notification dispatch. The `side: 'left' | 'right'` payload field is informational; consumers may rely on receipt order alone. Empty-bag `[D-18]` cases preserve the order — `skipped=true` notifications fire in the same `left → right` sequence.

A7 MUST emit in this order; A8 MUST process in receipt order without reordering. Audited as leak-free in §4.1.

### 4.3 Spectator audit

Spectators receive identical filtering to players (`STATE_MODEL §4.7`). The only private notification (`analystBonusDrawn` per §2.9) reaches the active player only; spectators see the public companion `analystBonusKept` per §2.10. No additional filtering needed.

---

## 5. Versioning / migration (recommendation)

**Recommendation: rely on BGA's framework versioning**. The `gameinfos.jsonc` `game_version` field bumps on schema changes; BGA enforces single-version play (replays use the version they were recorded under per BGA_PRIMER §1). Schema changes during gameplay are forbidden (BGA_PRIMER §2: "schema is immutable during gameplay"; post-release changes use `upgradeTableDb()`).

Adding a payload `version: number` field on every notification is **not recommended** — it doubles bytes for no gain. A single forward-incompatible change in any notification payload should bump `gameinfos.game_version`; the framework prevents mixed-version play.

**Stub for future**: if a pre-release migration becomes necessary, add an optional `_v: number` field to `getAllDatas()` only (notifications stay versionless). A future `upgradeTableDb($from_version)` would migrate DB rows and the contract bumps `_v` accordingly.

---

## 6. Notification name registry

For A7's `setupNotifications()` (or `setupPromiseNotifications()`) and A8's CSS animation classes:

| # | Name | Recipients | Hidden info? |
|---|---|---|---|
| 1 | `gameStarted` | all | NO |
| 2 | `intelDrawn` | all | YES (intentional) |
| 3 | `diceRolled` | all | YES (intentional) |
| 4 | `agentSpawned` | all | NO |
| 5 | `trickleResolved` | all | YES (intentional, batched) |
| 6 | `agentMoved` | all | NO |
| 7 | `intelTransferred` | all | NO |
| 8 | `agentRetired` | all | NO |
| 9 | `analystBonusDrawn` | player(active) | YES (private to active per [D-20]) |
| 10 | `analystBonusKept` | all | YES (intentional; kept tile is scored per [D-20]) |
| 10b | `analystBonusReturned` | all | NO (no type in payload per [D-20]) |
| 10c | `analystBonusSkipped` | all | NO ([D-18] empty-bag) |
| 11 | `blockadePlaced` | all | NO |
| 12 | `blockadeExpired` | all | NO |
| 13 | `agentPinned` | all | NO |
| 14 | `agentUnpinned` | all | NO |
| 15 | `pinExpired` | all | NO |
| 16 | `intelStolen` | all | NO |
| 17 | `agentSwapped` | all | NO |
| 18 | `agentRemovedHoneypot` | all | NO (Honeypot already public) |
| 19 | `agentDumpedOvercapacity` | all | NO |
| 20 | `actionsBoosted` | all | NO |
| 21 | `intelMoved` | all | NO |
| 22 | `scoreUpdated` | all | NO |
| 23 | `turnEnded` | all | NO |
| 24 | `gameEnded` | all | NO |

**Total: 26 notifications** (24 original + `analystBonusReturned` and `analystBonusSkipped` added per [D-20] / [D-26]). All under 128KB cap individually; only `trickleResolved` exceeds 1KB in worst case (~3KB).

---

## 7. Implementation directives

A7 (backend) MUST:
- Use exact notification names from §6 (camelCase, no underscores).
- Include `player_id`-typed fields (`owner`, `agent_owner`, `target_owner`, `winner_id`, etc.) so BGA auto-colors `${player_name}` in log lines (BGA_PRIMER §4).
- Wrap `clienttranslate()` around every human-visible string template.
- Emit notifications in the sequencing order specified in §3.
- Include `actions_remaining` echo on every `actions`-state action notification (per §2.23).
- Batch `trickleResolve` into a single `trickleResolved` notification (§3.10).
- Use `notify->player` (private) ONLY for `analystBonusDrawn` (locked per [D-20]).

A8 (frontend) MUST:
- Register handlers for every name in §6 via `setupPromiseNotifications()` (BGA_PRIMER §5).
- Wire each handler to its `UI_SPEC §6.1` animation row.
- Honor `prefers-reduced-motion` per UI_SPEC §6.4.
- For `trickleResolved`, decompose the batched payload per UI_SPEC §6.2 sub-animation sequence.
- For `analystBonusDrawn`, accept the private notification and prompt the player; the public `analystBonusKept` follows the player's response.

---

End of `docs/specs/CONTRACT.md`.
