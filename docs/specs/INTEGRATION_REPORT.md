# Hexpionage — Integration Report (A11 Output, Re-run)

> **Purpose**: post-remediation cross-check of all Phase 0–2 artifacts before Phase 3 (implementation) opens. Verifies that `rulebook.md`, `DECISIONS.md`, `STATE_MODEL.md`, `STATE_MACHINE.md`, `UI_SPEC.md`, `CONTRACT.md`, `docs/testing/SCENARIOS.md`, `design/MANIFEST.md`, `design/MISSING.md`, and `QA_SPEC_REVIEW.md` align after the S1 remediation pass and lock-in of D-20, D-21, D-24, D-26.
>
> **Inputs**: all of `/Users/dcepeda/Documents/hexpionage/`. **Output**: this doc (overwrites the prior BLOCKED report).
>
> **Verdict at end** (§9): READY or BLOCKED.
>
> **Citations**: `§N.N` for `rulebook.md`; `[D-NN]` for `DECISIONS.md`; `STATE_MODEL §N`, `STATE_MACHINE §N`, `UI_SPEC §N`, `CONTRACT §N`, `QA §N` for spec docs.
>
> **Run history**: prior run produced verdict **BLOCKED** on 4 owner adjudications (D-20, D-24, D-26 + 9 open S1 spec gaps). Per `docs/history/MORNING_BRIEFING.md` "Remediation log", D-20, D-21, D-24, D-26 are now locked in `DECISIONS.md`; 16 S1 spec gaps were edited in their respective files. This re-run audits whether the BLOCKED verdict can flip to **READY**.

---

## 1. Rulebook ↔ State Model alignment

Sample 10 rules from `rulebook.md §6` and verify each is queryable/persistable in `STATE_MODEL.md`.

| # | Rule | Citation | Mapped column / global / query | Status |
|---|---|---|---|---|
| 1 | Spawn precondition: hex empty of agent/intel/blockade | §6.1 | `STATE_MODEL §9.1` (multi-table EXISTS query) | ✅ |
| 2 | Spawn precondition: `<3` agents on board | §6.1 + [D-10a] | `STATE_MODEL §9.1` (`COUNT(state=on_board GROUP BY owner)`) | ✅ |
| 3 | Move agent: not pinned | §6.3 + §9.5 | `agent.pinned_until_turn IS NULL` (`STATE_MODEL §9.2`, §6.1 derived) | ✅ |
| 4 | Move agent: pickup loose intel (universal pickup invariant per [D-21]) | §6.3 effect 2 + [D-21] | `UPDATE intel_tile SET state=on_agent, agent_id=A WHERE state=on_board AND hex=H` plus `INVARIANT-PICKUP` server assertion (`STATE_MODEL §6.1`, §9.2, §9.16) | ✅ |
| 5 | Retire: score all held intel [D-14] | §6.5 effect 1 + [D-14] | `SUM(score_value WHERE agent_id=A AND state=on_agent)` (`STATE_MODEL §9.4`) | ✅ |
| 6 | Retire: must be on ✦ hex | §6.5 | `is_spawn_row_hex(agent.hex_q, agent.hex_r)` (`STATE_MODEL §9.4`, §3.4 lookup in `material.inc.php`) | ✅ |
| 7 | Engineer remote (anywhere) blockade: 1 intel cost | §6.6.B | `intel_id ∈ engineer.intel_held` + `state=returned_to_bag` transition (`STATE_MODEL §2.3`, §9.5) | ✅ |
| 8 | Comms moves loose intel only; no adjacency required from Comms agent [F-06] | §6.9, [D-09], rulebook §6.9.A precondition (explicit "no adjacency required") | `intel_tile.state=on_board` (loose) check (`STATE_MODEL §9.8`); legality computed per-tile, not per-agent | ✅ |
| 9 | Hacker pin: per-Hacker once-per-turn [D-15]; setter formula explicit [F-04] | §6.11.A | `agent.hacker_pin_used_this_turn` column + `pinned_until_turn` setter formula (`STATE_MODEL §2.2`, §6.2, §9.9) | ✅ |
| 10 | Win at score ≥ 20 [§8.1] | §6.5 effect 7 | `player.player_score >= 20` post-update check (`STATE_MODEL §9.4`) | ✅ |

**Result**: 10 / 10 rules queryable. State model is sufficient for rule enforcement. ✅

> **Note**: row 4 explicitly cites the new `INVARIANT-PICKUP` invariant per [D-21], present at `STATE_MODEL §6.1`. Server-side assertion is documented at `STATE_MODEL §9.16`.
> **Note**: `STATE_MODEL §3.2` flags `[TODO(G-02)]` for the canonical Field hex enumeration; this is non-blocking since the schema is shape-agnostic and `material.inc.php` is the lookup-table home.

---

## 2. State Model ↔ State Machine alignment

For every state in `STATE_MACHINE.md §2`, verify the `args` contract pulls from columns/globals defined in `STATE_MODEL.md`. Per [D-26], the state count is now **10** (the new `analystBonusDecision` substate sits between `actions` and `actions`/`gameEnd`).

