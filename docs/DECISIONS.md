# Hexpionage → BGA — Owner Decisions

Source-of-truth log for the 13 D-NN decisions referenced in [docs/history/PLAN.md](docs/history/PLAN.md). Agents must consult this before doing rules formalization, schema design, or implementation. Where a decision overrides the rulebook or FAQ, the override is called out explicitly.

---

## D-01 — Agent roster

**Decision**: `specialops` (the punchboard PNG filename) is the **artwork** for the Comms Specialist. Roster is **6 agent types**.

**Implications**:
- Component count: 6 agent types × 2 colors × 2 copies/type = 24 print pieces total (across both players). See D-10b for the per-player count.
- Sprite sheet uses `specialops_*.png` art under the in-game name "Comms Specialist."
- Asset manifest must alias the file to the rules-canonical name.

---

## D-02 — Player count

**Decision**: **2 players only.**

**Implications**:
- `gameinfos.inc.php` → `'players' => [2]`.
- No 3- or 4-player rule design required.
- Reserve `TODO(D-02-multi)` if multi-player expansion is ever revisited.

---

## D-03 — Tie-breaker

**Decision**: **Active player wins** if both players would cross 20 in the same turn.

**Implications**:
- End-game detection runs synchronously during action resolution. The first scoring action that reaches 20 wins immediately, no opponent retort.
- This makes ties effectively impossible because score is only mutated on the active player's actions.

---

## D-04 — Blockade supply (combined with D-07)

**Decision**: **Max 3 of your own blockades on the board simultaneously.** Supply is renewable: see D-07 for expiration mechanic. The 3-token physical print is treated as the **simultaneous board cap**, not a lifetime cap.

---

## D-05 — Honeypot mechanics

### D-05a — Entry & movement

**Decision**: **Drawn from the bag and trickles like normal intel.** Honeypot is one of the 40 bag tiles. It enters via the standard top-row draw and follows the gray die's trickle direction each turn.

### D-05b — Removal trigger (OVERRIDE)

**Decision**: **Agent is removed immediately when it enters a Honeypot's hex** (during Action phase movement), in addition to the FAQ-described removal at end of trickle.

> **Override note**: The FAQ literally says "agents with honeypots are removed from the game" at end-of-trickle only. This decision *expands* that to include voluntary movement onto a Honeypot. Rules Formalization Agent must call this out as `[D-05b override]` and not cite the FAQ as authority for the immediate-removal case.

**Resolution order on immediate removal**:
1. Active agent moves onto Honeypot hex.
2. Agent is removed from the board.
3. All intel held by that agent (including the Honeypot it just stepped onto and any prior intel) returns to the supply.
4. The Honeypot tile also returns to the supply (it was never picked up).
5. Action consumed; phase continues.

---

## D-06 — Pin lifecycle

### D-06a — Expiration

**Decision**: **Pin expires at end of pinned player's next turn.**

**Implications**:
- Track on each pin: `(pinned_agent_id, applied_on_turn_id)`.
- At end-of-turn, server clears any pin where `pinned_player == player_who_just_finished` and `applied_on_turn != current_turn`.

### D-06b — Stacking

**Decision**: **Max one pin per agent.** A second pin attempt against an already-pinned agent is **illegal** (server rejects).

**Implications**:
- Schema: `pin` is at most one row per `agent_id`. Could even live as a column on the `agent` table.
- Hacker pin action validation: `assert no existing pin on target`.

---

## D-07 — Blockade lifecycle (override)

**Decision**: **Blockades expire at end of the *opponent's* next turn**, then return to the placing player's pool.

**Combined supply rule (with D-04)**:
- **Unlimited lifetime** placements.
- **Maximum 3 of your own blockades on the board at any moment.**
- Engineer's place-blockade action is illegal if the player already has 3 active blockades.

**Implications**:
- Schema: `blockade` row carries `(hex, owner_player_id, placed_on_turn_id)`.
- End-of-turn cleanup: server removes blockades where `placed_on_turn_id` is on the opponent of the player whose turn just ended (i.e., one full opponent turn has elapsed).
- This mirrors the pin expiration mechanic — note the parallel for documentation.

> **Note**: rulebook is silent on blockade removal; this decision is a designer ruling and supersedes any silent implication of permanence.

---

## D-08 — Smuggler boost

**Decision**: **Hard cap at 4 actions per turn.** Only one Smuggler boost may be active per turn, regardless of how many Smugglers you control.

**Implications**:
- Server tracks a per-turn boolean `smuggler_boost_used`.
- Action counter ceiling = 3 + (1 if boost_used else 0) = at most 4.
- Boost action is illegal if already used this turn.

---

## D-09 — Comms Specialist target

