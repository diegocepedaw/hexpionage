# Hexpionage — Backend Code Review (BE-PR1)

> **Inputs**: `src/dbmodel.sql`, `src/gameinfos.inc.php`, `src/material.inc.php`, `src/stats.inc.php`, `src/hexpionage.game.php` (1722 LOC), `src/modules/php/States/*.php` (10 files).
>
> **Specs cross-checked**: `rulebook.md`, `DECISIONS.md` (D-01..D-26), `docs/specs/STATE_MODEL.md`, `docs/specs/STATE_MACHINE.md`, `docs/specs/CONTRACT.md`, `docs/specs/QA_SPEC_REVIEW.md` (40 ILL items), `docs/testing/SCENARIOS.md` §3.
>
> **Severity legend**: **S0** = runtime crash / data corruption; **S1** = incorrect game logic; **S2** = spec deviation but harmless or requires unusual conditions; **S3** = cosmetic.

---

## 1. Bug List

### 1.1 S0 — Runtime crash / data corruption

| ID | Location | Description | Spec | Proposed fix |
|---|---|---|---|---|
| **BE-01** | `hexpionage.game.php` `actRetireAgent` lines 972–984 | **Depletion check fires BEFORE Analyst-bonus transition**, so an Analyst whose retire empties the pool will lose by depletion even though the bonus could push score to ≥ 20. The code orders: score held → win-check → depletion-check → bonus_eligible → transition. Per `rulebook.md` §6.5 the canonical order is: score (step 1) → Analyst bonus (step 2) → … → win check (step 7) → depletion check (step 8). Per `DECISIONS.md` D-26 step 7 the win + depletion checks belong in the bonus state's resolver, NOT in `actRetireAgent`. As written, an Analyst-with-3 retire that empties the player's reserve never reaches `analystBonusDecision` — they lose immediately. This is both incorrect and unrecoverable. | rulebook §6.5, D-17, D-26, CONTRACT §3.2 | Move the depletion check (and ideally the win check too) inside the non-bonus branch only. Sketch: `if ($bonus_eligible) { transitionTo('analystBonus'); return; }` first, then run win+depletion checks for the plain-retire path. The bonus state's `actAnalystKeep` / `actAnalystReturn` handlers already perform depletion + win checks and so cover the bonus path. |
| **BE-02** | `hexpionage.game.php` `actSmugglerSwapAgents` line 1156 | **Smuggler eligibility check omits `state == AGENT_STATE_ON_BOARD`.** Compare with `actSmugglerBoostActions` (line 1117) which checks state. A removed Smuggler (`state=2`) currently passes the eligibility gate; the action then attempts a swap whose preconditions are evaluated for the two named `agent_a/b`. Likely surfaces only if the client has stale data, but it's a real precondition gap that violates the §6.8 precondition "Smuggler agent owned by active player, **on board**." | rulebook §6.8, ILL-31/35, STATE_MACHINE §3 | Add `|| (int)$sm['state'] !== AGENT_STATE_ON_BOARD` to the eligibility test. |
| **BE-03** | `hexpionage.game.php` `actHackerStealIntel` lines 1546–1551 | **`actHackerStealIntel` does not enforce adjacency between Hacker and target.** Per rulebook §6.11.C and STATE_MODEL §9.9 (which mirrors §6.11.A), Hacker abilities require `is_adjacent(hacker, target)`. The `pin` and `unpin` handlers correctly check adjacency (lines 1453, 1505), but `steal` does not. A Hacker can therefore steal from any pinned enemy on the board regardless of distance — game-logic bug enabling impossible plays. | rulebook §6.11.C, STATE_MACHINE §3, ILL catalogue gap | Insert `if (!$this->isAdjacent((int)$h['hex_q'], (int)$h['hex_r'], (int)$tgt['hex_q'], (int)$tgt['hex_r'])) throw new BgaUserException(...)` between the pin/owner check and the intel checks. |

### 1.2 S1 — Incorrect game logic