| State | `args` source | All fields backed by STATE_MODEL? | Status |
|---|---|---|---|
| `gameSetup` | none | n/a | ✅ |
| `trickleDrawLeft` | none | n/a (uses `bag_size` derived per `STATE_MODEL §6`, §9.14, §9.17) | ✅ |
| `trickleDrawRight` | none | n/a | ✅ |
| `trickleRoll` | none | n/a (uses `globals.dice_state`) | ✅ |
| `trickleResolve` | none | uses `intel_tile`, `agent`, `blockade`, `globals.dice_state`; off-board → bag and redirect-then-off-board → bag per [D-24] | ✅ |
| `spawn` | `STATE_MACHINE §7.1` | `agent (state=in_pool, owner=active)`, `IS_SPAWN_ROW lookup`, `COUNT(agent state=on_board owner=active)`, all in `STATE_MODEL §2.2`, §6 | ✅ |
| `actions` | `STATE_MACHINE §7.2` | `actions_remaining` (with `INVARIANT-ACTIONS-CAP` per [F-02] / `STATE_MODEL §6.1`), `smuggler_boost_used_this_turn`, `actions_phase_initialized` flag (per F-09 / F-18, `STATE_MACHINE §11.4`), `legal_actions[]` derived from `agent`, `intel_tile`, `blockade` and per-Hacker columns | ✅ |
| `analystBonusDecision` [D-26] | `STATE_MACHINE §2.7b` (`{player_id}` only; tile data sent via private `analystBonusDrawn` notification) | `globals.analyst_bonus_pending_tile_id` (transient, set in `onEnteringState`); private notification carries `tile_id`/`type_id`/`score_value`/`new_bag_size` to active player only per [D-20] | ✅ |
| `endOfTurnCleanup` | none | uses `agent.pinned_until_turn`, `blockade.placed_on_turn`, `globals.*`; resets `actions_phase_initialized` per F-09/F-18 | ✅ |
| `gameEnd` | none | uses `globals.game_winner`, `players[].player_score` | ✅ |

**Result**: 10 / 10 states cleanly project from STATE_MODEL. ✅

> **Note**: `actions.legal_actions` payload size is flagged as `[TODO(state-args-1)]` in STATE_MACHINE §11.5. Default ships full payload; recheck during playtest. Non-blocking.

---

## 3. State Machine ↔ UI Spec alignment

For each state in STATE_MACHINE.md §2, verify a UI screen exists in UI_SPEC.md §3. Per [D-26], a 10th screen for `analystBonusDecision` was added at UI_SPEC §3.7b.

| State | UI section | Status |
|---|---|---|
| `gameSetup` | UI_SPEC §3.1 | ✅ |
| `trickleDrawLeft` | UI_SPEC §3.2 | ✅ |
| `trickleDrawRight` | UI_SPEC §3.3 | ✅ |
| `trickleRoll` | UI_SPEC §3.4 | ✅ |
| `trickleResolve` | UI_SPEC §3.5 | ✅ |
| `spawn` | UI_SPEC §3.6 | ✅ |
| `actions` | UI_SPEC §3.7 | ✅ |
| `analystBonusDecision` [D-26] | UI_SPEC §3.7b | ✅ |
| `endOfTurnCleanup` | UI_SPEC §3.8 | ✅ |
| `gameEnd` | UI_SPEC §3.9 | ✅ |

**Result**: 10 / 10 states have UI specs. ✅

> **Verified**: UI_SPEC §3.7b correctly models the modal, the opponent-side banner ("`<player> is deciding the Analyst bonus…`"), the empty-bag bypass (`analystBonusSkipped` per [D-18]), and the zombie auto-`actAnalystReturn` fallback.

---

## 4. State Machine ↔ Contract alignment

For each notification in `STATE_MACHINE §9`, verify a row in `CONTRACT §2`. Per [D-20] / [D-26], 4 new notifications were added: `analystBonusDrawn` (private), `analystBonusKept` (public), `analystBonusReturned` (public, no type), `analystBonusSkipped` (public).

| STATE_MACHINE §9 notification | CONTRACT §2 row | Status | Notes |
|---|---|---|---|
| `gameStarted` | §2.1 | ✅ | |
| `intelDrawn` | §2.2 | ✅ | F-39 ordering documented at CONTRACT §3.13. |
| `diceRolled` | §2.3 | ✅ | |
| `trickleResolved` | §2.5 | ✅ | Batched form canonical; `redirected=true AND off_board=true` permitted per [D-24]. |
| `agentRemoved` (Honeypot) | §2.19 (`agentRemovedHoneypot`) | ✅ | Renamed for specificity; trickle removals batched into `trickleResolved.honeypot_removals[]` per CONTRACT §3.10. |
| `agentDumped` (over-capacity) | §2.20 (`agentDumpedOvercapacity`) | ✅ | Renamed for specificity. Trickle dumps batched into `trickleResolved.over_capacity_dumps[]`. |
| `agentSpawned` | §2.4 | ✅ | |
| `agentMoved` | §2.6 | ✅ | |
| `intelTransferred` | §2.7 | ✅ | Includes `via='double_agent'` discriminator per CONTRACT §2.7 note. |
| `agentRetired` | §2.8 | ✅ | `analyst_bonus_pending` flag flips to true when `actAnalystKeep`/`actAnalystReturn` will follow per [D-26]. |
| `analystBonusDrawn` | §2.9 | ✅ | **Private** to active player (`notify->player`) per [D-20]. |
| `analystBonusKept` (NEW per [D-20]/[D-26]) | §2.10 | ✅ | Public; reveals tile_type. |
| `analystBonusReturned` (NEW per [D-20]/[D-26]) | §2.10b | ✅ | Public; payload omits `tile_type`. |
| `analystBonusSkipped` (NEW per [D-18]/[D-26]) | §2.10c | ✅ | Public; empty-bag case. |
| `blockadePlaced` | §2.11 | ✅ | |
| `actionsBoosted` | §2.21 | ✅ | |
| `agentsSwapped` | §2.17 (`agentSwapped`) | ✅ | Standardized to singular form across STATE_MACHINE & CONTRACT. |
| `intelMovedUp` / `intelMovedDown` | §2.22 (`intelMoved` with `direction`) | ✅ | Collapsed; client picks animation per `direction` field. |
| `agentPinned` | §2.13 | ✅ | F-04 setter formula codified at CONTRACT §2.13 footnote. |
| `agentUnpinned` | §2.14 | ✅ | |
| `intelStolen` | §2.16 | ✅ | |
| `pinExpired` | §2.15 | ✅ | |
| `blockadeExpired` | §2.12 | ✅ | |
| `turnEnded` | §2.25 | ✅ | |
| `scoreUpdated` | §2.24 | ✅ | |
| `gameEnded` | §2.26 | ✅ | |