**Decision**: **Only loose intel on otherwise-empty hexes.** Comms Specialist cannot target intel currently held by an agent.

**Implications**:
- Server validation: target hex must contain ≥1 intel piece **and** no agent.
- UI: hover/click highlights only legal target hexes (empty hexes containing intel).

---

## D-10 — Spawn semantics

### D-10a — Per-turn cap

**Decision**: **Up to 3 spawns per turn** (cap refreshes each turn).

### D-10b — Recycling (CORRECTION + clarification)

**Decision**: **Once removed, an agent is gone for good** (no return to spawn pool, regardless of removal cause: retire, honeypot, over-capacity).

> **Component correction**: Each player has **2 of each agent type → 12 agents per player total** (not 6). This corrects the earlier scan in docs/history/PLAN.md and the initial agent inventory. Total physical agent pieces in the box = 12 × 2 players = 24.

**Implications**:
- Schema: `agent` table has up to 24 rows total at game start (12 per player), with `state` column indicating `in_pool` / `on_board` / `removed`.
- Spawn validation: `pool_count > 0` AND `bottom_row_empty_hex_count > 0` AND `spawned_this_turn < 3`.
- Implicit secondary loss condition: a player who runs out of all 12 agents cannot spawn or score further. **TODO(D-10c)**: Decide whether running out of agents is a loss condition or simply a lockout; flag for owner.

---

## D-11 — Score visibility

**Decision**: **Both players' scores are public at all times.**

**Implications**:
- Score lives on the public `player` table; included in `getAllDatas()` for all players.
- No filtering required; score notifications go via `notify->all`.

---

## D-12 — Asset & licensing

### D-12a — Art rights

**Decision**: **Owner has full rights to all art** in `final_printing/`. Asset pipeline may transform PSDs/PNGs into BGA web sprites without further licensing review.

### D-12b — BGG entry

**Decision**: **BGG ID `307967`** — https://boardgamegeek.com/boardgame/307967/hexpionage

**Implications**:
- `gameinfos.inc.php` → `'bgg_id' => 307967`, `'publisher_bgg_id'` → fill from BGG page metadata.

---

## D-13 — Variants

**Decision**: **No custom variants.** Ship canonical rules only. Standard BGA-provided options (turn timer, ELO/training, etc.) still apply.

**Implications**:
- `gameoptions.json` may be empty or omitted.
- Reserve `TODO(D-13-future)` for any post-launch variant work.

---

---

## D-14 — Retire scoring (formerly AMBIGUITY R-01)

**Decision**: When an agent retires, **all intel held by that agent is scored** for the active player. None returns to the bag.

**Implications**:
- `act_retire_agent` effect: every tile in `agent.intel_held` becomes `state = 'scored'`, `scored_by = active_player`.
- Score increment = sum of score values of all held intel (Honeypots cannot be held; see D-05).
- Scoring economy is significantly higher than the prior default ("score exactly one"). Maximum possible per-retire score = 3 × max_intel_value = 3 × 4 = 12 points (three State Secrets).
- The Analyst bonus (§6.12) draws **one extra** intel on retirement-with-3, also scored if "kept."

---

## D-15 — Hacker per-turn limits (formerly AMBIGUITY H-01)

**Decision**: Hacker abilities are **once per turn per Hacker**, not per player. Each Hacker independently has its own `pin_used` and `steal_used` flags.

**Implications**:
- State model: per-Hacker flags, not per-player. Track on the `agent` row (e.g., `hacker_pin_used_this_turn`, `hacker_steal_used_this_turn`).
- Owning multiple Hackers is meaningfully more powerful (pin + steal scales linearly with Hacker count).
- Reset all per-Hacker flags during end-of-turn cleanup.

---

## D-16 — First player (formerly AMBIGUITY F-01)

**Decision**: **Random first player.** The "most secrets" physical-game wording is replaced by a uniform random selection.

**Implications**:
- Setup: `bga_rand` chooses `active_player` from `{P1, P2}` with 50/50 odds.
- No player input required at table start.

---

## D-17 — Agent depletion loss (formerly TODO D-10c)

**Decision**: A player **loses** the game if they have **zero agents in their reserve pool AND zero agents on the board**. Spawning is voluntary (a player is never forced to spawn), so depletion is only a loss condition when there is also nothing left on the board to retire or use.

**Implications**:
- Game end check: at every relevant trigger (agent removal: retire/honeypot), check `len(agent_pool) + len(agents_on_board) == 0`. If true for active player → opponent wins. If true for non-active player at a moment when their state changes → that player loses, opponent wins.
- Spawn is voluntary: `act_pass_spawn` is always legal in spawn phase, even with empty board and full pool.
- This adds a secondary win condition: "force opponent to lose all 12 agents" via Honeypots, Hacker pins (preventing scoring), and Smuggler swaps onto Honeypots.

