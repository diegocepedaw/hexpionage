# Hexpionage — Playtest Scenario Library (A10)

> **Purpose**: deterministic, server-side playtest scenarios used to verify rule
> implementation, edge cases, and notification correctness. Each scenario fixes
> a starting state (deltas from the default post-setup), a sequence of actions
> or server steps, and the expected resulting state and notifications.
>
> **Conventions**:
> - State columns and entity names match `specs/STATE_MODEL.md` §2 (e.g.,
>   `intel_tile.state`, `agent.pinned_until_turn`, `agent.hacker_pin_used_this_turn`).
> - Notification names match `specs/STATE_MACHINE.md` §9 (`intelDrawn`,
>   `agentSpawned`, `trickleResolved`, etc.).
> - Hex coordinates are pointy-top axial `(q, r)` per STATE_MODEL §3.3. The
>   "default setup" assumes the layout described in STATE_MODEL §3.2 with
>   `top_left = (-4, -3)`, `top_right = (+4, -3)`, spawn row at `r = +3`.
> - Citations use `§N.N` for `rulebook.md` and `[D-NN]` for `DECISIONS.md`.
> - "Default state" = post-`setupNewGame` state per STATE_MODEL §8: 47 intel
>   in bag, 12 agents per player in pool, 0 on board, 0 blockades, score 0,
>   `turn_id=1`, `active_player=P1` (assume P1 won the random roll).
> - "P1 active" / "P2 active" indicates whose turn it is when the scenario
>   begins; turn_id is implicit unless stated otherwise.
> - `bag-1` shorthand: bag size decreases by 1.

---

## Section 1 — Coverage Matrix

The matrix below maps every R-rule (rulebook §6 actions, §9 edge cases) and
every relevant decision to the scenario(s) that exercise it. Section 4
invariants are tested by **every** scenario.

| Rule / Decision ID | What it covers | Scenarios |
|---|---|---|
| §5.1 Trickle phase order | draw-left, draw-right, roll, resolve sequencing | 1, 2, 3 |
| §6.1 `act_spawn_agent` | spawn from pool to ✦ hex | 1, 2 |
| §6.2 `act_pass_spawn` | end spawn phase | 1, 4, 5 |
| §6.3 `act_move_agent` | adjacent move + intel pickup | 5, 8, 14 |
| §6.4 `act_transfer_intel` | adjacent same-owner transfer | 12 |
| §6.5 `act_retire_agent` (free) | score all held intel [D-14] | 12, 13 |
| §6.6.A Engineer adjacent blockade | place adjacent | 8, 9 |
| §6.6.B Engineer remote blockade | costs 1 intel, no action | (illegal-list) |
| §6.7 Smuggler boost | once per player, 1 intel → 4 actions [D-08] | 10 |
| §6.8 Smuggler swap | 1 intel + 1 action; non-pinned | (illegal-list) |
| §6.9.A Comms move up | 1 action; loose intel only [D-09] | (illegal-list) |
| §6.9.B Comms move down | 1 intel + 1 action | (illegal-list) |
| §6.10 Double-agent transfer | no adjacency, any agent | (illegal-list) |
| §6.11.A Hacker pin | per-Hacker once-per-turn [D-15] | 11 |
| §6.11.B Hacker unpin | shares pin slot | (illegal-list) |
| §6.11.C Hacker steal | per-Hacker, separate slot, 1 intel | 11 |
| §6.12 Analyst retire bonus | trigger when held=3 at retire | 12 |
| §7.2 Trickle simultaneity | all moves computed before applied | 3 |
| §7.4 End-of-turn cleanup | pin/blockade expiry, flag reset | 11 |
| §8.1 Win at 20 (inline) | game ends before opponent turn | 13 |
| §8.3 / [D-17] Depletion loss | pool+board==0 → opponent wins | 14 |
| §9.1 Stacked intel | multi-tile single hex | 2, 3 |
| §9.2 Off-board trickle | return to bag | (illegal-list / invariant) |
| §9.3 Over-capacity dump (>3) | dump all on excess | 6, 7 |
| §9.3 EDGE(O-01) | Honeypot before over-capacity | 7 |
| §9.4 Honeypot resolution | remove agent + intel back to bag | 4, 5, 7, 14 |
| §9.5 Pinned agent restrictions | no move/retire/swap | 11 |
| §9.6.C Trickle blockade redirect | single blockade → other diagonal | 8 |
| §9.6.C/D Both diagonals blocked | no trickle that turn | 9 |
| [D-14] Retire-all scoring | all held intel scored on retire | 12, 13 |
| [D-15] Per-Hacker once-per-turn | each Hacker pin/steal independently | 11 |
| [D-17] Depletion = loss | opponent wins | 14 |
| [D-18] Empty-bag = no-op | trickle skips, game continues | 15 |
| [D-19] Intel score values | 0/2/2/2/3/4 | 12, 13 |

