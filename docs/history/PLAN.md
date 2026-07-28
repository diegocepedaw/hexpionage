# Hexpionage → Board Game Arena: Multi-Agent Orchestration Plan

> **Historical document.** This is the original multi-agent build plan, kept as a
> record of intent. It refers to files and paths that were renamed or never
> produced (for example `specs/RULES.md`, which shipped as `docs/rulebook.md`).
> Do not use it to navigate the repo — see [`ONBOARDING.md`](../../ONBOARDING.md).

## Context

**Project**: Port the physical board game *Hexpionage* — a 2-player hex-grid game with stacking "intel" pieces, six agent archetypes with unique abilities, hidden bag draws, dice-driven "trickling" mechanics, blockades, pinning, and a 20-point race — to Board Game Arena via BGA Studio.

**Why this plan exists**: BGA implementations fail predictably when teams jump into PHP/JS code before formalizing the rules and the state machine. The trickling mechanic, intel stacking, blockade-pair interactions, and capacity-loss cascade in Hexpionage are exactly the kinds of mechanics where a rulebook ambiguity causes hours of rework once code is written. The goal here is to specify everything completely *before* anyone writes a `dbmodel.sql` line.

**Source artifacts (canonical, do not paraphrase from memory)**:
- Rulebook PDF: `/Users/dcepeda/Downloads/final_printing/rulebook/booklet_final_template_200x200mm.pdf`
- Rulebook page PNGs: `/Users/dcepeda/Downloads/final_printing/rulebook/rules_templated_nice_{01,02,03}.png`
- Rules FAQ: `/Users/dcepeda/Downloads/final_printing/Hexpionage Rules FAQ.md`
- Pre-production document: `/Users/dcepeda/Downloads/final_printing/Hexpionage pre-production document.docx`
- Punchboard PNGs (agents, intel, tokens): `/Users/dcepeda/Downloads/final_printing/punchboard/punchboard 1/finished individual tiles no shape/` and `.../punchboard 2/`
- Game board: `/Users/dcepeda/Downloads/final_printing/game board/game_board_print.png`

**Workspace strategy**: Create a sibling project directory `~/Documents/hexpionage-bga/` with subfolders `specs/`, `assets/`, `src/` (BGA Studio sync target), `tests/`, and `agents/` (this plan, agent prompts, decision log). Do not edit anything in `Downloads/final_printing/` — that is the print master.

---

## Owner Decisions Required Before Phase 1

These are the rulebook gaps and component questions that block specification. Treat as a numbered decision log; nothing in Phase 1 may proceed until these are answered. Capture answers in `agents/DECISIONS.md`.

| # | Decision | Why it blocks | Default if no answer |
|---|---|---|---|
| D-01 | **Agent roster**: Are there 6 agent types or 7? Asset set contains `analyst`, `doubleagent`, `engineer`, `hacker`, `smuggler`, AND `specialops` (`.png` files exist for both colors). Rulebook lists Comms Specialist. Is `specialops` the visual asset for Comms Specialist, or a distinct 7th agent? | Drives database `type_id` enum, sprite sheet, and ability table. | Treat `specialops` as the Comms Specialist artwork. **Block until verified.** |
| D-02 | **Player count**: Is Hexpionage strictly 2-player, or are 3–4 player rules drafted/intended? | Drives `gameinfos.inc.php players` array, scoring math, intel supply per player count. | 2-player only. |
| D-03 | **Tie-breaker**: If both players cross 20 points on the same turn, who wins? | End-game state must resolve deterministically. | Active player wins (they crossed first). **Confirm with owner.** |
| D-04 | **Blockade supply**: Each player has 3 blockade triangles. Is the supply hard-capped at 3 placed simultaneously, 3 lifetime, or refreshable? Can blockades be removed mid-game? | Engineer ability legality check, UI counter, end-game pruning. |
| D-05 | **Honeypot placement**: Is Honeypot drawn from the bag like other intel and placed via the standard top-row draw? Does it trickle? Does an agent moving onto a Honeypot also get removed, or only at end-of-trickle? | Notification semantics + state machine resolution order. |
| D-06 | **Pin lifecycle**: When does a pin clear? End of pinning player's next turn? End of pinned player's next turn? When the Hacker moves/dies? Can multiple pins stack on one agent? | State table column on agents. |
| D-07 | **Blockade removability**: Can blockades be removed at all? By whom, at what cost? | Engineer reverse action existence. |
| D-08 | **Smuggler boost stacking**: Can two Smugglers each grant +1 action (4 → 5)? Can the same Smuggler's intel grant the boost more than once? | Action-counter cap and validation rule. |
| D-09 | **Comms Specialist target**: Can it move intel that is currently on an agent's hex (held intel)? FAQ implies no, rulebook unclear. | Click-target highlighting on the frontend. |
| D-10 | **Spawn limit semantics**: Up to 3 agents *per turn*, or up to 3 agents *over the game*? Does a retired agent return to the spawn pool? | Database `state` column on agent rows; affects long-game balance. |
| D-11 | **Score reveal**: Rulebook hint that score is hidden until 20 — confirm; if hidden, BGA must reveal score only on game-end notification. | Public-vs-private split in `getAllDatas()`. |
| D-12 | **Asset license**: Is the owner OK uploading the existing PNG art to BGA, or is BGA-licensed art required? Does the publisher's BGG ID exist? | `gameinfos.inc.php` and BGA submission. |
| D-13 | **Variants/options**: Any rule variants the owner wants exposed via `gameoptions.json` (timer, beginner mode, etc.)? | Optional, but easier to plan up front than retrofit. |

> **Rule**: Every agent in this plan must refuse to invent a rule when an owner decision is missing. They must emit a `TODO(D-NN)` and stop, not guess.

---

## Deliverable 1 — BGA Implementation Recon

### 1.1 What a BGA game requires (concise)