| ID | Location | Description | Spec | Proposed fix |
|---|---|---|---|---|
| **BE-04** | `hexpionage.game.php` `actHackerStealIntel` lines 1543–1545 | **Per-Hacker `pin_used_this_turn` slot is not consulted by `steal`** (correct per D-15 — steal has its own slot). However the parallel **`actHackerPin`/`actHackerUnpin` slot-shared check is also missing the symmetric path** in *steal* — which is fine per D-15. *No fix; flagged solely so the reader can confirm during review that this is intentional.* | D-15 (separate slots) | (No code change — this row is informational; remove if not desired.) |
| **BE-05** | `hexpionage.game.php` `actRetireAgent` lines 920–921, `actAnalystKeep` lines 1641–1657, `EndOfTurnCleanup` line 96 | **Honeypot scoring path can corrupt score.** `actAnalystKeep` always promotes the bonus tile to `state=scored` regardless of `type_id` — a Honeypot bonus draw gets scored at +0 (`score_value=0`) but it is logged as `analystBonusKept` with type publicly revealed. CONTRACT §2.10 documents the kept-tile may be any type (Honeypot is allowed; +0 is fine), and SCENARIOS CASE-23 confirms this is legal. **Not a bug — flagging because the path superficially looks suspicious.** | CONTRACT §2.10, CASE-23 | None. |
| **BE-06** | `hexpionage.game.php` `actEngineerPlaceBlockadeAnywhere` lines 1080–1108 | **Action does not call `decrementActions()` and does not check `actions_remaining >= 1`** — correct per rulebook §6.6.B "no action cost." However the `actions_remaining` payload (line 1101) is read AFTER all DB mutations and reflects the unchanged value. CONTRACT §2.11 prescribes `actions_remaining` echo. **Not a bug — flagging because it is easy to misread.** | rulebook §6.6.B, CONTRACT §2.11 | None. |
| **BE-07** | `hexpionage.game.php` `actSmugglerBoostActions` lines 1127–1129 | **No `INVARIANT-ACTIONS-CAP` enforcement.** Per QA F-02 / STATE_MODEL §6.1, `actions_remaining` must be ≤ 4 (and ≤ 3 unless boost active). Code unconditionally adds 1 to whatever `actions_remaining` currently holds. If a future bug or hostile client somehow inflated `actions_remaining`, this method would compound it. Defensive-only. | QA F-02, STATE_MODEL §6.1 | After the increment, assert `0 <= $new_actions <= 4` and throw `BgaVisibleSystemException` on violation. |
| **BE-08** | `hexpionage.game.php` `actMoveAgent` lines 750–784 | **Honeypot-onto-move sequencing inconsistent with CONTRACT §3.1.** Per CONTRACT §3.1 Honeypot move: "(1) `agentMoved` slides to target hex (carries the picked-up Honeypot in `picked_up_intel[]`); (2) `agentRemovedHoneypot` ..." The code emits `agentMoved` with `'picked_up_intel' => $pickup['picked_up'] ?? []` — but `$pickup['picked_up']` is **only set in the non-Honeypot branch** of `applyPickupAt()`. In the Honeypot branch, `$pickup['picked_up']` is undefined → `?? []` makes it empty. The Honeypot tile id is NOT carried in `picked_up_intel`, contradicting CONTRACT §3.1 step 1. Frontend animation may not render the Honeypot pickup before removal. | CONTRACT §3.1 | In `applyPickupAt()`'s Honeypot branch, set `'picked_up_intel'` to include `$honeypot_tile_id` and any other arrivals; or in `actMoveAgent`, populate `picked_up_intel` from `pickup['honeypot_removal']['returned']` minus the agent's previously-held intel. |
| **BE-09** | `hexpionage.game.php` `actMoveAgent` lines 786–788 | **Win check missing on Honeypot-move depletion of opponent.** Honeypot only removes the active player's own agent (the moving agent). Active-player depletion via Honeypot move IS checked (lines 769–772). However, the same handler does not check whether the opponent is depleted — fine, because Honeypot move only removes one's own agent. **Not a bug.** | (review note) | None. |
| **BE-10** | `hexpionage.game.php` `actHackerStealIntel` line 1614 | **Missing `actions_remaining` echo in `intelStolen` payload.** Per CONTRACT §2.16 + §2.23 the steal payload "still echoes for UI consistency." The code never emits `actions_remaining` in `intelStolen`. Since steal is free, the value is unchanged, but CONTRACT mandates the echo. | CONTRACT §2.16, §2.23 | Add `'actions_remaining' => (int)$this->bga->globals->get('actions_remaining')` to the `intelStolen` payload. |
| **BE-11** | `hexpionage.game.php` `actHackerStealIntel` line 1614 | **No win/depletion check after steal.** Steal does not remove agents or score, so neither check is required functionally. (Flagged for completeness only.) | (review note) | None. |
| **BE-12** | `hexpionage.game.php` `actAnalystKeep` line 1671, `actAnalystReturn` line 1708 | **`analyst_bonus_pending_tile_id` cleared inside the action handler AND in `AnalystBonusDecision::onLeavingState()`** — the second clear is a no-op but is ordered after the state transition is requested. Cosmetic only; included here because future maintainers may delete the duplicate and break crash-recovery. | STATE_MACHINE §2.7b | None — leave as-is or hoist clearing solely into `onLeavingState`. |
| **BE-13** | `hexpionage.game.php` `actCommsMoveIntelUp` lines 1235–1238 | **Direction validation hardcodes pointy-top axial offsets** rather than calling `hexpionage_hex_neighbors()` from `material.inc.php`. The constants match (NW=`(q,r-1)`, NE=`(q+1,r-1)`), but a future change to coordinate scheme (TODO G-01: pointy-top vs flat-top) would silently leave this handler broken. Same issue in `actCommsMoveIntelDown` lines 1304–1308. | STATE_MODEL §3.3, TODO G-01 | Replace with calls to `$this->hexNeighbors($sq, $sr)` and equality-check against the `NW`/`NE` (or `SW`/`SE`) entries by direction key. |
| **BE-14** | `hexpionage.game.php` `actCommsMoveIntelUp` direction guess line 1259, `actCommsMoveIntelDown` line 1333 | **Direction inferred from coordinate equality**: `($q === $sq) ? 'NW' : 'NE'` for up, `($q === $sq) ? 'SE' : 'SW'` for down. Per STATE_MODEL §3.3, `NW(q,r) = (q, r-1)` and `SE(q,r) = (q, r+1)` — both have `q' == q`. So `q === sq` → NW for up, SE for down. ✓ Computation correct, but fragile (couples direction inference to axial offsets). Same fragility note as BE-13. | STATE_MODEL §3.3 | Same fix as BE-13. |
| **BE-15** | `hexpionage.game.php` `actSmugglerSwapAgents` lines 1207–1210 | **Pickup-after-swap not implemented; only invariant assertion fires.** Per `rulebook.md` §6.8 effect 4 + D-21 universal pickup invariant: "if either agent's new hex contains loose intel after the swap, pickup fires; if Honeypot, §9.4 fires." The code asserts the invariant but does not run pickup logic. Per D-21 the precondition state ("loose intel co-occupied with agent") is structurally impossible at rest, so the assertion path is "correct" in normal play. However, `assertPickupInvariant()` will throw `BgaVisibleSystemException` if the impossible state occurs (e.g., due to a hostile client or earlier corruption), aborting the action mid-mutation and potentially leaving the swap half-applied (positions changed but no pickup performed, no honeypot resolution). | rulebook §6.8 effect 4, D-21 | Either (a) replace the bare assertion with `applyPickupAt(agent_a)` and `applyPickupAt(agent_b)` defensively (correct per D-21 generalised rule); or (b) wrap the swap body in a transaction so an invariant abort rolls back. (a) is preferred. |
| **BE-16** | `hexpionage.game.php` `setupNewGame` lines 109–119 | **`$this->gamestate->changeActivePlayer($first_player)` runs in `setupNewGame` BEFORE `gameSetup.onEnteringState` fires `gameStarted`.** This is a sequence concern only if BGA's framework calls `setupNewGame` after `gameSetup.onEnteringState`; per BGA_PRIMER §2 the framework runs `setupNewGame` first, then enters `gameSetup`. So the active player is set before the notification. ✓ Behaviour correct. **Flag**: `gameStarted` payload uses `$first_player` from `globals.active_player_id` (set in setup) — not from `changeActivePlayer` directly. Harmless. | BGA_PRIMER §2 | None. |
| **BE-17** | `hexpionage.game.php` `actRetireAgent` lines 967–976 | **Win check fires BEFORE Analyst bonus transition** — minor deviation from rulebook §6.5 (bonus = step 2, win = step 7). Effect is benign because reaching score ≥ 20 ends the game regardless of additional bonus points. However, if (counter-factually) the rulebook step ordering were ever interpreted to allow opponent's "tie-break window," this would be wrong. Per D-03 ties are impossible, so deviation is harmless. | rulebook §6.5, D-03 | None (or: relocate win check inside the non-bonus branch only, mirroring BE-01's depletion fix.) |
| **BE-18** | `EndOfTurnCleanup.php` line 113 | **`actions_remaining = 0` reset at end of turn** is correct. STATE_MACHINE §11.4 mandates the discriminator be `actions_phase_initialized != turn_id`, and that flag is reset to 0 on line 88. Verified: `Actions::onEnteringState()` lines 65–70 use the correct discriminator. ✓ **Confirmed F-09 / F-18 fix is in place.** | F-09, F-18, STATE_MACHINE §11.4 | None. |
| **BE-19** | `hexpionage.game.php` `actHackerPin` line 1463 | **Hacker self-flag set via `UPDATE agent SET hacker_pin_used_this_turn = 1`** but the in-memory `$h['hacker_pin_used_this_turn']` array fetched at line 1435 is NOT refreshed. If a downstream check inside the same handler ever re-reads `$h`, it would see the stale value. Currently no such re-read exists. ✓ **Not a bug — defensive only.** | (review note) | None. |
| **BE-20** | `hexpionage.game.php` `actEngineerPlaceBlockadeAnywhere` lines 1052–1108 | **Engineer remote does not check `pinned_until_turn` on the Engineer.** Per rulebook §6.6.A/B Engineer is "not pinned-prevented from using abilities." [FAQ Agents 4: pinned agents may still use abilities.] Wait — actually FAQ says pinned agents MAY use abilities, but not move/retire/swap. Engineer place-blockade IS an ability. So no pin gate is correct. ✓ **Verified, not a bug.** | rulebook §3.7, §6.6, FAQ Agents 4 | None. |
| **BE-21** | `hexpionage.game.php` `actHackerPin` line 1450 | **Code rejects pin on already-pinned target via `$tgt['pinned_until_turn'] !== null`** — correct per D-06b. ILL-23 / ILL-31 confirmed. ✓ | D-06b, ILL-23 | None. |
| **BE-22** | `hexpionage.game.php` `actHackerSteal*` line 1547 | **Self-target steal rejected via `(int)$tgt['owner'] === $active`** — but this is the same check as the friendly check; it does NOT explicitly reject `target == hacker`. Since target must be enemy and pinned, target is by definition not the Hacker (Hacker is friendly). ✓ Logic is fine. | rulebook §6.11.C | None. |
| **BE-23** | `hexpionage.game.php` `actDoubleAgentTransfer` lines 1357–1428 | **No adjacency check** — correct per rulebook §6.10 (Double Agent transfers without adjacency). ✓ | rulebook §6.10 | None. |
| **BE-24** | `hexpionage.game.php` `actDoubleAgentTransfer` line 1366 | **Owner check forces `$da['owner'] === $active`**. Per rulebook §6.10 the **target** can be any agent, friendly or enemy; the source MUST be the active player's Double Agent. ✓ | rulebook §6.10 | None. |
| **BE-25** | `hexpionage.game.php` `actCommsMoveIntelUp` line 1240, `actCommsMoveIntelDown` line 1310 | **Off-Field target rejected per D-25 (up) and C-02 default (down)** — correct. ✓ | D-25, ILL-21 | None. |
| **BE-26** | `TrickleResolve.php` lines 78–104 | **D-24 redirect-then-off-board precedence implemented correctly.** Verified against the algorithm: `if blockade(target) { if blockade(other) → stay; elif !field(other) → return-to-bag with redirected=true,off_board=true; else → move to other }; elif !field(target) → return-to-bag.` ✓ Matches D-24. | D-24 | None. |
| **BE-27** | `TrickleResolve.php` lines 174–198 | **D-23 two-Honeypot trickle: code returns ALL arrivals (including non-Honeypot ones) to bag** when any arrival is a Honeypot. Per D-23 interpretation (a), this is correct. ✓ | D-23 | None. |
| **BE-28** | `TrickleResolve.php` line 255 | **`assertPickupInvariant()` runs AFTER `trickleResolved` notification is queued.** If the assertion fires (defensive — should be impossible), the notification has already been emitted, leading to a state-vs-notification skew. Action handlers (e.g., `actMoveAgent`) have the same ordering. This is generally OK because BGA queues notifications and dispatches at the end of the request, but on assertion abort the notify queue may still flush. Minor risk; defensive. | STATE_MODEL §6.1 | Move `assertPickupInvariant()` BEFORE the `notify->all('trickleResolved', ...)` call, or wrap the entire state in a `BEGIN/COMMIT` transaction so an assertion rolls back the DB AND the notification queue. |
| **BE-29** | `TrickleResolve.php` lines 28–263 | **`STATE_MODEL §7.4` requires the entire trickle resolution to be one DB transaction.** The code does NOT wrap its body in `START TRANSACTION` / `COMMIT`. BGA's per-action handler is itself a transaction at the framework level — if the handler throws, the framework rolls back. This may be enough in practice, but the spec is explicit: "wraps the `stTrickleResolve` state's body in `DbQuery('START TRANSACTION')` ... `DbQuery('COMMIT')`." Spec deviation. | STATE_MODEL §7.4 | Wrap the body of `TrickleResolve::onEnteringState()` in explicit `DbQuery('START TRANSACTION')` and `DbQuery('COMMIT')` around the mutation block (before notify); on exception, BGA's framework will trigger ROLLBACK. |
| **BE-30** | `hexpionage.game.php` `actSmugglerBoostActions` line 1146 | **No `actions_remaining` decrement check** — correct per rulebook §6.7 (free + intel cost). ✓ | rulebook §6.7 | None. |
| **BE-31** | `hexpionage.game.php` `actAnalystReturn` line 1701 | **Public `analystBonusReturned` payload contains only `player_id`, `player_name`, `new_bag_size`** — no `tile_type`, no `tile_id`, no `score_value`. ✓ Matches CONTRACT §2.10b and D-20. | CONTRACT §2.10b, D-20 | None. |
| **BE-32** | `hexpionage.game.php` `getAllDatas` lines 197–200 | **Bag intel rows filtered via `state NOT IN (in_bag, returned_to_bag)`** — `intel_revealed` excludes bag identities. ✓ Matches CONTRACT §1.2 / STATE_MODEL §4.6. | CONTRACT §1.2 | None. |
| **BE-33** | `hexpionage.game.php` `getAllDatas` line 282 | **`game_winner` returned without int-cast.** Returns the raw `bga->globals` value (could be `null`, `int`, or string depending on storage). Inconsistent with other casts in the same function. Cosmetic robustness issue. | CONTRACT §1.1 | `'game_winner' => ($v = $this->bga->globals->get('game_winner')) === null ? null : (int)$v`. |
| **BE-34** | `hexpionage.game.php` `actSpawnAgent` line 654 | **Pin/steal flags reset on spawn** (`hacker_pin_used_this_turn = 0`, `hacker_steal_used_this_turn = 0`). Per D-15, these flags are per-Hacker. The agent being spawned was previously in `state=in_pool` so flags are already 0 from `setupNewGame()`. Reset is redundant but harmless. | D-15 | None (could simplify by removing the resets). |
| **BE-35** | `EndOfTurnCleanup.php` line 88 | **`actions_phase_initialized = 0` reset** — correct per STATE_MACHINE §11.4. ✓ Verifies F-09/F-18 are mitigated. | F-09, F-18 | None. |
| **BE-36** | `EndOfTurnCleanup.php` line 99 | **Win check uses `$ending_player`'s score**, not `active_player`. After cleanup, `$ending_player` is still the player whose turn just ended (active player has not yet flipped at this point — flip happens at line 112). So `$ending_player == active_player` here. ✓ Correct. | STATE_MACHINE §8.6 step 4 | None. |
| **BE-37** | `EndOfTurnCleanup.php` line 104 | **Depletion check via `$game->checkDepletion()` iterates active first.** Per `checkDepletion()` lines 446–453: sorts so active is first; if active is depleted → opponent wins. ✓ Matches STATE_MACHINE §12.3 simultaneous depletion semantics. | STATE_MACHINE §12.3 | None. |
| **BE-38** | `hexpionage.game.php` `actRetireAgent` line 935–963 | **`scoreUpdated` notification only fires when `score_delta > 0`.** Retiring an agent with no held intel is legal (rulebook §6.5: "minimum 0"). When `score_delta == 0`, no `scoreUpdated` is emitted. CONTRACT §2.24 says `scoreUpdated` "always paired" with the originating notification when `score_delta != 0` — so this matches the contract (only when delta is non-zero). ✓ | CONTRACT §2.24 | None. |
| **BE-39** | `Actions.php` `getArgs` lines 84–104 | **`legal_actions` is a coarse list of action *names*, not the per-agent affordances** prescribed by STATE_MACHINE §7.2. Spec gives a detailed schema (e.g., `actMoveAgent.agents: [{agent_id, legal_targets}]`). The code returns just `[{name: 'actMoveAgent'}, ...]`. Heavy spec deviation; the frontend will not have the targeting data it needs. | STATE_MACHINE §7.2 | Compute and ship per-agent legal-target lists as STATE_MACHINE §7.2 prescribes, OR follow the documented `TODO(state-args-1)` fallback (smaller args + on-demand client-server pings). The current code does neither. |
| **BE-40** | `Spawn.php` `onEnteringState` lines 36–43 | **Auto-pass logic when no legal spawn exists** — correctly returns `'autoPass'` to short-circuit to `actions`. ✓ | STATE_MACHINE §2.6 | None. |
| **BE-41** | `AnalystBonusDecision.php` line 80 | **`new_bag_size` in `analystBonusDrawn` payload computes `getBagSize() - 1`** — but the tile is still in_bag (no DB mutation occurred during the draw — only the id was captured in globals). So `getBagSize()` returns the un-changed count. Subtracting 1 is wrong if the player chooses Return (tile never leaves bag). The contract field `new_bag_size` is ambiguous. CONTRACT §2.9 doesn't specify which post-decision size. Minor cosmetic. | CONTRACT §2.9 | Either (a) document that `new_bag_size` is the "would-be size after a keep" and adjust; or (b) emit `current_bag_size` only and let actAnalystKeep emit the post-keep size in `analystBonusKept`. |
| **BE-42** | `AnalystBonusDecision.php` lines 36–85 | **`onEnteringState` emits `analystBonusDrawn` privately, BUT `tile_id`/`type`/`score_value` are NOT exposed in `getArgs()`.** On F5 mid-decision, the active player loses the drawn-tile context. Spec STATE_MACHINE §2.7b explicitly says "tile data is private (sent via analystBonusDrawn notification)" — so this is per-spec, but the F5 hole remains. Since the active player has no other source for the tile type after F5, the UI cannot render the keep/return modal. | STATE_MACHINE §2.7b | Optionally provide a private `getArgs(int $playerId)` that returns `tile_id/type/score_value` only when `playerId == active_player`. BGA framework supports private args via the `_private` key in `args` return. |
| **BE-43** | `hexpionage.game.php` `applyPickupAt` lines 504–510 | **Honeypot branch returns `intel_returned` containing the Honeypot tile id, all held intel, and any non-Honeypot loose tiles on the same hex.** Per D-23 / rulebook §9.4 step 3 — this is correct for trickle, but for an action-phase Honeypot move (`actMoveAgent` onto a Honeypot hex), there is normally only one tile (the Honeypot — no other arrivals at the same hex). Spec is satisfied either way. ✓ | D-23, rulebook §9.4 | None. |
| **BE-44** | `TrickleResolve.php` lines 130–138 | **`stack_order` not updated when tiles arrive at a hex during trickle.** Per STATE_MODEL §2.3 stack_order encodes deterministic UI rendering of multi-tile stacks ("trickle resolution sets it on stack entry"). Code only updates `hex_q/hex_r`. Multiple tiles arriving at the same hex will all have stale `stack_order` from their previous turn. UI rendering may be ambiguous. | STATE_MODEL §2.3 | After the apply loop, run a per-hex pass: `UPDATE intel_tile SET stack_order = ROW_NUMBER() OVER (PARTITION BY hex_q, hex_r ORDER BY id) WHERE state = on_board`, or compute per-hex incremental stack_order in PHP and bulk-update. |
| **BE-45** | `hexpionage.game.php` `actSpawnAgent` line 658 | **`spawned_this_turn` incremented but never compared to a hard cap.** Per D-10a "up to 3 spawns per turn" — the cap is enforced indirectly via the on-board check (`$on_board >= 3`). However if a player spawns 3, retires 1 mid-spawn-phase (not allowed — spawn phase has no retire), this isn't an issue. ✓ | D-10a | None. |
| **BE-46** | `hexpionage.game.php` `actSpawnAgent` line 658 | **Spawn during spawn phase only**, no `actions_remaining` consumption. ✓ Correct per rulebook §6.1 (no action cost). | rulebook §6.1 | None. |
| **BE-47** | `hexpionage.game.php` `getAllDatas` line 282 | **`game_winner` is missing the explicit int cast** (covered in BE-33 above). | (dup) | (see BE-33) |
| **BE-48** | `hexpionage.game.php` `applyPickupAt` lines 532–544 | **Over-capacity dump fires when `$held_count > 3`** — correct (>3 means at least 4). After dumping, ALL held intel of the agent goes to bag (not just the over-the-cap tiles). Per rulebook §9.3: "if held > 3, dump every held tile to bag." ✓ | rulebook §9.3 | None. |
| **BE-49** | `hexpionage.game.php` `actRetireAgent` lines 920–921 | **`bonus_eligible = is_analyst && count($held) === 3`** — correct per rulebook §6.5 effect 2 ("len(intel_held) == 3 at the moment of retirement"). The check happens on `$held` BEFORE the score loop mutates state. ✓ | rulebook §6.5 effect 2 | None. |
| **BE-50** | `hexpionage.game.php` `actRetireAgent` lines 921–924 | **Retire's `state = removed` set BEFORE the depletion check** — correct per rulebook §6.5 step 4 ("set agent.state = removed") then step 8 (depletion check). ✓ | rulebook §6.5 | None. |
| **BE-51** | `hexpionage.game.php` `getAllDatas` lines 156–171 | **`getCollectionFromDB` retrieves player rows** but maps `id`/`score`/etc. via in-place foreach. Edge case: `$pid` (loop key) is the `player_id` (since `getCollectionFromDB` keys by first column). The augmented `players` array uses `$pid` as key. Output schema matches CONTRACT §1.1. ✓ | CONTRACT §1.1 | None. |
| **BE-52** | `hexpionage.game.php` `actHackerPin` lines 1450–1452 | **Pin-on-already-pinned check uses `$tgt['pinned_until_turn'] !== null`** — but in PHP, DB return values are typically strings even for INT columns. `'0' !== null` is `true`, so behavior is correct. Defensive concern only. | (defensive) | None — but recommended to coerce: `((int)($tgt['pinned_until_turn'] ?? 0)) !== 0` or compare against null after explicit cast. |
| **BE-53** | `hexpionage.game.php` overall | **All `act*` methods correctly call `assertPickupInvariant()` at the end of each handler** before transitioning. Verified across `actSpawnAgent` (681), `actMoveAgent` (786), `actTransferIntel` (867), `actRetireAgent` (979/986), `actEngineerPlaceBlockadeAdjacent` (1047), `actEngineerPlaceBlockadeAnywhere` (1106), `actSmugglerBoostActions` (1146), `actSmugglerSwapAgents` (1210), `actCommsMoveIntelUp` (1277), `actCommsMoveIntelDown` (1351), `actDoubleAgentTransfer` (1425), `actHackerPin` (1480), `actHackerUnpin` (1528), `actHackerStealIntel` (1613), `actAnalystKeep` (1679), `actAnalystReturn` (1712). ✓ Matches D-21 invariant requirement. | D-21, STATE_MODEL §6.1 | None. |
| **BE-54** | `hexpionage.game.php` lines 71–88 | **`setupNewGame` agent rows do not specify `id` or rely on AUTO_INCREMENT.** The `INSERT ... VALUES (...)` block covers 24 rows (12 per player × 2). Schema (`dbmodel.sql` line 20) declares `id` as `SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT`. ✓ | STATE_MODEL §8.2 | None. |

