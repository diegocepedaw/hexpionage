# Hexpionage — QA Spec Review (A9 Phase 2)

> **Purpose**: Adversarial review of the Hexpionage spec set BEFORE any code is written. The goal is to surface every internal inconsistency, contradiction, missing edge case, illegal-action coverage gap, hidden-info leak risk, and unresolved ambiguity now, while changes are still cheap.
>
> **Inputs reviewed**:
> - `rulebook.md` (canonical rulebook, ~1222 lines).
> - `DECISIONS.md` (D-01 through D-19, plus open follow-ups).
> - `final_printing/Hexpionage Rules FAQ.md` (the 6-bullet FAQ).
> - `specs/STATE_MODEL.md` (A4 output).
> - `specs/STATE_MACHINE.md` (A5 output).
> - `PLAN.md` §Deliverable 3 / §Deliverable 4.
>
> **Hard rules followed**:
> - Adversarial. Find problems; do not propose fixes (except in §6 candidate D-NN items).
> - Cite every issue against rulebook §, decision ID, FAQ point.
> - No edits to other specs.
> - Defer judgment on intent — the owner adjudicates.
>
> **Conventions for this doc**:
> - Issues are tagged `[FINDING-NN]` and given a severity label (`S0`, `S1`, `S2`, `S3`).
> - Severity definitions: `S0` blocks implementation; `S1` must be fixed in the spec before code; `S2` should be fixed before code (or accepted explicitly); `S3` is cosmetic / clarity.
> - Adversarial scenarios are tagged `[CASE-NN]`. Coverage is checked: `OK` (covered) / `MISSING` (gap) / `AMBIGUOUS` (covered but unclear).
> - Illegal-action tests are tagged `[ILL-NN]`. Each cites the §6 precondition it violates.
> - Candidate decisions are proposed as `[D-NN-CANDIDATE]` for owner adjudication.

---

## 1. Internal Consistency Check (rulebook ↔ STATE_MODEL ↔ STATE_MACHINE)

### 1.1 Findings

**[FINDING-01] (S2)** — `dice_state` keying inconsistency: rulebook §3.1 + §7.2 step A use color keys; STATE_MODEL §2.5 + STATE_MACHINE §8.4 use intel type names. Bijective per [D-19] but vocabulary drift could trigger an implementer's `dice_state[tile.color]` lookup miss.

**[FINDING-02] (S1)** — `actions_remaining` cap not enforced anywhere.
- §5.3 says "max 4," STATE_MODEL §2.5 declares `0..4`, but §6.7 effect 3 just does `+= 1` with no cap check. Cleanup §8.6 step 3 doesn't reset `actions_remaining`.
- Result: a stale value combined with a boost can yield 5+. Schema invariant unenforced.
- **Cite**: §5.3, §6.7 effect 3, STATE_MODEL §2.5, STATE_MACHINE §8.6 step 3, §11.4.

**[FINDING-03] (S1)** — `player.agents_remaining` denormalization can drift.
- STATE_MODEL §2.1 stores it; §6 derived table doesn't list it. Spawn (§6.1) and Honeypot removal (§9.4) don't specify whether to update it. Source of truth ambiguous.
- **Cite**: STATE_MODEL §1.1, §2.1, §6.

**[FINDING-04] (S1)** — `pinned_until` setter formula not codified.
- §6.11.A effect 1 says "Set `pinned_until = T*` where `T*` is the turn_id at which the pin clears: end of pinned player's next turn cleanup." The actual formula is left as prose.
- Naive implementer might compute `current_turn_id + 1`, which happens to work because Hacker can't pin own agents, but the spec must state the formula explicitly.
- STATE_MODEL §2.2 + §9.11 use the cleanup-side query but never give the SETTER formula. STATE_MACHINE §9 `agentPinned` payload includes `pinned_until_turn` but no derivation. **Cite**: §6.11.A.

**[FINDING-05] (S2)** — Blockade may be placed on opponent's ✦ spawn row.
- §6.6.A and §6.6.B do not exclude ✦ hexes. A player can blockade an opponent's spawn slots → denial-of-spawn strategy.
- **Cite**: §6.6.A, §6.6.B, STATE_MODEL §9.5. See [D-22-CANDIDATE].

**[FINDING-06] (S1)** — Comms target adjacency to source intel: spec implies no adjacency required, but never states it.
- §6.9.A inputs allow any loose intel as target. The Comms agent need not be adjacent to either source or destination of the moved tile.
- An implementer could wrongly add an adjacency precondition by analogy with `act_move_agent`. Spec must explicitly state "no adjacency required between Comms agent and target tile."
- **Cite**: §6.9.A inputs, §3.5; STATE_MODEL §9.8.

