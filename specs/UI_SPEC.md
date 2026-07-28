# Hexpionage — BGA Frontend Specification (A6)

> **Purpose**: translate the physical interaction model into a BGA frontend spec. One screen per state, one click affordance per action, one tooltip per ability, one animation per public state-mutating notification.
>
> **Inputs**: `rulebook.md`, `DECISIONS.md`, `specs/STATE_MACHINE.md`, `assets/MANIFEST.md`, `assets/MISSING.md`, `specs/BGA_PRIMER.md` §6/§7, `specs/STATE_MODEL.md` §3. **Out of scope**: CSS/JS source (A8), notification schema (A11), sprite pixel coordinates (asset pipeline).
>
> **CSS class prefix**: all custom classes use `hxp_` per `BGA_PRIMER §6`. Citations: `§N.N` = rulebook; `[D-NN]` = decisions; `STATE_MACHINE §N`, `STATE_MODEL §N`, `MANIFEST §N`, `MISSING §N`.

---

## 1. Layout overview

The play surface is dominated by `board.png` (the unfolded hex board, MANIFEST §A; resized to 1200×608 desktop, 800×405 tablet/mobile breakpoint). The board art has the Field shading, ✦ spawn markers, score-track digits, and intel-entry "1"/"2" labels baked in (MISSING §§8–11), so all per-hex affordances live as an *invisible click overlay* on top of the PNG. Side panels carry per-player state that doesn't fit on the board (agent reserve count, blockade reserve count, agents-on-board summary, action counter, dice tray during trickle).

### 1.1 Desktop layout (`viewport ≥ 1280px`)

Board is centered; two side panels flank it; sticky bottom action bar and notification log; top strip with BGA-standard player roster and turn/phase indicators.

```
+---------------------------------------------------------------------------+
|  HXP_TOPBAR — roster + phase breadcrumb [TRICKLE]→[SPAWN]→[ACTIONS]   T7  |
+----------------+------------------------------------+---------------------+
| HXP_PANEL_LEFT |          HXP_BOARD_WRAP            | HXP_PANEL_RIGHT     |
| P1 white       |  board.png (1200×608)              | P2 black            |
| Reserve: 9/12  |  + .hxp_hex_overlay (clickable)    | Reserve: 10/12      |
| Blockades: 1/3 |  + intel/blockade/agent/pin layers | Blockades: 2/3      |
| On board:      |  + .hxp_score_marker over track    | On board: ...       |
|  CS Eng Hkr    |                                    |                     |
|  (with intel   |  HXP_DICE_TRAY  (6 colored dice)   |                     |
|   counts)      |  HXP_BAG  (icon + count)           |                     |
+----------------+------------------------------------+---------------------+
|  HXP_ACTION_BAR  Actions: 2/3  [Move][Transfer][Retire][Engineer▾]        |
|                  [Smuggler▾][Comms▾][Hacker▾][DoubleAgent▾][End Turn][?]  |
+---------------------------------------------------------------------------+
|  HXP_LOG  (BGA-standard collapsible notification log + chat)              |
+---------------------------------------------------------------------------+
```

Per-player side panel contents:
- **Reserve count** (`agents_remaining`, `STATE_MODEL §4.1`) — number plus a 6×2 mini-grid of agent-type icons; greyscaled when that copy is on-board or removed.
- **Blockades on board** — `N of 3`; renewing supply per [D-04]+[D-07].
- **Agents on board summary** — type, hex coordinate, intel-held count + colored dots per intel type.
- **Active-player highlight** — `.hxp_panel.is-active` glowing border + chevron (`MISSING §4`).

### 1.2 Tablet layout (`768px ≤ viewport < 1280px`)

Board scales to 800×405 full-width. Side panels stack below the board side-by-side at half-width each. Dice tray + bag sit between board and panels. Action bar sticky bottom; buttons may wrap to two rows.

```
+--------- HXP_TOPBAR (compressed) -----------+
|         HXP_BOARD_WRAP (800×405)            |
|         HXP_DICE_TRAY  +  HXP_BAG           |
| HXP_PANEL_LEFT (P1) | HXP_PANEL_RIGHT (P2)  |
|              HXP_ACTION_BAR                 |
|              HXP_LOG                        |
+---------------------------------------------+
```

### 1.3 Phone layout (`480px ≤ viewport < 768px`)

Same vertical stack. Tooltips collapse into a single `?` help button. Action bar wraps to up to three rows; secondary buttons (Engineer-anywhere, Smuggler boost, Hacker steal) move into a `[ More ▾ ]` overflow menu. Below 480px the playable area is unusable: a one-time banner says "Hexpionage requires a tablet or desktop." Spectator mode still renders the board scaled.

### 1.4 Z-index scheme

All custom z-indices stay under 900 per `BGA_PRIMER §6` (BGA dialogs occupy 950+). Layering, low → high: board image 10 → hex overlay 20 → blockades 30 → intel 40 → agents 50 → pin overlay 60 → intel-held badges 70 → score marker 80 → dice tray 100 → side panels 200 → action bar 300 → tooltips 800 → help/intro modals 850. (BGA dialogs 950+, out of scope.)

---

## 2. Hex grid technique

### 2.1 Decision: Option B — absolute axial positioning