---

## Section 2 — Scenario Library (15 scenarios)

Each scenario is a self-contained reference. "Setup" lists only deltas from
the default post-`setupNewGame` state. "Sequence" tabulates one row per
action/server step; "Actor" is `server` for auto steps and `P1`/`P2` for
player inputs. Notification names match STATE_MACHINE §9.

---

## SCENARIO-01: OPENING_TURN

**Coverage**: §5.1, §6.1, §6.2, §6.13, §10.2, §10.5

**Setup** (deltas from default): none. Fresh game; P1 active; bag=47;
all agents `state='in_pool'`; no loose intel; turn_id=1.

**Sequence**:

| # | Actor | Action | Expected delta | Notifications |
|---|-------|--------|----------------|---------------|
| 1 | server | trickleDrawLeft | 1 tile drawn → top_left, bag=46 | `intelDrawn{side:'left',new_bag_size:46}` |
| 2 | server | trickleDrawRight | 1 tile drawn → top_right, bag=45 | `intelDrawn{side:'right',new_bag_size:45}` |
| 3 | server | trickleRoll | 6 dice, `globals.dice_state` populated | `diceRolled{dice_state}` |
| 4 | server | trickleResolve | both new tiles trickle SW or SE; both land on empty hexes (no agents on board); no Honeypot lands on agent; capacity check no-op | `trickleResolved{moves:[2 entries], honeypot_removals:[], over_capacity_dumps:[]}` |
| 5 | P1 | actSpawnAgent(any pool agent, any ✦ hex) | agent.state→on_board, hex set, spawned_on_turn=1, agents_on_board=1 | `agentSpawned` |
| 6 | P1 | actSpawnAgent | agents_on_board=2 | `agentSpawned` |
| 7 | P1 | actSpawnAgent | agents_on_board=3 | `agentSpawned` |
| 8 | P1 | actPassSpawn | phase→`actions`, actions_remaining=3 | (none) |
| 9 | P1 | actPassActions | phase→`endOfTurnCleanup` | (none) |
| 10 | server | endOfTurnCleanup | turn_id→2, active_player→P2, flags reset | `pinExpired{[]}`, `blockadeExpired{[]}`, `turnEnded{new_active_player_id:P2,new_turn_id:2}` |

**Assertions**:
- After step 8: `len(agents_on_board WHERE owner=P1) == 3`, `len(agent_pool WHERE owner=P1) == 9`.
- After step 4: exactly 2 intel rows have `state='on_board'` (none on agents because no agents existed during trickle).
- After step 10: `globals.dice_state == {}`, `globals.smuggler_boost_used_this_turn == false`.

---

## SCENARIO-02: TRICKLE_STACK

**Coverage**: §7.2 step C, §7.3, §9.1

**Setup** (deltas):
- 3 loose intel positioned so all three trickle to the same empty hex `H`:
  - `intel_A` at hex `H_NW` (color whose die rolls SE → SE(H_NW)=H).
  - `intel_B` at hex `H_NE` (color whose die rolls SW → SW(H_NE)=H).
  - `intel_C` at hex `H_above_via_chain`: assume tile at `H_NW` lineage so trickle direction lands on `H` (rigged dice).
- For determinism, the test harness pins `dice_state` so the three target the same `H`.
- No agent on `H` or any source hex; no blockades; no top-row draw needed (or drawn elsewhere).

**Sequence**:

| # | Actor | Action | Expected delta | Notifications |
|---|-------|--------|----------------|---------------|
| 1 | server | trickleDrawLeft / Right | top-row tiles placed (irrelevant to test) | `intelDrawn` ×2 |
| 2 | server | trickleRoll | `dice_state` rigged | `diceRolled` |
| 3 | server | trickleResolve | A, B, C all move to `H`; stack of 3 forms; `stack_order` 0,1,2 | `trickleResolved{moves: 3 onto H, stack_size: 3}` |

**Assertions**:
- `COUNT(intel_tile WHERE state='on_board' AND hex=H) == 3`.
- All three rows have distinct `stack_order` values.
- `COUNT(intel_tile WHERE state='on_agent' AND agent_id=...) == 0` (nobody picked up).

---

## SCENARIO-03: TRICKLE_CROSS

**Coverage**: §7.2 step C (simultaneity), §9.1