| Layer | Files | Purpose |
|---|---|---|
| Metadata | `gameinfos.inc.php`, `gameoptions.json`, `gamepreferences.json`, `material.inc.php`, `stats.inc.php` | Player count, options, sprite refs, statistics |
| Backend | `hexpionage.game.php`, `hexpionage.action.php` (or modern PHP attribute-routed actions), `dbmodel.sql` | Action handlers, validation, DB schema |
| State machine | `states.inc.php` | Named states, transitions, possible actions, args functions |
| Frontend logic | `hexpionage.js`, modules under `modules/js/` | Setup, notifications, click handlers, animations |
| Frontend layout | `hexpionage_hexpionage.tpl` (template), `hexpionage.css`, `modules/css/` | DOM skeleton, hex grid, sprites |
| i18n | strings extracted via `_()` / `clienttranslate()` | English-first, translatable |

### 1.2 Constraints that materially shape Hexpionage

- **Stateless PHP**: a fresh `Game` instance is constructed per request. All persistent state must live in the DB or `bga->globals`. → Hexpionage's per-turn action counter, current phase, and dice results all need to round-trip the DB.
- **Server-authoritative**: client validation is UX only. Every action handler in `hexpionage.action.php` re-validates legality. → Every illegal-move test from the QA agent must pass *server-side*.
- **Hidden information discipline**: `getAllDatas()` and `notify->all` must never include the supply bag contents or upcoming draws. → Bag is a server-side multiset; only the 2 drawn pieces per turn are revealed.
- **Notifications drive animations**: each notification is a discrete animation step on the client. → A trickle resolution will fire a sequence of notifications: `intelDrawn`, `diceRolled`, `intelTrickled` (per-piece or batched), `agentRemovedHoneypot`, `agentDumpedOvercapacity`.
- **`bga_rand`** is the only RNG. → Bag draws and dice rolls go through it. Reproducibility for replay is automatic.
- **Z-index < 900**, single CSS file, sprite sheets preferred. → Asset pipeline must produce sprite sheets, not 40+ individual PNG requests.
- **Hex grids are not officially supported** — no built-in component. Community pattern is CSS Grid with offset rows or absolute positioning with axial `(q, r)` coordinates. → Frontend agent must pick one and stick with it.
- **Undo**: opt-in via `db_undo_support`. Hexpionage actions are mostly idempotent within the action phase (place agent, take action) but trickling resolution should not be undoable mid-resolution. → Wrap the entire trickle phase in a single transaction; expose undo only for action-phase choices.

### 1.3 Implementation checklist (Hexpionage-specific)

- [ ] `dbmodel.sql`: tables for `agents`, `intel_on_board`, `intel_on_agent`, `blockades`, `bag`, `pins`, `dice_state`, plus columns on `player`.
- [ ] `gameinfos.inc.php`: 2 players (or pending D-02), undo enabled.
- [ ] `states.inc.php`: states for `gameSetup`, `trickleDraw`, `trickleRoll`, `trickleResolve`, `spawn`, `actions`, `actionResolution`, `endTurn`, `gameEnd`.
- [ ] Server: `bga_rand` for bag draws + dice; transactional trickle resolver.
- [ ] Client: hex grid renderer, sprite sheet for agents/intel, action button bar driven by state args.
- [ ] Notifications: ≤8 distinct names. Discipline against per-hex notification spam.
- [ ] Stats: track per-player intel scored, agents retired, blockades placed, pins applied, honeypots stepped on, average actions/turn.

### 1.4 Common pitfalls Hexpionage will hit

1. **Trickle order leaks bag**: if trickle resolves intel one-by-one with a notification per move, a careful spectator can deduce upcoming draws. Mitigation: resolve trickle server-side, send one batched `trickleResolved` notification with all moves.
2. **Stacking visual ambiguity**: piles of intel on one hex must be readable. Mitigation: fan-out CSS or counter badges; tooltip lists contents.
3. **Capacity-loss timing**: rulebook order is *trickle → honeypot removal → over-capacity dump*. Coding it as one pass over agents will produce wrong scores. Mitigation: three discrete server passes, three notifications, each animatable.
4. **Pinned-but-acting agents**: easy to forget pinned agents still use abilities. Mitigation: state-machine assertion + adversarial test.
5. **Smuggler boost**: 3-action vs 4-action is a state-args concern. Mitigation: action counter is a single integer recomputed every action; boost decrements a separate "intel cost paid this turn" tracker.

---

## Deliverable 2 — Game Understanding Package

This deliverable is the *output of the Rules Formalization Agent*, not embedded prose here. The agent produces `specs/RULES.md` (formal spec) and `specs/RULES_INDEX.md` (rule-id → rulebook page citation). What the package must contain:

1. **Component inventory** with asset filenames (cross-referenced to `assets/MANIFEST.md`).
2. **Setup procedure** — exact bag composition, spawn-row designation, first-player rule (D-03 dependent).
3. **Phase-by-phase turn structure** — three top-level phases (Trickle, Spawn, Actions), with sub-steps numbered for state-machine reference (e.g., `R-1.3` = "Phase 1 step 3 = roll all 6 dice").
4. **Agent ability table** — name, color, cost, target, preconditions, effect, post-effect, cited rulebook page. One row per ability variant (e.g., Engineer has two cost variants → two rows).
5. **Intel rules** — types, value, movement (passive trickle vs active Comms move), stacking, blockade interaction, capacity rule.
6. **Win/loss + tie-breaker** (D-03).
7. **Hidden vs public split** — the table in §1.4 of the BGA primer, applied per-entity.
8. **Edge cases** — every FAQ point with a unique ID (`E-01`, `E-02`, …) so the QA agent can write tests against them.
9. **Ambiguity register** — direct mirror of the Owner Decisions table above; agents must update it as new ambiguities surface.
10. **Hard-to-digitize list** — physical mechanics that need explicit digital semantics (simultaneous trickle, stacking visuals, dice rolling theatre).
11. **UI-required rules** — anything that needs visible affordances (action counter, intel counter on agents, pin marker, blockade marker, dice display).
12. **Backend-validated rules** — every preconditioned action (movement legality, blockade legality, pin legality, capacity check).

