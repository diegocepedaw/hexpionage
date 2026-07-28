# HANDOVER — bootstrap prompt for the next agent

Paste everything below the line into a fresh agent session started in this
directory. It is written to be self-contained enough to act on, and it points at
the repo's own docs for everything else.

Keep this file current: when the situation below stops being true, edit it.

---

## Your role

You are taking over ongoing development and debugging of **Hexpionage on Board Game
Arena**, working in `/Users/dcepeda/Documents/hexpionage`. The previous agent got the
code into a verified prototype state and deployed it to BGA Studio. Your job is the
next phase: **make it actually work on a live Studio table, then keep it working.**

Read these before doing anything, in this order:

1. `AGENTS.md` — working agreements, and the traps specific to this project. Non-optional.
2. `ONBOARDING.md` — full project tour: state, layout, BGA mental model, testing, open items.
3. `docs/README.md` — index of the specs, so you know which document answers which question.

Do not re-derive the project from source. It is heavily documented and the docs are
accurate as of 2026-07-28.

## Where things stand

**The offline story is finished and green.** The rules engine is implemented and
verified: `./tools/check.sh 300` runs 300 bot-played games, re-asserting 15 database
invariants after every single action, and passes with 0 failures. A static checker
also proves the PHP↔JS contract agrees on all 26 notifications, all 18 actions, and —
critically — all 18 action *parameter name* sets.

**The code is deployed.** All 27 files of `src/` were uploaded to Studio on
2026-07-28 and verified byte-for-byte against local. The remote is clean: no skeleton
stub state classes, no legacy `hexpionage.game.php` / `hexpionage.js`, and the stale
duplicate `gameinfos.inc.php` has been deleted.

**The game has never been played.** Not once, by anyone, on a real table. Everything
the offline harness cannot reach is therefore unverified: rendering, CSS, animations,
click flow, real BGA framework API fidelity, turn order, spectators, zombie players,
and the ~128 KB cap on state-args payloads.

That gap is your whole job. Expect the first table to break.

## Do this first

The owner drives the browser; you drive the code. You cannot click through Studio
yourself, so work in that loop: ask the owner to perform a step, get the output or
error back, fix, re-upload, repeat.

1. Ask the owner to run a **Dry run build** in Studio (Control Panel → Manage games →
   hexpionage). Compare whatever it reports against `docs/history/HAL_DRY_RUN.md`,
   which lists everything the previous dry run flagged. All of those are fixed, so a
   clean report is the expectation — anything new is a real finding.
2. Ask the owner to **Express Start** a 2-player table (Manual / Turn-based mode; it
   auto-seats dev0 and dev1, so no second browser profile is needed).
3. When something breaks, ask for the output of *BGA unexpected exceptions logs* and
   *BGA request&SQL logs*, both linked below the game area on the table page.
4. Work through `docs/testing/SCENARIOS.md` — 15 playtest scenarios and 40
   illegal-action cases, written to be executed by hand on a table.

The *Save & restore state* controls on the table page give 3 slots. They are the
practical way to test the rare paths listed under "known weak spots" below: save the
position just before, then replay it as often as you need.

## Commands

```bash
export PATH="/opt/homebrew/bin:$PATH"   # php is Homebrew's, not on the default PATH

./tools/check.sh                # lint + contract + links + 40 games  (~15 s)
./tools/check.sh 300            # the thorough version                (~70 s)

php tools/harness/run_tests.php --games=1 --seed=42 --verbose   # replay one game
php tools/harness/check_contract.php                            # PHP<->JS agreement
python3 scripts/check_links.py                                  # cross-references

python3 scripts/upload_to_bga.py --dry-run   # file list, no connection
python3 scripts/upload_to_bga.py --check     # test credentials, upload nothing
python3 scripts/upload_to_bga.py --verify    # upload, then list the remote
```

**Run `./tools/check.sh` before every upload.** It is much faster than finding the
same bug on a live table, and every failure it reports is real.

Deployment is already configured. Credentials live in a git-ignored `.env.bga`
(SSH-key auth, no password anywhere). Two facts that cost the last agent time:

- The SFTP username is the base name **without** the dev-account digit — the account
  is `Rewl0`, the SFTP user is `Rewl`. Getting it wrong produces
  `Permission denied (password,publickey,keyboard-interactive)`, which is
  indistinguishable from a rejected key.
- Uploads target the `hexpionage/` project directory, not the SFTP home directory,
  which merely *contains* the project folders. This is already the script's default.

## How to work here

The full rules are in `AGENTS.md`. The four that matter most:

- **`docs/` outranks `src/`.** `docs/rulebook.md` is canonical and `docs/DECISIONS.md`
  holds the owner's adjudications (D-01…D-26), cited inline in code as `[D-21]`. If
  the code disagrees with them, the code is wrong — unless you change the spec first,
  deliberately, with the owner.
- **Never invent a rule.** If behaviour is genuinely unspecified, add a `TODO(D-NN)`
  and ask the owner. That convention is used throughout the codebase.
- **Extend the harness with the code.** A new invariant goes in `assertInvariants()`
  in `tools/harness/run_tests.php`; a new action needs its `legal_actions` shape added
  to `RandomBot::expand()` in `tools/harness/bot.php` or the simulation silently stops
  covering it.
- **Never weaken a check to make it pass.** The harness stub exists to run the real
  game code unmodified. If `src/` needs a stub workaround to run, that is a finding.

When you fix something a live table revealed, ask whether the offline harness *could*
have caught it. If yes, add the check — that is how this project stops regressing.

## Known weak spots — where bugs are most likely

- **`analystBonusSkipped`** is the one notification 300 simulated games never emitted.
  It needs an Analyst retiring with 3 intel while the bag is empty. Untested by machine.
- **`actHackerStealIntel` and `actHackerUnpin`** fire only a handful of times per few
  hundred games. Thin coverage; give them deliberate manual testing.
- **`undoSavepoint()`** is called through a `method_exists()` shim because the
  framework version was never confirmed. Verify on Studio and delete the shim.
- **Score-track pixel anchors** in `Game.js::_slideScoreMarker` are estimates against
  the real board art. Almost certainly need adjusting once you see them render.
- **State-args payload size.** `legal_actions` on a dense board has never been measured
  against BGA's ~128 KB cap. Flagged as `TODO(args-1)` in `docs/specs/STATE_MACHINE.md`.
- **The harness stubs are unverified against the real framework.** `bga_stub.php`
  implements the BGA API surface from documentation, not from a running Studio
  instance. Where the real framework differs, the harness is confidently wrong.

## Open items that are not yours to decide

These need the owner, not a code change. Full list in `ONBOARDING.md` §7.

- Art in `src/img/` is placeholder except `board.png`. Sprite geometry is locked, so
  real art drops in without code changes — see `design/PIPELINE.md`.
- `TODO(I-02)`: the intel distribution 7/8/8/8/8/8 in `material.inc.php` is a guess.
  The total of 47 is right; the split needs confirming against the punchboard masters
  in `design/masters/punchboard/`. This is game balance, not code.
- Publisher metadata in `gameinfos.jsonc` is empty.
- ~95 S2/S3 review items in `docs/testing/CODE_REVIEW_*.md` are open by choice. All
  S0 and S1 items are resolved.

## Repo facts worth knowing up front

- **Git.** Local repo, clean history, nothing pushed yet. When a remote is
  created it should be **private** — `design/masters/` holds unreleased print masters.
- **Git LFS.** `design/masters/` is LFS-backed (315 MB; one board PSD is 123 MB, over
  GitHub's 100 MB per-file limit). Run `git lfs install` once per machine.
- **No build step and no dependencies.** PHP 8.1+, node, and python3 are all you need.
  There is nothing to install, compile, or bundle.
- **`src/` maps 1:1 to the Studio project root.** `src/` itself is not uploaded:
  `src/modules/php/Game.php` becomes `modules/php/Game.php` on the remote.
- **Files cite their specs.** Most PHP and JS files open with a header naming the spec
  sections they implement. Follow the citation before editing the file.