**Setup** (deltas):
- `intel_X` (color: brown, die forced to SE) at `hex_A`.
- `intel_Y` (color: green, die forced to SW) at `hex_B`, where `hex_B = SE(hex_A)` and `hex_A = SE(hex_B)` is impossible — instead pick adjacent diagonals: `hex_A = NW(hex_C)`, `hex_B = NE(hex_C)`. Then `SE(hex_A) = ?` and `SW(hex_B) = ?`. Concretely choose `hex_A=(0,0)`, `hex_B=(1,0)`. Then `SE(hex_A)=(0,1)` and `SW(hex_B)=(0,1)`. Both trickle to the same hex `(0,1)`. **Rework**: to test "ending on each other's old hex," use `hex_A=(0,0)`, `hex_B=(0,1)` (B is SE of A); X trickles SE → (0,1)=B's old hex; Y trickles NW? — no, trickle is SW/SE only. Use: `hex_A=(0,0)` X trickles SE → (0,1); `hex_B=(0,1)` Y trickles SE → (0,2). Each ends on the next-down hex.
- The point of this scenario: both moves computed before either applied, so X arriving at (0,1) does **not** see Y still there — Y has already left to (0,2) in the same simultaneous step.

**Sequence**:

| # | Actor | Action | Expected delta | Notifications |
|---|-------|--------|----------------|---------------|
| 1 | server | trickleResolve | X: (0,0)→(0,1); Y: (0,1)→(0,2). Single hex never holds both during resolution. | `trickleResolved{moves:[{X,(0,0)→(0,1)},{Y,(0,1)→(0,2)}]}` |

**Assertions**:
- After resolve: `intel_tile X.hex_q,hex_r=(0,1)`; `Y.hex_q,hex_r=(0,2)`.
- Hex `(0,1)` contains exactly 1 tile (X), not 2.
- No `over_capacity_dumps` or `honeypot_removals`.

---

## SCENARIO-04: HONEYPOT_TRICKLE

**Coverage**: §9.4, §7.2 step D, [D-05a]

**Setup** (deltas):
- P2 has agent `A1` (engineer) on hex `(2,0)` holding 2 intel: `[Industrial Tech, State Secret]`.
- Loose Honeypot at `hex_above = NE(2,0) = (3,-1)`, color=gray, dice forced so gray die = odd → SW → (2,0) (lands on A1).
- No other relevant intel.
- P1 active turn; trickle phase.

**Sequence**:

| # | Actor | Action | Expected delta | Notifications |
|---|-------|--------|----------------|---------------|
| 1 | server | trickleResolve | Honeypot arrives on A1 hex; §9.4 fires: A1 removed, all 3 tiles (2 held + the Honeypot) → bag (`state='returned_to_bag'`). | `trickleResolved{honeypot_removals:[{agent_id:A1, intel_returned:[3 tiles]}]}` |

**Assertions**:
- `agent A1.state == 'removed'`, `hex_q,hex_r == NULL`.
- All 3 intel rows have `state='returned_to_bag'`, `agent_id=NULL`, `hex_q,hex_r=NULL`.
- `bag_size` increased by 3 vs pre-resolve.
- No score change.
- Invariant: `intel_tile WHERE type='honeypot' AND state='on_agent'` is empty.

---

## SCENARIO-05: HONEYPOT_MOVE

**Coverage**: §6.3, §9.4, [D-05b override]

**Setup** (deltas):
- P1 active, phase=`actions`, `actions_remaining=3`.
- P1 agent `A2` (smuggler) on `(0,1)` with 1 held intel `[Blackmail]`.
- Loose Honeypot at `(1,1)` (= E neighbor of (0,1)).
- No blockade.

**Sequence**:

| # | Actor | Action | Expected delta | Notifications |
|---|-------|--------|----------------|---------------|
| 1 | P1 | actMoveAgent(A2, (1,1)) | A2 moves; on pickup, Honeypot type detected → §9.4: A2 removed, Blackmail + Honeypot → bag; actions_remaining=2 | `agentMoved{agent_id:A2,from:(0,1),to:(1,1)}`, `agentRemoved{agent_id:A2,reason:'honeypot'}`, `agentDumped{agent_id:A2,dumped_intel:[Blackmail,Honeypot]}` |

**Assertions**:
- `A2.state='removed'`. `agent_on_hex[(1,1)] = null`. `agent_on_hex[(0,1)] = null`.
- Both intel rows `state='returned_to_bag'`.
- `actions_remaining == 2` (move action consumed despite removal).
- No score change.

---

## SCENARIO-06: OVERCAP_DUMP

**Coverage**: §9.3, §7.2 step E

**Setup** (deltas):
- P1 agent `A3` on hex `(0,0)` with 2 held intel `[Leaked Email, Industrial Tech]`.
- 2 loose non-Honeypot intel poised to trickle onto (0,0):
  - `intel_P` at `NE(0,0)=(1,-1)`, dice forced so its color = odd → SW → (0,0).
  - `intel_Q` at `NW(0,0)=(0,-1)`, dice forced so its color = even → SE → (0,0).
- Phase: trickleResolve.

**Sequence**:

| # | Actor | Action | Expected delta | Notifications |
|---|-------|--------|----------------|---------------|
| 1 | server | trickleResolve | Both arrive on A3 hex. Pickup: A3.intel_held grows to 4. §9.3 fires: all 4 tiles → bag, A3 stays on board with `intel_held=[]`. | `trickleResolved{over_capacity_dumps:[{agent_id:A3,dumped:[4 tiles]}]}`, `agentDumped` |