We use **absolute positioning with axial `(q, r) → pixel` transform**, not CSS Grid offset rows. Three reasons:

1. **Precision over the baked board art.** `board.png` carries the Field shading, ✦ markers, and score track baked in (`MANIFEST §A`, `MISSING §§8–11`). The clickable hex overlay must sit *exactly* on the printed hex centers, with sub-pixel accuracy at multiple zoom factors. CSS Grid with offset rows would force us to tune CSS variables to match the print art's hex pitch and would round badly under non-100% zoom (`BGA_PRIMER §6` Safari rounding warning). Absolute pixel positioning per `(q, r)` lets us calibrate against two anchor pixels measured directly from the PNG.
2. **Pointy-top orientation.** Per `STATE_MODEL §3.1`, axes are `(q, r)` pointy-top with origin at the Field's center hex. The standard pointy-top pixel transform is a straight matrix-multiply (see §2.3); CSS Grid would have to re-encode the same math as variable CSS values.
3. **Click targeting.** Each hex is its own DOM element, so click-handling is trivial: `addEventListener('click', ...)` on the cell. No pixel→hex rounding is needed (`BGA_PRIMER §7` notes this is a community-recommended pattern). For non-rectangular Fields (Hexpionage's Field is roughly hexagon-shaped, `STATE_MODEL §3.2`), absolute positioning is the clean path.

### 2.2 DOM skeleton (no JS / CSS code; class & structure only)

```
<div class="hxp_board_wrap">
  <img class="hxp_board_img" src="img/board.png" />        <!-- baked-in art -->
  <div class="hxp_hex_overlay">
    <!-- One <div> per Field hex, generated from material.inc.php hex list (TODO G-02) -->
    <div class="hxp_hex" data-q="-4" data-r="-3" style="left:..px;top:..px"></div>
    <div class="hxp_hex hxp_hex_spawn"   data-q="0" data-r="3"></div>   <!-- ✦ row -->
    <div class="hxp_hex hxp_hex_entry_l" data-q="-4" data-r="-3"></div> <!-- top-left intel entry -->
    <div class="hxp_hex hxp_hex_entry_r" data-q="4"  data-r="-3"></div> <!-- top-right intel entry -->
    ...
  </div>
  <div class="hxp_intel_layer">     <!-- loose-intel tiles, positioned per (q,r) -->
  <div class="hxp_blockade_layer">  <!-- blockade tokens, edge-anchored (see §2.4) -->
  <div class="hxp_agent_layer">     <!-- agent tokens, positioned per (q,r) -->
  <div class="hxp_pin_layer">       <!-- pin overlay, positioned over agent center -->
  <div class="hxp_score_marker hxp_score_marker_p1" style="left:..px"></div>
  <div class="hxp_score_marker hxp_score_marker_p2" style="left:..px"></div>
</div>
```

The `.hxp_hex` cells are **transparent**; visual hex appearance comes from `board.png` underneath. Hover/selection styling is added via `:hover` rules and `is-legal`/`is-armed`/`is-target` modifier classes.

### 2.3 Pixel transform (pointy-top axial)

Per `STATE_MODEL §3.3` the axial neighbors are `NW(q,r)=(q,r-1)`, `NE=(q+1,r-1)`, `E=(q+1,r)`, `SE=(q,r+1)`, `SW=(q-1,r+1)`, `W=(q-1,r)`. The standard pointy-top axial→pixel transform (Red Blob, `BGA_PRIMER §7`):

```
HEX_W = sqrt(3) * R;  HEX_H = 2*R;
x = ORIGIN_X + HEX_W * (q + r/2);
y = ORIGIN_Y + (3*R/2) * r;
```

Calibration: A8 measures `ORIGIN_X, ORIGIN_Y` (pixel center of `(0,0)` hex on `board.png`) and `R` (from any neighbor's pixel offset), then persists the four constants in `modules/js/hex_layout.js`. The tablet 800×405 render multiplies by 0.667 via a single `--hxp-scale` CSS variable on `.hxp_board_wrap` so all per-hex `left`/`top` values cascade. Confirm pointy-top vs flat-top per `[TODO G-01]` before locking; the schema is unchanged either way.

### 2.4 Blockade edge anchoring

Per `MISSING §7` note: blockades visually live *between* hexes (rulebook §9.6 redirect rule treats them as edge-adjacent obstacles). For a blockade owned by P at hex `(q, r)`:

- **Position**: centered on the printed hex `(q, r)`. The token (40×40 px from `MANIFEST §D`) is small enough that it reads as "this hex is blocked" without overlapping the hex's neighbors.
- **Rendering**: `position: absolute; left: pixel_of(q,r).x - 20; top: pixel_of(q,r).y - 20`. White-blockade asset for P1, black for P2.
- **Note**: this is a digital simplification; the physical token may be a triangle wedged on a hex edge, but the digital token sits centered on the hex it occupies (see `STATE_MODEL §6.3` schema: blockades have a `(hex_q, hex_r)` not an edge identifier).

### 2.5 Hover and selection feedback

Each `.hxp_hex` cell gets:

- `:hover` — subtle inner glow (`box-shadow: inset 0 0 8px rgba(255,255,255,0.4)`). Always shown when active player.
- `.is-legal` — applied by JS to all hexes that are valid targets when an action is "armed" (§3.7); cyan glow.
- `.is-armed-source` — applied to the source agent's hex once an action is armed and a source has been picked; gold glow.
- `.is-target-preview` — applied on hover **only when the hex is `.is-legal`**; brighter cyan + the agent or intel that would be moved there shown ghosted.

Spectators and the inactive player see hover glow but no `.is-legal` class (no actions for them).

---

## 3. Per-state UI

For every state in `STATE_MACHINE.md §2.1`–`§2.9`, this section gives:
- What the active player sees (focused area + bottom action bar).
- What is clickable.
- Hover/click feedback.
- Tooltip / help text.
- Action button list (exact buttons shown, ordered as in the action bar).
- Animation cues entering / leaving the state.

### 3.1 `gameSetup` (BGA reserved id 1)

- **Focus**: full-screen splash with the Hexpionage title plate, a randomized "selecting first player…" line, and a fading progress dots animation. Behind the splash, the board fades in pre-loaded so when the splash dismisses the table is already drawn.
- **Clickable**: nothing. (BGA framework auto-transitions on `setupComplete`.)
- **Action bar**: hidden.
- **Tooltip**: none.
- **Animation cue (enter)**: `gameStarted` notification fade-in of the splash, 200ms; coin-flip animation reveals first player, 600ms; splash fades out, 200ms.
- **Animation cue (leave)**: splash fade.
- **Total budget**: ~1 second from page load to first interactive state.

### 3.2 `trickleDrawLeft`

- **Focus**: top-left intel-entry hex glows briefly; bag icon glows; phase breadcrumb shows `Trickle (drawing 1/2)`.
- **Clickable**: nothing (auto-state). **Action bar**: disabled, banner `Trickle in progress…`.
- **Tooltip on bag**: `<bag_size> intel tiles remaining.`
- **Animation**: `intelDrawn` → tile slides bag → entry hex (250ms), face-down → face-up flip on landing. Empty-bag case ([D-18]): no slide; banner `Bag empty — no draw this turn` 600ms.

### 3.3 `trickleDrawRight`

Identical to §3.2 with top-right entry hex. Sequential (not parallel) so the player can track each tile's identity.

### 3.4 `trickleRoll`

- **Focus**: dice tray (`MISSING §2`, 6 colored squares). Phase breadcrumb `Trickle (rolling)`.
- **Tooltip per die** (pre-roll): `<intel_type> die: odd → SW, even → SE.`
- **Animation**: 6 dice tumble in place (CSS `rotate(360deg) scale(1.1)`), 600ms; land on outcome (1 pip = odd, 2 pips = even), with SW/SE arrow beneath.
- **Leave**: dice stay visible into `trickleResolve` and beyond.

### 3.5 `trickleResolve`

- **Focus**: each moving loose-intel tile briefly glows in its direction; tiles slide simultaneously to targets.
- **Clickable**: nothing. **Action bar**: disabled, shows `Resolving trickle…` banner.
- **Animation (enter)**: receive one batched `trickleResolved` notification (`STATE_MACHINE §9`) carrying `moves`, `honeypot_removals`, `over_capacity_dumps`, `new_bag_size`. Sub-animations are ordered per §6.2: (1) parallel slides staggered 30ms per tile (≤500ms total); (2) off-board tiles continue past the edge and fade into bag (+200ms); (3) redirected tiles bounce briefly against the blockade before taking the open diagonal (§9.6); (4) honeypot removals (FAQ-canonical order, §9.3 EDGE O-01): agent fade + slide-to-graveyard 300ms, held intel + Honeypot fly to bag 300ms; (5) over-capacity dumps: held intel fly to bag 300ms; (6) bag-size counter flips to `new_bag_size`.
- **Leave**: advances to `spawn`; dice tray persists through spawn/actions (per `STATE_MACHINE §11.5 TODO state-machine-4`).

### 3.6 `spawn`

- **Focus**: active player's side panel highlights (`is-active`); reserve grid becomes interactive; legal ✦ hexes (empty per §6.1, listed in `available_spawn_hexes`) glow gold. Instruction line: `Pick a reserve agent, then a ✦ hex.`
- **Clickable**: reserve agents, legal ✦ hexes, `[Pass Spawn]`.
- **Feedback**: hover-on-reserve shows agent tooltip (§5.1). Click-on-reserve arms the agent (`.is-armed`) and lights up legal ✦ hexes (`.is-legal` cyan glow); other reserve agents grey out. Hover-on-legal-hex shows a ghost preview. Click-on-legal-hex fires `actSpawnAgent`; if `spawn_cap_remaining > 0` AND legal hexes remain, state self-loops; else `autoPass`.
- **Action bar**: `[Pass Spawn]` (always enabled), `[Cancel]` (only when armed).
- **Animations**: enter — chevron + ✦ glow fade-in (200ms). Per-spawn — `agentSpawned` slide (250ms); reserve count pop (150ms). Leave — ✦ glow fades out (200ms).
- **Auto-pass**: if `spawn_cap_remaining == 0 OR pool_empty OR no_legal_hex` (`STATE_MACHINE §2.6`), UI shows a 600ms `No legal spawn — passing` banner before advancing.

### 3.7 `actions` — the most complex state

The action bar uses a **two-step "arm then commit"** pattern. The player clicks an action button (or in some cases a board element first); the button enters an "armed" state; legal targets on the board glow; the player clicks a target; the action fires. Cancel button (or pressing Escape) disarms.

#### 3.7.1 Action bar layout

Context-sensitive buttons. Universal actions (Move, Transfer, Retire, End Turn, Help) sit at top level. Agent-grouped abilities (Engineer, Smuggler, Comms, Hacker, Double Agent) collapse into dropdown menus.

```
Actions: <X> / <max>   [Move] [Transfer] [Retire]
                       [Engineer▾]  → Place Adjacent (1A) | Place Anywhere (1I)
                       [Smuggler▾]  → Boost (1I) | Swap (1A+1I)
                       [Comms▾]     → Move Up (1A) | Move Down (1A+1I)
                       [Double Agent▾] → Transfer To Any (1A)
                       [Hacker▾]    → Pin (1A) | Unpin (1A) | Steal (1I)
                       [End Turn]   [? Help]
```

A button is enabled iff its action name appears in `legal_actions` (`STATE_MACHINE §7.2`); otherwise it shows greyscale + a tooltip explaining why (e.g., "No on-board Engineer", "Already used Smuggler Boost this turn", "Not enough actions remaining"). When a dropdown's children are all disabled, the parent dropdown is hidden entirely. `[End Turn]` is always enabled (`actPassActions`, §6.13).

#### 3.7.2 Action arming flow (general pattern)

(1) Click action button → `is-armed` state (gold border). (2) Legal source agents glow gold (`.hxp_agent.is-legal`); help line tells the player what to pick. (3) Click source → source gets `.is-armed-source`; legal target hexes/agents glow cyan. (4) Click target → handler fires `act<X>(...)`. Cancel/Escape disarms at any step. Variants per §4.

#### 3.7.3 Action counter and Smuggler boost

The counter (`MISSING §3`) shows `Actions: <X> / <max>` bound to `actions_remaining` and `smuggler_boost_used_this_turn`. When `<max>` flips 3→4 the counter pulses gold and reads `BOOST` briefly. When `actions_remaining == 0` AND no intel-only ability is legal, auto-pass kicks in (§6.13): bar shows `Auto-passing…` for 800ms and state transitions.

#### 3.7.4 Animation sub-cues and entry / exit

Per-action animations are detailed in §6.1; each public action has a matching notification animation. **Enter** (from `spawn`): counter resets to 3, phase breadcrumb advances, bottom-bar buttons fade in (200ms). **Self-loop**: re-render `legal_actions`; buttons re-evaluate; counter ticks down. **Leave** on `actPassActions`/`autoPass`: bottom-bar fades out, transition to `endOfTurnCleanup`. **Leave** on `gameWin`: board freezes; `gameEnd` modal appears (§3.9).

### 3.7b `analystBonusDecision` [D-26]

A modal-style overlay state introduced by [D-26]. The active player has just retired an Analyst with 3 intel; the server has drawn a bonus tile from the bag and revealed it privately to the active player (`analystBonusDrawn` notification, private per [D-20]). The player must choose to keep (score) or return (back to bag).

- **Focus**: dimmed board background; centered modal showing the drawn tile face-up (the type and score value are visible only to the active player). Title: `Analyst bonus drawn`.
- **Modal contents**: large rendering of the drawn tile (using the appropriate intel sprite), the tile name, and the score-value badge (e.g., `+4` for State Secret).
- **Buttons**:
  - `[Keep (+N points)]` — primary action; fires `actAnalystKeep`. Tooltip: `Score this tile immediately.`
  - `[Return to bag]` — secondary action; fires `actAnalystReturn`. Tooltip: `Return the tile to the bag without scoring; opponents will not learn its type.`
  - `[?]` — opens a help popover explaining the bonus mechanic.
- **Cancel**: not allowed — the action is mandatory once the bonus has been drawn (per [D-26], no undo: the random draw is irreversible).
- **Opponent view**: the modal is NOT shown to the opponent. Opponent sees a status banner across the top: `<player> is deciding the Analyst bonus…`. No tile type is revealed to the opponent.
- **Spectator view**: same as opponent — banner only, no tile type.
- **Empty-bag case** ([D-18]): the state is bypassed entirely (server fires `analystBonusSkipped`). UI shows brief banner `Bag empty — bonus forfeited` for ~600ms before returning to `actions`.
- **Zombie behavior**: if the active player times out, server auto-fires `actAnalystReturn` (the safer default — no score change, no leak).
- **Animations**:
  - Enter: `analystBonusDrawn` (private to active) → tile slides bag → modal center (250ms); modal fades in (150ms).
  - Leave on `Keep`: tile slides modal → score-zone (300ms); modal fades out (150ms); `scoreUpdated` animates score-marker (300ms). See §6.1 `analystBonusKept`.
  - Leave on `Return`: tile slides modal → bag (300ms); modal fades out (150ms). Bag-size counter NOT incremented in the public payload (active player saw it; opponent never saw the type — the bag count update is the only public effect). See §6.1 `analystBonusReturned`.

### 3.8 `endOfTurnCleanup`

- **Focus**: status banner across the top of the board: `End of turn — cleaning up.` Side panels show pin and blockade markers fading out as their cleanup notifications arrive.
- **Clickable**: nothing.
- **Action bar**: disabled.
- **Animations**:
  - `pinExpired` notification → for each cleared pin, the pin marker over that agent fades out (200ms).
  - `blockadeExpired` notification → for each cleared blockade, the blockade token slides from the hex back to its owner's panel (250ms); blockade reserve count ticks up.
  - `turnEnded` notification → top-bar `Turn N` increments; active-player highlight transitions from one panel to the other (chevron slide-across, 300ms).
- **Total budget**: ~600–800ms (parallel sub-animations); plus dice tray clears at end (`STATE_MACHINE §11.5 TODO state-machine-4` default).

### 3.9 `gameEnd`

- **Focus**: board greys out; `Winner` banner slides in centered. Banner contents: winning player's avatar + name; final score (`Final: 20–14` or similar); win reason (`Reached 20 points` or `Opponent depleted`).
- **Clickable**: BGA-standard end-of-game UI (rematch button, return-to-lobby). No game actions.
- **Action bar**: hidden.
- **Animations**:
  - `gameEnded` notification → confetti / scoring summary slide-in, 800ms.
  - Score markers animate to final positions (300ms each, chained).
  - BGA framework auto-runs end-of-game scoring screen (per `STATE_MACHINE §2.9`).

---

## 4. Click behavior decisions

Per BGA convention (`BGA_PRIMER §5`), all interactions use **click**, not drag-and-drop. Click is touch-friendly and avoids drag-mid-zoom issues. The "two-step arm-then-commit" pattern (§3.7.2) is the canonical interaction; this section enumerates per-action click sequences.

All actions follow the **arm → pick source → pick target → (optional intel-pay modal) → fire** template established in §3.7.2. Per-action specifics below; canceling at any step disarms (`Cancel` button or Escape).

### 4.1 Spawn (§6.1)

Click reserve agent → click ✦ hex → fires `actSpawnAgent`. `[Pass Spawn]` always available.

### 4.2 Move agent (§6.3)

Click `[Move]` → click un-pinned friendly agent → click adjacent legal hex → fires `actMoveAgent`. If the target hex holds a Honeypot ([D-05b]), the agent is removed mid-animation (`agentRemoved` notification, §6).

### 4.3 Transfer intel (§6.4)

Click `[Transfer]` → click source agent (with ≥1 intel and ≥1 adjacent friendly) → click adjacent friendly target → if source holds >1 intel, modal `Choose intel:` → fires `actTransferIntel`. Single-intel auto-binds (no modal).

### 4.4 Retire (§6.5) — FREE per [D-14]

Click `[Retire]` → click an un-pinned, on-✦, not-just-spawned agent → fires `actRetireAgent({agent_id})` immediately. (No Analyst-bonus payload field per [D-26] — `actRetireAgent` always carries just `{agent_id}`.)

If the retired agent is an Analyst with exactly 3 intel AND bag is non-empty, the server transitions to the new `analystBonusDecision` state (§3.7b per [D-26]); the client receives the private `analystBonusDrawn` notification, renders the modal, and the player picks Keep or Return. Empty-bag case fires `analystBonusSkipped` and proceeds directly back to `actions` without a modal ([D-18]).

(Optional stretch: "Retire and end turn?" auto-confirm when retire is the only legal action; ship manual flow first.)

### 4.5 Comms move (§6.9)

**Up (1A)**: click `[Comms▾] → [Move Up]` → click Comms → click loose intel with legal NW/NE → click NW or NE arrow → fires `actCommsMoveIntelUp`. **Down (1A+1I)**: same pattern with SW/SE arrows; after target hex pick, modal `Pay 1 intel from this Comms (≠ moved intel):` → fires `actCommsMoveIntelDown`. Single-other-intel auto-binds.

### 4.6 Hacker steal (§6.11.C)

Click `[Hacker▾] → [Steal]` → (auto-bind Hacker if only one legal) modal **1** `Pinned enemy agents:` → modal **2** `<agent>'s intel:` → modal **3** `Pay 1 intel from <hacker>:` → fires `actHackerStealIntel`. Modals are nested; `Cancel` at any step disarms. Modal 1 is skipped when only one pinned enemy exists.

### 4.7 Smuggler swap (§6.8)

Click `[Smuggler▾] → [Swap]` → click Smuggler → click `agent A` (any un-pinned, including the Smuggler per [TODO S-01]) → click `agent B` → modal `Choose intel:` (skipped if Smuggler has 1 intel) → fires `actSmugglerSwapAgents`.

### 4.8 Engineer (§6.6)

**Adjacent (1A)**: click `[Engineer▾] → [Place Adjacent]` → click Engineer → click adjacent legal hex → fires `actEngineerPlaceBlockadeAdjacent`. **Anywhere (1I, no A)**: same flow targeting any legal Field hex; intel-pay modal if Engineer holds >1 intel.

### 4.9 Hacker pin / unpin (§6.11.A/B)

Click `[Hacker▾] → [Pin]` (or `[Unpin]`) → click legal Hacker (per-Hacker `pin_used == 0`, [D-15]) → click adjacent enemy (Pin) or adjacent friendly pinned (Unpin) → fires.

### 4.10 Double Agent transfer (§6.10)

Click `[Double Agent▾] → [Transfer]` → click Double Agent → click ANY other on-board agent (no adjacency, §6.10) → intel-pay modal if needed → fires `actDoubleAgentTransfer`.

### 4.11 Smuggler boost (§6.7)

Click `[Smuggler▾] → [Boost]` → click Smuggler → intel-pay modal if needed → fires `actSmugglerBoostActions`. Action counter pulses; `<max>` becomes 4.

### 4.12 End turn

Click `[End Turn]` → if any free Retire is still legal, confirmation modal `You have <N> free retires available. End turn anyway?` Otherwise fires `actPassActions` immediately.

### 4.13 No drag-and-drop

Per `BGA_PRIMER §5` and to avoid touch-device pitfalls, drag-and-drop is **not** used. Click-source-then-click-target is universal. (The PLAN deliverable's drag-spawn suggestion is overridden in favor of consistency with the other 14 actions.)

---

## 5. Tooltip and help text drafts

All copy below is content-only; A8 owns formatting and BGA `clienttranslate()` wrappers.

### 5.1 Agent tooltips (≤2 sentences each)

- **Comms Specialist**: `Move loose intel up one space (1A) or down (1A + 1I). Cannot target intel held by an agent.`
- **Analyst**: `When retiring with exactly 3 intel, draw 1 bonus tile from the bag and choose to keep (score) or return.`
- **Smuggler**: `Spend 1 intel to boost your action cap to 4 this turn (once per turn). Or spend 1 intel + 1 action to swap two on-board agents (neither may be pinned).`
- **Engineer**: `Place a blockade on an adjacent hex (1A) or anywhere on the Field (1I). Max 3 blockades per player on the board.`
- **Hacker**: `Pin or unpin an adjacent agent (1A; one per Hacker per turn). Steal one intel from any pinned enemy agent (1I; separate slot).`
- **Double Agent**: `Transfer one of your held intel to ANY agent in play, anywhere on the board (1A). No adjacency required.`

### 5.2 Action tooltips (≤1 sentence each, shown on button hover)

- `Move Agent (1A)` — `Move an agent to an adjacent Field hex; pick up loose intel on arrival.`
- `Transfer Intel (1A)` — `Move one intel from one of your agents to an adjacent agent you control.`
- `Retire Agent (FREE)` — `On a ✦ hex (and not spawned this turn): score all held intel; agent leaves play permanently.`
- `Engineer Place Blockade Adjacent (1A)` — `Engineer places a blockade on an adjacent empty Field hex.`
- `Engineer Place Blockade Anywhere (1I)` — `Engineer spends one of its intel; place a blockade anywhere in the Field.`
- `Smuggler Boost Actions (1I)` — `Spend 1 intel; raise your action cap to 4 this turn (once per turn).`
- `Smuggler Swap Agents (1A + 1I)` — `Spend 1 intel; swap any two on-board agents (neither may be pinned).`
- `Comms Move Intel Up (1A)` — `Move one loose intel one hex up (NW or NE).`
- `Comms Move Intel Down (1A + 1I)` — `Spend 1 intel; move one loose intel one hex down (SW or SE).`
- `Double Agent Transfer (1A)` — `Send one of this Double Agent's intel to ANY other agent in play.`
- `Hacker Pin (1A)` — `Pin an adjacent enemy agent (it cannot move/retire/be-swapped until end of its next turn).`
- `Hacker Unpin (1A)` — `Unpin an adjacent friendly pinned agent.`
- `Hacker Steal (1I)` — `Steal one intel from any pinned enemy agent (anywhere on the board; pay 1 intel from this Hacker).`
- `End Turn` — `Pass to your opponent. (Free retires and intel-only abilities are still available before passing.)`

### 5.3 Non-obvious rule popups (first-time tutorial; one-time, dismissible)

Short pinned tooltips with a `Got it` button, fired once per game session per trigger:

- **Honeypot first contact** [§9.4, D-05b]: `Gray Honeypots are traps. Any agent that touches one is permanently removed; held intel + the Honeypot return to the bag.`
- **Blockade pair vertical block** [§9.6.D]: `Two blockades on the SW and SE neighbors of a hex stop intel above from trickling. Same applies to Comms vertical moves.`
- **Capacity dump** [§9.3]: `Agents hold at most 3 intel. Receiving a 4th dumps ALL intel back to the bag.`
- **Comms cost asymmetry** [§6.9, §12.3 #4]: `Move Up costs 1 Action. Move Down costs 1 Action + 1 Intel from this Comms.`
- **Engineer remote placement** [§6.6.B, §12.3 #5]: `Place Anywhere costs 1 Intel and NO Action — useful after your 3 Actions are spent.`
- **Hacker slot split** [D-15, §12.3 #6–7]: `Each Hacker has two per-turn slots: pin/unpin (shared) and steal (separate). Two Hackers = double the slots.`
- **Smuggler boost is per-player** [D-08, §12.3 #8]: `Smuggler Boost is once per turn per player, regardless of how many Smugglers you control.`
- **Retire scores ALL intel** [D-14, §12.3 #11/18]: `Retire scores every intel the agent is holding. State Secret = 4 pts is the highest.`
- **Same-turn-spawn retire blocked** [§6.5, §12.3 #12]: `An agent cannot retire on the same turn it was spawned.`
- **Pinned agents can use abilities** [§9.5, §12.3 #13]: `Pinned agents can still use their abilities — only Move, Retire, and Swap are blocked.`

### 5.4 Disabled-button tooltips

Disabled buttons in the action bar carry a tooltip explaining why. Examples:

- `Move (disabled)` → `No movable agent (all your agents are either pinned or have no legal target).`
- `Transfer (disabled)` → `No agent has intel to transfer to an adjacent friendly agent.`
- `Smuggler Boost (disabled)` → `Already boosted this turn.` OR `No Smuggler has intel.`
- `Hacker Steal (disabled)` → `No pinned enemy agents to steal from.`

---

## 6. Animation list with timing budget

All animations use CSS transforms (`translate3d`, `opacity`, `scale`) for GPU acceleration. Per `BGA_PRIMER §6`, avoid `drop-shadow` filter on Safari mid-animation; prefer `box-shadow`. All animations honor `prefers-reduced-motion`: under that media query, slides/fades collapse to instant cuts (with a brief flash on the affected element to preserve cause-effect legibility).

### 6.1 Animation table

Each row: name (matching `STATE_MACHINE §9`), duration, description. All slides ≤300ms, fades ≤200ms (`MISSING §6` budget).

| Animation | Duration | Description |
|---|---|---|
| `gameStartedSplash` | 1000ms | Title splash in/out (200+600+200). |
| `intelDrawn` | 250ms | Tile slides bag → entry hex; face-down → face-up flip. |
| `diceRolled` | 600ms | All 6 dice tumble in place; land on outcome with SW/SE arrow. |
| `trickleResolved` | ≤800ms | Composite — see §6.2. |
| `agentSpawned` | 250ms | Slide reserve → ✦ hex; reserve count ticks down (150ms pop). |
| `agentMoved` | 250ms | Source → target slide; picked-up intel rides along. |
| `intelTransferred` | 200ms | Intel hops source agent → target agent in a small arc. |
| `agentRetired` | 250ms + N×200ms | Agent fade-out; each held intel flies to score-zone, staggered 50ms. |
| `analystBonusDrawn` | 250ms | (private to active) Bonus tile slides bag → modal center; modal fades in (+150ms). [D-26] |
| `analystBonusKept` | 300ms | Tile slides modal → score-zone; score counter increments. [D-20, D-26] |
| `analystBonusReturned` | 300ms | Tile slides modal → bag area (no score change, no public type reveal). [D-20, D-26] |
| `analystBonusSkipped` | 600ms | Banner: `Bag empty — bonus forfeited`; auto-dismiss. [D-18, D-26] |
| `scoreUpdated` | 300ms | Score-marker pawn slides on track; number flip on per-player label. |
| `blockadePlaced` | 250ms | Blockade slides from owner panel to hex. |
| `actionsBoosted` | 200ms | Counter pulses gold; intel fades into bag (+300ms). |
| `agentsSwapped` | 300ms | Two agents arc-swap; intel rides with each. |
| `intelMovedUp`/`Down` | 250ms | Intel slides hex → NW/NE or SW/SE; for `Down`, paid intel fades into bag (+200ms). |
| `agentPinned`/`Unpinned` | 200ms | Pin marker fades in / out. |
| `intelStolen` | 250ms | Stolen intel hops victim → hacker; paid intel fades into bag (+200ms). |
| `intelStacked` | 150ms | Subtle bounce when a tile joins a stack on a hex. |
| `agentRemoved` (Honeypot) | 300ms | Fade out + slide to graveyard; held intel fly to bag. |
| `agentDumped` (over-capacity) | 300ms | All held intel fly to bag in parallel; agent stays put. |
| `pinExpired` | 200ms | Cleared pin marker fades out. |
| `blockadeExpired` | 250ms | Token slides hex → owner panel; reserve count ticks up. |
| `turnEnded` | 300ms | Active-player chevron slides between panels; turn counter increments. |
| `gameEnded` | 800ms | Win banner slides in; final scoreline; BGA confetti. |

### 6.2 `trickleResolved` sub-animation sequence

Server emits one batched `trickleResolved` (`STATE_MACHINE §8.5 F`); client decomposes:

1. `t=0`: all `moves` slide (250ms each, 30ms stagger; total ≤500ms).
2. `t=0`: redirected tiles bump 80ms against the blockade then take the open diagonal (§9.6).
3. `t=window_end`: off-board tiles continue past edge, fade into bag (+200ms).
4. `t=window_end + 50ms`: `honeypot_removals` in parallel — agent fade+graveyard slide (300ms); held intel + Honeypot fly to bag (300ms).
5. Same window: `over_capacity_dumps` (parallel with step 4 if disjoint; otherwise after, per §9.3 EDGE O-01).
6. `t=last+100ms`: bag-size counter flip (300ms) to `new_bag_size`.

Busy turn worst case ~1100ms; quiet turns ~400ms.

### 6.3 Animation count

**24 named animations** (1 per public state-mutating notification + sub-cues), plus the `trickleResolved` composite. All slides ≤300ms, fades ≤200ms (`MISSING §6`).

### 6.4 Reduced motion

Under `prefers-reduced-motion: reduce`, all animations collapse to a 50ms opacity flash on affected elements (no translate/rotate/scale). Cause-effect remains legible.

---

## 7. Mobile / responsive plan

**Breakpoints**: Desktop ≥1280px (§1.1, two-panel flank), Tablet 768–1279px (§1.2, full-width board, panels below side-by-side), Phone 480–767px (§1.3, vertical stack + `[More▾]` overflow), Sub-phone <480px (warning banner; spectator only).

**Element changes**: tooltips disappear at phone, replaced by a global `?` Help button (§9); disabled-button tooltips remain via long-press. Side panels go 220px (desktop) → 50% width (tablet) → full-width stacked (phone). Action bar wraps from 1 to 2–3 rows. Dice tray scrolls horizontally on phone. Modals go full-width on phone, centered popover on tablet/desktop.

**Touch**: clicks become taps; hover states fall back to long-press (= "show tooltip"). Arm-then-commit pattern unchanged.

**Performance**: sprites ship 1× and 2× (retina); board.png pre-loaded during `gameSetup`; total initial asset budget under 2MB compressed (`BGA_PRIMER §6`).

---

## 8. Spectator view

Per `BGA_PRIMER §6` `spectatorMode` class and `STATE_MODEL §4` (all state public, [D-11]): layout identical to player view (board, panels, dice tray, log) but the action bar is hidden entirely. Top bar shows `Spectating` + active player + phase breadcrumb. Hex hover glow remains for read-only inspection; `is-legal` / `is-armed` classes never apply. Tooltips remain available; score is visible (public per [D-11]); bag visible as count only (composition server-only). All animations run identically. `[data-theme="dark"]` (`BGA_PRIMER §6`) applies the same way.

---

## 9. Help / Rules reference modal

The `[ ? Help ]` button opens a tabbed modal at all times (Escape or close button dismisses). Tabs:

1. **Quick Reference** — agent ability table mirrored from rulebook §6 / §5.1 of this spec, rendered as HTML.
2. **Honeypot** — text panel mirrored from rulebook §9.4 + [D-05b]: any agent touching a Honeypot is permanently removed; held intel + Honeypot return to bag.
3. **Blockades** — mirror of rulebook §9.6 with a small diagram: single blockade redirects to the open diagonal; both diagonals blocked = no trickle; same applies to Comms vertical moves; blockades freeze underlying intel; max 3 per player; expire at end of opponent's next turn.
4. **Phases** — `Trickle → Spawn → Actions` + cleanup (rulebook §5), with the auto-resolution note for Trickle.
5. **Win conditions** — first to 20 points (rulebook §8.1); zero pool AND zero on board = loss ([D-17]).

Modal text is **reused from `rulebook.md`** via `clienttranslate()`; no new rules copy is invented here.

---

## 10. Player aid / first-time guide

Optional 3-slide intro on first game load only (suppressed via localStorage flag `hxp_intro_seen=true`):

1. **Goal** — score 20 points by retiring agents holding intel; each turn has 3 phases (Trickle / Spawn / Actions).
2. **Agents** — grid of 6 agent types using the tooltip blurbs from §5.1.
3. **Watch out** — three callouts: (a) Honeypots instantly remove agents; (b) agents hold at most 3 intel — a 4th dumps all to bag; (c) retiring scores ALL held intel (State Secret = 4 pts).

A `[Got it — let's play]` button dismisses and writes the flag. A "Skip intro" link appears on every slide.

---

## 11. UX risks identified

1. **`actions` state-args payload size** (`STATE_MACHINE §11.5 TODO state-args-1`). `legal_actions` could be large in dense mid-game positions; if it approaches the 128KB BGA cap, A8 should switch to lazy per-agent target loading. Default: ship full payload; revisit if >50KB in playtest.
2. **Hacker steal modal nesting** (§4.6). 3 sequential modals risk tap fatigue on phones. Mitigation: collapse to a single `[Back][Next]` wizard once observed.
3. **`trickleResolved` animation timing**. Worst case ~1100ms could feel slow. Fallback: per-tile sequential animations on low-end devices; `prefers-reduced-motion` cut-off already specified.
4. **Hex calibration** (§2.3). Depends on identifying the Field's center hex from `board.png`. If the Field is non-hex-shaped, ORIGIN_X/Y need a different anchor pair; tied to `[TODO G-02]`.
5. **Pinned agent ability discoverability** (§9.5). Players assume "pinned = useless". The first-time popup §5.3 covers this; pinned agents that can still use abilities retain the `.is-legal` glow when the matching ability is armed.
6. **Spawn click pattern**. New players may attempt drag (§4.13 forbids it). Mitigation: a one-time overlay on first spawn — `Click reserve agent, then ✦ hex.`
7. **Smuggler self-swap** ([TODO S-01]). Default is legal (§4.7); flagged for owner confirmation.
8. **Dice persistence** (`STATE_MACHINE §11.5 TODO state-machine-4`). Default is keeping dice visible across spawn/actions; easy to swap to clearing-on-spawn if playtest confusion.

---

End of `specs/UI_SPEC.md`.