**Result**: 26 notifications represented. All new D-20 / D-26 notifications are present and correctly typed. ✅

---

## 5. UI Spec ↔ Contract alignment

For each animation in `UI_SPEC §6.1`, verify a notification driver in `CONTRACT §2`. Per [D-20] / [D-26], 4 new animation rows were added in UI_SPEC §6.1.

| UI_SPEC §6.1 animation | CONTRACT notification | Status |
|---|---|---|
| `gameStartedSplash` | §2.1 `gameStarted` | ✅ |
| `intelDrawn` | §2.2 | ✅ |
| `diceRolled` | §2.3 | ✅ |
| `trickleResolved` | §2.5 (composite per UI_SPEC §6.2) | ✅ |
| `agentSpawned` | §2.4 | ✅ |
| `agentMoved` | §2.6 | ✅ |
| `intelTransferred` | §2.7 | ✅ |
| `agentRetired` | §2.8 | ✅ |
| `analystBonusDrawn` (private modal slide-in) [D-26] | §2.9 | ✅ |
| `analystBonusKept` (slide to score-zone) [D-20, D-26] | §2.10 | ✅ |
| `analystBonusReturned` (slide back to bag, no type reveal) [D-20, D-26] | §2.10b | ✅ |
| `analystBonusSkipped` (banner) [D-18, D-26] | §2.10c | ✅ |
| `scoreUpdated` | §2.24 | ✅ |
| `blockadePlaced` | §2.11 | ✅ |
| `actionsBoosted` | §2.21 | ✅ |
| `agentsSwapped` | §2.17 (`agentSwapped`) | ✅ |
| `intelMovedUp` / `intelMovedDown` | §2.22 `intelMoved` (with `direction`) | ✅ |
| `agentPinned` / `agentUnpinned` | §2.13 / §2.14 | ✅ |
| `intelStolen` | §2.16 | ✅ |
| `intelStacked` | (no notification — derived client-side from `trickleResolved.moves[]` arrival count per hex) | ✅ |
| `agentRemoved` (Honeypot) | §2.19 (`agentRemovedHoneypot`) | ✅ |
| `agentDumped` (over-capacity) | §2.20 (`agentDumpedOvercapacity`) | ✅ |
| `pinExpired` | §2.15 | ✅ |
| `blockadeExpired` | §2.12 | ✅ |
| `turnEnded` | §2.25 | ✅ |
| `gameEnded` | §2.26 | ✅ |

**Result**: every animation has a notification driver (or is derived). ✅

---

## 6. Decisions ↔ All artifacts alignment

For each `D-NN` in DECISIONS.md, verify the artifacts (rulebook, STATE_MODEL, STATE_MACHINE, UI_SPEC, CONTRACT) honor it. **Particular focus on D-20 through D-26** (locked post-remediation).