> The Rules Formalization Agent must never paraphrase a rule without a `[p.X]` citation tag.

---

## Deliverable 3 — Multi-Agent Architecture

11 specialized agents. Each spec below: **Role / Inputs / Outputs / Scope / Must-not / Validation / Dependencies / Parallelism**.

> All agents read from `specs/` and `agents/DECISIONS.md`. All agents write to `specs/` (or their dedicated subfolder). Agents must not write into `src/` until Phase 3.

### A1. BGA Platform Research Agent
- **Role**: Authoritative source on BGA Studio capabilities, file layout, naming conventions, and current platform constraints.
- **Inputs**: BGA doc URLs (listed in §1 of original brief), current BGA Cookbook revision, any reference game source the owner can share.
- **Outputs**: `specs/BGA_PRIMER.md` (the §1 of this plan, expanded), `specs/BGA_CHECKLIST.md` (file-by-file what we must produce), `specs/BGA_PATTERNS.md` (selected reusable patterns: hex grid, hidden draw, undo).
- **Scope**: BGA platform only.
- **Must not**: Recommend a Hexpionage-specific implementation. Cite docs for every claim. Mark `[NOT CONFIRMED]` for anything not in the docs.
- **Validation**: Every claim has a doc URL or `[NOT CONFIRMED]` tag. Owner spot-checks 3 random claims against the actual docs.
- **Dependencies**: None.
- **Parallelism**: Runs in parallel with A2 and A3.

### A2. Rules Formalization Agent
- **Role**: Convert rulebook + FAQ into a machine-readable spec.
- **Inputs**: Rulebook PDF, FAQ, pre-production document, `agents/DECISIONS.md`.
- **Outputs**: `specs/RULES.md`, `specs/RULES_INDEX.md`, `specs/AMBIGUITIES.md` (delta-only updates to the decision log).
- **Scope**: Rules only. No implementation guidance, no UI prescription.
- **Must not**: Invent rules. Use `TODO(D-NN)` for unresolved items. Cite every rule with rulebook page or FAQ section.
- **Validation**: QA Agent (A9) cross-checks every rule statement against the rulebook PDF. Spot-check by owner on 5 randomly selected rules.
- **Dependencies**: A1 (for terminology of states/actions only).
- **Parallelism**: Runs in parallel with A3 after A1 starts.

### A3. Component & Asset Audit Agent
- **Role**: Inventory the print assets, classify them as web-ready / needs-work / missing, and design the asset pipeline.
- **Inputs**: All directories under `Downloads/final_printing/`, plus BGA sprite-sheet conventions from A1.
- **Outputs**: `assets/MANIFEST.md` (every file: name, size, source, target use, transformation needed), `assets/PIPELINE.md` (build script: PSD → PNG → sprite sheet, with target dimensions), `assets/MISSING.md` (assets to design from scratch — score tracker, dice faces, action counter, turn indicator).
- **Scope**: Visual assets only.
- **Must not**: Touch source PSDs. Recommend art changes. Reference rules (refer to RULES.md).
- **Validation**: Owner approves the missing-assets list. Pipeline script is dry-run-able without modifying source files.
- **Dependencies**: None to start; depends on A2 only for terminology cross-check.
- **Parallelism**: Runs in parallel with A1, A2.

### A4. Game State Model Agent
- **Role**: Design the canonical backend representation (DB schema + in-memory model).
- **Inputs**: `specs/RULES.md`, `specs/BGA_PRIMER.md`, decision log.
- **Outputs**: `specs/STATE_MODEL.md` containing:
  - DDL for `dbmodel.sql` (tables, columns, indexes, FK comments).
  - Object model diagram (logical entities: Agent, Intel, Blockade, Pin, Bag, Dice, Score).
  - Public-vs-private state matrix per entity.
  - `getAllDatas()` shape (TypeScript-style schema).
  - Derived state list (e.g., "agents with >3 intel" is computed, not stored).
  - Persistence + serialization rules (JSON for `bga->globals`).
