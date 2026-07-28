# Hexpionage → Board Game Arena — Project Onboarding

**Read this first.** It is the single entry point for the project: what it is, where
everything lives, what state it is in, how to run and test it, and what is left to do.

Last verified: all offline checks green (`./tools/check.sh`).

---

## 1. What this project is

Hexpionage is a 2-player hex-grid area-control/espionage board game
([BGG 307967](https://boardgamegeek.com/boardgame/307967/hexpionage)). This repo is a
port of it to [Board Game Arena](https://boardgamearena.com) (BGA).

- **Players**: exactly 2 (locked, decision D-02).
- **Board**: 44 hexes — 30 lavender "Field" hexes (rows of 6/7/8/9) plus 14 orange
  "intel rain" hexes above them. The bottom Field row (9 hexes) is the spawn row.
- **Pieces**: 12 agents per player (2 each of 6 types), 47 intel tiles, 3 blockades
  per player.
- **Win**: first to 20 points, or the opponent runs out of agents (depletion).

A turn is: *trickle* (intel rains down the board) → *spawn* → *3 actions* → *cleanup*.

The rules-of-record are `docs/rulebook.md` (implementation-grade, ~82 KB). Do not infer
rules from the code; the code implements the rulebook.

---

## 2. Where you are right now

| Phase | Status |
|---|---|
| 0–2  Specs, contracts, decisions | Complete |
| 3    Implementation (PHP + JS) | Complete |
| 3.5  Modern-framework refactor (post-HAL) | **Complete** |
| 3.6  Offline test harness + bug fixes | **Complete** |
| 4    BGA Studio test-table validation | **Not started — this is your next step** |
| 5    Polish, art, submission | Not started |

The project was previously written against BGA's *legacy* framework, then migrated
to the *modern* framework after a BGA Studio "dry run" (HAL) flagged the old layout.
`docs/history/HAL_DRY_RUN.md` is the record of that dry run and is still an accurate checklist of
what the modern framework requires. `docs/history/MORNING_BRIEFING.md` and `docs/history/PLAN.md` are useful
history but describe the **pre-refactor** file layout (`hexpionage.game.php`,
`hexpionage.js` at the root); those files no longer exist.

Everything that can be verified without BGA Studio now passes:

```
$ ./tools/check.sh
== PHP syntax ==            all files parse
== JS syntax ==             ok
== server/client contract   consistent (26 notifications, 18 actions, 10 states)
== cross-references         all 442 in-repo path references resolve
== rules engine             63 assertions passed, 0 failed, 0 playout failures
ALL CHECKS PASSED
```

---

## 3. Repository map

Top level, in the order you will care about it:

```
hexpionage/
├── README.md              GitHub landing page — what this is, quick start.
├── ONBOARDING.md          ← you are here. The full tour.
├── AGENTS.md              Working agreements for AI agents (CLAUDE.md points here).
│
├── src/                   THE DEPLOYABLE GAME. Contents map 1:1 to the BGA Studio
│                          project root (src/ itself is NOT uploaded). See below.
│
├── tools/                 Offline test rig — runs the real src/ PHP with no Studio.
│   ├── check.sh           Run everything. This is the command you will use most.
│   └── harness/           See tools/harness/README.md for internals.
│       ├── bga_stub.php       Minimal offline re-implementation of the BGA framework.
│       ├── engine.php         Offline state-machine driver.
│       ├── bot.php            Random-legal-move policy.
│       ├── run_tests.php      Assertions + N simulated games.
│       └── check_contract.php Static PHP↔JS contract cross-check.
│
├── docs/                  Specs and rules. The source of truth for behaviour.
│   ├── rulebook.md        Canonical rules spec (~82 KB). The code implements this.
│   ├── DECISIONS.md       Owner adjudications D-01..D-26. Cited all over the code.
│   ├── FAQ.md             The owner's rules FAQ.
│   ├── SOURCES.md         Where every source artifact and reference doc lives.
│   ├── specs/             Locked design docs — change these before changing code.
│   │   ├── CONTRACT.md        getAllDatas payload + all 26 notifications.
│   │   │                      THE server/client contract.
│   │   ├── STATE_MACHINE.md   10 states, transitions, per-state args, 18 actions.
│   │   ├── STATE_MODEL.md     DB schema semantics + invariants.
│   │   ├── UI_SPEC.md         Screen-by-screen client behaviour.
│   │   ├── BGA_PRIMER.md      How BGA works, for people new to the platform.
│   │   ├── BGA_PATTERNS.md    Idioms to copy.
│   │   ├── BGA_CHECKLIST.md   Pre-submission gate.
│   │   └── QA_SPEC_REVIEW.md / INTEGRATION_REPORT.md   Review findings.
│   ├── testing/           Test plans and review findings (documents, not code).
│   │   ├── SCENARIOS.md       15 playtest scenarios + 40 illegal-action tests.
│   │   │                      Your manual script for Studio testing (Phase 4).
│   │   ├── CODE_REVIEW_BACKEND.md / CODE_REVIEW_FRONTEND.md  (all S0/S1 resolved)
│   │   └── I18N_SWEEP.md
│   └── history/           Records of how we got here. Paths inside are stale by
│                          design; each file carries a banner saying so.
│       ├── PLAN.md            Original multi-agent build plan.
│       ├── MORNING_BRIEFING.md Phase 3 build log (pre-refactor layout).
│       └── HAL_DRY_RUN.md     Studio dry-run output + modern-framework checklist.
│
├── design/                Art pipeline and physical-game masters.
│   ├── README.md          Start here for anything art-related.
│   ├── MANIFEST.md        Annotated inventory of every master.
│   ├── PIPELINE.md        Turning masters into the sprite sheets in src/img/.
│   ├── BOARD_LAYOUT.md    The 44-hex layout, derived from the printed board.
│   ├── MISSING.md         Art that still needs authoring.
│   ├── build_placeholders.py   Generates the placeholders in src/img/.
│   ├── legacy_metadata/   Superseded BGA config. Reference only.
│   └── masters/           PRINT MASTERS — Git LFS, 315 MB, read-only.
│                          board/ box/ punchboard/ rulebook/ pre-production/
│
└── scripts/
    ├── upload_to_bga.py   SFTP upload of src/ to BGA Studio.
    └── check_links.py     Verifies every in-repo path reference resolves.
```

Two conventions worth knowing before you read any code:

- **Files cite their specs.** Almost every PHP and JS file opens with a header naming
  the spec sections it implements (`per docs/specs/STATE_MACHINE.md §2.7`). Follow it.
- **Decisions are cited inline** as `[D-21]`. Look them up in `docs/DECISIONS.md`
  rather than guessing why a rule is the way it is.

### `src/` in detail

```
src/
├── gameinfos.jsonc            Game metadata. Canonical (modern BGA reads .jsonc).
├── gameoptions.jsonc          Empty by decision D-13 (no variants at launch).
├── gamepreferences.jsonc      Empty.
├── stats.jsonc                3 table stats + 9 player stats.
├── dbmodel.sql                agent / intel_tile / blockade tables + player columns.
├── material.inc.php           Constants: agent & intel types, the 44-hex board tables,
│                              hex neighbour maths. Loaded by Game.php and states.
├── hexpionage.view.php        HTML skeleton + 4 modals.
├── hexpionage.css             Layout, hex grid, sprites, animations, dark mode.
├── img/                       PLACEHOLDER art (see §7).
└── modules/
    ├── php/
    │   ├── Game.php           Main class. Setup, getAllDatas, all 18 act* handlers,
    │   │                      and every rules helper. ~1,850 lines.
    │   └── States/            One class per game state (10 files).
    └── js/
        ├── Game.js            The whole client: setup, 10 state branches,
        │                      26 notification handlers, animations. ~2,250 lines.
        └── help_modal.js      Help/rules modal copy.
```

> **Git LFS.** `design/masters/` is LFS-backed because one board PSD is 123 MB, over
> GitHub's 100 MB per-file limit. Run `git lfs install` once per machine before
> cloning or pulling, or you will get pointer text files instead of art.

---

## 4. How the game is wired (5-minute mental model)

If you have never touched BGA, read `docs/specs/BGA_PRIMER.md`. The short version:

**Server (PHP).** `Game.php` extends `\Bga\GameFramework\Table`. State classes in
`modules/php/States/` each declare an `id`, a `type` (`GAME` = automatic,
`ACTIVE_PLAYER` = waits for input), and a `name`.

- A `GAME` state runs `onEnteringState()` and **returns the next state class**
  (`return Spawn::class;`). It must always transition.
- An `ACTIVE_PLAYER` state runs `getArgs()`, whose return value is shipped to the
  client as the data it needs to render choices, then waits.
- Player actions are `public function actX(...)` methods marked `#[PossibleAction]`.
  They validate, mutate the DB, emit notifications, and return the next state class.
  In this codebase they all live on `Game.php` — BGA allows that (an action not
  found on the current state class falls back to the Game class), and each handler
  guards itself with `ensurePhaseIsSpawn()` / `ensurePhaseIsActions()`.

**Client (JS).** `Game.js` uses the classic BGA `define(... declare("bgagame.hexpionage", ebg.core.gamegui, {...}))`
shape. Three things matter:

1. `setup(gamedatas)` — `gamedatas` is whatever `Game::getAllDatas()` returned.
2. `onEnteringState(stateName, args)` — one `case` per server state name.
3. `setupNotifications()` + `notif_<name>(n)` — one handler per server notification.

**The contract between them** is `docs/specs/CONTRACT.md`, and it is machine-checked by
`tools/harness/check_contract.php`.

> **The #1 BGA footgun**, and the bug class that was actually found here:
> `this.bgaPerformAction("actMoveAgent", { agent_id: 3, q: 1, r: 2 })` matches the
> payload keys to the PHP **parameter names**. If PHP says `$engineer_id` and JS
> sends `agent_id`, the action fails at runtime with no compile-time warning.
> `check_contract.php` now catches this.

**State ids.** BGA reserves state id `1` (framework setup) and `99` (framework game
end). Custom states must use other ids. Ours:

| id | class | type |
|---|---|---|
| 5  | `GameSetup` | GAME |
| 10 | `TrickleDrawLeft` | GAME |
| 11 | `TrickleDrawRight` | GAME |
| 12 | `TrickleRoll` | GAME |
| 13 | `TrickleResolve` | GAME |
| 20 | `Spawn` | ACTIVE_PLAYER |
| 30 | `Actions` | ACTIVE_PLAYER |
| 35 | `AnalystBonusDecision` | ACTIVE_PLAYER |
| 90 | `EndOfTurnCleanup` | GAME |
| 98 | `GameEnd` | GAME (emits `gameEnded`, then hands off to framework state 99) |

---

## 5. Testing — how to actually run this thing

### 5.1 Offline (fast, no BGA account needed)

This is the loop you will live in. It runs the **real** `src/` PHP against a stubbed
BGA framework and an in-memory SQLite database.

```bash
brew install php          # one-time; needs PHP 8.1+ (8.5 verified)
                          # node and python3 are used for the JS and link checks

./tools/check.sh          # everything, ~15 s
./tools/check.sh 300      # everything with 300 simulated games, ~70 s
```

Individual pieces:

```bash
php tools/harness/check_contract.php              # static PHP↔JS contract check
python3 scripts/check_links.py                    # every in-repo path still resolves
php tools/harness/run_tests.php --games=100       # simulate 100 games
php tools/harness/run_tests.php --games=1 --verbose --seed=999
```

What the playout does: it boots a real game, then repeatedly asks the state machine
what the active player may do — **reading only the `getArgs()` payload the real UI
gets** — picks a random legal move, and after *every single action* re-asserts a set
of hard invariants against the database:

- row counts never change (24 agents, 47 intel)
- no two agents on a hex; no agent over the 3-on-board cap
- `player.agents_remaining` always equals the number of pooled agent rows
- an agent never carries more than 3 intel; intel is never held by an off-board agent
- loose intel never sits under an agent (`INVARIANT-PICKUP`, decision D-21)
- blockade cap of 3 per player
- **each player's score always equals the sum of their scored tiles**
- any PHP warning/notice raised from `src/` fails the run

Every run is seeded, so a failure is reproducible: the message tells you the seed,
and `--seed=<n> --games=1 --verbose` replays it.

The suite also reports **coverage**: which of the 18 actions and 26 notifications the
simulation actually exercised. Anything in `NOT EXERCISED` has never been executed by
a machine and needs manual attention on the Studio test table.

### 5.2 On BGA Studio (the real thing)

You need a BGA Studio account and the `hexpionage` project (it exists —
`studio.boardgamearena.com/studiogame?game=hexpionage`).

1. **Upload.** `src/`'s *contents* go to the Studio project root:
   ```bash
   BGA_SFTP_HOST=1.studio.boardgamearena.com \
   BGA_SFTP_PORT=2022 \
   BGA_SFTP_USER=<you> \
   BGA_SFTP_PASSWORD=<secret> \
   python3 scripts/upload_to_bga.py --verify
   ```
   Add `--dry-run` first to see the file list without connecting. Credentials come
   only from the environment and are never written to disk.
2. **Delete leftovers on the remote.** The Studio skeleton ships stub state classes
   (`PlayerTurn`, `NextPlayer`, `EndScore`) under `modules/php/States/`. They will
   collide with ours. Delete them. Also make sure no `hexpionage.game.php` or
   `hexpionage.js` survives at the remote root from the pre-refactor era.
3. **Dry run.** In Studio, "Manage games" → your project → **Dry run build**. Compare
   against `docs/history/HAL_DRY_RUN.md`; every item listed there is now fixed locally, so a clean
   report is the expectation.
4. **Play.** "Express Start" a 2-player table (open the second seat in another
   browser profile) and work through `docs/testing/SCENARIOS.md`.
5. **Watch the logs.** Studio surfaces PHP errors in the table's error console; the
   offline harness catches most of them first, but framework-API mismatches can only
   surface here.

### 5.3 Where the offline harness stops

The harness proves the **rules engine** is correct and internally consistent. It
deliberately does **not** prove:

- that our stubs match the deployed BGA framework's exact API (e.g. `undoSavepoint()`,
  `$this->bga->playerScore`, autowiring of typed action parameters);
- anything about rendering, CSS, animations, or client-side interaction flow;
- multiplayer/turn-timer/zombie/spectator behaviour;
- `legal_actions` payload size against BGA's ~128 KB cap on dense boards
  (flagged as `TODO(args-1)` in `STATE_MACHINE.md`).

Those are exactly what Phase 4 on a Studio test table is for.

---

## 6. What was fixed to get here

For context on the most recent pass (all changes are in `src/` and `tools/`):

| Fix | Why it mattered |
|---|---|
| Removed `transitionCompat()` | Every action was calling `gamestate->nextState()` with a legacy transition name that no longer exists, catching the resulting `Throwable`, and falling back to a state class. It "worked" by exception. All 25 call sites now return the state class directly. |
| `GameSetup` id `1` → `5`; `GameEnd` id `99` → `98` | BGA reserves 1 and 99 for its own states; declaring them collides with the framework. `GameEnd` now emits `gameEnded` and returns `99` to hand off. |
| **7 of 18 actions had mismatched JS→PHP parameter names** | `actTransferIntel`, `actDoubleAgentTransfer`, both Engineer blockade actions, both Comms actions and `actHackerStealIntel` would all have failed at runtime. `actCommsMoveIntelUp` wasn't sending `comms_id` at all. |
| Added `description` / `descriptionMyTurn` to the 3 `ACTIVE_PLAYER` states | BGA requires them; without them the status bar is blank. |
| Removed orphan `notif_actionsRemaining` | Handler for a notification the server never sends (per `CONTRACT.md` §2.23). |
| Deleted the duplicate `gameinfos.inc.php` | Duplicated `gameinfos.jsonc`, which modern BGA actually reads. Two copies drift. Moved to `design/legacy_metadata/`. |
| Corrected the stale `material.inc.php` header | It claimed the board layout was a placeholder; G-01/G-02 were resolved long ago and the tables are canonical. |
| Added `tools/` | The whole offline harness described in §5. |

A second pass then made the repo fit for GitHub:

| Change | Why |
|---|---|
| Imported `~/Downloads/final_printing/` into `design/masters/` | The print masters were the last thing living outside the project. The repo is now self-contained. Stored via Git LFS because one PSD is 123 MB. |
| Restructured into `docs/`, `design/`, `scripts/`, `src/`, `tools/` | Nine loose markdown files at the root gave no signal about what mattered. Every reference in the codebase was rewritten to match. |
| Added `scripts/check_links.py` to `check.sh` | The restructure could have silently rotted ~440 cross-references between code and specs. Now any broken in-repo path fails the build. |
| Added `README.md`, `AGENTS.md`, `design/README.md`, `docs/README.md` | Landing page for humans, working agreements for AI agents, and indexes for the two densest directories. |

---

## 7. What is still open

**Blocking a public release, not blocking a prototype:**

1. **Art is placeholder.** `src/img/{agents,intel,tokens}.png` are generated mock
   sprite sheets (`design/build_placeholders.py`); `board.png` is a real downscale of
   the printed board. Sprite cell geometry is locked, so replacing the PNGs in place
   needs no code change — follow `design/PIPELINE.md`.
   `dice_faces.svg` and `score_markers.svg` currently aren't referenced by any CSS or
   JS; decide whether to wire them up or delete them.
2. **`TODO(I-02)` — intel distribution is a guess.** `material.inc.php::INTEL_TILE_COUNTS`
   uses 7/8/8/8/8/8. The total (47) is right; the split needs confirming against the
   punchboard masters. This changes game balance, not code.
3. **Publisher metadata is empty** in `gameinfos.jsonc` (`publisher`,
   `publisher_website`, `publisher_bgg_id`). Complexity/strategy/luck ratings now live
   in Studio's Game Metadata Manager, not in this file.
4. **Score-track pixel anchors** in `Game.js::_slideScoreMarker` are estimates; verify
   against the real board art.
5. **`analystBonusSkipped`** is the one notification the simulation has never emitted
   (it needs an Analyst retiring with 3 intel while the bag is empty). Test by hand.
6. **`actHackerStealIntel` / `actHackerUnpin`** fire only a handful of times per few
   hundred simulated games; give them explicit manual coverage.
7. **`undoSavepoint()`** is called through a `method_exists()` shim because the
   framework version wasn't confirmed. Verify on Studio and remove the shim.
8. **~95 S2/S3 review items** in `docs/testing/CODE_REVIEW_*.md` are open by choice. All S0
   and S1 items are resolved.
9. **No remote.** The project is a local git repo with a clean history, but nothing
   has been pushed yet. When you create the GitHub repo, make it **private** —
   `design/masters/` holds unreleased print masters — and enable Git LFS (315 MB of
   masters, within GitHub's 1 GB free LFS quota but worth watching).
10. **`~/Downloads/final_printing/` is now a duplicate.** Its contents were copied,
   not moved, so the originals are untouched. Delete them once you have pushed and
   confirmed the LFS objects survived the round trip.

---

## 8. Working agreements

- `rulebook.md` and `docs/specs/` are the source of truth. If code and spec disagree, the
  spec wins — or change the spec first, deliberately.
- Decisions are numbered (`D-01`..`D-26`) in `DECISIONS.md` and cited in code
  comments. When you change behaviour that a decision covers, update the decision.
- Run `./tools/check.sh` before every upload to Studio. It is faster than finding the
  same bug on a test table.
- When you add an action or a notification, `check_contract.php` will fail until both
  sides agree. That is the point.
- When you add a rule invariant, add it to `assertInvariants()` in
  `tools/harness/run_tests.php` so 300 random games have to respect it.