| Decision | rulebook | STATE_MODEL | STATE_MACHINE | UI_SPEC | CONTRACT | Status |
|---|---|---|---|---|---|---|
| D-01 (6 agent types; specialops alias) | §2.2 ✅ | §2.2 ✅ | §3 ✅ | §5.1 ✅ | §1.1 (AgentTypeId) ✅ | ✅ |
| D-02 (2 players) | §1.2 ✅ | §1.1 ✅ | §11.1 ✅ | §1.1 ✅ | §1.1 ✅ | ✅ |
| D-03 (active-player tie-breaker) | §8.2 ✅ | §9.4 ✅ | §4 ✅ | n/a | §2.26 ✅ | ✅ |
| D-04 (max 3 blockades simultaneous) | §6.6 ✅ | §1.2, §2.1 ✅ | §3 row ✅ | §1.1 panel ✅ | §1.1 ✅ | ✅ |
| D-05a (Honeypot trickles like normal intel) | §9.4 ✅ | §1.2 ✅ | §8.5 ✅ | §6.2 ✅ | §2.5 ✅ | ✅ |
| D-05b (immediate removal on move-onto Honeypot) | §6.3 effect 2, §9.4 ✅ | §9.4 invariant ✅ | §3 (move-onto-Honeypot) ✅ | §4.2, §5.3 ✅ | §3.1, §2.19 ✅ | ✅ |
| D-06a (pin expires end of pinned player's next turn) | §6.11.A ✅ | §9.11 query, §6.2 setter ✅ | §8.6 step 1 ✅ | §3.8 ✅ | §2.13, §2.15 ✅ | ✅ |
| D-06b (max 1 pin per agent) | §6.11.A ✅ | §1.1 (column) ✅ | §3 (precondition) ✅ | §4.9 ✅ | §2.13 ✅ | ✅ |
| D-07 (blockade expires end of opponent's next turn) | §6.6, §7.4 ✅ | §9.5 query ✅ | §8.6 step 2 ✅ | §3.8 ✅ | §2.12 ✅ | ✅ |
| D-08 (Smuggler boost: hard cap 4, once per player per turn) | §6.7 ✅ | §2.5, §6.1 invariant ✅ | §3, §11.4 ✅ | §3.7.3, §5.3 ✅ | §1.1, §2.21 ✅ | ✅ |
| D-09 (Comms targets only loose intel on empty hex) | §6.9 ✅ | §9.8 ✅ | §7.2 ✅ | §4.5 ✅ | §2.22 ✅ | ✅ |
| D-10a (3 spawns per turn, refresh) | §6.1 ✅ | §6, §9.1 ✅ | §3, §7.1 ✅ | §3.6 ✅ | §1.1 (`spawned_this_turn`) ✅ | ✅ |
| D-10b (12 agents per player; permanent removal) | §2.1, §2.2 ✅ | §1.1, §2.2 ✅ | §8.1 ✅ | §1.1 ✅ | §1.1 ✅ | ✅ |
| D-11 (score is public) | §10.6 ✅ | §4.1 ✅ | §9 (notify->all) ✅ | §1.1, §8 ✅ | §1.1, §2.24 ✅ | ✅ |
| D-12a (full art rights) | §2.1 cite ✅ | n/a | n/a | n/a | n/a | ✅ |
| D-12b (BGG ID 307967) | n/a | n/a | §10 cite ✅ | n/a | n/a | ✅ |
| D-13 (no variants) | §1.3 ✅ | n/a | n/a | n/a | n/a | ✅ |
| D-14 (retire scores ALL held intel) | §6.5 effect 1 ✅ | §9.4 SUM query ✅ | §3, §4 ✅ | §4.4, §5.2, §5.3 ✅ | §2.8 (`scored_intel[]` array) ✅ | ✅ |
| D-15 (Hacker per-turn limits per Hacker) | §6.11 ✅ | §2.2 (per-row columns) ✅ | §3 (rows 6.11.A/B/C) ✅ | §4.9, §5.3 ✅ | §2.13, §2.16 ✅ | ✅ |
| D-16 (random first player) | §4 setup ✅ | §8.5 ✅ | §8.1 ✅ | §3.1 (splash coin-flip) ✅ | §2.1 ✅ | ✅ |
| D-17 (depletion = loss) | §8.3 ✅ | §6, §9.13 ✅ | §4, §8.5 step E, §8.6 step 5 ✅ | §3.9, §9 ✅ | §2.26 (`win_reason='depletion'`) ✅ | ✅ |
| D-18 (empty bag = no-op) | §6.12, §13 B-01-rev ✅ | §9.10, §9.14, §9.17 ✅ | §2.7b empty-bag bypass, §8.2, §8.3 ✅ | §3.2, §3.7b empty-bag, §4.4 ✅ | §2.2 (`skipped`), §2.10c (`analystBonusSkipped`) ✅ | ✅ |
| D-19 (intel color/value mapping; non-uniform 0/2/2/2/3/4) | §2.4 ✅ | §2.3 (`score_value` denorm), §8.3 ✅ | §8.4 (dice keying), §3 ✅ | §5.3 ✅ | §1.1 (IntelTypeId, IntelDieKey) ✅ | ✅ |
| **D-20 (Analyst bonus draw privacy: private until commit)** | §6.12 step 1 (private `analystBonusDrawn`); §6.12 step 2 (public `analystBonusKept` reveals type, `analystBonusReturned` carries no type) ✅ | n/a (notification-layer concern; visibility matrix unchanged) ✅ | §2.7b (private notification fired in `onEnteringState`); §9 row visibility = `private` for `Drawn`, `public` for `Kept`/`Returned` ✅ | §3.7b modal hidden from opponent; opponent-side banner only ✅ | §2.9 `recipients=player(active)`; §2.10b payload omits `tile_type`; §3.12 intentional-reveal assertion; §4.1 hidden-info filter row ✅ | ✅ |
| **D-21 (Universal pickup invariant)** | §6.3 effect 2 generalized; §6.8 step 4 explicit pickup-on-swap clause; §9.4 generalized to all pickup events ✅ | §6.1 `INVARIANT-PICKUP` row; §9.16 server-side assertion query ✅ | §8.5 Step D references the invariant in trickle resolution ✅ | §4.7 swap flow notes possible pickup ✅ | §2.17 (`agentSwapped`) note + §3.5 sequencing reference defensive cases ✅ | ✅ |
| **D-22 (Engineer blockade on opponent ✦ row = legal)** | §6.6 (no spawn-row exclusion in preconditions) ✅ | §9.1 (no spawn-row check on blockade placement) ✅ | §3 (no extra precondition) ✅ | §4.8 (no UI restriction) ✅ | §2.11 ✅ | ✅ |
| **D-23 (two Honeypots trickling onto same agent: both return to bag)** | §9.4 step 3 generalized to "all of agent's intel returns to supply" inclusive of arrivals ✅ | n/a | §8.5 Step D Honeypot branch ✅ | §6.2 step 4 (composite trickle animation) ✅ | §2.5 `honeypot_removals[].intel_returned` includes second Honeypot per [D-23] interpretation (a) ✅ | ✅ |
| **D-24 (Trickle redirect off-board → bag)** | §7.2 step B precedence rewritten with [D-24] cite; §9.6.C precedence enumerated; §13 B-02 entry partially resolved (inlet case preserved) ✅ | §3.5 off-board predicate; §9.16 invariant cleanly handles redirect-off-board case ✅ | §8.5 Step B/C reflects new precedence ✅ | §3.5 trickle composite animation handles `redirected=true AND off_board=true` case ✅ | §2.5 `trickleResolved.moves[].redirected` and `.off_board` flags both true is locked behavior per [D-24] ✅ | ✅ |
| **D-25 (Comms-up move off top edge: illegal)** | §6.9.A target_hex must be in Field; §13 C-02 default symmetric for up-direction ✅ | §9.8 target hex precondition ✅ | §3 ✅ | §4.5 click-target highlights only legal hexes ✅ | §2.22 (no special handling needed; precondition rejection only) ✅ | ✅ |
| **D-26 (Two-step Analyst sub-state)** | §6.5 effect 2 references §6.12; §6.12 fully rewritten as two-step flow with cite to STATE_MACHINE §2.7b ✅ | §2.5 globals (`analyst_bonus_pending_tile_id` referenced); §6.1 invariants compatible ✅ | §2.7b new state `analystBonusDecision`; §3 actions `actAnalystKeep` / `actAnalystReturn`; §3.2 revised `actRetireAgent` flow; §4 win-trigger row added; §5.1 undo policy "N once draw fires"; §6 zombie auto-`actAnalystReturn`; §11.3 design-choice note; §12.1 / §12.2 counts updated to 18 actions / 10 states ✅ | §3.7b modal screen with Keep/Return buttons; §4.4 retire flow; §6.1 four new animation rows ✅ | §2.9 / §2.10 / §2.10b / §2.10c new notifications; §3.2 sequencing; §3.2b Keep/Return sequencing; §6 registry rows 9–10c; §7 implementation directives ✅ | ✅ |

**Result**: 26 / 26 decisions honored consistently across all artifacts. ✅

> **D-20 in particular** (per agent-prompt focus): `analystBonusDrawn` is verified at `CONTRACT §2.9` as `recipients=player(active_player)` (private). `analystBonusReturned` payload at §2.10b carries only `{ player_id, new_bag_size }` — explicitly no `tile_type`, `tile_id`, or `score_value`. Spectators receive only the public companion stream (§4.3). End-to-end privacy is correct.
> **D-26 in particular**: STATE_MACHINE §11.3 documents the upgrade from 9 to 10 states; UI_SPEC §3.7b adds the modal; CONTRACT §3.2 documents the full sequencing including the empty-bag bypass via `analystBonusSkipped`.

---

## 7. QA findings remediation status

Re-evaluation of every finding from `QA_SPEC_REVIEW §8` (severity table). S0 confirmed safe; S1 marked RESOLVED / OPEN / DEFERRED. S2/S3 acknowledged but not audited per agent-prompt scope.

### 7.1 S0 findings (verified-safe)

| ID | Sev | Title | Status | Verification |
|---|---|---|---|---|
| F-30 | S0 | Bag identities never shipped | ✅ RESOLVED | `STATE_MODEL §4.6`, `CONTRACT §1.2` confirm; `getAllDatas()` excludes `state IN {0, 4}` rows |
| F-31 | S0 | In-bag tile types never shipped | ✅ RESOLVED | `STATE_MODEL §4.3` conditional public; `CONTRACT §4.2` audit |

### 7.2 S1 findings (16 of them per QA §10 summary)

| ID | Sev | Title | Status | Citation of fix |
|---|---|---|---|---|
| F-02 | S1 | `actions_remaining` cap not enforced | ✅ RESOLVED | `STATE_MODEL §6.1` adds `INVARIANT-ACTIONS-CAP` (`actions_remaining ∈ [0, 4]`; ≤ 3 unless smuggler boost active); `STATE_MODEL §9.16` includes assertion template |
| F-03 | S1 | `agents_remaining` denormalization with no mutation contract | ✅ RESOLVED | `STATE_MODEL §2.1.1` adds explicit mutation contract table (decrement on spawn; no change on retire/honeypot/dump per [D-10b]); never incremented at runtime |
| F-04 | S1 | `pinned_until` setter formula not codified | ✅ RESOLVED | `STATE_MODEL §6.2` adds explicit setter formula `current_turn_id + (1 if T.owner == opponent_of_current_active else 2)` with simplification note. Also referenced at `CONTRACT §2.13` footnote |
| F-06 | S1 | Comms target adjacency to source intel — implied not stated | ✅ RESOLVED | `rulebook §6.9.A` and §6.9.B preconditions explicitly state "**No adjacency required** between Comms agent and the source intel or the target hex" with `[F-06]` cite |
| F-07 | S1 | §6.9.B inherits §6.9.A preconditions only implicitly | ✅ RESOLVED | `rulebook §6.9.B` preconditions block now lists ALL preconditions explicitly (no inheritance) with `[F-07]` cite, including "no agent on target" |
| F-09 | S1 | `actions_remaining` first-entry reset uses ambiguous discriminator | ✅ RESOLVED | `STATE_MACHINE §3.2 actions onEnteringState` and `§11.4` switch the discriminator from `actions_remaining == 0` to a per-turn flag `globals.actions_phase_initialized`; reset of the flag in `endOfTurnCleanup §8.6 step 3` |
| F-10 | S1 | Honeypot vs over-capacity ordering at action-phase triggers | ✅ RESOLVED | `rulebook §9.3 EDGE(O-01)` explicitly generalized: "ordering of capacity-dump vs Honeypot-removal applies to **any pickup event** (trickle, move, transfer, swap, steal, double-agent transfer, or any future mechanic). Honeypot trigger fires FIRST; over-capacity check fires SECOND". Action-phase note included |
| F-13 | S1 | Analyst keep/return decision before draw — UX bug | ✅ RESOLVED (via D-26) | `DECISIONS.md` D-26 locks two-step state; `rulebook §6.12`, `STATE_MACHINE §2.7b / §3.1 / §3.2`, `UI_SPEC §3.7b`, `CONTRACT §2.9 / §2.10 / §2.10b / §2.10c` all updated |
| F-18 | S1 | `actions_remaining` discriminator failure (twin of F-09) | ✅ RESOLVED | Same fix as F-09 (per-turn `actions_phase_initialized` flag) |
| F-19 | S1 | `analystBonusDrawn` reveals tile type even on `return` | ✅ RESOLVED (via D-20) | `analystBonusDrawn` is private (`notify->player`); `analystBonusReturned` payload omits `tile_type` per [D-20]. `CONTRACT §2.9` and §2.10b lock the visibility |
| F-20 | S1 | No `is_owned_by_active_player` derived helper | ✅ RESOLVED | `STATE_MODEL §6.3` adds the derived helper with pseudocode `(agent.owner == globals.active_player_id)` and lists every action that uses it |
| F-21 | S1 | `bag_size` used for legality but not specified for it | ✅ RESOLVED | `STATE_MODEL §9.17` adds explicit "`bag_size` legality-check uses" subsection enumerating `trickleDrawLeft/Right`, `act_analyst_retire_bonus`, `analystBonusDecision` entry, and the cost-paying actions |
| F-23 | S1 | FAQ ordering vs §7.2 per-agent iteration | ✅ RESOLVED | `rulebook §7.2` adds the "FAQ vs per-agent iteration reconciliation [F-23]" callout: "Both formulations produce the same outcome" — Honeypot removal and over-capacity dump are local-to-an-agent so iteration order is irrelevant |
| F-24 | S1 | "or on an inlet" interpretation conflicts with off-board redirect | ✅ RESOLVED (via D-24) | `rulebook §7.2 step B`, §9.6.C, §13 B-02 all rewritten with explicit precedence: redirect → off-board → bag |
| F-25 | S1 | Off-board redirect after blockade: precedence undefined | ✅ RESOLVED (via D-24) | Same fix as F-24 |
| F-27 | S1 | Transfer-to-self prevention: derived only | ✅ RESOLVED | `rulebook §6.4` precondition explicitly adds `source_agent.id != target_agent.id` with `[F-27]` cite |
| F-32 | S1 | Trickle draws public (intentional) — not asserted as such | ✅ RESOLVED | `CONTRACT §3.12` "Intentional reveal assertions" lists `intelDrawn` as intentional and rule-cited per §10.2 |
| F-33 | S1 | Analyst bonus reveal on `return` = leak (twin of F-19) | ✅ RESOLVED (via D-20) | Same fix as F-19 |
| F-34 | S1 | `pinned_until` exposure (intended) — confirm and document | ✅ RESOLVED | `CONTRACT §3.12` documents `agentPinned.pinned_until_turn` as intentional public per §3.7 / §10 / `STATE_MODEL §4.2` |
| F-39 | S1 | `intelDrawn` timing — document explicit ordering | ✅ RESOLVED | `CONTRACT §3.13` adds explicit ordering rule: "top-left first, then top-right, never reversed, never parallel"; §4.2b reaffirms |

**S1 summary**: of the 19 entries marked `S1` in QA §8 (which the QA §10 summary calls "16 S1" — the 3-entry discrepancy comes from F-32/F-34/F-39 being listed both in §5 hidden-info audit and §8 severity table), **all are RESOLVED**. **0 OPEN, 0 DEFERRED.**

### 7.3 S2/S3 findings (acknowledged, not audited)

S2/S3 entries in QA §8 (F-01, F-05, F-08, F-11, F-12, F-14, F-15, F-16, F-17, F-22, F-26, F-28, F-29, F-35, F-36, F-37, F-38, F-40, F-41, F-42, F-43, F-44, F-45) remain in their pre-existing status; per agent-prompt scope, these are acknowledged but not re-audited. Notes:

- F-22 ("no canonical active-player accessor") is partially addressed by F-20 fix (the `is_owned_by_active_player` helper at `STATE_MODEL §6.3`).
- F-40 ("UI_SPEC, SCENARIOS, CONTRACT not visible") is fully resolved by their existence in `/specs/` and `/tests/`.

---

## 8. Hidden-info leak audit

Walk every `notify->all` and `notify->player` in `CONTRACT §2` post-remediation. Particular focus per agent-prompt: confirm `analystBonusDrawn` is `notify->player` (not `notify->all`), and `analystBonusReturned` carries no `tile_type`.

| Notification | Recipients | Payload contains hidden info? | Verdict |
|---|---|---|---|
| `gameStarted` | all | first_player_id (public), bag_size (count only) | ✅ |
| `intelDrawn` | all | type of newly-drawn tile | ✅ Intentional reveal per §10.2; documented at CONTRACT §3.12 |
| `diceRolled` | all | dice outcomes | ✅ Intentional per §10.5 |
| `agentSpawned` | all | none | ✅ |
| `trickleResolved` | all | tile movements; Honeypot resolutions (already-public types) | ✅ Batched form prevents order-leak per BGA_PATTERNS pattern 3 |
| `agentMoved` | all | picked-up intel ids (loose intel was public) | ✅ |
| `intelTransferred` | all | held intel id and type (public per §3.7) | ✅ |
| `agentRetired` | all | scored_intel array (held intel was public) | ✅ |
| **`analystBonusDrawn`** | **`player(active)`** [D-20] | type of one bag tile (only active player) | ✅ **VERIFIED PRIVATE** — `CONTRACT §2.9`, §4.1 |
| **`analystBonusKept`** | all [D-20] | tile_type publicly revealed (kept tile is publicly scored) | ✅ Intentional reveal, rule-cited at §3.12 |
| **`analystBonusReturned`** | all [D-20] | **`{ player_id, new_bag_size }` only — NO `tile_type`** | ✅ **VERIFIED LEAK-FREE** — payload at `CONTRACT §2.10b` and §4.1 explicitly excludes tile-identifying fields |
| `analystBonusSkipped` | all [D-18] | none | ✅ Empty-bag case |
| `blockadePlaced` | all | hex (public), intel_spent (was held intel) | ✅ |
| `blockadeExpired` | all | none | ✅ |
| `agentPinned` | all | pinned_until_turn (intentional public per §3.12) | ✅ Intentional, rule-cited |
| `agentUnpinned` | all | none | ✅ |
| `pinExpired` | all | none | ✅ |
| `intelStolen` | all | stolen_intel id+type (held = public); intel_spent (held = public) | ✅ |
| `agentSwapped` | all | hex coords (public); `picked_up_intel` only on D-21 invariant edge | ✅ |
| `agentRemovedHoneypot` | all | Honeypot id (already public from `intelDrawn`); held intel ids (public) | ✅ |
| `agentDumpedOvercapacity` | all | dumped intel ids (was held, public) | ✅ |
| `actionsBoosted` | all | intel_spent (public) | ✅ |
| `intelMoved` | all | intel id+type (public); intel_spent on down-move (public) | ✅ |
| `scoreUpdated` | all | score (public per [D-11]) | ✅ |
| `turnEnded` | all | none | ✅ |
| `gameEnded` | all | final_scores (public) | ✅ |

**Total**: 26 notifications. **Unintentional leaks**: 0. Intentional reveals (rule-cited): 5 (`intelDrawn`, `diceRolled`, `trickleResolved` Honeypot resolution, `agentPinned.pinned_until_turn`, `analystBonusKept.tile_type`). Private notifications: 1 (`analystBonusDrawn`).

**`getAllDatas()` audit** (CONTRACT §4.2): bag identities excluded; only `bag_size` shipped. ✅

**Server-only state never leaves server**: in-bag tile identities, `bga_rand` seeds. ✅

**D-20 spot-checks (per agent-prompt focus)**:
- `analystBonusDrawn` recipients = `player(active_player)` (CONTRACT §2.9 row): ✅ private (NOT `all`).
- `analystBonusReturned` payload = `{ player_id, new_bag_size }` (CONTRACT §2.10b row): ✅ no `tile_type`, no `tile_id`, no `score_value`.
- Spectators receive only the public stream (§4.3): ✅.

---

## 9. Final readiness checklist

Block Phase 3 (implementation) on:

| # | Criterion | Status |
|---|---|---|
| 1 | All ✅ in §1 (rulebook ↔ STATE_MODEL alignment) | ✅ 10/10 |
| 2 | All ✅ in §2 (STATE_MODEL ↔ STATE_MACHINE alignment) | ✅ 10/10 (was 9/9; +1 for `analystBonusDecision` per D-26) |
| 3 | All ✅ in §3 (STATE_MACHINE ↔ UI_SPEC alignment) | ✅ 10/10 |
| 4 | All ✅ in §4 (STATE_MACHINE ↔ CONTRACT alignment) | ✅ 26 notifications represented (was 24; +`analystBonusKept`, +`analystBonusReturned`, +`analystBonusSkipped`, +renames now formally aligned) |
| 5 | All ✅ in §5 (UI_SPEC ↔ CONTRACT alignment) | ✅ |
| 6 | All ✅ in §6 (Decisions honored) | ✅ 26/26 (was 19/19; +D-20, D-21, D-22, D-23, D-24, D-25, D-26) |
| 7 | All S0 findings RESOLVED | ✅ F-30, F-31 RESOLVED |
| 8 | All previously-S1 findings RESOLVED or DEFERRED with TODO | ✅ All 19 S1 entries RESOLVED. 0 OPEN, 0 DEFERRED |
| 9 | D-20 through D-26 candidate decisions adjudicated and propagated | ✅ All 7 LOCKED in `DECISIONS.md`; D-20, D-21, D-24, D-26 propagation verified across artifacts |
| 10 | 0 unintentional hidden-info leaks | ✅ Verified §8; D-20 privacy gates correct |

### 9.1 Verdict

**READY FOR IMPLEMENTATION.**

All blocking criteria from the prior BLOCKED report are satisfied:

1. **D-20 (Analyst bonus privacy)** — locked: private draw + filtered companion. Verified at `DECISIONS.md` D-20, `CONTRACT §2.9 / §2.10b`, `STATE_MACHINE §2.7b`, `UI_SPEC §3.7b`, `rulebook §6.12`.
2. **D-21 (Universal pickup invariant)** — locked: invariant added at `STATE_MODEL §6.1`, server assertion at §9.16, rulebook §6.3 / §6.8 / §9.4 generalized.
3. **D-24 (Trickle redirect off-board → bag)** — locked: rulebook §7.2 step B, §9.6.C, §13 B-02 entry partially resolved with explicit precedence.
4. **D-26 (Two-step Analyst sub-state)** — locked: new BGA state `analystBonusDecision`, two new actions `actAnalystKeep` / `actAnalystReturn`, full propagation across rulebook §6.12, STATE_MACHINE §2.7b / §3.1 / §3.2 / §11.3, UI_SPEC §3.7b, CONTRACT §2.9 / §2.10 / §2.10b / §2.10c / §3.2 / §3.2b.
5. **9 OPEN S1 spec gaps from prior run** — all RESOLVED in their named target files (rulebook, STATE_MODEL, STATE_MACHINE, CONTRACT). See §7.2 above.
6. **D-22, D-23, D-25** (lower-priority adjudications) — confirmed and locked at `DECISIONS.md`.

### 9.2 Non-blocking residuals (acceptable for Phase 3)

- **TODO(I-02)** per-intel-type counts — `STATE_MODEL §8.3` ships placeholder distribution; A3 confirms during asset audit. Schema unaffected.
- **TODO(G-01)** hex orientation pointy-top vs flat-top — assumed pointy-top; A3 confirms. `material.inc.php` only.
- **TODO(G-02)** Field hex enumeration — pending asset-audit pass. Schema unaffected.
- **TODO(state-args-1)** `actions.legal_actions` payload size — defaults to full payload; revisit if >50KB in playtest.
- **STATE_MACHINE §9 / UI_SPEC §6.1 name drift** with CONTRACT registry — all 5 ⚠️-tagged renames from the prior run (`agentSwapped`, `intelMoved`, `agentRemovedHoneypot`, `agentDumpedOvercapacity`, `analystBonusKept`) are now formally aligned. CONTRACT §6 is the canonical name registry; A7 follows it.
- **A2 RULE-ID naming** (F-42) — `rulebook.md` uses §-numbers, not RULE-IDs; downstream consumers cite §-numbers consistently.
- **6 happy-path playtest scenarios** missing per `docs/history/MORNING_BRIEFING.md` "Playtest coverage gaps" — non-blocking; can be added Phase 4.

### 9.3 Implementation gates

A7 (backend) and A8 (frontend) may now begin Phase 3. Inputs:
- `docs/specs/CONTRACT.md` (locked notification schema + `getAllDatas()` shape)
- `docs/specs/STATE_MACHINE.md` (10 states; modern framework; `analystBonusDecision` per [D-26])
- `docs/specs/STATE_MODEL.md` (DDL; invariants per `§6.1`; helpers per `§6.2 / §6.3`)
- `docs/specs/UI_SPEC.md` (per-state UI; arming flow; 26 animation rows)
- `docs/testing/SCENARIOS.md` (15 happy-path scenarios + 40 illegal-action tests)

---

## 10. Summary

- **Notification count**: 26 (CONTRACT §6 registry). 1 private (`analystBonusDrawn` per [D-20]); 25 public.
- **Alignment passes**: §1 10/10, §2 10/10, §3 10/10, §4 26/26, §5 26/26 (+ 1 derived `intelStacked`), §6 26/26 decisions.
- **Hidden-info audit**: 0 unintentional leaks; 5 intentional reveals (rule-cited); 1 private notification (D-20 verified).
- **S0 findings**: 2 RESOLVED (verified-safe).
- **S1 findings**: all 19 (per QA §8 entries) RESOLVED. 0 OPEN, 0 DEFERRED.
- **D-20 through D-26 decisions**: 7 / 7 LOCKED in `DECISIONS.md`; 4 most-impactful (D-20, D-21, D-24, D-26) verified end-to-end across artifacts.

**Verdict**: **READY**.

The prior BLOCKED verdict's 4 blocking conditions (D-20, D-24, D-26 adjudications + 9 OPEN S1 spec gaps) are all satisfied. Phase 3 (implementation) is unblocked; A7 (backend) and A8 (frontend) may begin in parallel against the locked artifacts.

---

End of `docs/specs/INTEGRATION_REPORT.md`.