**Assertions**:
- `A3.state='on_board'`, hex unchanged.
- `COUNT(intel_tile WHERE agent_id=A3 AND state='on_agent') == 0`.
- 4 tiles transitioned to `state='returned_to_bag'`.
- No `agentRemoved`.

---

## SCENARIO-07: HONEYPOT_THEN_OVERCAP

**Coverage**: §9.3 EDGE(O-01), §9.4

**Setup** (deltas):
- P1 agent `A4` on `(0,0)` holding **3** intel `[State Secret, Security Cred, Blackmail]` (already at cap).
- 2 trickling tiles arriving on A4's hex:
  - `intel_HP` (Honeypot) — dice rigged.
  - `intel_X` (Industrial Tech) — dice rigged.
- Both arrive on `(0,0)` simultaneously.

**Sequence**:

| # | Actor | Action | Expected delta | Notifications |
|---|-------|--------|----------------|---------------|
| 1 | server | trickleResolve | §9.3 EDGE(O-01): Honeypot resolution fires FIRST → A4 removed; all of A4's 3 held + Honeypot → bag. Over-capacity check then no-op (agent gone). The non-Honeypot arrival `intel_X`: per §9.4 rule "all of agent's intel returns to bag," `intel_X` was on its way to A4 — implementation per STATE_MACHINE §8.5: tile is already part of the arriving set; it returns to bag with the rest. | `trickleResolved{honeypot_removals:[{agent_id:A4,intel_returned:[HP,SS,SC,BM,X]}], over_capacity_dumps:[]}` |

**Assertions**:
- `A4.state='removed'`.
- 5 intel rows now in `state='returned_to_bag'` (3 held + Honeypot + intel_X).
- `over_capacity_dumps` array is **empty** (Honeypot fired first; no agent left to over-cap).
- No score change.

---

## SCENARIO-08: BLOCKADE_REDIRECT

**Coverage**: §9.6.C, §6.6.A, §7.2 step B

**Setup** (deltas):
- Loose `intel_R` (color brown) at `(2,0)`. Dice forced: brown die = odd → SW → target `(1,1)`.
- P1 blockade on `(1,1)` (placed last turn or pre-set in scenario).
- `(2,1)` (= SE of (2,0)) is empty Field hex.
- No other intel relevant.

**Sequence**:

| # | Actor | Action | Expected delta | Notifications |
|---|-------|--------|----------------|---------------|
| 1 | server | trickleResolve | intended SW=(1,1) blockaded → redirect to other diagonal SE=(2,1) (open). Tile lands on (2,1). | `trickleResolved{moves:[{tile:R, from:(2,0), to:(2,1), redirected:true}]}` |

**Assertions**:
- `intel_R.hex_q,hex_r == (2,1)`.
- Blockade row at `(1,1)` unchanged.
- No tiles returned to bag from this move.

---

## SCENARIO-09: BLOCKADE_PAIR_BOTH

**Coverage**: §9.6.C last clause, §9.6.D

**Setup** (deltas):
- Loose `intel_S` (color cyan) at `(0,0)`. Dice forced: cyan die = even → SE → `(0,1)`.
- P2 blockade on `(0,1)` (SE neighbor of (0,0)).
- P2 blockade on `(-1,1)` (SW neighbor of (0,0)).
- Both diagonals below `(0,0)` are blockaded.

**Sequence**:

| # | Actor | Action | Expected delta | Notifications |
|---|-------|--------|----------------|---------------|
| 1 | server | trickleResolve | both `SW(0,0)` and `SE(0,0)` blockaded → tile marked `no_move`. Stays at (0,0). | `trickleResolved{moves:[{tile:S, from:(0,0), to:(0,0), blocked:true}]}` |

**Assertions**:
- `intel_S.hex_q,hex_r == (0,0)` (unchanged).
- Both blockade rows unchanged (`state='on_board'`).
- No `agentRemoved`, no over-capacity dumps.

---

## SCENARIO-10: SMUGGLER_BOOST

**Coverage**: §6.7, [D-08]

**Setup** (deltas):
- P1 active, phase=`actions`, `actions_remaining=3`, `smuggler_boost_used_this_turn=false`.
- P1 Smuggler `A5` on `(0,3)` (✦ row) holding 2 intel `[Leaked Email, Blackmail]`.
- Other P1 agents available for legal moves (so 4 actions can actually fire).

**Sequence**:

| # | Actor | Action | Expected delta | Notifications |
|---|-------|--------|----------------|---------------|
| 1 | P1 | actSmugglerBoostActions(A5, Leaked Email) | intel→bag; `smuggler_boost_used_this_turn=true`; `actions_remaining=4` (3+1, no decrement) | `actionsBoosted{smuggler_id:A5,intel_spent:LE,new_actions_remaining:4}` |
| 2 | P1 | actMoveAgent(any) | actions_remaining=3 | `agentMoved` |
| 3 | P1 | actMoveAgent(any) | actions_remaining=2 | `agentMoved` |
| 4 | P1 | actMoveAgent(any) | actions_remaining=1 | `agentMoved` |
| 5 | P1 | actMoveAgent(any) | actions_remaining=0 | `agentMoved` |
| 6 | P1 | actSmugglerBoostActions(A5, Blackmail) | **REJECTED** — boost flag already true ([D-08]) | (server error) |
| 7 | P1 | actPassActions | phase→cleanup | (none) |

**Assertions**:
- Step 1: `Leaked Email.state='returned_to_bag'`.
- Step 5: total of 4 successful actMoveAgent calls in this turn (one above the base cap of 3).
- Step 6: server returns illegal-action error; no state change.
- Step 7 onward: cleanup resets `smuggler_boost_used_this_turn=false`.

---

## SCENARIO-11: HACKER_PIN_STEAL

**Coverage**: §6.11.A, §6.11.C, §9.5, [D-15], §7.4

**Setup** (deltas):
- P1 has TWO Hackers on board: `H1` at `(0,0)`, `H2` at `(2,0)`. Both have `hacker_pin_used_this_turn=0`, `hacker_steal_used_this_turn=0`.
- P2 has agent `T1` at `(1,0)` (= E of H1, W of H2) holding 2 intel `[State Secret, Industrial Tech]`. Not pinned.
- H2 holds 1 intel `[Blackmail]` (will be paid as steal cost on turn N+2).
- Turn N: P1 active, phase=`actions`.

**Sequence (multi-turn)**:

| # | Actor | Action | Expected delta | Notifications |
|---|-------|--------|----------------|---------------|
| 1 | P1 (turn N) | actHackerPin(H1, T1) | T1.pinned_until_turn=N+1 (clears at end of P2's next turn after this); H1.hacker_pin_used_this_turn=1; actions_remaining-=1 | `agentPinned{hacker_id:H1, target:T1, pinned_until_turn:N+1}` |
| 2 | P1 (turn N) | actPassActions | phase→cleanup | (none) |
| 3 | server | endOfTurnCleanup turn N | active_player→P2, turn_id=N+1; H1 hacker_pin_used reset to 0; T1 still pinned (T1.owner=P2; pin clears at end of P2's turn) | `turnEnded` |
| 4 | server (turn N+1) | trickle/spawn/actions for P2 | T1 is pinned → `actMoveAgent(T1)`/`actRetireAgent(T1)`/swap involving T1 all illegal (§9.5) | per phase |
| 5 | server | endOfTurnCleanup turn N+1 | T1.pinned_until_turn cleared (owner=P2 just ended turn, pinned_until_turn ≤ current); active_player→P1, turn_id=N+2 | `pinExpired{[T1]}`, `turnEnded` |
| 6 | server (turn N+2) | trickle/spawn → actions for P1 | new turn; H2.hacker_steal_used=0 fresh | (per phase) |
| 7 | P1 (turn N+2) | actHackerPin(H1, T1) | re-pins T1 (T1 no longer pinned post-step-5); H1.hacker_pin_used=1 | `agentPinned` |
| 8 | P1 (turn N+2) | actHackerStealIntel(H2, paid:Blackmail, target:T1, stolen:State Secret) | Blackmail→bag; State Secret moves T1→H2; H2.hacker_steal_used=1; **no action cost** | `intelStolen{hacker_id:H2, target:T1, stolen:SS, intel_spent:BM}` |
| 9 | P1 (turn N+2) | actHackerStealIntel(H1, ..., T1, Industrial Tech) | **Note**: rules require paid_intel ∈ hacker.intel_held; if H1 has none, REJECTED (no fuel). If H1 had a tile, this would succeed — H1 has its own per-Hacker steal slot per [D-15]. | (success or rejection per fuel) |

**Assertions**:
- Step 1: `T1.pinned_until_turn != NULL`.
- Step 3: `H1.hacker_pin_used_this_turn == 0` after cleanup (per-Hacker reset, §7.4 step 3).
- Step 7: `T1.pinned_until_turn` set again (re-pin allowed since [D-06b] checks current pin status, not history).
- Step 8: `State Secret.agent_id == H2.id`. `H2.hacker_steal_used_this_turn == 1`. `H1.hacker_steal_used_this_turn == 0` (different Hacker, untouched).
- Step 9 if attempted: legality depends on H1 fuel — but the **per-Hacker** flag for H1 steal is independent of H2's usage ([D-15]).

---

## SCENARIO-12: RETIRE_FULL_INTEL [D-14]

**Coverage**: §6.5, §6.12, [D-14], [D-19]

**Setup** (deltas):
- P1 score = 0 at scenario start.
- P1 Analyst `A6` on ✦ hex `(0,3)`, `spawned_on_turn = turn_id - 1` (legal to retire), holding 3 intel:
  - `[State Secret (4), Security Cred (3), Industrial Tech (2)]` = 9 pts.
- Bag has tiles remaining (not empty).
- Phase=`actions`, `actions_remaining=3`.

**Sequence**:

| # | Actor | Action | Expected delta | Notifications |
|---|-------|--------|----------------|---------------|
| 1 | P1 | actRetireAgent(A6, analyst_keep_decision='keep') | (a) score the 3 held: P1.score += 9; (b) Analyst bonus triggered (held=3, type=analyst): draw 1 tile from bag, decision=keep. Suppose drawn = State Secret (4). P1.score += 4. Total +13; A6.state='removed'. | `agentRetired{agent_id:A6,scored_intel:[SS,SC,IT],score_delta:9, is_analyst_bonus_pending:true}`, `analystBonusDrawn{tile_id:X, type:state_secret, decision:'keep', score_delta:4}`, `scoreUpdated{player:P1,new_score:13}` |

**Assertions**:
- `A6.state='removed'`, `A6.intel_held` empty (all 3 transitioned `state='scored'`, `scored_by=P1`).
- `P1.score == 13`.
- The bonus tile has `state='scored'`.
- Free action: `actions_remaining` unchanged at 3 (retire is free, §6.5).
- If bonus draw had been a Honeypot, `score_delta` for the bonus = 0 (Honeypot scored as 0; tile still consumed per §6.12).

---

## SCENARIO-13: WIN_INLINE_19_TO_21

**Coverage**: §8.1, §8.2 [D-03], §6.5 effect 7

**Setup** (deltas):
- P1.score = 19. P2.score = 7.
- P1 active, phase=`actions`. P1 agent `A7` (engineer, not analyst) on `(0,3)` ✦ hex; spawned_on_turn = turn_id - 2; not pinned; holding 1 intel `[Industrial Tech (2)]`.

**Sequence**:

| # | Actor | Action | Expected delta | Notifications |
|---|-------|--------|----------------|---------------|
| 1 | P1 | actRetireAgent(A7) | P1.score += 2 → 21; inline win check (§6.5 effect 7) fires: `game_winner=P1`. State transition: actions → gameEnd via `gameWin`. P2's next turn never starts. | `agentRetired`, `scoreUpdated{P1,21}`, `gameEnded{winner:P1, win_reason:'score_20'}` |

**Assertions**:
- `globals.game_winner == P1`.
- `globals.phase == 'game_end'`.
- No `turnEnded` notification fires (control did not pass to P2).
- P2.score remains 7 (unchanged).

---

## SCENARIO-14: DEPLETION_LOSS [D-17]

**Coverage**: §8.3, §9.4, [D-17], inline trigger from `actions` per STATE_MACHINE §12.3

**Setup** (deltas):
- P2 has `agents_remaining = 0` (pool empty; 11 already removed by Honeypots / retires throughout the game). P2 has exactly **1** agent on the board: `A8` on `(0,0)`.
- P1 had previously placed a Honeypot situation: loose Honeypot at `(0,1)` (= SE of (0,0)).
- P2 active, phase=`actions`. (P2 has no choice but to move A8 or pass — forced suicide for the test.)

**Sequence**:

| # | Actor | Action | Expected delta | Notifications |
|---|-------|--------|----------------|---------------|
| 1 | P2 | actMoveAgent(A8, (0,1)) | A8 moves; pickup Honeypot → §9.4: A8 removed; Honeypot → bag. Post-removal: P2.agents_remaining=0 AND agents_on_board=0 → [D-17] depletion: game_winner=P1. Inline transition `actions → gameEnd` per STATE_MACHINE §4 / §12.3. | `agentMoved`, `agentRemoved{A8,reason:'honeypot'}`, `gameEnded{winner:P1, win_reason:'depletion'}` |

**Assertions**:
- `A8.state='removed'`.
- `P2.agents_remaining + COUNT(agent WHERE owner=P2 AND state='on_board') == 0`.
- `globals.game_winner == P1`.
- P1.score is whatever it was; not the trigger.
- Note: per §8.3 edge, even if P2 had passed instead, the depletion check at `endOfTurnCleanup` would also have fired — but P2 still had 1 board agent before the move, so depletion only fires after the Honeypot removal.

**Variant**: if P2 had a non-Honeypot move available (no forced suicide), P2 would simply pass, and the game would continue (depletion does not fire because `agents_on_board > 0`).

---

## SCENARIO-15: EMPTY_BAG_NOOP [D-18]

**Coverage**: §5.1 (with [D-18]), §6.12 (Analyst bonus forfeit), §13 B-01-rev

**Setup** (deltas):
- Bag is empty: `COUNT(intel_tile WHERE state IN ('in_bag','returned_to_bag')) == 0`.
- All 47 intel are either `on_board`, `on_agent`, or `scored`.
- P1 active, turn N, beginning of trickle.
- Some loose intel exists on the board (so trickleResolve still has work).

**Sequence**:

| # | Actor | Action | Expected delta | Notifications |
|---|-------|--------|----------------|---------------|
| 1 | server | trickleDrawLeft | bag=0 → skip placement per [D-18]; top_left hex unchanged | (none, OR empty-payload `intelDrawn{skipped:true}` per A11) |
| 2 | server | trickleDrawRight | same as 1 | (none / skipped) |
| 3 | server | trickleRoll | dice roll proceeds normally | `diceRolled` |
| 4 | server | trickleResolve | resolves whatever loose intel exists; over-cap and Honeypot rules unchanged | `trickleResolved` |
| 5 | P1 | spawn / actions / pass | normal turn | normal notifications |
| 6 | server | endOfTurnCleanup | normal; **no game-end trigger from bag emptiness** | `turnEnded` |
| 7 | (later) | If a P1 retire mid-game has Analyst-bonus condition satisfied while bag empty | bonus skipped per [D-18] §6.12 effect 1; retire scoring still proceeds for the held intel | `agentRetired{is_analyst_bonus_pending:false}` (no `analystBonusDrawn`) |

**Assertions**:
- Step 1–2: no new rows transition into `state='on_board'` from the bag.
- Step 4: tiles on the board still trickle / return-to-bag normally (any tile leaving the board off-bottom DOES go back to bag, increasing bag size — at which point subsequent trickle draws are no longer skipped).
- Step 6: `globals.game_winner == NULL` (no special end condition from empty bag).
- Step 7: no Analyst bonus tile is drawn; player's `analyst_keep_decision` payload is irrelevant if bag was empty at trigger time.

---

## Section 3 — Illegal-Action Quick-Test List

This compact table enumerates illegal action attempts the server must reject.
Each row pairs an action name (camelCase per STATE_MACHINE §3) with a single
illegal-input scenario and the expected error code/category. Server returns
the error and applies **no state mutation** (STATE_MODEL §7).

| # | Action | Illegal input | Expected error category |
|---|--------|---------------|--------------------------|
| 1 | `actSpawnAgent` | target hex is not on ✦ row | `ERR_INVALID_HEX` (§6.1) |
| 2 | `actSpawnAgent` | target ✦ hex contains loose intel | `ERR_HEX_OCCUPIED` (§6.1, §9.8) |
| 3 | `actSpawnAgent` | active player already has 3 on board | `ERR_SPAWN_CAP` (§6.1) |
| 4 | `actSpawnAgent` | `agent_id_from_pool` belongs to opponent | `ERR_OWNERSHIP` (§6.1) |
| 5 | `actMoveAgent` | target hex non-adjacent | `ERR_NOT_ADJACENT` (§6.3) |
| 6 | `actMoveAgent` | target hex contains opposing agent | `ERR_HEX_OCCUPIED` (§6.3) |
| 7 | `actMoveAgent` | target hex has blockade | `ERR_BLOCKADE` (§6.3) |
| 8 | `actMoveAgent` | source agent is pinned | `ERR_PINNED` (§9.5) |
| 9 | `actMoveAgent` | target hex outside Field | `ERR_OFF_FIELD` (§6.3) |
| 10 | `actMoveAgent` | `actions_remaining == 0` | `ERR_NO_ACTIONS` |
| 11 | `actTransferIntel` | source/target not adjacent | `ERR_NOT_ADJACENT` (§6.4) |
| 12 | `actTransferIntel` | source==target | `ERR_SELF_TARGET` (§9.12) |
| 13 | `actTransferIntel` | target agent owned by opponent | `ERR_OWNERSHIP` (§6.4) |
| 14 | `actTransferIntel` | intel_id not in source.intel_held | `ERR_INTEL_NOT_HELD` |
| 15 | `actRetireAgent` | agent.spawned_on_turn == current turn_id | `ERR_SAME_TURN_RETIRE` (§6.5) |
| 16 | `actRetireAgent` | agent.hex is not ✦ row | `ERR_NOT_ON_SPAWN_ROW` (§6.5) |
| 17 | `actRetireAgent` | agent is pinned | `ERR_PINNED` (§9.5) |
| 18 | `actEngineerPlaceBlockadeAdjacent` | non-adjacent target | `ERR_NOT_ADJACENT` (§6.6.A) |
| 19 | `actEngineerPlaceBlockadeAdjacent` | active player already has 3 blockades on board | `ERR_BLOCKADE_CAP` ([D-04]) |
| 20 | `actEngineerPlaceBlockadeAnywhere` | intel_id not on this engineer | `ERR_INTEL_NOT_HELD` (§6.6.B) |
| 21 | `actSmugglerBoostActions` | `smuggler_boost_used_this_turn == true` | `ERR_BOOST_ALREADY_USED` ([D-08]) |
| 22 | `actSmugglerSwapAgents` | either agent is pinned | `ERR_PINNED_TARGET` (§6.8, FAQ) |
| 23 | `actSmugglerSwapAgents` | agent_a == agent_b | `ERR_SELF_TARGET` (§9.12) |
| 24 | `actCommsMoveIntelUp` | target hex contains an agent | `ERR_HEX_OCCUPIED` ([D-09]) |
| 25 | `actCommsMoveIntelUp` | target hex has blockade | `ERR_BLOCKADE` (§6.9.A) |
| 26 | `actCommsMoveIntelUp` | target intel is held by an agent (not loose) | `ERR_NOT_LOOSE` ([D-09]) |
| 27 | `actCommsMoveIntelDown` | paid_intel == target_intel | `ERR_SELF_PAY` (§9.12) |
| 28 | `actCommsMoveIntelDown` | target hex off the bottom of Field | `ERR_OFF_FIELD` ([C-02] default) |
| 29 | `actDoubleAgentTransfer` | source.id == target.id | `ERR_SELF_TARGET` (§6.10) |
| 30 | `actHackerPin` | target agent owned by active player | `ERR_OWNERSHIP` (§6.11.A) |
| 31 | `actHackerPin` | target already pinned | `ERR_ALREADY_PINNED` ([D-06b]) |
| 32 | `actHackerPin` | hacker.hacker_pin_used_this_turn==1 | `ERR_PIN_USED` ([D-15]) |
| 33 | `actHackerUnpin` | target not pinned | `ERR_NOT_PINNED` (§6.11.B) |
| 34 | `actHackerUnpin` | shares slot — hacker_pin_used_this_turn==1 | `ERR_PIN_USED` ([D-15]) |
| 35 | `actHackerStealIntel` | target not pinned | `ERR_TARGET_NOT_PINNED` (§6.11.C) |
| 36 | `actHackerStealIntel` | hacker.hacker_steal_used_this_turn==1 | `ERR_STEAL_USED` ([D-15]) |
| 37 | `actHackerStealIntel` | paid_intel not in hacker.intel_held | `ERR_INTEL_NOT_HELD` |
| 38 | any phase-3 action | sent during phase=`spawn` | `ERR_WRONG_PHASE` (§3 mapping) |
| 39 | `actSpawnAgent` | sent during phase=`actions` | `ERR_WRONG_PHASE` |
| 40 | `actRetireAgent` (Analyst, held=3) | missing `analyst_keep_decision` payload | `ERR_MISSING_FIELD` (STATE_MACHINE §3.2) |

---

## Section 4 — Validation Invariants

Server-side invariants that **every** scenario must respect at every
post-action checkpoint. Implementations should expose a `runInvariants()`
debug helper and call it after each action in tests.

- **I1**: `COUNT(intel_tile) == 47` always (47 rows persist for game lifetime). [STATE_MODEL §1.2]
- **I2**: For every `intel_tile`: exactly one of `(hex_q, hex_r)`, `agent_id`, `scored_by` is non-null, matching `state` (`on_board` ↔ hex set; `on_agent` ↔ agent_id set; `scored` ↔ scored_by set; `in_bag`/`returned_to_bag` ↔ all three null). [STATE_MODEL §1.2 / §3.4]
- **I3**: `intel_tile` rows with `type='honeypot'` are **never** in `state='on_agent'` (held). Implementer must assert at every action exit. [§9.4 / EDGE I-04]
- **I4**: For each player P: `len(agent_pool) + len(agents_on_board) + len(agents_removed) == 12`. [STATE_MODEL §1.2 / D-10b]
- **I5**: For each player P: `COUNT(blockade WHERE owner=P AND state='on_board') <= 3`. [D-04]+[D-07]
- **I6**: At most one agent per hex (`COUNT(agent WHERE state='on_board' AND hex=H) <= 1`). [STATE_MODEL §1.2]
- **I7**: At most one blockade per hex (`COUNT(blockade WHERE state='on_board' AND hex=H) <= 1`). [STATE_MODEL §1.2]
- **I8**: For every agent on board: `len(intel_held) <= 3` AT REST (after capacity-dump fired). Transient excess is allowed only inside trickle/move handlers, never observable post-action. [§9.3]
- **I9**: `globals.actions_remaining` is in `0..3` if `smuggler_boost_used_this_turn=false`, else `0..4`. [§5.3, D-08]
- **I10**: After `endOfTurnCleanup`: `smuggler_boost_used_this_turn=false`, every agent's `hacker_pin_used_this_turn=0` and `hacker_steal_used_this_turn=0`, `globals.dice_state={}`. [§7.4 step 3]