> **DERIVED implication**: a player who has agents on the board but none in their pool can still play normally; they only lose when board agents are also gone. This is functionally an attrition victory.

---

## D-18 — Empty-bag handling (formerly AMBIGUITIES B-01 + A-01)

**Decision**: When the bag is empty and a draw is requested (top-left, top-right, or Analyst bonus), **nothing is drawn; everything else continues normally**. No game-end trigger, no special handling.

**Implications**:
- `trickle_draw_left` / `trickle_draw_right`: if `len(bag) == 0`, skip the placement. The corresponding intel entry hex remains empty for that turn.
- `act_analyst_retire_bonus`: if bag empty, the bonus is forfeited; retirement proceeds normally with the held intel scoring.
- Game continues until win condition (20 points or D-17 depletion).

---

## D-19 — Intel color/value mapping (formerly AMBIGUITIES I-01 + I-03)

**Decision**: Final mapping of intel type → color → score value:

| Intel type | Color | Score value | Asset file |
|---|---|---|---|
| Honeypot | **Gray** | **0** (special; never scored) | `honeypot.png` |
| Industrial Tech | **Brown** | **2** | `industrial_tech.png` |
| Leaked Email | **Purple** | **2** | `leaked_email.png` |
| Blackmail | **Green** | **2** | `blackmail.png` |
| Security Credential | **Yellow** | **3** | `security_credential.png` |
| State Secret | **Cyan** | **4** | `state_secret.png` |

**Implications**:
- Score values are **non-uniform**: 5 distinct values (0, 2, 2, 2, 3, 4). Total possible point pool depends on per-type counts (still TODO I-02).
- Dice color → intel color mapping is now 1:1: gray die = Honeypot, brown die = Industrial Tech, purple die = Leaked Email, green die = Blackmail, yellow die = Security Credential, cyan die = State Secret.
- The earlier print-art file scan that said "all 2 points" was wrong; only Industrial Tech / Leaked Email / Blackmail are 2-point tiles.
- Strategic implication: State Secret (cyan, 4 pts) is twice as valuable as basic intel; Security Credential (yellow, 3 pts) is intermediate. Players should prioritize retiring with high-value intel.

---

---

## D-20 — Analyst bonus draw privacy (formerly QA candidate D-20)

**Decision**: **Private until commit.** When the Analyst retires with 3 intel and the bonus draw fires:
- Server fires `analystBonusDrawn` as `notify->player(active_player)` only — opponent does not see the tile type.
- If active player chooses `keep`: the tile is publicly scored. Fire a public notification (`analystBonusKept`) revealing the tile type at this point.
- If active player chooses `return`: the tile returns to bag face-down. Fire a public notification (`analystBonusReturned`) carrying NO tile type — only that a return happened.

**Implications**:
- `CONTRACT.md` must update: `analystBonusDrawn` becomes private; add `analystBonusKept` (public, reveals type) and `analystBonusReturned` (public, no type).
- F-19 / F-33 in QA review: resolved.
- Spectators see what active player sees? **No** — spectators get the public-notification stream only (matching what the opponent sees). [DERIVED — consistent with bag-hidden-from-spectators policy.]

---

## D-21 — Universal intel pickup invariant (formerly QA candidate D-21)

**Decision**: **Loose intel and an agent NEVER co-occupy a hex.** Intel pickup is automatic and immediate in any circumstance where they would. The "Smuggler swap onto loose intel" scenario is structurally impossible because the precondition state (loose intel on a hex with an agent) cannot exist.

**Generalized rule (replaces the Move-only wording in rulebook §6.3)**: At any moment where an `intel_tile.state == 'on_board'` and an agent occupies the same hex, the intel is immediately picked up by that agent. This applies to:
- Move (§6.3) — already specified.
- Trickle resolution (§7.2 step E) — already specified.
- Smuggler swap (§6.8) — newly clarified: if a swap would create co-occupation, pickup fires; if it triggers Honeypot, §9.4 fires.
- Any hypothetical future mechanic that creates co-occupation.

**Server invariant**: `intel_tile WHERE state = 'on_board' AND hex IN (SELECT hex FROM agent WHERE state = 'on_board')` returns **zero rows always**. Assert at end of every action.

**Implications**:
- `rulebook.md` §6.3 effect 2 must be generalized to "any agent gaining co-occupation with loose intel picks it up."
- `STATE_MODEL.md` §6 should add the invariant as a server-side assertion.
- Honeypot trigger generalizes: any pickup fires §9.4.
- D-21-CANDIDATE in QA review: resolved.