- **Scope**: Server-side state only.
- **Must not**: Define state machine transitions (that's A5). Touch frontend.
- **Validation**: Round-trip test: every rule in `specs/RULES.md` can be expressed as a query against this schema. A11 (Integration Review) signs off.
- **Dependencies**: A1, A2.
- **Parallelism**: Sequential after A1, A2; parallel with A6.

### A5. State Machine Agent
- **Role**: Design BGA states and transitions.
- **Inputs**: `specs/RULES.md`, `specs/STATE_MODEL.md`, `specs/BGA_PRIMER.md`.
- **Outputs**: `specs/STATE_MACHINE.md` containing:
  - State diagram (Mermaid or DOT) with: `gameSetup → playerTurn (=trickleDraw → trickleRoll → trickleResolve → spawn → actions → endTurn) → next player or gameEnd`.
  - State table: name, type (game/active/multi-active/end), description, args, possibleactions, transitions, on-entering callback contract, on-leaving callback contract.
  - End-game detection rule (D-03 dependent).
  - Undo policy per state.
  - Zombie/timeout policy per state.
- **Scope**: State machine only. Does not implement.
- **Must not**: Specify handler bodies. Specify UI.
- **Validation**: Every legal action in `specs/RULES.md` maps to exactly one `(state, action)` pair. Every state has at least one outbound transition or is the end state. A11 signs off.
- **Dependencies**: A4.
- **Parallelism**: Sequential after A4; parallel with A6.

### A6. UI/UX Mapping Agent
- **Role**: Translate the physical interaction model into a BGA frontend spec.
- **Inputs**: `specs/RULES.md`, `assets/MANIFEST.md`, `specs/STATE_MACHINE.md`.
- **Outputs**: `specs/UI_SPEC.md` containing:
  - ASCII/SVG layout for desktop + tablet (hex board, side panels, action bar).
  - Hex grid technique decision (CSS Grid with offset rows vs absolute axial, with rationale).
  - Click model per state (what's clickable, what shows hover preview, what shows tooltip).
  - Drag-vs-click decision (recommended: click-source-then-click-target for movement; drag for spawn).
  - Action bar contract: which buttons in which state, button enable/disable rules.
  - Tooltip + help text per agent ability.
  - Mobile/responsive: minimum width, breakpoints, what gets stacked.
  - Animation list with timing budget (slide ≤300ms, fade ≤200ms).
- **Scope**: UI design only. No code.
- **Must not**: Reinvent BGA dialogs. Use BGA standard components (Counter, etc.) where they fit.
- **Validation**: Every state in `specs/STATE_MACHINE.md` has a corresponding screen description. Owner approves a paper prototype.
- **Dependencies**: A2, A3, A5.
- **Parallelism**: Sequential after A5.

### A7. Backend Implementation Agent
- **Role**: Implement PHP server logic.
- **Inputs**: All specs, especially `STATE_MODEL.md`, `STATE_MACHINE.md`, and the notifications contract derived in A11.
- **Outputs**: `src/dbmodel.sql`, `src/hexpionage.game.php`, `src/states.inc.php`, `src/material.inc.php`, action handlers (modern attribute-routed or `*.action.php`).
- **Scope**: Backend only.
- **Must not**: Add features not in spec. Skip server-side validation. Use `RAND()` instead of `bga_rand`. Send private data via `notify->all`.
- **Validation**:
  - Every action handler validates pre/post-conditions from `RULES.md`.
  - QA Agent illegal-action tests pass (server returns errors, no state mutation).
  - Trickle resolver is fully transactional.
  - Linter + PHPStan clean.
- **Dependencies**: A4, A5, A11 contract.
- **Parallelism**: Backend can begin once A4+A5 are signed off, even if frontend isn't ready.

### A8. Frontend Implementation Agent
- **Role**: Implement JS/CSS/HTML.
- **Inputs**: `specs/UI_SPEC.md`, `assets/PIPELINE.md` outputs (sprite sheets), notifications contract.
- **Outputs**: `src/hexpionage.js`, `src/hexpionage.css`, `src/modules/js/*`, `src/hexpionage_hexpionage.tpl`, image build artifacts.
- **Scope**: Frontend only.
- **Must not**: Add client-side rules logic that the server doesn't enforce. Use jQuery (dojo/vanilla only). Exceed z-index 900.
- **Validation**:
  - Every notification from backend has a handler.
  - Every state's `onEnteringState` matches `UI_SPEC`.
  - Manual test in BGA Studio test table passes the playtest agent's scenarios.
- **Dependencies**: A6, A7's notification contract.
- **Parallelism**: Can scaffold in parallel with A7 once UI_SPEC is signed off; full integration sequential.

### A9. Rules QA / Adversarial Agent
- **Role**: Stress-test the spec and (later) the implementation against the rulebook + FAQ.
- **Inputs**: `specs/RULES.md`, FAQ, decision log.
- **Outputs**:
  - Phase 2 (pre-impl): `specs/QA_SPEC_REVIEW.md` — discrepancies, missing rules, contradictions, illegal-action test cases, edge-case scenarios.
  - Phase 4 (post-impl): `tests/QA_REPORT.md` — pass/fail per scenario, with reproduction steps.
- **Scope**: Adversarial. Try to break things.
- **Must not**: Propose fixes. Only identify problems and own decisions.
- **Validation**: Owner spot-checks at least 5 raised issues for legitimacy.
- **Dependencies**: A2 for the spec review; A7+A8 for the impl review.
- **Parallelism**: Spec review parallel with A4–A6. Impl review sequential after A7+A8.

### A10. Playtest Simulation Agent
- **Role**: Generate representative game flows + scripted test cases.
- **Inputs**: `specs/RULES.md`, `specs/STATE_MACHINE.md`.
- **Outputs**: `tests/SCENARIOS.md` containing:
  - Scripted setups (`SCENARIO-01: opening turn`, `SCENARIO-02: trickle with stacked intel`, `SCENARIO-03: honeypot + capacity dump combo`, …).
  - For each: starting state JSON, action sequence, expected state after each action, expected notifications.
  - Edge case coverage list mapped to `RULES.md` rule IDs.
- **Scope**: Test design.
- **Must not**: Run tests. Implement.
- **Validation**: Coverage matrix shows every rule ID is exercised by at least one scenario.
- **Dependencies**: A2, A5.
- **Parallelism**: Parallel with A6.

### A11. Integration Review Agent
- **Role**: Cross-check that all specs and implementations agree.
- **Inputs**: All spec docs and (later) the codebase.
- **Outputs**:
  - Phase 2: `specs/CONTRACT.md` — the canonical notification contract (notification names, payload shape, who-sees-it). Signed off before A7 begins.
  - Phase 4: `specs/INTEGRATION_REPORT.md` — final readiness checklist with pass/fail per item.
- **Scope**: Cross-cutting consistency only.
- **Must not**: Author rules or UI. Only verify alignment.
- **Validation**: Final report has a green check on every readiness item, or explicit deferral with rationale.
- **Dependencies**: All.
- **Parallelism**: Spec phase: parallel with A6–A10 final review. Impl phase: sequential post-A7+A8.

---

## Deliverable 4 — Execution Plan (Phases 0–5)

Each phase lists: Agents, Inputs, Outputs, Human Review Checkpoint, Parallel Work, Blocking Dependencies, Definition of Done.

### Phase 0 — Recon
- **Agents**: A1, A2 (initial pass), A3.
- **Inputs**: Brief, source files.
- **Outputs**: `specs/BGA_PRIMER.md`, `specs/BGA_CHECKLIST.md`, `specs/RULES.md` v0.1 (rough), `assets/MANIFEST.md`, decision log seeded with D-01–D-13.
- **Human review**: Owner answers all `D-NN` items. **Hard gate.**
- **Parallel**: A1, A2, A3 all run in parallel from the start.
- **Blocking**: Decision answers.
- **Definition of done**: All decisions answered or explicitly deferred; primer + manifest + rules-v0.1 exist.

### Phase 1 — Specification
- **Agents**: A2 (final), A4, A5, A6, A10, A11 (contract).
- **Inputs**: Phase 0 outputs + decisions.
- **Outputs**: `specs/RULES.md` v1.0, `specs/STATE_MODEL.md`, `specs/STATE_MACHINE.md`, `specs/UI_SPEC.md`, `tests/SCENARIOS.md`, `specs/CONTRACT.md`.
- **Human review**: Owner reads RULES.md cover-to-cover; reviews state diagram; approves UI mockups.
- **Parallel**: After A2 v1.0 lands → A4 and A6 parallel; A5 starts when A4 lands; A10 parallel with A5; A11 contract drafts after A5.
- **Blocking**: A2 v1.0 blocks A4. A4 blocks A5. A5 + A6 block A11 contract.
- **Definition of done**: Every rule has a state-machine home; every action has a DB mutation; every notification is in the contract; UI spec has a screen per state.

### Phase 2 — Validation
- **Agents**: A9 (spec review), A11 (cross-check).
- **Inputs**: All Phase 1 outputs.
- **Outputs**: `specs/QA_SPEC_REVIEW.md`, updates to `specs/AMBIGUITIES.md`, signed-off `specs/CONTRACT.md`.
- **Human review**: Owner adjudicates every issue A9 raises (`fix in spec` / `accept` / `defer with TODO`).
- **Parallel**: A9 and A11 run in parallel.
- **Blocking**: Phase 1 done.
- **Definition of done**: Zero unresolved discrepancies between specs; owner sign-off on CONTRACT.md; no `TODO(D-NN)` remaining without explicit deferral.

### Phase 3 — Implementation
- **Agents**: A7 (backend), A8 (frontend), supported by A3 (asset finalization).
- **Inputs**: All signed-off specs.
- **Outputs**: Working `src/` codebase deployed to BGA Studio test environment; sprite sheets in `src/img/`.
- **Human review**: Mid-phase: owner plays a scripted scenario from `SCENARIOS.md` end-to-end; flags blockers. End-of-phase: full code review.
- **Parallel**: A7 and A8 mostly parallel after CONTRACT.md is final. Asset pipeline (A3) finalization parallel with both.
- **Blocking**: CONTRACT.md must be signed off. Schema (A4) must be final.
- **Definition of done**: Game playable end-to-end in BGA Studio for one happy-path scenario; all notifications wired; PHPStan + JS lint clean.

### Phase 4 — Testing
- **Agents**: A9 (impl review), A10 (scenario execution), A11 (integration report).
- **Inputs**: Phase 3 build, `tests/SCENARIOS.md`.
- **Outputs**: `tests/QA_REPORT.md`, `specs/INTEGRATION_REPORT.md`, bug list with severity.
- **Human review**: Owner co-drives a multiplayer test session in BGA Studio.
- **Parallel**: A9 (illegal actions, edge cases) parallel with A10 (full scenarios). A11 final after both.
- **Blocking**: Phase 3 done.
- **Definition of done**: Every scenario in `SCENARIOS.md` passes; every illegal-action test returns a server error; hidden info verified by inspecting network payloads; integration report green.

### Phase 5 — Polish & Submission
- **Agents**: A6 (UX polish), A8 (frontend polish), A1 (submission checklist).
- **Inputs**: Phase 4 outputs.
- **Outputs**: i18n strings extracted; help text written; accessibility audit (color-blind palette, keyboard nav where feasible); performance check (DOM node count on a full board, animation FPS); BGA submission package.
- **Human review**: Owner reads help text, plays a complete game, signs off.
- **Parallel**: i18n + a11y + perf in parallel.
- **Blocking**: Phase 4 done.
- **Definition of done**: BGA pre-release request submitted with link to test table.

---

## Deliverable 5 — Agent Prompt Templates

Each prompt is copy-pasteable. Replace `{{...}}` with actual paths. All prompts share preamble: *"Operate in the `~/Documents/hexpionage-bga/` workspace. Read `agents/DECISIONS.md` first; for any unresolved decision, emit `TODO(D-NN)` and stop — do not invent rules."*

### A1 — BGA Platform Research Agent

> You are the **BGA Platform Research Agent** for the Hexpionage port. Your job is to produce a complete, citation-backed primer on Board Game Arena Studio.
>
> **Files to read**: official BGA docs (URLs in `agents/SOURCES.md`). Use WebFetch.
>
> **Produce**:
> 1. `specs/BGA_PRIMER.md` — sections: project skeleton, backend (PHP), state machine, notifications, frontend (JS/CSS), hex grid patterns (mark `[NOT CONFIRMED]` if community-only), hidden info, multiplayer sync, testing, pitfalls, submission.
> 2. `specs/BGA_CHECKLIST.md` — table: file → purpose → required-or-optional → notes.
> 3. `specs/BGA_PATTERNS.md` — three patterns Hexpionage will reuse: (a) hex grid layout, (b) hidden bag draw, (c) batched notification for atomic resolution.
>
> **Do not**: write Hexpionage-specific code or rules. Reference the doc URL for every claim. Mark uncertainty.
>
> **Output format**: markdown, 2000–4000 words across the three files.
>
> **Validation**: pick 3 random claims; for each, the cited URL must contain the claim verbatim or as a clear paraphrase.

### A2 — Rules Formalization Agent

> You are the **Rules Formalization Agent**. Convert the human-readable rulebook + FAQ into a machine-readable spec.
>
> **Files to read** (in order):
> 1. `/Users/dcepeda/Downloads/final_printing/rulebook/booklet_final_template_200x200mm.pdf` — page-by-page using the Read tool's `pages` parameter.
> 2. `/Users/dcepeda/Downloads/final_printing/Hexpionage Rules FAQ.md`.
> 3. `/Users/dcepeda/Downloads/final_printing/Hexpionage pre-production document.docx` (extract via `unzip -p` or `textutil`).
> 4. `agents/DECISIONS.md`.
>
> **Produce**:
> - `specs/RULES.md` — sections: Components, Setup, Turn structure (with rule IDs `R-1.1`, `R-1.2`, …), Agents (one row per ability), Intel system, Win condition, Hidden vs public, Edge cases (`E-NN`).
> - `specs/RULES_INDEX.md` — alphabetic index of rule IDs → rulebook page.
> - `specs/AMBIGUITIES.md` — append-only log of rules silent or contradictory; reference the decision IDs.
>
> **Do not**: invent rules. Paraphrase without citing a page. Touch `src/`. Recommend implementations.
>
> **Citation rule**: every rule statement ends with `[p.N]` (rulebook) or `[FAQ]`.
>
> **Output format**: markdown, ≤5000 words across files.

### A3 — Component & Asset Audit Agent

> You are the **Component & Asset Audit Agent**.
>
> **Files to read**:
> - All directories under `/Users/dcepeda/Downloads/final_printing/` recursively (use `ls`, do not open binaries).
> - `specs/RULES.md` Components section, for naming.
>
> **Produce**:
> - `assets/MANIFEST.md` — table per file: relative path, byte size, format, source kind (PNG/PSD/PDF/SVG), intended use (BGA sprite / unused), transformation needed (none / crop / resize / regenerate).
> - `assets/PIPELINE.md` — shell script + dependency list for: PSD → flattened PNG, PNG → sprite sheets at target dimensions, manifest validation. Script must be dry-run-able.
> - `assets/MISSING.md` — list of assets the rules require but no source provides (score tracker 0–20, dice faces, action counter UI, turn indicator, blockade-pair preview overlay).
>
> **Do not**: open or edit source files. Recommend rules-related changes.
>
> **Validation**: Manifest file count matches `find Downloads/final_printing -type f | wc -l`.

### A4 — Game State Model Agent

> You are the **Game State Model Agent**.
>
> **Files to read**: `specs/RULES.md`, `specs/BGA_PRIMER.md`, `agents/DECISIONS.md`.
>
> **Produce** `specs/STATE_MODEL.md` with:
> 1. DDL (`CREATE TABLE ... IF NOT EXISTS ... ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`) for: `agent`, `intel_on_board`, `intel_on_agent`, `blockade`, `pin`, `bag`, `dice` (or globals-only — pick one and explain), `turn_state` (action counter, smuggler-boost flag).
> 2. Player table extensions (score, agents-in-supply, blockades-in-supply).
> 3. Logical entity diagram.
> 4. Public/private matrix per column.
> 5. `getAllDatas()` JSON shape.
> 6. Derived state list.
>
> **Do not**: write PHP code. Specify state transitions. Touch frontend.
>
> **Validation**: every rule in `specs/RULES.md` has a queryable answer. Run a manual checklist against R-IDs.

### A5 — State Machine Agent

> You are the **State Machine Agent**.
>
> **Files to read**: `specs/RULES.md`, `specs/STATE_MODEL.md`, `specs/BGA_PRIMER.md`.
>
> **Produce** `specs/STATE_MACHINE.md` with:
> 1. Mermaid state diagram covering: `gameSetup → trickleDraw → trickleRoll → trickleResolve → spawn → actions ↻ (subactions) → endTurn → trickleDraw (next player) | gameEnd`.
> 2. State table: id, type, description, args contract, possibleactions list, transitions, on-entering, on-leaving, undo allowed (Y/N), zombie behavior.
> 3. End-game detection rule.
>
> **Do not**: write handler bodies. Specify UI.
>
> **Validation**: coverage matrix — every action in RULES.md maps to exactly one `(state, action)` pair; every state has ≥1 outbound transition or is the end state.

### A6 — UI/UX Mapping Agent

> You are the **UI/UX Mapping Agent**.
>
> **Files to read**: `specs/RULES.md`, `assets/MANIFEST.md`, `specs/STATE_MACHINE.md`.
>
> **Produce** `specs/UI_SPEC.md` with:
> 1. ASCII layout (desktop + tablet) for the main screen and any modals.
> 2. Hex grid technique decision (CSS Grid offset rows vs absolute axial). Pick one. Justify.
> 3. Per-state UI: what's shown, what's clickable, hover/click feedback, action button list, tooltips.
> 4. Animation list with timing budget.
> 5. Help-text drafts for each agent ability (≤2 sentences each).
> 6. Mobile breakpoints + responsive plan.
>
> **Do not**: write CSS/JS. Use non-BGA dialog patterns.
>
> **Validation**: every state in STATE_MACHINE.md has a screen description; every action in RULES.md has a click affordance.

### A7 — Backend Implementation Agent

> You are the **Backend Implementation Agent**.
>
> **Files to read**: all of `specs/`, especially `STATE_MODEL.md`, `STATE_MACHINE.md`, `CONTRACT.md`. Read existing scaffolding in `src/`.
>
> **Produce/edit**: `src/dbmodel.sql`, `src/hexpionage.game.php`, `src/states.inc.php`, `src/material.inc.php`, action handlers (modern attribute-routed).
>
> **Rules**:
> - Use `bga_rand` for all randomness. Never `RAND()`.
> - Every action handler validates pre/post-conditions from RULES.md.
> - `notify->all` carries no private data. Use `notify->player` for hands.
> - Trickle resolver is one transactional method emitting one `trickleResolved` notification with all moves batched.
> - Comment each handler with the rule ID(s) it implements.
>
> **Do not**: add features not in spec. Skip server-side validation. Mutate state in `getAllDatas()`. Write to `material.inc.php` from runtime.
>
> **Validation**: PHPStan level 5 clean; all illegal-action tests from QA agent return server errors with no state mutation; every rule in RULES.md is implementable in `<5` queries per action.

### A8 — Frontend Implementation Agent

> You are the **Frontend Implementation Agent**.
>
> **Files to read**: `specs/UI_SPEC.md`, `specs/CONTRACT.md`, `assets/PIPELINE.md` outputs.
>
> **Produce/edit**: `src/hexpionage.js`, `src/hexpionage.css`, `src/hexpionage_hexpionage.tpl`, `src/modules/js/*`, sprite-sheet build artifacts.
>
> **Rules**:
> - Vanilla JS or modern BGA framework only. No jQuery.
> - Single CSS file; z-index < 900.
> - Every notification in `CONTRACT.md` has a handler.
> - Every state in STATE_MACHINE.md has a matching `onEnteringState` branch.
> - No client-side rules logic that the server doesn't enforce; client is presentation only.
>
> **Do not**: animate longer than 300ms (snappy game feel). Add unbounded loops. Block on `await` chains during rapid replays.
>
> **Validation**: manual test in BGA Studio test table runs SCENARIO-01 through SCENARIO-05 from `tests/SCENARIOS.md` without console errors.

### A9 — Rules QA / Adversarial Agent

> You are the **Rules QA / Adversarial Agent**. Try to break the spec (Phase 2) and the implementation (Phase 4).
>
> **Phase 2 inputs**: `specs/RULES.md`, FAQ, decision log.
> **Phase 2 produce** `specs/QA_SPEC_REVIEW.md`:
> - Inconsistencies (rule X contradicts rule Y).
> - Missing edge cases (what if all 6 dice show "down" and a column is fully blockaded?).
> - Illegal-action test list (one per server-validated rule, ≥30 cases).
> - Ambiguities not yet captured in `DECISIONS.md`.
>
> **Phase 4 inputs**: deployed Studio table + Phase 2 lists.
> **Phase 4 produce** `tests/QA_REPORT.md`: pass/fail per case, repro steps, severity (S0–S3).
>
> **Do not**: propose fixes. Implement. Defer judgment on intent — flag and let owner decide.
>
> **Validation**: Owner spot-checks 5 raised issues; ≥4 must be legitimate.

### A10 — Playtest Simulation Agent

> You are the **Playtest Simulation Agent**.
>
> **Files to read**: `specs/RULES.md`, `specs/STATE_MACHINE.md`, `specs/STATE_MODEL.md`.
>
> **Produce** `tests/SCENARIOS.md`: at least 12 scenarios covering opening turn, trickle with stacking, blockade-pair edge cases, honeypot removal, capacity-loss cascade, smuggler boost, hacker pin + steal, double agent transfer, near-end-game (19 → 20 retire), illegal moves, undo flow, multi-player sync (P1 takes action, P2 sees correct notification).
>
> Each scenario includes: starting state JSON (compatible with STATE_MODEL.md), action sequence, expected state after each step, expected notifications.
>
> **Do not**: run tests. Implement.
>
> **Validation**: coverage matrix shows every R-ID and E-ID is exercised at least once.

### A11 — Integration Review Agent

> You are the **Integration Review Agent**.
>
> **Phase 1/2 produce** `specs/CONTRACT.md`: notification name → payload schema → recipients (all/player). One row per notification. Sign-off required before A7 begins.
>
> **Phase 4 produce** `specs/INTEGRATION_REPORT.md`:
> - Backend ↔ frontend notification mismatches.
> - State-machine ↔ rules disagreements.
> - UI ↔ state-machine mismatches.
> - Hidden-info leak audit (inspect network payloads of `getAllDatas` and every notification).
> - Final readiness checklist with ✅/❌ per item.
>
> **Do not**: author rules or UI. Suggest fixes (delegate to other agents via flagged TODOs).
>
> **Validation**: final report has zero ❌ or each ❌ has explicit deferral approved by owner.

---

## Deliverable 6 — Correctness Strategy

### 6.1 Rulebook is the source of truth (with FAQ override)
- **Rulebook**: highest priority. Every rule statement in `specs/RULES.md` is cited by page.
- **FAQ**: overrides rulebook on conflicts. Every FAQ-only rule cited as `[FAQ]`.
- **Decision log**: overrides FAQ where the FAQ is silent and the owner has decided. Cited as `[D-NN]`.
- **Pre-production doc**: never an authority. May surface design intent only.

### 6.2 Ambiguity tracking
- `specs/AMBIGUITIES.md` is append-only.
- Every entry has: ID, summary, where surfaced, current resolution (`OPEN` / `RESOLVED → see D-NN` / `DEFERRED → see X`).
- A2 and A9 are the primary writers; A11 audits for completeness.
- No agent may close an entry; only the owner.

### 6.3 Anti-invention discipline
- Every prompt template ends with the line: *"For any rule not in `specs/RULES.md`, emit `TODO(D-NN)` and stop. Do not invent."*
- A9's first deliverable is to grep all spec docs for unsupported claims (text not traceable to a rulebook page or FAQ entry).
- Code reviews check every comment and identifier against the rule index.

### 6.4 Cross-checks
| Cross-check | Owner agent | Trigger |
|---|---|---|
| State machine vs legal actions | A11 | After A5 lands |
| Schema vs state machine | A11 | After A4 + A5 land |
| UI spec vs state machine | A11 | After A6 lands |
| Backend vs schema | A11 | After A7 first build |
| Frontend vs notification contract | A11 | After A8 first build |
| Implementation vs rules | A9 | Every Phase 4 build |

### 6.5 Test categories (each must be explicit)
- **Legal-action tests**: every state's `possibleactions` × every legal input → state transitions correctly.
- **Illegal-action tests**: every server-validated rule has a negative test (illegal input → server error, no state mutation).
- **Hidden-info tests**: load network tab; assert no notification payload contains `bag` contents or other player's hidden state.
- **Endgame tests**: 20-point retire on first vs second player; tie scenario per D-03.
- **Visual completeness tests**: every entity in STATE_MODEL.md has a sprite or generated graphic in `assets/MANIFEST.md`.
- **Asset completeness audit**: A3 final pass — every required sprite is checked into `src/img/`.

### 6.6 Adversarial review cadence
- A9 reviews after **every** spec milestone, not only at phase end.
- A9 explicitly tries to construct illegal states (e.g., agent on blockaded hex, two pins on one agent if D-06 forbids).
- A9 verifies trickling order produces the FAQ-required outcome under contrived dice rolls.

### 6.7 Final integration review (gate to submission)
- A11's INTEGRATION_REPORT.md must be all-green.
- Owner manually plays 3 full games (one of which is multi-account).
- Hidden-info audit: open browser dev tools, inspect WebSocket payloads, confirm no leak.

---

## Deliverable 7 — Recommended Order of Operations

Concrete sequence with parallelism. Numbered steps; bracketed `[parallel: ...]` flags concurrent work.

1. **Phase 0 launch** — owner copies this plan to workspace; seeds `agents/DECISIONS.md` with D-01 through D-13; creates `~/Documents/hexpionage-bga/`.
2. **A1, A2 (rough), A3** run in parallel. [parallel: A1 ‖ A2-rough ‖ A3]
3. **Owner answers all D-NN decisions** based on A2-rough surfacing. **Hard gate.**
4. **A2 (final)** consumes decisions → `RULES.md` v1.0.
5. **A4 (state model)** runs.
6. **A5 (state machine)** runs after A4 lands. [parallel with A6 once it can start]
7. **A6 (UI spec)** runs after A5 lands.
8. **A10 (scenarios)** runs once A5 lands. [parallel: A6 ‖ A10]
9. **A11 drafts CONTRACT.md** after A5 + A6 land.
10. **A9 spec review** runs after RULES.md final + STATE_MACHINE.md exist. [parallel: A9 ‖ A6 final ‖ A10 ‖ A11 contract]
11. **Owner adjudicates A9 issues** → updates RULES.md / DECISIONS.md / STATE_MACHINE.md as needed. **Hard gate.**
12. **A11 signs off CONTRACT.md.** **Hard gate before any implementation.**
13. **A3 finalization** (sprite sheet build) parallel with A7+A8 starting. [parallel: A3-final ‖ A7 ‖ A8 scaffold]
14. **A7 backend** lands core schema + state machine + happy-path actions.
15. **A8 frontend** scaffold + notification handlers + hex board render. [parallel with A7]
16. **First playable build** in BGA Studio test table.
17. **A9 impl review** + **A10 scenario execution** in parallel. Bugs file against A7/A8.
18. **A7/A8 bug fixes** iterate.
19. **A11 INTEGRATION_REPORT.md** end-to-end.
20. **Owner full-game playthroughs** (≥3, one multi-account).
21. **Phase 5 polish** — i18n, a11y, perf — in parallel.
22. **Submission package** assembled by A1; owner submits to BGA pre-release.

**Critical-path summary**: A2 → A4 → A5 → CONTRACT → A7 → first build → fixes → INTEGRATION_REPORT → submission. Everything else runs alongside this spine.

---

## Appendix A — Critical Files (path quick-reference)

**Source artifacts (read-only)**
- `~/Downloads/final_printing/rulebook/booklet_final_template_200x200mm.pdf`
- `~/Downloads/final_printing/rulebook/rules_templated_nice_{01,02,03}.png`
- `~/Downloads/final_printing/Hexpionage Rules FAQ.md`
- `~/Downloads/final_printing/Hexpionage pre-production document.docx`
- `~/Downloads/final_printing/punchboard/punchboard 1/finished individual tiles no shape/`
- `~/Downloads/final_printing/punchboard/punchboard 2/`
- `~/Downloads/final_printing/game board/game_board_print.png`

**Workspace (writable)**
- `~/Documents/hexpionage-bga/agents/DECISIONS.md`
- `~/Documents/hexpionage-bga/agents/SOURCES.md` (URL list for A1)
- `~/Documents/hexpionage-bga/specs/{BGA_PRIMER,BGA_CHECKLIST,BGA_PATTERNS,RULES,RULES_INDEX,AMBIGUITIES,STATE_MODEL,STATE_MACHINE,UI_SPEC,CONTRACT,QA_SPEC_REVIEW,INTEGRATION_REPORT}.md`
- `~/Documents/hexpionage-bga/assets/{MANIFEST,PIPELINE,MISSING}.md`
- `~/Documents/hexpionage-bga/tests/{SCENARIOS,QA_REPORT}.md`
- `~/Documents/hexpionage-bga/src/` — BGA Studio code root.

## Appendix B — Verification (how to know the plan succeeded)

1. **Spec phase done**: every R-ID maps to a state, a schema mutation, a notification, and a UI affordance — `specs/CONTRACT.md` makes this traceable.
2. **Implementation done**: BGA Studio test table runs every scenario in `tests/SCENARIOS.md` without manual intervention; all illegal-action tests fail server-side; hidden-info audit shows zero leaks.
3. **Submission ready**: A1's `BGA_CHECKLIST.md` items all checked; owner signs off after a full multi-account playthrough.

End of plan.