**[FINDING-07] (S1)** — Comms-down (§6.9.B) inherits §6.9.A preconditions only by "as §6.9.A" reference; no-agent-on-target is not restated.
- Implementer reading §6.9.B literally might omit `no agent on target` validation.
- Also: [D-09] reads "target hex contains ≥1 intel **and** no agent" but actually means SOURCE hex (the loose intel's hex). The decision conflates source and target.
- **Cite**: [D-09], §6.9.A, §6.9.B, STATE_MODEL §9.8.

**[FINDING-08] (S2)** — `intel_held` ordering: rulebook §2.3 says ordered list; STATE_MODEL §6 recovers via `ORDER BY id` which is NOT insertion order under transfers (lower-id transferred-in tile sorts before higher-id native). No rule depends on order, but spec should clarify.
- **Cite**: §2.3; STATE_MODEL §6.

**[FINDING-09] (S1)** — `actions_remaining` reset on first entry to `actions` is not invariant-safe.
- STATE_MACHINE §11.4 uses `globals.actions_remaining == 0` to distinguish first entry from self-loop.
- Failure mode: player passes actions voluntarily at `actions_remaining = 1` (declined to fire boosted 4th). End-of-turn cleanup (§8.6 step 3) does NOT reset `actions_remaining` to 0. Next turn first entry: discriminator sees `1 ≠ 0` → no reset → player gets only 1 action.
- **Cite**: STATE_MACHINE §8.6 step 3, §11.4; rulebook §5.3.

**[FINDING-10] (S1)** — Mid-action over-capacity vs Honeypot ordering not asserted at every trigger site.
- §9.3 EDGE O-01: trickle = Honeypot-first then over-capacity. For action-phase: "Honeypots cannot be held → only capacity dump runs."
- BUT §6.4, §6.10, §6.11.C effects merely assert "Honeypots cannot be held — invariant" without saying what to do at runtime if violated.
- Spec should require: every action-phase trigger that mutates `intel_held` runs Honeypot check first, dump second (defensive). Currently order is unspecified.
- **Cite**: §9.3 EDGE O-01, §6.4 effect 3, §6.10 effect 3, §6.11.C effect 4.

**[FINDING-11] (S2)** — Trickle: when multiple tiles arrive at one agent hex with a Honeypot among them, fate of non-Honeypot arrivals is ambiguous.
- §9.4 step 3: "Return all of `agent.intel_held` to the bag, including the Honeypot itself." The non-Honeypot arrivals were never picked up (agent removed before pickup) — they should remain loose on the now-empty hex.
- STATE_MACHINE §8.5 Step D says "skip pickup/capacity check," supporting the loose-on-hex reading.
- Spec should make this explicit. **Cite**: §7.2 step E, §9.4, STATE_MACHINE §8.5 Step D. See [D-23-CANDIDATE].

**[FINDING-12] (S2)** — `pinned_until=0` would clear immediately under cleanup query (`<= turn_id` and turn_id ≥ 1). Minor sentinel risk; spec should disallow 0 as setter value.

**[FINDING-13] (S1)** — Analyst keep/return decision is collected BEFORE the tile is drawn — UX bug.
- §6.12 sequence: draw tile → see type → player chooses keep/return. Player MUST see the tile before deciding.
- STATE_MACHINE §3.2 puts `analyst_keep_decision` in the `actRetireAgent` payload, sent BEFORE the server fires the bonus draw. Client cannot make an informed choice.
- Two correct flows: (a) sub-state that reveals the tile and prompts the player; (b) blind pre-commit (deprives player of informed decision).
- **Cite**: §6.12 effects 1–4; STATE_MACHINE §3.1, §3.2. See [D-26-CANDIDATE].

**[FINDING-14] (S2)** — `state=returned_to_bag` (4) "may be folded back to state=in_bag (0)" per STATE_MODEL §2.3, but STATE_MACHINE never specifies the trigger. Tiles stay at state=4 indefinitely; bag-size query masks but the per-tile state is muddled.
- **Cite**: STATE_MODEL §2.3, §1.1; STATE_MACHINE §8.6.

**[FINDING-15] (S3)** — Comms move legal-target generation in STATE_MACHINE §7.2 ignores blockade-pair vertical block.
- The `actCommsMoveIntelUp.legal_targets` description: "loose intel only; target empty of agent and blockade."
- Missing: §9.6.D rule "if both `pair_above(H)` are blockaded, intel on H cannot be Comms-moved up." For per-direction, the up-move is legal as long as the target itself is open. The blockade-pair rule only forbids movement when BOTH adjacent diagonals in the move direction are blocked. Since the player chooses ONE diagonal, having only one diagonal blocked is fine. Two-blocked = no move.
- But UI legality check: if the player chose intel I and only one of {NW(I.hex), NE(I.hex)} is blockaded, the other is still legal. Comms-up `legal_targets` should enumerate the unblocked one. Per-action check fine.
- §9.6.D edge case: what if `NW(I.hex)` is off-board AND `NE(I.hex)` is blockaded? Effectively single-direction, blocked. Default per [B-02 / D-derived]: cannot move up. STATE_MACHINE silent. Cosmetic but worth flagging.

**[FINDING-16] (S3)** — Notification `agentRemoved` is fired in `trickleResolve` (Honeypot kill) AND in `actions` (Honeypot move-onto), but the payload `reason` is implementation-only.
- STATE_MACHINE §9 row `agentRemoved`: payload `{agent_id, hex, reason}`. The two reasons are "honeypot_trickle" and "honeypot_move." But what about "depletion forfeiture"? When a player retires their last agent, that's an `agentRetired` not `agentRemoved`. OK. What about over-capacity dump that empties the agent? Agent stays on board. OK. The reasons are bounded; spec should enumerate them. Minor.

**[FINDING-17] (S2)** — `getAllDatas()` shape (STATE_MODEL §5) does not expose `pin_clears_on_turn` semantics correctly to opponents.
- The shape `pinned_until: number | null` is exposed publicly (per public/private matrix §4.2). Good.
- BUT a player calculating "when will my Hacker's pin expire on opponent X" needs to know `pinned_until` interpretation. The schema exposes the integer, but the meaning ("end of pinned player's next turn") is opaque without context. UI must format. Cosmetic-ish, but error-prone.

**[FINDING-18] (S1)** — Twin of F-09: `actions_remaining == 0` mid-actions also triggers reset.
- Player fires 3 actions, hits 0; self-loop entry runs `Actions::onEnteringState()`; discriminator sees 0 → resets to 3 → unlimited actions.
- **Cite**: STATE_MACHINE §8.6 step 3, §11.4.

**[FINDING-19] (S2)** — `analystBonusDrawn` is `notify->all`. On `return`, the tile goes back to the bag — but the opponent already saw its type. This leaks bag composition unlike `intelDrawn` (where revealed-and-returned never happens). Spec doesn't assert intent.
- **Cite**: §6.12 effects 2–4; STATE_MACHINE §3.1, §9 `analystBonusDrawn`. See [D-20-CANDIDATE].

### 1.2 Missing fields / queries the schema cannot answer

- **[FINDING-20] (S1)** — STATE_MODEL §6 derived table doesn't list `is_owned_by_active_player(agent)`. Used in nearly every action.
- **[FINDING-21] (S1)** — `bag_size` not specified as legality-check input for [D-18] skip-on-empty (trickle, Analyst bonus). Implicit.
- **[FINDING-22] (S2)** — No canonical "active player" PHP/SQL accessor pattern documented.

---

## 2. Rule Contradictions (rulebook ↔ FAQ ↔ DECISIONS)

**[FINDING-23] (S1)** — FAQ "all Honeypots first across all agents, then all dumps" vs §7.2 per-agent iteration.
- FAQ point 3 implies a global two-pass ordering. Rulebook §7.2 step E/F lumps both into a per-agent loop. Iteration order is unspecified but should not matter (effects on different agents are independent).
- **Cite**: FAQ Movement point 3; rulebook §9.3 EDGE O-01; rulebook §7.2 step E.

**[FINDING-24] (S1)** — STATE_MACHINE §8.5 Step B treats "redirect target is off-board" as `no_move`, but rulebook §9.2 says off-board trickling returns to bag.
- §8.5 conflates "blockaded redirect" with off-board handling. Per rulebook §7.2 step D (off-board return) fires AFTER step B (redirect); a redirect to off-board should still be applied then returned in step D.
- **Cite**: rulebook §9.2, §7.2 steps B, D; STATE_MACHINE §8.5 Steps B, C.

**[FINDING-25] (S1)** — Off-board redirect after blockade: precedence undefined.
- Scenario: T's SE is blockaded, SW is off-board. Two interpretations:
  - Per §9.2 + redirect: redirect to SW, then off-board returns to bag.
  - Per FAQ Agents 7 / B-02 default ("blockade pair OR on inlet → no trickle"): both diagonals "blocked" (one by blockade, one by inlet) → tile stays.
- **Cite**: §13 B-02; STATE_MACHINE §8.5; §7.2 steps B, D. See [D-24-CANDIDATE].

**[FINDING-26] (S2)** — §6.6.B Engineer remote: no adjacency required. Confirmed via "anywhere" name + preconditions. **OK.**

**[FINDING-27] (S1)** — §6.4 transfer-to-self prevention is implicit only (input requires distinct ids; §9.12 lists as derived). Spec should make it an explicit precondition.

**[FINDING-28] (S2)** — §6.10 Double Agent target corner cases (pinned target, Smuggler-mid-action, self-target) — all verified consistent with §9.5, §7.5, §6.10 precondition. **OK.**

**[FINDING-29] (S2)** — §6.5 effect 2 doesn't say Analyst-bonus tile bypasses §9.3 capacity check. Implicit (agent being removed; bonus tile never entered `intel_held`). Cosmetic.

---

## 3. Adversarial Edge Cases

**[CASE-01] (OK)** — All 6 dice show same direction (all SW).
- Outcome per §7.2: every loose tile of every color tries SW. Per-tile redirect on blockade fires independently. East-edge tiles see SW into Field; west-edge tiles may see SW off-board → return to bag.
- **Spec covers**: §7.2 + §9.2.

**[CASE-02] (MISSING-01)** — Trickle delivers two Honeypots (gray) to same hex with one agent.
- Outcome: agent picks up first Honeypot → §9.4 fires → agent removed; both Honeypots return to bag (one was about to be picked up; the other arrives at now-empty hex as loose). Or both go to bag? Per §9.4 step 3: "Return all of `agent.intel_held` to the bag, including the Honeypot itself." That's the first Honeypot. The second Honeypot was never picked up → loose on the (now-empty) hex.
- Spec is silent on whether the second Honeypot is also returned. Plain reading: it stays loose.
- **MISSING-01**: confirm with owner.

**[CASE-03] (MISSING-02)** — Player has 4 Smugglers; only 1 boost per turn per [D-08]. Can a Smuggler boost AND swap on the same turn (both abilities of same agent)?
- Per [D-08]: boost is per-player per-turn. Per §6.7 + §6.8: boost and swap are different actions on the same agent.
- Spec implies: yes, same Smuggler can do both. No conflict.
- **MISSING-02**: spec doesn't confirm explicitly.

**[CASE-04] (MISSING-03 — partial)** — Player retires their LAST agent for 20+ points and the retire empties the pool simultaneously.
- Per §6.5 step 7 (win check) before step 8 (depletion check). If score ≥ 20 → WIN immediately. If score < 20 → LOSE per depletion.
- Confirmed in §8.3 + §6.5. But: does the Analyst bonus fit into this ordering? §6.5 step 2 invokes §6.12 BEFORE step 7 (win check). So if Analyst's last-agent retire scores 18 from held + 4 from kept bonus = 22 → win.
- What about: Analyst with 3 intel = 6 base score, retires for the bonus, draws Honeypot (worthless), keeps it → no score added. Now total 6, but pool/board both empty → LOSS.
- **Verify**: §6.5 step 7 fires AFTER step 2 (Analyst bonus). OK, this is correct: bonus completes, then win check.
- Edge: a 4-step ordering: 1=score held intel, 2=Analyst draw (if applicable) = +0..+4, 3=clear held, 4=remove agent, 5=update board, 6=permanent removal, 7=win check, 8=depletion check. **Spec correct**.

**[CASE-05] (MISSING-04)** — Bag has 1 tile; trickle draw left = 1 tile; trickle draw right = 0 tiles (skipped per [D-18]). Then `trickle_roll` rolls 6 dice. The 1 drawn tile starts trickling. What about dice colors with no corresponding loose intel?
- §7.2 step A: iterate `loose_intel_on_board`. Each tile uses its color's die direction. Dice with no matching tile are simply unused. **Confirmed legal**.

**[CASE-06] (OK)** — A Hacker pins an agent. The pinned agent's owner uses their OWN Hacker to unpin? Or only their OWN Hackers?
- §6.11.B precondition: `target_agent.owner == active_player`. Active player = pinned agent's owner. So yes, friendly Hacker unpins.
- Two Hackers same player: either may unpin (each subject to own per-turn flag).
- **Confirmed**.

**[CASE-07] (MISSING-05)** — Comms moves a Honeypot tile (loose, on board). Where does it end up?
- §6.9.A allows moving any loose intel up. Honeypot is intel. Move Honeypot UP — goes to target hex. If target hex has agent: per §6.9 [D-09] precondition `no agent on target_hex` → illegal.
- So Comms cannot move loose Honeypot onto an agent hex. Confirmed.
- But: Comms moves Honeypot to empty hex. Honeypot now loose on that hex. Next turn it trickles per gray die. Standard.
- Comms moving Honeypot DOWN (§6.9.B) costs 1 intel. Same story. **Confirmed**.

**[CASE-08] (OK)** — Smuggler swaps own agent with an enemy agent who is on a Honeypot hex.
- Honeypot hex with agent triggers §9.4 immediately. So no agent can be ON a Honeypot hex (except in the brief atomic moment of trickle resolution before §9.4 fires). After resolution, the Honeypot is gone (returned to bag).
- Therefore: swap with agent-on-Honeypot-hex is impossible mid-action. **Confirmed (chain holds)**.

**[CASE-09] (OK)** — Engineer places blockade adjacent to own intel-stack. Does the intel-stack on Engineer's adjacent hex still trickle, or stop?
- §9.6.B: "blockade prevents intel from trickling … OR moving in." But this is about intel ON the blockaded hex.
- The Engineer-adjacent placement is on an OFFSET hex; the Engineer's hex with intel is not blockaded.
- §6.6.A precondition: target hex has no agent and no blockade. The Engineer's hex (where Engineer stands) has the agent (Engineer). So the blockade goes on ANOTHER adjacent hex, not the Engineer's hex.
- The Engineer's intel: held by Engineer (on agent), doesn't trickle anyway (§7.2 step A: only loose tiles). **Confirmed legal**.

**[CASE-10] (OK with caveat)** — Player has 1 agent left in pool, 0 on board, takes a turn.
- Per [D-10a]: spawn up to 3 (subject to pool). Spawn 1.
- Take actions, retire that agent.
- Per §6.5 step 7 (win check): score ≥ 20? If yes → WIN. If no → step 8: depletion check fires; pool=0, board=0 → LOSE.
- **Confirmed via §6.5 ordering. Spec correct.**

### 3.1 Additional adversarial scenarios (compact)

| # | Scenario | Status |
|---|---|---|
| CASE-11 | Hacker steal target same-hex as Hacker | n/a (one-agent-per-hex invariant) |
| CASE-12 | Hacker steals while target's owner about to retire | OK (active-player only acts) |
| CASE-13 | Swap during partial move | n/a (atomic actions §7.5) |
| CASE-14 | Spawn on hex with loose Honeypot | n/a (§6.1 forbids intel on spawn hex) |
| CASE-15 | Trickle deposits intel on ✦ row | OK; spawn cap drops naturally |
| CASE-16 | Mixed-color stack splits on trickle | OK (§7.3 + FAQ point 1) |
| CASE-17 | Trickle pushes hand to 4+ | OK; §9.3 dumps all |
| CASE-18 | Engineer remote pays own last intel | OK |
| CASE-19 | Engineer remote pays Honeypot | n/a (Honeypot not holdable §9.4) |
| CASE-20 | Smuggler boost pays Honeypot | n/a (same) |
| CASE-21 | Voluntary skip of guaranteed-win retire | OK |
| CASE-22 | Active-player depletion mid-game | OK (§7.4 + §8.3) |
| CASE-23 | Analyst bonus draws Honeypot, "keep" | OK (Honeypot scored at +0; STATE_MODEL §1.1 invariant only blocks `on_agent`) |
| CASE-24 | Trickle pushes hand to 4+ on hex with intel | OK; dump fires |
| CASE-25 | Player passes spawn at depletion | OK; voluntary loss per §7.4/[D-17] |
| CASE-26 | All 12 agents removed | OK; depletion |
| CASE-27 | Both at 19; active retires +1 | OK; active wins via §8.1 + [D-03] |
| CASE-28 | actions_remaining=0 with no legal actions | OK; auto-pass §6.13 |
| CASE-29 | Remote blockade on opponent spawn row | LEGAL but flagged [D-22-CANDIDATE] |
| CASE-30 | Trickle onto pinned agent | OK (pinned still picks up) |
| CASE-31 | Swap places spawn-this-turn agent on ✦ | OK; spawn-lock still holds |
| CASE-32 | Same Hacker pins twice same turn | n/a (per-Hacker flag blocks) |
| CASE-33 | Boosted 4th action plus free Retire | OK; Retire free per §6.5 |
| CASE-34 | Swap onto hex with loose intel — pickup? | **MISSING-29 [D-21-CANDIDATE]** |
| CASE-35 | Swap both ends had loose intel — both pickup? | **MISSING-30** (ties to D-21) |
| CASE-36 | Comms-up move off-top edge | **MISSING-31 [D-25-CANDIDATE]** |

---

## 4. Illegal-Action Test Catalog

For every server-validated rule. Compact table form: action / illegal input / cite / expected server error code.

| # | Action | Illegal input | Cite | Expected error |
|---|---|---|---|---|
| ILL-01 | act_move_agent | non-adjacent target | §6.3 | not_adjacent |
| ILL-02 | act_move_agent | pinned agent | §6.3 + §9.5 | agent_pinned |
| ILL-03 | act_move_agent | off-Field target | §6.3 + STATE_MODEL §3.5 | not_in_field |
| ILL-04 | act_move_agent | blockaded target | §6.3 + §9.6.A | target_blockaded |
| ILL-05 | act_move_agent | target has another agent | §6.3 | target_occupied_by_agent |
| ILL-06 | act_spawn_agent | hex has intel | §6.1 + §9.8 | spawn_hex_has_intel |
| ILL-07 | act_spawn_agent | already 3 on board | §6.1 + [D-10a] | spawn_cap_reached |
| ILL-08 | act_spawn_agent | agent already on board | §6.1 | agent_not_in_pool |
| ILL-09 | act_spawn_agent | empty pool | §6.1 | pool_empty |
| ILL-10 | act_retire_agent | not on ✦ hex | §6.5 + §9.9 | not_on_spawn_row |
| ILL-11 | act_retire_agent | pinned | §6.5 + §9.5 | agent_pinned |
| ILL-12 | act_retire_agent | spawned this turn | §6.5 | same_turn_spawn |
| ILL-13 | act_engineer_place_blockade_adjacent | target occupied | §6.6.A | target_occupied |
| ILL-14 | act_engineer_place_blockade_* | exceeding 3-cap | §6.6 + [D-04] | blockade_cap_reached |
| ILL-15 | act_smuggler_boost_actions | already used | §6.7 + [D-08] | boost_already_used |
| ILL-16 | act_smuggler_swap_agents | pinned agent | §6.8 + FAQ Agents 4 | agent_pinned |
| ILL-17 | act_smuggler_swap_agents | both agents same | §6.8 + §9.12 | agents_same |
| ILL-18 | act_comms_move_intel_* | target intel held by agent | §6.9 + [D-09] | intel_held_not_loose |
| ILL-19 | act_comms_move_intel_* | target hex blockaded | §6.9 + §9.6.A + FAQ 6 | target_blockaded |
| ILL-20 | act_comms_move_intel_down | paying with moving intel | §6.9.B + §9.12 | paid_intel_is_target |
| ILL-21 | act_comms_move_intel_* | target off-board | §6.9 + C-02 default | target_off_field |
| ILL-22 | act_hacker_pin | own agent | §6.11.A | target_friendly |
| ILL-23 | act_hacker_pin | already pinned | §6.11.A + [D-06b] | target_already_pinned |
| ILL-24 | act_hacker_unpin | not pinned | §6.11.B | target_not_pinned |
| ILL-25 | act_hacker_steal_intel | target not pinned | §6.11.C | target_not_pinned |
| ILL-26 | act_hacker_pin | per-Hacker pin slot used | §6.11.A + [D-15] | hacker_pin_slot_used |
| ILL-27 | act_hacker_steal_intel | per-Hacker steal slot used | §6.11.C + [D-15] | hacker_steal_used |
| ILL-28 | act_hacker_pin | after unpin same turn (slot shared) | [D-15] | hacker_pin_slot_used |
| ILL-29 | act_transfer_intel | non-adjacent | §6.4 | not_adjacent |
| ILL-30 | act_transfer_intel | source doesn't have intel | §6.4 | intel_not_held |
| ILL-31 | act_transfer_intel | target is opponent | §6.4 | target_not_friendly |
| ILL-32 | act_double_agent_transfer | target not on board | §6.10 | target_agent_not_on_board |
| ILL-33 | act_retire_agent | opponent's agent | §6.5 | agent_not_friendly |
| ILL-34 | any act_* | wrong phase | §3 mapping | phase_mismatch |
| ILL-35 | any act_* | not your turn | §5.4 | not_your_turn |
| ILL-36 | action-cost act_* | actions_remaining < cost | §6 | insufficient_actions |
| ILL-37 | intel-cost act_* | intel not held by actor | §6.6.B/§6.7/§6.8/§6.9.B/§6.11.C | intel_not_held_by_actor |
| ILL-38 | act_engineer_place_blockade_* | hex has another blockade | §6.6 | target_blockaded |
| ILL-39 | act_smuggler_swap_agents | self-swap with self | §6.8 + S-01 default | agents_same |
| ILL-40 | any act_* | malformed payload | framework | bad_request |

---

## 5. Hidden-Info Leak Audit

| ID | Sev | Finding |
|---|---|---|
| F-30 | S0 | Bag identities never shipped (STATE_MODEL §4.6 + STATE_MACHINE §12.4). **OK.** |
| F-31 | S0 | In-bag tile types never shipped (STATE_MODEL §4.3 conditional public). **OK.** |
| F-32 | S1 | Trickle draws public (intentional reveal per §10.2). **OK.** |
| F-33 | S1 | Analyst bonus draw privacy — see F-19 / [D-20-CANDIDATE]. On `return`, opponent has seen tile type → bag-composition leak. |
| F-34 | S1 | `pinned_until_turn` exposed publicly (necessary for opp prediction). **OK.** |
| F-35 | S2 | `agent.hacker_*_used_this_turn` exposed (necessary). **OK.** |
| F-36 | S2 | `actions_remaining` exposed (standard). **OK.** |
| F-37 | S2 | `bag_size` exposed (count only). **OK.** |
| F-38 | S2 | RNG seeds (BGA-managed). **OK.** |
| F-39 | S1 | `intelDrawn` reveals tile type at draw time (compliant §10.2). Batched `trickleResolved` per §8.5 — leak-free. **OK.** |

---

## 6. Candidate D-NN Decisions Surfaced (D-20+)

**[D-20-CANDIDATE]** — Analyst bonus draw privacy.
- **Question**: when the Analyst bonus draws a tile, must the tile's type be revealed publicly to both players, or only to the active player (with the result of `keep`/`return` revealed publicly after)?
- **Interpretations**:
  - (a) **Public always** (current spec). Both players see the drawn tile and the keep/return decision.
  - (b) **Private until commit**. Only the active player sees the tile; on `keep`, the tile is publicly scored (so its type is then revealed); on `return`, the tile goes back face-down (the type was never publicly revealed).
- Default per STATE_MACHINE §3.1 + §12.4: (a). Suggested for owner adjudication.

**[D-21-CANDIDATE]** — Smuggler swap intel pickup.
- **Question**: when a Smuggler swap moves an agent onto a hex with loose intel, does the agent pick up the loose intel?
- **Interpretations**:
  - (a) **No pickup** (rulebook §6.8 effect 3 says intel travels with each agent; silence on pickup means no pickup).
  - (b) **Pickup** (consistent with §6.3 move semantics — any time an agent's hex changes to one with loose intel, pickup fires).
- See FINDING-CASE-34, CASE-35.

**[D-22-CANDIDATE]** — Engineer blockade on opponent's spawn row.
- **Question**: may a player place a blockade (adjacent or remote) on an opponent's `✦` spawn row hex?
- **Interpretations**:
  - (a) **Yes** — strategic denial of spawn (current spec behavior, not prohibited).
  - (b) **No** — implicit "no blockades on spawn-row hexes" rule.
- See FINDING-05 + CASE-29.

**[D-23-CANDIDATE]** — Two-Honeypot trickle to single agent.
- **Question**: when two Honeypots arrive at the same agent's hex via trickle, are BOTH returned to bag? Or only the one that triggers §9.4?
- **Interpretations**:
  - (a) **Both returned** (§9.4 step 3 covers all "arrivals" to that hex).
  - (b) **Only the triggering Honeypot returned**; the second is left loose on the now-empty hex.
- See CASE-02. The §7.2 algorithm step E says "for each agent receiving arrivals: if any arrival is a Honeypot, run §9.4." It does not specify what happens to other arrivals.

**[D-24-CANDIDATE]** — Trickle off-board redirect priority.
- **Question**: when a tile's intended trickle direction is blocked by a blockade and the redirect target is off the Field, does the tile (1) return to bag (per §9.2) or (2) stay on its current hex (per §13 B-02 default for blocked-on-inlet)?
- See FINDING-25.

**[D-25-CANDIDATE]** — Comms move-up off-top edge.
- **Question**: a Comms-Specialist tries to move loose intel from a top-edge hex upward; the target is off the Field. Legal? (Symmetric to C-02 for comms-down.)
- **Interpretations**:
  - (a) **Illegal** (default by symmetry with C-02). Comms move target must be in Field.
  - (b) **Legal — tile returns to bag** (consistent with §9.2 trickle-off semantics applied to Comms moves).
- See CASE-36.

**[D-26-CANDIDATE]** — `analystBonusDecision` revealed-then-decided UX.
- **Question**: how is the Analyst keep/return decision collected when the player must see the drawn tile first?
- **Interpretations**:
  - (a) **Two-step BGA state**: introduce a sub-state `analystBonusDecision` between trigger and decision; player decides after seeing tile.
  - (b) **Blind pre-commit**: client commits keep/return before draw (current STATE_MACHINE §3.2 spec; bad UX).
- See FINDING-13.

---

## 7. Plan-Level Discrepancies

### 7.1 Deliverable progress audit

PLAN.md §Deliverable 4 lists Phase 0–5. Current artifacts:
- `specs/BGA_PRIMER.md`, `specs/BGA_CHECKLIST.md`, `specs/BGA_PATTERNS.md` — A1 outputs (Phase 0). **EXISTS**.
- `rulebook.md` — rules formalization (was supposed to be `specs/RULES.md`). **EXISTS but RENAMED**.
- `DECISIONS.md` — owner decisions. **EXISTS**.
- `assets/MANIFEST.md` (A3 output) — referenced by PLAN but not in `/Users/dcepeda/Documents/hexpionage/` per directory listing. Only `assets/` directory is listed, no MANIFEST file visible. **MISSING-33**: A3 deliverable not visible.
- `specs/STATE_MODEL.md` (A4 output) — Phase 1. **EXISTS**.
- `specs/STATE_MACHINE.md` (A5 output) — Phase 1. **EXISTS**.
- `specs/UI_SPEC.md` (A6 output) — Phase 1. **NOT VISIBLE**.
- `tests/SCENARIOS.md` (A10 output) — Phase 1. **NOT VISIBLE**.
- `specs/CONTRACT.md` (A11 output) — Phase 1. **NOT VISIBLE**.
- `specs/QA_SPEC_REVIEW.md` (this doc, A9 output) — Phase 2. **IN PROGRESS**.

**[FINDING-40] (S2)** — A6 (UI_SPEC), A10 (SCENARIOS), A11 (CONTRACT) outputs not visible. PLAN states A4/A5 must complete before A11 contract drafts. A4/A5 are done. A11 contract should now be drafting in parallel with A9. May be on a different branch / pending.

**[FINDING-41] (S3)** — `rulebook.md` is the artifact, but PLAN.md repeatedly refers to `specs/RULES.md`. Naming drift; harmless but confusing.

### 7.2 Plan vs. rulebook contradictions

**[FINDING-42] (S2)** — PLAN A2 description says: "every rule is given a unique RULE-ID." `rulebook.md` does NOT use RULE-IDs; it uses §-numbers. Spec downstream consumers cannot cite "RULE-XX" because none exist. Implementer must invent a mapping or use §-numbers. Not blocking but undermines PLAN.

**[FINDING-43] (S3)** — PLAN A4 description: "Map every rule to a state field" — STATE_MODEL §10 has the cross-walk. Confirms compliance.

**[FINDING-44] (S3)** — PLAN A5 description: "Every legal action in `specs/RULES.md` maps to exactly one `(state, action)` pair." STATE_MACHINE §12.1 confirms 16 actions, all mapped. **OK.**

**[FINDING-45] (S2)** — PLAN A9 (this agent) is told "Phase 2 (pre-impl): produce QA_SPEC_REVIEW.md." Phase 4 will produce QA_REPORT.md (post-impl). This doc is on track.

---

## 8. Severity-Tagged Issue List

Severity definitions repeated for ease of reference: **S0** blocks impl; **S1** must fix in spec; **S2** should fix; **S3** cosmetic.

| ID | Sev | Title |
|---|---|---|
| F-01 | S2 | dice_state keying inconsistency (color vs type-name) |
| F-02 | S1 | actions_remaining cap-violation possible without enforcement |
| F-03 | S1 | agents_remaining denormalized field with no mutation contract |
| F-04 | S1 | pinned_until setter formula not codified |
| F-05 | S2 | Engineer remote blockade on spawn row not prohibited |
| F-06 | S1 | Comms move target-occupancy preconditions ambiguous |
| F-07 | S1 | §6.9.B inherits §6.9.A preconditions only implicitly |
| F-08 | S2 | intel_held ordering: ID-ordered ≠ insertion-ordered |
| F-09 | S1 | actions_remaining first-entry reset uses ambiguous discriminator |
| F-10 | S1 | Honeypot vs over-capacity ordering at action-phase triggers |
| F-11 | S2 | Two-tile-redirect into same agent hex: non-Honeypot fate unclear |
| F-12 | S2 | pinned_until=0 sentinel collision risk |
| F-13 | S1 | Analyst keep/return decision collected before draw — UX bug |
| F-14 | S2 | "returned_to_bag" → "in_bag" fold-back trigger undefined |
| F-15 | S3 | Comms legal_targets miss blockade-pair edge |
| F-16 | S3 | agentRemoved.reason enumeration not documented |
| F-17 | S2 | pinned_until_turn meaning opaque to client |
| F-18 | S1 | actions_remaining discriminator failure (twin of F-09) |
| F-19 | S2 | analystBonusDrawn reveals tile type even on "return" |
| F-20 | S1 | No "is_owned_by_active_player" derived helper documented |
| F-21 | S1 | bag_size used for legality but not specified for it |
| F-22 | S2 | No canonical "active player" accessor pattern |
| F-23 | S1 | FAQ ordering vs §7.2 per-agent iteration semantics |
| F-24 | S1 | "or on an inlet" interpretation conflicts with off-board redirect |
| F-25 | S1 | Off-board redirect after blockade: precedence undefined |
| F-26 | S2 | Engineer remote: no adjacency, confirmed |
| F-27 | S1 | Transfer to-self prevention: derived only |
| F-28 | S2 | Double Agent transfer covers corner cases (verified) |
| F-29 | S2 | Analyst bonus capacity bypass not asserted |
| F-30 | S0 | Bag content not shipped (verified safe) |
| F-31 | S0 | In-bag tile type not shipped (verified safe) |
| F-32 | S1 | Trickle draws public (intentional, OK) |
| F-33 | S1 | Analyst bonus reveal on return = leak |
| F-34 | S1 | pinned_until exposure (intended) |
| F-35 | S2 | hacker_*_used_this_turn exposure (intended) |
| F-36 | S2 | actions_remaining exposure (intended) |
| F-37 | S2 | bag_size exposure (OK) |
| F-38 | S2 | RNG seeds (BGA-managed) |
| F-39 | S1 | intelDrawn timing acceptable |
| F-40 | S2 | UI_SPEC, SCENARIOS, CONTRACT not visible |
| F-41 | S3 | rulebook.md vs specs/RULES.md naming |
| F-42 | S2 | RULE-ID naming inconsistent with §-number |
| F-43 | S3 | A4 cross-walk OK |
| F-44 | S3 | A5 mapping OK |
| F-45 | S3 | A9 in scope |

**Issue count by severity**:
- S0: 2 (FINDING-30, FINDING-31 — verified safe; counts as items reviewed)
- S1: 16 (real spec gaps to resolve before implementation)
- S2: 19 (clarifications; should resolve)
- S3: 8 (cosmetic / clarity)

(Adversarial scenarios MISSING-01..MISSING-32 are tracked separately under §3 and §6.)

---

## 9. Recommended Next Actions

### 9.1 For owner (adjudication)

1. **Adjudicate D-20** (Analyst bonus privacy). Recommended: (a) public always — keeps spec consistent with `intelDrawn`. But (b) is more strategic.
2. **Adjudicate D-21** (Smuggler swap pickup). Affects implementation directly.
3. **Adjudicate D-22** (blockade on opponent spawn row). Strategic balance question.
4. **Adjudicate D-23** (two-Honeypot trickle). Edge case, minor.
5. **Adjudicate D-24** (off-board redirect priority). Affects trickle algorithm correctness.
6. **Adjudicate D-25** (Comms move-up off-top). Symmetric to C-02 already locked.
7. **Adjudicate D-26** (Analyst decision UX). Affects state-machine design — A5 must split or accept blind pre-commit.
8. **Resolve FINDING-13** (Analyst keep/return UX). Tied to D-26.

### 9.2 For implementation agents (A7 backend, A8 frontend)

- **Be aware of FINDING-02, FINDING-09, FINDING-18**: `actions_remaining` reset / cap enforcement. Add explicit enforcement at each mutation site; add invariant assertion at end of each action.
- **Be aware of FINDING-03**: `agents_remaining` denormalized. Document the source of truth (recommend deriving on read, not storing).
- **Be aware of FINDING-04**: `pinned_until` setter formula. Implement `pin_clear_turn = compute_next_turn_of(target.owner)`.
- **Be aware of FINDING-10**: At every action-phase trigger that mutates `intel_held`, fire Honeypot check first, capacity dump second.
- **Be aware of FINDING-11**: Two arrivals on agent hex with one Honeypot — confirm non-Honeypot's fate per D-23.
- **Be aware of FINDING-13/D-26**: Analyst bonus must be a sub-state OR client UX must accept blind pre-commit.
- **Be aware of FINDING-19/D-20**: Analyst bonus visibility on `return`.
- **Be aware of FINDING-23/24/25**: Trickle resolution algorithm steps may need re-spec.
- **Use [ILL-01..ILL-40]** as the illegal-action test catalog input for unit tests.

### 9.3 For self / Phase 4 retest

- All MISSING-NN edge cases revisit in Phase 4 once test scenarios exist.
- Confirm fixes for S0/S1 issues by re-reading rulebook + specs.
- Test against a live BGA Studio environment that all illegal actions return server errors with the expected codes.
- Verify hidden-info: monitor `/network/` payloads for any in-bag tile reveals.
- Run all 30 adversarial cases against the implementation; map to PASS/FAIL.

---

## 10. Summary

This review surfaced **45 distinct findings** across internal-consistency, rule-contradiction, missing-edge-case, illegal-action, hidden-info, and plan-discrepancy categories. Severity distribution: 2 S0 (verified-safe checks), 16 S1 (must fix in spec), 19 S2 (should fix), 8 S3 (cosmetic). 36 illegal-action tests catalogued. 36 adversarial scenarios validated; 32 of them flagged MISSING for owner confirmation. 7 candidate D-NN decisions proposed for adjudication (D-20 through D-26).

**Top 3 most concerning issues**:

1. **FINDING-13 / D-26** (S1): The Analyst keep/return decision is collected via the `actRetireAgent` payload BEFORE the tile is drawn server-side. This forces blind pre-commit, depriving the player of informed choice. STATE_MACHINE §3.1/§3.2 explicitly defers this to a "UI modal before sending the request" — but the modal cannot show the drawn tile because the draw happens on the server in response to the request. This is a real player-facing logic bug introduced by the modern-framework consolidation; needs a sub-state or different design.

2. **FINDING-25 / D-24** (S1): When a tile's intended trickle direction is blocked by a blockade and the redirect direction is off-board, the spec is internally contradictory. STATE_MACHINE §8.5 redirects then applies, off-board → bag (tile lost). Rulebook §13 B-02 default ("blockaded inlet → no trickle") implies the tile stays on its hex. Two algorithms produce different game states.

3. **FINDING-09 / FINDING-18** (S1): `actions_remaining = 0` is used as the "first entry vs self-loop" discriminator in `Actions::onEnteringState()`. But it is also the legitimate post-3rd-action state. A naive implementation would reset `actions_remaining` to 3 mid-actions, granting unlimited actions. Compounded by FINDING-02: there is no documented invariant enforcement that `actions_remaining ≤ 4`.

**Candidate decisions proposed**: D-20 (Analyst bonus privacy), D-21 (swap pickup), D-22 (blockade on opponent spawn row), D-23 (two-Honeypot trickle), D-24 (off-board redirect priority), D-25 (Comms-up off-top), D-26 (Analyst decision UX).

End of `QA_SPEC_REVIEW.md`.