---

## D-24 — Trickle redirect off-board priority (formerly QA candidate D-24)

**Decision**: **Off-board wins.** When a tile's intended trickle direction is blocked by a blockade and the redirect target is off the Field, the tile returns to the bag (per §9.2). The redirect succeeded; it just terminated off-board.

**Algorithm clarification** (rulebook §7.2 step B + §9.6):
1. Compute intended direction `D` from dice.
2. If `D(hex)` is blockaded:
   - Compute redirect target `D'(hex)` (the other diagonal).
   - If `D'(hex)` is also blockaded → tile stays this turn.
   - Else if `D'(hex)` is off-Field → tile returns to bag (§9.2).
   - Else → tile moves to `D'(hex)`.
3. Else if `D(hex)` is off-Field → tile returns to bag (§9.2).
4. Else → tile moves to `D(hex)`.

**Implications**:
- `rulebook.md` §7.2 step B and §9.6.C must be revised to specify this precedence explicitly.
- F-24 / F-25 in QA review: resolved.

---

## D-26 — Analyst keep/return UX (formerly QA candidate D-26)

**Decision**: **Two-step sub-state.** Add a new BGA state `analystBonusDecision` between trigger and decision.

**State flow**:
1. Player fires `actRetireAgent` on an Analyst with 3 intel.
2. Server resolves retirement (scoring all 3 held intel per [D-14]); fires `agentRetired`.
3. Server transitions to new state `analystBonusDecision` (active-player state).
4. Server draws bonus tile from bag (or skips if empty per [D-18]); fires `analystBonusDrawn` (private to active player per [D-20]).
5. State machine waits for player input: `actAnalystKeep` or `actAnalystReturn`.
6. On commit, server fires public notification (`analystBonusKept` reveals type; `analystBonusReturned` does not).
7. Server runs win check + depletion check, then transitions back to `actions` (or `gameEnd`).

**Implications**:
- `STATE_MACHINE.md` must add the `analystBonusDecision` state with its `args`, `possibleactions`, transitions, undo policy (NOT undoable — would re-roll the bonus draw), zombie behavior (auto-`actAnalystReturn` to avoid stalling).
- 2 new actions: `actAnalystKeep`, `actAnalystReturn`.
- `CONTRACT.md` must add the new actions and their notifications.
- `UI_SPEC.md` must add a screen for `analystBonusDecision` (modal showing the drawn tile + Keep/Return buttons).
- F-13 / D-26-CANDIDATE: resolved.
- Empty-bag case [D-18]: skip steps 3–6 entirely (no decision needed because no draw); server emits a brief `analystBonusSkipped` notification and transitions directly to win/depletion check.

---

## Remaining open follow-ups (non-blocking; have proposed defaults)

### Lower-priority adjudications — owner walked through and confirmed

- **D-22**: Engineer blockade on opponent's `✦` spawn-row hex → **legal** (strategic denial). Engineer place-blockade actions only check that the hex has no agent, no intel, and no existing blockade; spawn-row status is not a placement constraint.
- **D-23**: Two Honeypots trickling onto same agent → **both return to bag**. Per §9.4 step 3 ("all of agent's intel returns to supply"), all arrivals to the agent's hex during a single trickle resolution are bundled with the Honeypot removal: the agent goes to removed; all incoming intel (including the second Honeypot) returns to bag.
- **D-25**: Comms-up move target off top edge → **illegal**. Symmetric with [D-09 / C-02]. The action is rejected at the precondition stage; `target_hex` must be a valid Field hex.

### Asset / board-confirmation TODOs

- **TODO(I-02)**: per-intel-type tile counts. 47 total minus N honeypots. Asset audit pass must read punchboard PSDs to enumerate. Implementer can use a placeholder distribution until verified.
- **TODO(G-01)**: Confirm hex orientation (pointy-top assumed) by inspection of `game_board_print.png`.
- **TODO(G-02)**: Enumerate Field hex coordinates and total count. Asset audit pass.

### Rulebook polish (apply during S1 remediation pass)

- **TODO(B-02)**: rulebook phrase "or on an inlet" — default interpretation: edge-of-board hex with only one downward diagonal in the Field.
- **TODO(S-01)**: Smuggler swapping itself with another agent — default: legal.
- **TODO(P-01)**: Pinned agent serving as source of `act_transfer_intel` — default: legal.

---

## Decision-log discipline

- This file is **append-only**. To revise a decision, add a new entry referencing the original (`D-NN-r1`) — never silently rewrite.
- Every spec doc that depends on a decision must cite the decision ID inline (e.g., `[D-05b]`).
- Agents must `TODO(D-NN)` and stop if they encounter a rules question not resolved here.
