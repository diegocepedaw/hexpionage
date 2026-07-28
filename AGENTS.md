# AGENTS.md — working agreements for AI agents in this repo

This file is for coding agents (Copilot CLI, Claude Code, Cursor, etc.). Humans
should read [`ONBOARDING.md`](ONBOARDING.md) instead — it has the full tour.

## The one-paragraph brief

This repo ports the board game **Hexpionage** to Board Game Arena. `src/` is the
deployable game (PHP server + vanilla-JS client, BGA *modern* framework). `docs/` is
the spec set that `src/` was built from and is cited by file and section throughout
the code. `tools/` is an offline harness that runs the real `src/` PHP without BGA
Studio. The rules engine works and is verified; the game has never run on a live
Studio table.

## Before you change anything

1. **Read the spec section the code cites.** Nearly every PHP and JS file opens with
   a header naming its source sections, e.g. `per docs/specs/STATE_MACHINE.md §2.7`.
   Follow that link before editing the file.
2. **Check `docs/DECISIONS.md`.** Rules ambiguities were adjudicated by the owner as
   D-01 through D-26 and are cited inline as `[D-21]`. If your change touches a
   decision, the decision must be updated too — do not silently diverge.
3. **Do not invent rules.** If behaviour is genuinely unspecified, add a
   `TODO(D-NN)` marker and surface the question to the owner. That convention is
   used throughout the codebase; `TODO(I-02)` is a live example.

## Verify with

```bash
./tools/check.sh          # ~15 s — run this before you claim anything works
./tools/check.sh 300      # ~70 s — run before an upload or a big change
```

It runs five things, all of which must pass:

| Step | What it catches |
|---|---|
| PHP + JS syntax | parse errors |
| `check_contract.php` | server/client drift: notification names, action names, **action parameter names**, state branches, config sanity |
| `check_links.py` | any in-repo path reference that no longer resolves |
| `run_tests.php` | rules-engine defects — N random games with 15 DB invariants re-asserted after **every** action |
| coverage report | actions and notifications the simulation never reached |

A failure prints a seed. `php tools/harness/run_tests.php --games=1 --seed=<n> --verbose`
replays it deterministically.

## Rules of engagement

- **`docs/` wins over `src/`.** If code and spec disagree, that is a bug in the code —
  or a spec change that must be made deliberately and first.
- **Extend the harness with the code.** New rule invariant → add it to
  `assertInvariants()` in `tools/harness/run_tests.php`. New action → add its
  `legal_actions` shape to `RandomBot::expand()` in `tools/harness/bot.php`, or the
  simulation will silently stop covering that branch.
- **Never weaken a check to make it pass.** The harness stub exists to run the real
  game code; if `src/` needs a stub workaround, that is a finding, not a fix.
- **Do not edit `design/masters/`.** Those are print-production masters for the
  published physical game, stored in Git LFS. They are read-only inputs.
- **Do not commit credentials.** `scripts/upload_to_bga.py` reads BGA Studio SFTP
  settings from the environment or a git-ignored `.env.bga`. Prefer SSH-key auth
  (`BGA_SFTP_KEY`) so no password exists anywhere. Never paste a password into a
  file, a commit, or a chat transcript.
- **Historical docs stay historical.** `docs/history/` records what was planned and
  what happened. Do not "fix" its stale paths — annotate if needed.

## Traps specific to this project

- **`bgaPerformAction` payload keys must equal the PHP parameter names.** BGA
  autowires by name, not position. A mismatch is silent until runtime on a live
  table. Seven of the eighteen actions were broken this way. `check_contract.php`
  now guards it — never bypass that check.
- **State ids `1` and `99` are reserved by BGA.** Custom states must use other ids.
  `GameSetup` is `5`, `GameEnd` is `98` and hands off to the framework's `99`.
- **`descriptionMyTurn` is mandatory** on `ACTIVE_PLAYER` states or the status bar
  renders blank.
- **Config lives in `.jsonc`, not `.inc.php`.** Modern BGA reads `src/gameinfos.jsonc`
  and `src/stats.jsonc`. The `.inc.php` equivalents in `design/legacy_metadata/` are
  dead references only; re-adding them to `src/` causes drift.
- **State transitions are return values,** not `nextState()` calls: an `act*` method
  or `onEnteringState()` returns the next state's `::class`, an int state id, or
  `null` to stay put.
- **Notifications are a contract.** Every one in `docs/specs/CONTRACT.md` needs a
  matching `notif_<name>` handler in `src/modules/js/Game.js`, and nothing extra.

## Environment notes

- PHP is Homebrew's and may not be on the default `PATH`:
  `export PATH="/opt/homebrew/bin:$PATH"`.
- `design/masters/` is Git LFS. Run `git lfs install` once before cloning or pulling,
  or you will get pointer files instead of art.
- There is no package manager, build step, or dependency install. PHP, node and
  python3 are all you need.

## What is still open

`ONBOARDING.md` §7 is the live list: placeholder art, the `TODO(I-02)` intel
distribution, empty publisher metadata, unverified score-track pixel anchors, three
under-covered code paths, and the Studio test pass that has never been run. Check it
before starting work so you do not duplicate a known item.