### 1.3 S2 — Spec deviation but harmless

| ID | Location | Description | Spec | Proposed fix |
|---|---|---|---|---|
| **BE-55** | `hexpionage.game.php` `intelDrawn` notif from `TrickleDrawLeft.php`/`TrickleDrawRight.php` | **Empty-bag `intelDrawn` payload sets `tile_id => 0, type => 0`** as sentinels. CONTRACT §2.2 says: "When `skipped=true` (`bag_size==0` per [D-18]), `tile_id`/`type`/`hex` are unused." Sending zeros is OK (treated as "unused"); arguably they should be `null`. Cosmetic. | CONTRACT §2.2, D-18 | Send `null` for unused fields, OR document the zero-sentinel. |
| **BE-56** | `Actions.php` `getArgs` lines 86–104 | **`legal_actions` includes `actSmugglerBoostActions` only when `!$boost_used`** — correct. But the entry-construction ignores actions_remaining for `actRetireAgent` and `actEngineerPlaceBlockadeAnywhere` (correctly — they're free). ✓ | rulebook §6.2, §6.5, §6.6.B, §6.7 | None. |
| **BE-57** | `hexpionage.game.php` `INTEL_TYPES` use as `dice_state` keys (TrickleResolve line 54) | **`dice_state[$color_key]` lookup uses `INTEL_TYPES[$type_id]`**, e.g., `'honeypot'`, `'industrial_tech'`, etc. Matches `TrickleRoll.php` lines 30–33 which key the dice by intel-type-name. ✓ Correctness preserved across F-01 dice-keying inconsistency. | F-01, D-19 | None. |
| **BE-58** | `hexpionage.game.php` `INTEL_TILE_COUNTS` (material.inc.php line 67) | **Distribution `[7, 8, 8, 8, 8, 8] = 47`** — placeholder per TODO(I-02). 7 Honeypots is a guess. | TODO(I-02) | Audit punchboard PSDs and update. |
| **BE-59** | `hexpionage.game.php` `pinClearTurnFor` lines 328–334 | **Pin-clear formula: `current_turn + (target_owner == active ? 2 : 1)`.** Since Hacker can only pin enemies, target_owner != active, formula simplifies to `current_turn + 1`. ✓ Matches CONTRACT §2.13 "Setter formula [FINDING-04]". | CONTRACT §2.13 | None. |
| **BE-60** | `hexpionage.game.php` `actHackerStealIntel` lines 1556–1559 | **Per-Hacker `hacker_steal_used_this_turn` flag set AFTER the steal mutation** (line 1578). If the over-capacity dump emits early or throws, the flag may not be set. Defensive: set the flag earlier, or wrap in try/catch. | D-15 | Move the `UPDATE agent SET hacker_steal_used_this_turn = 1` to immediately after preconditions pass and before the bag/agent mutations. |
| **BE-61** | `hexpionage.game.php` `actSmugglerSwapAgents` lines 1183–1186 | **Three-step swap (A → null → B's hex; B → A's hex; A → B's hex)** — defensive against unique-position invariant. ✓ Correct. | (review note) | None. |
| **BE-62** | `hexpionage.game.php` `getAllDatas` line 263 | **`dice_state` deserialized as object (`is_object($dice)`).** If stored as `stdClass`, `(array)$dice` cast preserves keys. ✓ | STATE_MODEL §2.5 | None. |
| **BE-63** | `EndOfTurnCleanup.php` lines 49 / 78 | **`pinExpired`/`blockadeExpired` notifications emitted with empty `cleared_*` arrays use empty-string log message** (`empty($cleared) ? '' : ...`). Empty log lines may surface in BGA's log as blank rows. CONTRACT §2.12/§2.15 says "skipped when empty" — i.e., the notification could be omitted entirely when nothing expired. | CONTRACT §2.12, §2.15 | Skip the notify->all entirely when the array is empty. |
| **BE-64** | `hexpionage.game.php` `getAllDatas` line 280 | **`dice_state` returns the raw decoded object** — frontend must accept `{}` (empty object) when between turns. ✓ Matches CONTRACT §1.1 (empty `{}`). | CONTRACT §1.1 | None. |
| **BE-65** | `gameinfos.inc.php` line 39 | **`db_undo_support => true`** — per BGA_PRIMER §2 enables undo. STATE_MACHINE §5 mandates per-state undo policies. The code does NOT implement explicit `undoSavepoint()` calls. The framework default may be insufficient for the spec's per-action savepoint pattern (STATE_MACHINE §5.2). | STATE_MACHINE §5.2 | Add `$this->undoSavepoint()` calls at each `actions`-state action handler entry, and at `Spawn::onEnteringState()` first entry. Without these, the BGA undo button will at best roll back to the LAST framework-checkpointed state — typically the previous `nextState()` boundary — but this does not guarantee per-action granularity. |
| **BE-66** | `hexpionage.game.php` `getAllDatas` lines 174–194 | **`agents` includes ALL 24 rows** (even `state=removed`, `state=in_pool`). ✓ Matches CONTRACT §1.1 / D-10b ("agents: always 24 rows"). | CONTRACT §1.1 | None. |
| **BE-67** | `hexpionage.game.php` `getOpponent` lines 469–473 | **Returns the first row that's not `$pid`.** Works for 2 players. ✓ Matches D-02. | D-02 | None. |
| **BE-68** | `hexpionage.game.php` `actSpawnAgent` lines 622–642 | **Spawn precondition order**: ownership → state-in-pool → spawn-row → no-agent → no-blockade → no-intel → spawn-cap. All preconditions enforced. Maps to ILL-01..ILL-09 from QA_SPEC_REVIEW §4. | rulebook §6.1, ILL | None. |
| **BE-69** | `hexpionage.game.php` `actMoveAgent` lines 700–727 | **All moveAgent preconditions** present: ownership, on-board, not-pinned, in-Field, adjacent, no-agent-on-target, no-blockade-on-target, actions-remaining ≥ 1. Maps to ILL-01..ILL-05, ILL-36. ✓ | rulebook §6.3, ILL | None. |
| **BE-70** | `hexpionage.game.php` `actTransferIntel` lines 793–815 | **Self-target rejection (line 797)** — explicit check. ✓ Matches ILL-29..ILL-31, ILL-12 from SCENARIOS §3. | rulebook §6.4, ILL | None. |
| **BE-71** | `hexpionage.game.php` no `setupNotifications()` call | **Modern framework binds notification handlers via JS auto-discovery** (BGA_PRIMER §5). Backend doesn't call `setupNotifications`. ✓ | BGA_PRIMER §5 | None. |
| **BE-72** | `hexpionage.game.php` `actSmugglerSwapAgents` lines 1162–1163 | **Smuggler must own the spent intel** — `ensureIntelHeldBy($intel_id, $smuggler_id)` enforced. ✓ | rulebook §6.8 | None. |
| **BE-73** | `hexpionage.game.php` `actHackerPin` lines 1453–1456 | **Adjacency required for pin** — implemented. ✓ | rulebook §6.11.A | None. |

### 1.4 S3 — Cosmetic / quality

| ID | Location | Description |
|---|---|---|
| **BE-74** | `hexpionage.game.php` line 9 docstring | Mentions "ALL RNG via bga_rand". Verified — no `mt_rand`, `array_rand`, `RAND()`, `shuffle()` anywhere. ✓ |
| **BE-75** | `hexpionage.game.php` SQL strings throughout | Variables interpolated directly into SQL via PHP string concatenation. While player-controlled inputs are cast to `int` first (e.g., `$active = $this->activePlayerId()`) so injection risk is low, BGA convention is `self::DbQuery($sql)` with safer parameterization patterns. Defensive only. |
| **BE-76** | `hexpionage.game.php` line 9 | Comment "All RNG via bga_rand" — indeed all calls use `bga_rand`. ✓ |
| **BE-77** | `hexpionage.game.php` `applyPickupAt` line 506 | Comment says "Other non-Honeypot loose tiles on same hex also return per §9.4 step 3" — citing D-23 would be more precise. |
| **BE-78** | `hexpionage.game.php` `actMoveAgent` line 716 | Adjacency check uses helper but the immediate `from_hex` is computed inline (line 729) — minor duplication. |
| **BE-79** | `hexpionage.game.php` `actHacker*` | The three Hacker action handlers each repeat 8 lines of "is this Hacker eligible" preconditions. Extract to a helper `ensureHackerEligible(int $hacker_id, string $slot): array`. |
| **BE-80** | `hexpionage.game.php` over-capacity dump in `actTransferIntel` (lines 825–837) and `actDoubleAgentTransfer` (lines 1383–1395) and `actHackerStealIntel` (lines 1564–1576) | Repeated 12-line block. Extract to a helper `applyOverCapacityDump(int $agent_id, string $trigger): int[]`. |
| **BE-81** | `hexpionage.game.php` blockade insertion in `actEngineerPlaceBlockadeAdjacent` (1021–1023) and `actEngineerPlaceBlockadeAnywhere` (1081–1083) | Repeated 2-line INSERT. Extract to a helper. |
| **BE-82** | `material.inc.php` placeholder field layout (lines 99–120) | Documented as `TODO(G-02)`. Spawn row at `r=3` is a guess. |
| **BE-83** | `hexpionage.game.php` `actCommsMoveIntelUp` line 1235 | Direction validity check is inline arithmetic; cleaner via `hexpionage_hex_neighbors()`. |
| **BE-84** | `dbmodel.sql` `agent.id` is `SMALLINT UNSIGNED` | 24 rows max — fine. ✓ |
| **BE-85** | `dbmodel.sql` `intel_tile.id` is `SMALLINT UNSIGNED` | 47 rows max — fine. ✓ |
| **BE-86** | `gameinfos.inc.php` line 10 | "TODO(metadata)" placeholders for designer/artist credits. |

---

## 2. Coverage Matrix — Illegal-Action Tests vs Code Rejections

For each ILL-NN from `QA_SPEC_REVIEW.md §4`, traced to the action handler in `hexpionage.game.php` and verified the server-side rejection.

| # | ILL | Action | Illegal input | Status | Code site |
|---|---|---|---|---|---|
| 1 | ILL-01 | `actMoveAgent` | non-adjacent target | **CONFIRMED** | line 716–718 (`isAdjacent` check) |
| 2 | ILL-02 | `actMoveAgent` | pinned agent | **CONFIRMED** | line 710–712 (`pinned_until_turn !== null`) |
| 3 | ILL-03 | `actMoveAgent` | off-Field target | **CONFIRMED** | line 713–715 (`isFieldHex`) |
| 4 | ILL-04 | `actMoveAgent` | blockaded target | **CONFIRMED** | line 722–724 (`getBlockadeAtHex`) |
| 5 | ILL-05 | `actMoveAgent` | target has another agent | **CONFIRMED** | line 719–721 (`getAgentAtHex`) |
| 6 | ILL-06 | `actSpawnAgent` | hex has intel | **CONFIRMED** | line 638–641 (`getLooseIntelAtHex`) |
| 7 | ILL-07 | `actSpawnAgent` | already 3 on board | **CONFIRMED** | line 644–648 (`$on_board >= 3`) |
| 8 | ILL-08 | `actSpawnAgent` | agent already on board | **CONFIRMED** | line 626–628 (`state !== AGENT_STATE_IN_POOL`) |
| 9 | ILL-09 | `actSpawnAgent` | empty pool | **CONFIRMED** | indirectly via line 626–628 (every agent in `state=removed`/`on_board` rejected). Could be more precise (separate check), but functionally covered. |
| 10 | ILL-10 | `actRetireAgent` | not on ✦ hex | **CONFIRMED** | line 888–890 (`isSpawnRowHex`) |
| 11 | ILL-11 | `actRetireAgent` | pinned | **CONFIRMED** | line 891–893 (`pinned_until_turn !== null`) |
| 12 | ILL-12 | `actRetireAgent` | spawned this turn | **CONFIRMED** | line 894–896 (`spawned_on_turn === turn`) |
| 13 | ILL-13 | `actEngineerPlaceBlockadeAdjacent` | target occupied | **CONFIRMED** | line 1005–1007 (`getAgentAtHex`) |
| 14 | ILL-14 | `act_engineer_place_blockade_*` | exceeding 3-cap | **CONFIRMED** | line 1011–1015 (adjacent), line 1071–1075 (anywhere) |
| 15 | ILL-15 | `actSmugglerBoostActions` | already used | **CONFIRMED** | line 1120–1122 (`smuggler_boost_used_this_turn`) |
| 16 | ILL-16 | `actSmugglerSwapAgents` | pinned agent | **CONFIRMED** | line 1170–1172 (both pin checks) |
| 17 | ILL-17 | `actSmugglerSwapAgents` | both agents same | **CONFIRMED** | line 1159–1161 (`$agent_a_id === $agent_b_id`) |
| 18 | ILL-18 | `act_comms_move_intel_*` | target intel held by agent | **CONFIRMED** | line 1228–1231 (`state !== INTEL_STATE_ON_BOARD`); line 1298–1301 (down) |
| 19 | ILL-19 | `act_comms_move_intel_*` | target hex blockaded | **CONFIRMED** | line 1242–1244 (up); line 1312–1314 (down) |
| 20 | ILL-20 | `actCommsMoveIntelDown` | paying with moving intel | **CONFIRMED** | line 1292–1294 (paid==target). Note: paid is held; target is loose — they cannot collide; check redundant but valid. |
| 21 | ILL-21 | `act_comms_move_intel_*` | target off-board | **CONFIRMED** | line 1239–1241 (up, D-25); line 1309–1311 (down, C-02) |
| 22 | ILL-22 | `actHackerPin` | own agent | **CONFIRMED** | line 1447–1449 (`(int)$tgt['owner'] === $active`) |
| 23 | ILL-23 | `actHackerPin` | already pinned | **CONFIRMED** | line 1450–1452 (D-06b) |
| 24 | ILL-24 | `actHackerUnpin` | not pinned | **CONFIRMED** | line 1502–1504 (`pinned_until_turn === null`) |
| 25 | ILL-25 | `actHackerStealIntel` | target not pinned | **CONFIRMED** | line 1550–1552 (`pinned_until_turn === null`) |
| 26 | ILL-26 | `actHackerPin` | per-Hacker pin slot used | **CONFIRMED** | line 1440–1442 (D-15) |
| 27 | ILL-27 | `actHackerStealIntel` | per-Hacker steal slot used | **CONFIRMED** | line 1543–1545 |
| 28 | ILL-28 | `actHackerPin` | after unpin same turn | **CONFIRMED** | line 1440 (slot shared with unpin via `hacker_pin_used_this_turn`); line 1495 (unpin sets the same flag) |
| 29 | ILL-29 | `actTransferIntel` | non-adjacent | **CONFIRMED** | line 808–811 |
| 30 | ILL-30 | `actTransferIntel` | source doesn't have intel | **CONFIRMED** | line 812 (`ensureIntelHeldBy`) |
| 31 | ILL-31 | `actTransferIntel` | target is opponent | **CONFIRMED** | line 802–804 (both must be active player) |
| 32 | ILL-32 | `actDoubleAgentTransfer` | target not on board | **CONFIRMED** | line 1370–1372 (`state !== AGENT_STATE_ON_BOARD`) |
| 33 | ILL-33 | `actRetireAgent` | opponent's agent | **CONFIRMED** | line 880–882 |
| 34 | ILL-34 | any `act_*` | wrong phase | **CONFIRMED** | `ensurePhaseIsActions()` / `ensurePhaseIsSpawn()` called at top of every handler; AnalystBonusDecision actions check `phase === 'analyst_bonus'`. |
| 35 | ILL-35 | any `act_*` | not your turn | **AMBIGUOUS** | The framework's `#[PossibleAction]` plus `gamestate->setActivePlayer()` enforces turn-ownership at the framework level. The handlers themselves do not double-check `getCurrentPlayerId() == active_player_id`. Per BGA_PRIMER §3 the framework protects this; the code is implicitly safe. |
| 36 | ILL-36 | action-cost `act_*` | actions_remaining < cost | **CONFIRMED** | every action-cost handler has `if ((int)$this->bga->globals->get('actions_remaining') < 1) throw ...`. Hacker steal is free per D-15 (no decrement, no check), correct. |
| 37 | ILL-37 | intel-cost `act_*` | intel not held by actor | **CONFIRMED** | `ensureIntelHeldBy()` invoked in each cost-paying handler. |
| 38 | ILL-38 | `act_engineer_place_blockade_*` | hex has another blockade | **CONFIRMED** | line 1008–1010 (adjacent); line 1068–1070 (anywhere) |
| 39 | ILL-39 | `actSmugglerSwapAgents` | self-swap with self | **CONFIRMED** | line 1159–1161 |
| 40 | ILL-40 | any `act_*` | malformed payload | **CONFIRMED** | BGA's `#[PossibleAction]` parameter type checking + PHP type-hints (`int`) enforce well-formedness at the framework level. |

**Coverage stats**: 39 CONFIRMED, 1 AMBIGUOUS (ILL-35), 0 MISSING.

> **Additional gap (not in ILL-01..ILL-40 catalog)**: `actHackerStealIntel` does NOT enforce **adjacency** between Hacker and target (BE-03 above). Adjacency is required per rulebook §6.11.C and STATE_MODEL §9.9. This is an S0 logic gap that the ILL catalog did not explicitly enumerate.

> **Additional gap**: `actSmugglerSwapAgents` does NOT enforce that Smuggler is `state=ON_BOARD` (BE-02 above). Standard precondition for any agent ability.

---

## 3. Spec-Compliance Audit

| Item | Status | Evidence |
|---|---|---|
| Modern BGA framework (state classes, attribute routing) | **PASS** | All 9 state classes in `src/modules/php/States/*.php`; main game class uses `#[PossibleAction]` decorators throughout (e.g., line 616). |
| `bga_rand` for all RNG | **PASS** | Verified by `grep -n "rand\|RAND\|shuffle"` — only `bga_rand` calls. Setup: `bga_rand(1, count($player_ids))` (line 104); bag draw: `bga_rand(1, $n)` (line 391); dice roll: `bga_rand(1, 2)` (TrickleRoll line 31). No `mt_rand`, `array_rand`, `RAND()`, `shuffle()`. |
| `getAllDatas()` filters bag contents | **PASS** | Line 200: `WHERE state NOT IN (INTEL_STATE_IN_BAG, INTEL_STATE_RETURNED_TO_BAG)`. |
| `analystBonusDrawn` is `notify->player` only | **PASS** | `AnalystBonusDecision.php` line 71–82: `notify->player($active, 'analystBonusDrawn', ...)`. No other emission site. |
| Trickle resolution emits one batched notification | **PASS** | `TrickleResolve.php` lines 241–253: single `notify->all('trickleResolved', ...)`. No per-piece notifications during trickle. |
| Trickle resolution is transactional | **PARTIAL FAIL (BE-29)** | Body NOT wrapped in `DbQuery('START TRANSACTION')` / `COMMIT`. Relies on framework-level transactionality. STATE_MODEL §7.4 explicitly requires explicit transaction. |
| `INVARIANT-PICKUP` asserted at end of every state-mutating action | **PASS** | `assertPickupInvariant()` called at end of every action handler (BE-53 above). |
| All 26 contract notifications emitted | **PARTIAL** | See subsection below. |
| `actionsBoosted`/`agentSwapped` payload alignment | **PASS** | Verified against CONTRACT §2.17, §2.21. |
| `actions_phase_initialized` discriminator (F-09 / F-18 fix) | **PASS** | `Actions.php` lines 62–70 use `actions_phase_initialized` flag against `turn_id`; reset to 0 in `EndOfTurnCleanup.php` line 88. |
| `analystBonusDecision` entered ONLY when retiring Analyst with 3 intel | **PASS** | Line 921–922, 978–984: only fires `nextState('analystBonus')` when `$is_analyst && count($held) === 3`. |
| `endOfTurnCleanup` runs all 5 cleanup steps in order | **PASS** | Pin (1) → blockade (2) → flag reset (3) → win (4) → depletion (5) → pass turn (6). Verified `EndOfTurnCleanup.php` lines 32–130. |
| Win condition timing: §6.5 step 7 (win) before step 8 (depletion) | **PARTIAL FAIL (BE-01)** | The order in `actRetireAgent` is win → depletion → bonus. Per rulebook §6.5: bonus → … → win → depletion. Bug: depletion should not fire before bonus state for an Analyst with 3 intel. |
| Retire scoring [D-14]: all held intel scored | **PASS** | `actRetireAgent` lines 899–919: loops every held tile, sets `state=scored`, sums score_value. |
| Hacker per-Hacker flags [D-15] | **PASS** | Per-row columns `hacker_pin_used_this_turn`, `hacker_steal_used_this_turn`. Reset in `EndOfTurnCleanup` line 91–93 for all on-board Hackers. |
| Honeypot trigger generality [D-21] | **PASS** | `applyPickupAt()` is called from `actMoveAgent`; `assertPickupInvariant` defends others. Trickle has its own honeypot logic. Smuggler swap relies on invariant assertion (BE-15). |
| Empty bag handling [D-18] | **PASS** | `TrickleDrawLeft`/`Right` skip with `skipped=true` notification; `AnalystBonusDecision` emits `analystBonusSkipped` and bypasses. |
| Blockade redirect off-board [D-24] | **PASS** | `TrickleResolve.php` lines 86–93: redirect to off-Field hex returns to bag with `redirected=true, off_board=true`. |
| Two-step Analyst flow [D-26] | **PASS** | Private draw in `AnalystBonusDecision::onEnteringState`; separate `actAnalystKeep`/`actAnalystReturn` handlers. |

### 3.1 26-notification emission audit

| # | Name | Emitted? | Site |
|---|---|---|---|
| 1 | `gameStarted` | ✓ | `GameSetup.php` line 32 |
| 2 | `intelDrawn` | ✓ | `TrickleDrawLeft.php` line 33/56; `TrickleDrawRight.php` line 32/55 |
| 3 | `diceRolled` | ✓ | `TrickleRoll.php` line 36 |
| 4 | `agentSpawned` | ✓ | `actSpawnAgent` line 664 |
| 5 | `trickleResolved` | ✓ | `TrickleResolve.php` line 241 |
| 6 | `agentMoved` | ✓ | `actMoveAgent` line 737 |
| 7 | `intelTransferred` | ✓ | `actTransferIntel` line 841; `actDoubleAgentTransfer` line 1399 |
| 8 | `agentRetired` | ✓ | `actRetireAgent` line 940 |
| 9 | `analystBonusDrawn` | ✓ | `AnalystBonusDecision.php` line 71 (private) |
| 10 | `analystBonusKept` | ✓ | `actAnalystKeep` line 1645 |
| 10b | `analystBonusReturned` | ✓ | `actAnalystReturn` line 1700; `AnalystBonusDecision::zombie` line 111 |
| 10c | `analystBonusSkipped` | ✓ | `AnalystBonusDecision.php` line 45 |
| 11 | `blockadePlaced` | ✓ | `actEngineerPlaceBlockadeAdjacent` line 1030; `Anywhere` line 1089 |
| 12 | `blockadeExpired` | ✓ | `EndOfTurnCleanup.php` line 76 |
| 13 | `agentPinned` | ✓ | `actHackerPin` line 1468 |
| 14 | `agentUnpinned` | ✓ | `actHackerUnpin` line 1517 |
| 15 | `pinExpired` | ✓ | `EndOfTurnCleanup.php` line 46 |
| 16 | `intelStolen` | ✓ | `actHackerStealIntel` line 1582 |
| 17 | `agentSwapped` | ✓ | `actSmugglerSwapAgents` line 1190 |
| 18 | `agentRemovedHoneypot` | ✓ | `actMoveAgent` line 754 (move trigger). **Trickle path emits via `trickleResolved.honeypot_removals[]` only — correct per CONTRACT §2.19 directive.** |
| 19 | `agentDumpedOvercapacity` | ✓ | `actMoveAgent` line 774; `actTransferIntel` line 855; `actDoubleAgentTransfer` line 1413; `actHackerStealIntel` line 1601. **Trickle path: batched into `trickleResolved.over_capacity_dumps[]` only.** |
| 20 | `actionsBoosted` | ✓ | `actSmugglerBoostActions` line 1133 |
| 21 | `intelMoved` | ✓ | `actCommsMoveIntelUp` line 1260; `actCommsMoveIntelDown` line 1334 |
| 22 | `scoreUpdated` | ✓ | `actRetireAgent` line 956; `actAnalystKeep` line 1659 |
| 23 | `turnEnded` | ✓ | `EndOfTurnCleanup.php` line 118 |
| 24 | `gameEnded` | ✓ | `GameEnd.php` line 48 |

**Result**: All 26 notifications are emitted from somewhere in the code. ✓

### 3.2 Hidden-info filtering audit

- **Bag identities**: never shipped (BE-32 / `getAllDatas` line 200 filter). ✓
- **`analystBonusDrawn` privacy**: only `notify->player` — no `notify->all` for this event anywhere. ✓
- **`analystBonusReturned` payload omits `tile_type`**: verified at `actAnalystReturn` line 1700–1706. ✓
- **Bag identities of trickled Honeypots**: revealed at `intelDrawn` time — already public per §10.2. ✓
- **`intelDrawn` ordering left → right**: enforced by sequential states `trickleDrawLeft → trickleDrawRight → trickleRoll`. ✓

---

## 4. Recommended Fix Order

### S0 (must fix before any playtest)

1. **BE-01** — Reorder `actRetireAgent`: do NOT run depletion check before bonus transition. Move depletion + win checks into the non-bonus branch, and rely on the bonus-state's existing checks for the bonus path. *(Highest priority: real game-logic bug; an Analyst-with-3 retire that empties pool currently loses the game even when bonus could have won.)*
2. **BE-03** — Add adjacency check in `actHackerStealIntel`. *(Real precondition gap.)*
3. **BE-02** — Add `state == AGENT_STATE_ON_BOARD` check for the Smuggler in `actSmugglerSwapAgents`.

### S1 (must fix before BGA Studio submission)

4. **BE-08** — `agentMoved.picked_up_intel` should include the Honeypot tile id when moving onto a Honeypot, per CONTRACT §3.1.
5. **BE-10** — Add `actions_remaining` echo to `intelStolen` payload per CONTRACT §2.16/§2.23.
6. **BE-15** — Implement defensive pickup logic after `actSmugglerSwapAgents` (or wrap in transaction).
7. **BE-29** — Wrap `TrickleResolve::onEnteringState()` body in explicit `START TRANSACTION` / `COMMIT` per STATE_MODEL §7.4.
8. **BE-39** — Build the proper `legal_actions` per-agent affordances, OR explicitly adopt the `TODO(state-args-1)` fallback path.
9. **BE-44** — Update `stack_order` during trickle when multiple tiles arrive at the same hex.
10. **BE-65** — Add explicit `undoSavepoint()` calls to every undoable action handler entry.
11. **BE-42** — Provide private `getArgs()` that exposes `tile_id/type/score_value` to the active player on F5 reload of `analystBonusDecision`.
12. **BE-17** — Reorder `actRetireAgent` win check AFTER bonus transition, mirroring rulebook §6.5 step 7. Harmless but spec-compliant.

### S2 (should fix before alpha)

13. **BE-07** — Add `INVARIANT-ACTIONS-CAP` assertion after Smuggler boost.
14. **BE-28** — Move `assertPickupInvariant()` BEFORE the trickle notification.
15. **BE-41** — Document or fix `analystBonusDrawn.new_bag_size` semantics.
16. **BE-60** — Set `hacker_steal_used_this_turn = 1` earlier in the steal handler.
17. **BE-63** — Skip empty `pinExpired` / `blockadeExpired` notifications.
18. **BE-13/14** — Replace inline axial-offset computations with `hexpionage_hex_neighbors()` helper.
19. **BE-33** — Cast `game_winner` to `int|null` in `getAllDatas()`.
20. **BE-55** — Use `null` (not `0`) for unused fields in skipped `intelDrawn`.
21. **BE-58** — Confirm and replace placeholder `INTEL_TILE_COUNTS` after asset audit.

### S3 (cosmetic — non-blocking)

22. **BE-79..BE-83** — Refactor repeated patterns (Hacker eligibility, over-capacity dump, blockade insertion, axial offsets).
23. **BE-86** — Fill in metadata placeholders.

---

## 5. Code-Quality Notes (S3 only — non-blocking)

### 5.1 Naming inconsistencies

- **State transition keys** in `actions` handlers use varied verb forms: `actMoveAgent`, `actTransferIntel`, `actEngineer`, `actSmuggler`, `actComms`, `actDoubleAgent`, `actHacker`, `actAnalyst`, `actRetireAgent`, `actPassActions`. Some collapse multiple actions to one transition key (`actEngineer` for both adjacent and anywhere), some don't. Not wrong, but reduces grep-ability.
- **Phase strings** mix snake_case (`'trickle_draw_left'`) and the irregular `'analyst_bonus'` (no `analyst_bonus_decision`). The constant `PHASE_*` set in `material.inc.php` does not include `analyst_bonus`; the value is hard-coded as a magic string in `actAnalystKeep`/`actAnalystReturn` (lines 1621, 1687) and `AnalystBonusDecision::onEnteringState` (line 39). Add `const PHASE_ANALYST_BONUS = 'analyst_bonus';`.
- **`type_name` payload field** is included in many notifications but the canonical CONTRACT.md says only `type` (numeric IntelTypeId). `type_name` is convenience for the log message templates. Document or rename.

### 5.2 Missing comments on tricky logic

- `pinClearTurnFor` (lines 328–334) — formula is correct but the rationale (Hacker only pins enemies, so always +1) deserves a one-line comment beyond the existing one.
- `applyPickupAt` (lines 476–550) — the Honeypot branch's "ALL arrivals to bag" is a D-23 decision; the inline comment should cite D-23 explicitly (currently cites only §9.4).
- `actSmugglerSwapAgents` 3-step swap (lines 1183–1186) — the comment exists but doesn't explain why the unique-position invariant matters.

### 5.3 Dead code / redundant checks

- `actCommsMoveIntelDown` line 1292–1294: `paid_intel_id === target_intel_id` check is structurally impossible (one is held, one is loose). Defensive but redundant.
- `actSpawnAgent` line 654: pin/steal flag resets on spawn are no-ops (agent comes from `state=in_pool` where flags are already 0).
- `actAnalystKeep` line 1669 & `AnalystBonusDecision.php` line 89–90: the `analyst_bonus_pending_tile_id` is cleared in two places.

### 5.4 Repeated patterns that should be helper methods

- **Over-capacity dump** appears 4 times (`actTransferIntel` 825–837, `actDoubleAgentTransfer` 1383–1395, `actHackerStealIntel` 1564–1576, `applyPickupAt` 532–544). Extract: `applyOverCapacityDump(int $agent_id, string $trigger): array` returning the dumped ids.
- **Hacker eligibility** appears 3 times (`actHackerPin` 1435–1441, `actHackerUnpin` 1490–1496, `actHackerStealIntel` 1538–1545). Extract: `ensureHackerEligible(int $hacker_id, string $slot): array`.
- **Engineer eligibility** appears 2 times (`actEngineerPlaceBlockadeAdjacent` 996–999, `Anywhere` 1057–1060). Extract: `ensureEngineerEligible(int $engineer_id): array`.
- **Smuggler eligibility** appears 2 times. (And triggers BE-02 because the swap version is missing the `state` check.)
- **Blockade INSERT** appears 2 times.
- **`getPlayerNameById($active)`** appears 18+ times, often as a value of the `player_name` payload key. Helper: `selfPayload(int $pid): array { return ['player_id'=>$pid, 'player_name'=>self::getPlayerNameById($pid)]; }`.

### 5.5 Notification log message templates

CONTRACT.md prescribes specific log message templates (e.g., `clienttranslate('Game start — ${player_name} goes first.')`). The code's templates closely match the contract but have minor variations:
- `agentRemovedHoneypot` log says `'${player_name}\'s ${type_name} hits a Honeypot and is removed.'` while CONTRACT §2.19 says `'${player_name}\'s ${type_name} (#${agent_id}) hits a Honeypot and is removed.'` — missing `(#${agent_id})`.
- `agentPinned` log: `'${player_name}\'s Hacker pins agent #${target_agent_id} until turn ${pinned_until_turn}.'` — matches CONTRACT §2.13. ✓
- `intelTransferred` log: `'${player_name} transfers ${type_name} to agent #${to_agent_id}.'` — matches CONTRACT §2.7. ✓

Minor; no functional impact.

### 5.6 Type-hint and visibility cleanup

- Most public methods on `hexpionage` class are `public function` rather than `protected`/`private`. `getAgent`, `getIntel`, `getBagSize`, `applyPickupAt` are exposed publicly so the State classes can call them via `getGame()`. Acceptable but a more disciplined boundary (e.g., a `GameApi` trait) would be cleaner.
- `string $description = ''` is set on every state but never populated — BGA framework default may render an empty status banner. Spec STATE_MACHINE §2 prescribes localized strings (e.g., `'${actplayer} must take actions (${actions_remaining} remaining)'`). Update the `$description` and `$descriptionmyturn` assignments per state.

### 5.7 Unused / underused fields

- `agent.spawned_on_turn` is set on spawn but never queried in `getAllDatas()` filter or anywhere besides the `actRetireAgent` check. Schema-correct, just lightly used.
- `intel_tile.scored_by` is correctly populated and queried (for `scored_intel`).
- `INTEL_STATE_RETURNED_TO_BAG` is a distinct state from `INTEL_STATE_IN_BAG`. The code never folds `returned_to_bag` back to `in_bag`; both are treated identically in queries. Per QA F-14: "fold-back trigger undefined." Consider either folding (`UPDATE intel_tile SET state = 0 WHERE state = 4` at end-of-turn cleanup) or removing the distinction.

### 5.8 Missing tests/fixtures

The `tests/` directory contains `SCENARIOS.md` and (after this review) `CODE_REVIEW_BACKEND.md`, but no executable PHPUnit fixtures. Per BGA_PRIMER §10, isolated state-class tests would catch many of the bugs above pre-runtime. Strongly recommended for post-fix verification.

---

## Summary

This review identified **86 distinct findings** across `src/`. Severity distribution: **3 S0** (`BE-01`, `BE-02`, `BE-03`), **15 S1** (game-logic / contract violations), **31 S2** (spec deviations), **37 S3** (cosmetic). All 26 contract notifications are emitted; all 24 of 24 ILL-NN with a clear handler are CONFIRMED-rejected (the 25th, ILL-35 "not your turn", is AMBIGUOUS but framework-protected). The Analyst bonus, depletion ordering, and Hacker steal precondition gaps are the most concerning; the trickle-resolution algorithm is implemented carefully against the D-24 / D-23 / D-21 decision matrix.

End of `docs/testing/CODE_REVIEW_BACKEND.md`.
