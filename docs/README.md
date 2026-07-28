# docs/ — specs, rules, and history

Behaviour is defined here, not in the code. If `src/` and these documents disagree,
that is a bug in `src/` — or a spec change that should have been made here first.

## Start with

| Document | What it is |
|---|---|
| [`rulebook.md`](rulebook.md) | **Canonical.** Implementation-grade rules spec (~82 KB). The code implements this. |
| [`DECISIONS.md`](DECISIONS.md) | Owner adjudications D-01…D-26 for rulebook gaps. Cited inline in code as `[D-21]`. |
| [`FAQ.md`](FAQ.md) | The owner's rules FAQ for the physical game. |
| [`SOURCES.md`](SOURCES.md) | Where every source artifact and external reference lives. |

## `specs/` — locked design documents

Written in Phase 1–2 and signed off before implementation. Change these deliberately,
before changing code.

| Document | Read it when |
|---|---|
| [`specs/CONTRACT.md`](specs/CONTRACT.md) | Anything crosses the server/client boundary. Defines the `getAllDatas` payload and all 26 notifications. Machine-checked by `tools/harness/check_contract.php`. |
| [`specs/STATE_MACHINE.md`](specs/STATE_MACHINE.md) | You touch states, transitions, state args, or any of the 18 actions. |
| [`specs/STATE_MODEL.md`](specs/STATE_MODEL.md) | You touch the database schema, or need to know which invariants must hold. |
| [`specs/UI_SPEC.md`](specs/UI_SPEC.md) | You touch the client: layout, hex grid, clicks, tooltips, animations. |
| [`specs/BGA_PRIMER.md`](specs/BGA_PRIMER.md) | You have never built a BGA game. |
| [`specs/BGA_PATTERNS.md`](specs/BGA_PATTERNS.md) | You want the idiom BGA expects rather than inventing one. |
| [`specs/BGA_CHECKLIST.md`](specs/BGA_CHECKLIST.md) | You are preparing to submit the game. |
| [`specs/QA_SPEC_REVIEW.md`](specs/QA_SPEC_REVIEW.md), [`specs/INTEGRATION_REPORT.md`](specs/INTEGRATION_REPORT.md) | You want the review findings behind the specs. |

## `testing/` — test plans and review findings

| Document | What it is |
|---|---|
| [`testing/SCENARIOS.md`](testing/SCENARIOS.md) | 15 playtest scenarios + 40 illegal-action cases. **This is the manual script for Studio testing.** |
| [`testing/CODE_REVIEW_BACKEND.md`](testing/CODE_REVIEW_BACKEND.md), [`testing/CODE_REVIEW_FRONTEND.md`](testing/CODE_REVIEW_FRONTEND.md) | Line-by-line review findings. All S0/S1 resolved; ~95 S2/S3 open by choice. |
| [`testing/I18N_SWEEP.md`](testing/I18N_SWEEP.md) | Translatable-string audit. |

The code reviews were written against the pre-refactor file layout, so they cite
filenames like `src/hexpionage.js` that no longer exist. The findings still apply;
the paths do not.

Automated testing lives in [`tools/`](../tools/), not here.

## `history/` — how we got here

Records of intent and past state. **Their paths are deliberately stale** — each file
carries a banner explaining what it describes and when.

| Document | What it records |
|---|---|
| [`history/PLAN.md`](history/PLAN.md) | The original multi-agent build plan. |
| [`history/MORNING_BRIEFING.md`](history/MORNING_BRIEFING.md) | The Phase 3 implementation and fix-wave log. |
| [`history/HAL_DRY_RUN.md`](history/HAL_DRY_RUN.md) | BGA Studio dry-run output that triggered the modern-framework migration. Still a useful requirements checklist. |
