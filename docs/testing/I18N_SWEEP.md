# I18N Sweep — Hexpionage BGA port

Auditor: i18n Sweep Agent
Spec basis: `docs/specs/BGA_PRIMER.md` §4 (clienttranslate rule), `docs/specs/CONTRACT.md` §2 (notification log_message templates), BGA Studio docs ("Tools for translators": clienttranslate / self::_() / totranslate / `_()` JS / `$this->_()`).

This document audits every PHP, JS, and HTML-template source file in `src/` for translatable user-facing strings. Wrapping conventions: `clienttranslate('…')` (PHP notif/log templates), `self::_("…")` (server-side immediate, used in exceptions), `totranslate('…')` (PHP static metadata), `_("…")` (JS via `dojo.i18n`), `<?= $this->_("…") ?>` or `{TXT_*}` substitution (view templates).

Files scanned (17 total, ~5,361 lines): `hexpionage.game.php`, `hexpionage.js`, `hexpionage.view.php`, `material.inc.php`, `gameinfos.inc.php`, `stats.inc.php`, `modules/js/help_modal.js`, and 9 state classes under `modules/php/States/`.

---

## 1. Unwrapped string inventory

### A. JavaScript client (`src/hexpionage.js`) — UNWRAPPED (largest surface)

**Zero** `_()` calls in the entire file. Every literal string in `textContent`, `title`, button labels, status banners, modal titles, and tooltips is hard-coded English. Single largest gap in the codebase.

| ID | File:Line | Snippet | String | Surface | Proposed fix |
|---|---|---|---|---|---|
| **I18N-01..04** | `src/hexpionage.js:183, 188, 193, 198` | `this._setStatus("Trickle in progress…")` etc. | `"Trickle in progress…"`, `"Rolling dice…"`, `"Resolving trickle…"` | Status bar during the four trickle sub-states | wrap each with `_(…)` |
| **I18N-05..08** | `src/hexpionage.js:182, 187, 192, 197` | `this._setSubstate("Trickle (drawing 1/2)")` etc. | Four phase-breadcrumb labels | Sub-phase breadcrumb | wrap each with `_(…)` |
| **I18N-09** | `src/hexpionage.js:202` | `this._setSubstate("Spawn — pick a reserve agent, then a ✦ hex.");` | `"Spawn — pick a reserve agent, then a ✦ hex."` | Sub-phase prompt | wrap with `_(…)` |
| **I18N-10** | `src/hexpionage.js:225` | `this._setStatus("Decide on Analyst bonus…");` | `"Decide on Analyst bonus…"` | Active-player prompt | wrap with `_(…)` |
| **I18N-11** | `src/hexpionage.js:227` | `this._setStatus(this._getActivePlayerName() + " is deciding the Analyst bonus…");` | `" is deciding the Analyst bonus…"` (concatenated) | Spectator prompt | `dojo.string.substitute(_("${player_name} is deciding the Analyst bonus…"), { player_name: this._getActivePlayerName() })` |
| **I18N-12..14** | `src/hexpionage.js:232, 233, 237` | `_setSubstate/_setStatus` calls | `"End of turn — cleaning up."` (×2 sites), `"Game over."` | Phase breadcrumb / status banner | wrap each with `_(…)`; unify two duplicates onto one source key |
| **I18N-15..21** | `src/hexpionage.js:276–312` | All action-bar buttons (`addBtn`, `addActionBtn`, `addDropdown`) | `"Pass Spawn"`, `"Move"`, `"Transfer"`, `"Retire"`, `"Engineer"`, `"Smuggler"`, `"Comms"`, `"Hacker"`, `"Double Agent"`, `"End Turn"`, plus 10 dropdown sub-option labels (`"Place Adjacent (1A)"`, `"Place Anywhere (1I)"`, `"Boost (1I)"`, `"Swap (1A + 1I)"`, `"Move Up (1A)"`, `"Move Down (1A + 1I)"`, `"Pin (1A)"`, `"Unpin (1A)"`, `"Steal (1I)"`, `"Transfer To Any (1A)"`), plus the `"?"` glyph | Action bar | wrap every literal `label` argument with `_("…")` |
| **I18N-22** | `src/hexpionage.js:382–395` | `_tooltipForAction` map | 13 tooltip strings (one per action) | Tooltips on action buttons | wrap each map value in `_("…")` |
| **I18N-23** | `src/hexpionage.js:398` | `(base + " (Currently disabled.)")` | `" (Currently disabled.)"` | Tooltip disabled suffix | `dojo.string.substitute(_("${base} (Currently disabled.)"), { base })` |
| **I18N-24..26** | `src/hexpionage.js:416, 808, 943` | `_setStatus` arming prompts | `"Pick a source agent or target."`, `"Pick the second agent to swap."`, `"Pick the destination hex (NW/NE for Up, SW/SE for Down)."` | Status prompts | wrap each with `_(…)` |
| **I18N-27..34** | `src/hexpionage.js:957, 962–963, 993, 996, 1005, 1009, 1017, 1021` | Intel-choice and Hacker-steal wizard modal titles + button labels | `"Choose an intel tile"`, `"Pick a Hacker"`, `"Pick intel to steal"`, `"Pay 1 intel from this Hacker"`, `"Hacker #${id}"`, `"Intel #${id}"` (concat fallbacks), and `INTEL_LABEL[…]` lookups | Modal titles + button labels | wrap each title with `_(…)`; convert concat fallbacks to `dojo.string.substitute(_("Intel #${id}"), {id})` and `_("Hacker #${id}")`; reuse the wrapped `INTEL_LABEL` map (see I18N-35a) |
| **I18N-35** | `src/hexpionage.js:71–87, 648, 1084` | `AGENT_LABEL` and `INTEL_LABEL` constant maps + their use in `node.title` and `cell.title` | 12 strings: 6 agent names (`"Comms Specialist"`, `"Analyst"`, `"Smuggler"`, `"Engineer"`, `"Hacker"`, `"Double Agent"`) + 6 intel names (`"Honeypot"`, `"Industrial Tech"`, `"Leaked Email"`, `"Blackmail"`, `"Security Credential"`, `"State Secret"`) | Agent / intel tooltips and modal labels (board pieces, reserve grid, dice tray, intel-choice modal, analyst-bonus modal) | Either declare maps as `_("…")` lookups computed lazily, or wrap at point of use. **High-impact: these labels show on every tile and every modal.** |
| **I18N-36** | `src/hexpionage.js:1110` | `this._setStatus("Pick a ✦ hex to spawn.");` | `"Pick a ✦ hex to spawn."` | Status after picking reserve agent | wrap |
| **I18N-37** | `src/hexpionage.js:1120` | `die.title = INTEL_LABEL[key] + " die: odd → SW, even → SE.";` | `" die: odd → SW, even → SE."` | Dice tooltip | `dojo.string.substitute(_("${name} die: odd → SW, even → SE."), { name: _(INTEL_LABEL[key]) })` |
| **I18N-38** | `src/hexpionage.js:1234` | `return p ? p.name : "Active player";` | `"Active player"` | Active-player fallback (used in status) | wrap with `_(…)` |
| **I18N-39..43** | `src/hexpionage.js:1276–1308` | Help-modal content (`_renderHelpTab`) | 6 quickref items + 1 honeypot body + 2 blockade bodies + 3 phases items + 2 win-conditions bodies + 5 section titles (`"Agent abilities"`, `"Honeypot"`, `"Blockades"`, `"Phases"`, `"Win conditions"`) ≈ 19 strings | Help modal (5 tabs) | wrap every literal title and body string |
| **I18N-44** | `src/hexpionage.js:1319–1321` | Intro modal slide titles + bodies | `"Goal"`, `"Agents"`, `"Watch out"` + 3 long body strings | First-time intro modal | wrap each |
| **I18N-45** | `src/hexpionage.js:1433` | `this._setStatus("Bag empty — " + args.side + "-side draw skipped.", "info");` | concat | Status banner on empty-bag intel draw | `dojo.string.substitute(_("Bag empty — ${side}-side draw skipped."), { side: args.side })` |
| **I18N-46..49** | `src/hexpionage.js:1601–1604` | Analyst-bonus modal text content | `"Bonus tile"` (fallback), `"+${score_value} points"` (concat), `"Keep (+${score_value} pts)"` (concat), `"Return to bag"` | Analyst bonus modal | wrap each; convert concat to `dojo.string.substitute(_("Keep (+${value} pts)"), {value})` etc. |
| **I18N-50** | `src/hexpionage.js:1631` | `this._setStatus("Bag empty — bonus forfeited.", "info");` | `"Bag empty — bonus forfeited."` | Status banner on analyst-bonus skip | wrap |
| **I18N-51** | `src/hexpionage.js:1854–1856` | Game-end status string | `"Winner"` (fallback), `"reached 20 points"`, `"opponent depleted"`, composed `"Game over — … wins (…)."` | Game-over banner | use `dojo.string.substitute(_("Game over — ${player_name} wins (${reason})."), {…})`; wrap each reason string with `_(…)`; wrap `"Winner"` fallback |

The `AGENT_LABEL` and `INTEL_LABEL` maps (lines 71–87) are referenced via I18N-35; they account for 12 unwrapped strings (6 + 6) reused across at least four UI surfaces.

### B. JavaScript help module (`src/modules/js/help_modal.js`) — UNWRAPPED

This auxiliary module mirrors the inline help content of `hexpionage.js`. It contains **zero** `_()` calls and is intended for future use as a separate import. Per its own header comment ("All copy here is REUSED from rulebook.md per UI_SPEC §9 ('Modal text is reused from rulebook.md via clienttranslate(); no new rules copy is invented here.')") it is supposed to be wrapped, but it is not.

| ID | File:Line | String | Surface | Proposed fix |
|---|---|---|---|---|
| **I18N-52** | `src/modules/js/help_modal.js:32`, `48`, `56`, `64`, `78` | Tab titles: `"Agent abilities"`, `"Honeypot"`, `"Blockades"`, `"Phases"`, `"Win conditions"` | Help modal tabs (parallel to I18N-40..43) | wrap each title in `_("…")` |
| **I18N-53** | `src/modules/js/help_modal.js:37–42` | 6 quickref strings (agent abilities) | Help modal Agent abilities | wrap each list item |
| **I18N-54** | `src/modules/js/help_modal.js:51` | Honeypot body (1 string) | Help modal | wrap |
| **I18N-55** | `src/modules/js/help_modal.js:58–59` | Blockades body (2 strings) | Help modal | wrap |
| **I18N-56** | `src/modules/js/help_modal.js:70–72` | Phases body (3 list items) | Help modal | wrap |
| **I18N-57** | `src/modules/js/help_modal.js:81–82` | Win conditions body (2 strings) | Help modal | wrap |

### C. PHP server (`src/hexpionage.game.php`) — MOSTLY OK, two lapses

| ID | File:Line | Snippet | String | Surface | Proposed fix |
|---|---|---|---|---|---|
| **I18N-58..64** | `src/hexpionage.game.php:419, 822, 908, 1375, 1558, 1627, 1693` | `throw new BgaVisibleSystemException("…");` (7 sites) | `"INVARIANT-PICKUP violation [D-21]: loose intel co-occupies an agent hex."`, `"INVARIANT-HONEYPOT-HELD violated"` (×4 with minor variations), `"No pending Analyst bonus tile"` (×2) | `BgaVisibleSystemException` is rendered to players (despite "system" in the name). | Either wrap each message in `self::_(…)` / `clienttranslate(…)`, or downgrade to internal logs if these invariants are never expected to fire in production. Borderline grading: per spec these are "errors visible to players, must use clienttranslate()". |

### D. PHP view template (`src/hexpionage.view.php`) — CRITICAL ISSUE

The template uses 24 `{TXT_*}` placeholders (e.g. `{TXT_PHASE_TRICKLE}`, `{TXT_SCORE}`, `{TXT_RESERVE}`, `{TXT_HELP_TITLE}`, `{TXT_INTRO_NEXT}`). These are BGA template substitution tokens that are filled in by `view_hexpionage_hexpionage::build_page($viewArgs)`.

**The `build_page` method is empty** (lines 28–36). This means none of the `{TXT_*}` placeholders are actually substituted at render time — they will appear as literal `{TXT_PHASE_TRICKLE}` strings in the rendered HTML.

| ID | File:Line | Placeholder | Required (English) text | Proposed fix |
|---|---|---|---|---|
| **I18N-65..85** | `src/hexpionage.view.php:50–282` | 21 distinct `{TXT_*}` placeholder names (some repeated): `{TXT_PHASE_TRICKLE}`, `{TXT_PHASE_SPAWN}`, `{TXT_PHASE_ACTIONS}`, `{TXT_TURN}`, `{TXT_SCORE}` (×2), `{TXT_RESERVE}` (×2), `{TXT_BLOCKADES}` (×2), `{TXT_ACTIONS}` (×3), `{TXT_ANALYST_BONUS_TITLE}`, `{TXT_CANCEL}` (×2), `{TXT_BACK}`, `{TXT_HELP_TITLE}`, `{TXT_HELP_TAB_QUICKREF}`, `{TXT_HELP_TAB_HONEYPOT}`, `{TXT_HELP_TAB_BLOCKADE}`, `{TXT_HELP_TAB_PHASES}`, `{TXT_HELP_TAB_WIN}`, `{TXT_INTRO_SKIP}`, `{TXT_INTRO_PREV}`, `{TXT_INTRO_NEXT}`, `{TXT_SUBPHONE_WARNING}` | English-source equivalents: `Trickle`, `Spawn`, `Actions`, `Turn`, `Score`, `Reserve`, `Blockades`, `Actions`, `Analyst Bonus`, `Cancel`, `Back`, `Help`, `Agent abilities`, `Honeypot`, `Blockades`, `Phases`, `Win conditions`, `Skip`, `Previous`, `Next`, sub-phone warning | Top bar, player panels, action bar, all four modals, sub-phone banner | In `build_page`, populate `$this->tpl['TXT_*'] = self::_("…")` for every placeholder; or rewrite the template body with inline `<?= $this->_("…") ?>`. |
| **I18N-86..88** | `src/hexpionage.view.php:98, 207, 249` | `alt="Hexpionage board"`, `aria-label="Help"`, `aria-label="Close"` | Three accessibility attributes | Visible to screen readers and BGA's accessibility tooling | Use `<?= 'aria-label="' . $this->_("Help") . '"' ?>` style, or replace with `aria-label="<?= $this->_('Help') ?>"`. "Hexpionage" itself is a proper noun (borderline). |

These 24 placeholder tokens need a `build_page` body that assigns each to a translated string. Without that, the deployed game will literally render the placeholder text (e.g., `{TXT_SCORE}: 0`) on screen, which is a visible defect even before any localization concerns.

### E. PHP state files — MIXED

| ID | File:Line | Snippet | String | Surface | Proposed fix |
|---|---|---|---|---|---|
| **I18N-89** | `src/modules/php/States/GameEnd.php:54` | `'win_reason_text' => $win_reason === 'score_20' ? 'reached 20 points' : 'opponent depleted'` | `'reached 20 points'`, `'opponent depleted'` | These strings appear as `${win_reason_text}` substitutions inside the wrapped `gameEnded` log message (`clienttranslate('Game over — ${player_name} wins (${win_reason_text}).')`). | The OUTER template is wrapped, but the VALUES being substituted are unwrapped English strings. Wrap each substitution: `'win_reason_text' => $win_reason === 'score_20' ? clienttranslate('reached 20 points') : clienttranslate('opponent depleted')`. Per CONTRACT.md §2 + BGA convention, `${type_name}`-style substitutions need to be wrapped at the source. |

The other state-file `clienttranslate(...)` calls in `EndOfTurnCleanup.php`, `AnalystBonusDecision.php`, `GameSetup.php`, `TrickleDrawLeft.php`, `TrickleDrawRight.php`, `TrickleResolve.php`, `TrickleRoll.php` are correctly wrapped.

### F. PHP top-level `hexpionage.game.php` notification substitutions — BORDERLINE

A subtle pattern: many `${type_name}` values are passed in raw, sourced from `INTEL_TYPES[…]` / `AGENT_TYPES[…]` constants in `material.inc.php` (see lines 23–30, 45–52). These constants store unlocalized snake_case strings (`'comms_specialist'`, `'state_secret'`, etc.) which are then interpolated into `clienttranslate('…${type_name}…')` log messages.

| ID | File:Line | Snippet | Issue | Proposed fix |
|---|---|---|---|---|
| **I18N-90** | `src/hexpionage.game.php:669, 760, 845, 947, 1265, 1339, 1403, 1593, 1652` and `States/AnalystBonusDecision.php:78`, `States/TrickleDrawLeft.php:62`, `States/TrickleDrawRight.php:62` | `'type_name' => INTEL_TYPES[$type_id]` / `AGENT_TYPES[$type_id]` | Substitution value is the raw unlocalized snake_case key. These strings will reach the player as `comms_specialist`, `state_secret`, etc. | Either (a) wrap at material.inc.php (rename constants to use `clienttranslate('Comms Specialist')` etc.) OR (b) at point of use: `'type_name' => clienttranslate('Comms Specialist')` keyed on type_id. BGA's translation extractor needs the values to be wrapped string literals, not snake_case keys. |

This is technically a defect: the resulting log lines will read `Player1 spawns a comms_specialist on (3, 2).` — the type_name token displays the lowercase snake_case identifier, not a player-readable label. Tracked as one umbrella ID (I18N-90).

### G. PHP `gameinfos.inc.php`, `stats.inc.php` — CORRECTLY WRAPPED

`gameinfos.inc.php:36` uses `totranslate(...)` for the tie-breaker description. `stats.inc.php` uses `totranslate(...)` for all 12 stat names. Both are correctly wrapped per BGA convention.

`material.inc.php` contains only constants, code, and developer comments — no player-visible strings emitted directly. Its `INTEL_TYPES` / `AGENT_TYPES` strings serve as machine identifiers; their leakage into player-visible log lines is tracked under I18N-90.

---

## 2. Correctly-wrapped strings (sanity check)

Representative confirmed-wrapped sites:

- `src/hexpionage.game.php:560` — `throw new BgaUserException(self::_("Action only legal in actions phase"));` (typical of all 86 `self::_(…)` exception messages).
- `src/hexpionage.game.php:665` — `clienttranslate('${player_name} spawns a ${type_name} on (${q}, ${r}).')` (typical of all 23 `clienttranslate` notification templates).
- `src/modules/php/States/GameSetup.php:34` — `clienttranslate('Game start — ${player_name} goes first.')`
- `src/modules/php/States/TrickleDrawLeft.php:58` — `clienttranslate('Intel drawn (left): ${type_name} → top-left entry hex.')` (parallel right-side at `TrickleDrawRight.php:57`)
- `src/modules/php/States/TrickleRoll.php:38` — `clienttranslate('Trickle dice rolled.')`
- `src/modules/php/States/TrickleResolve.php:243` — `clienttranslate('Trickle resolved: ${moves_count} tiles moved, …')`
- `src/modules/php/States/EndOfTurnCleanup.php:48, 78, 120` — pin-expired / blockade-expired / turnEnded all wrapped
- `src/modules/php/States/AnalystBonusDecision.php:47, 74, 113` — skipped / drawn / returned all wrapped
- `src/modules/php/States/GameEnd.php:50` — `clienttranslate('Game over — ${player_name} wins (${win_reason_text}).')` (caveat: substitution value unwrapped — see I18N-89)
- `src/gameinfos.inc.php:36` — `'tie_breaker_description' => totranslate('Active player wins when both would cross 20 simultaneously [D-03]')`
- `src/stats.inc.php:11, 16, 21, 30, 35, …` — every stat `name` uses `totranslate(…)`

The PHP server-side notification surface is well-disciplined; the JS client and the view template are not.

---

## 3. Coverage stats

- **Files scanned**: 17 source files
- **Total user-facing strings found** (approximate; each finding ID may bundle multiple instances of the same construct):
  - PHP server (game.php + States + view + metadata): **121** distinct user-facing string sites
  - JS client (hexpionage.js + help_modal.js): **132** distinct user-facing string sites (action labels, tooltips, status banners, modal titles, help-tab content, intro slides, AGENT_LABEL, INTEL_LABEL)
  - View template: **24** `{TXT_*}` placeholders + 3 raw `aria-label` / `alt` attributes = **27**
  - **Total: ~280**
- **Wrapped**: ~109 (all `BgaUserException(self::_(…))` + all PHP `clienttranslate(…)` + 12 `totranslate(…)` in stats.inc.php + 1 in gameinfos.inc.php) = ≈ **109**
- **Unwrapped**: **~171**
  - PHP: 7 `BgaVisibleSystemException` + 1 hard-coded substitution in GameEnd.php (I18N-89) + ~12 type_name leak instances (I18N-90) ≈ **20** of 121 (87% wrap rate within PHP)
  - View template: **27** of 27 unwrapped (0% wrap rate; `build_page` body is empty)
  - JS: **~124** of 132 unwrapped (≈ 5% wrap rate; only the few `INTEL_LABEL`/`AGENT_LABEL` reuses incidentally render in localized form via I18N-28/I18N-35 if those maps are translated)
- **Overall wrap rate**: 109 / 280 ≈ **39%**

If we exclude the JS file (which is the dominant offender) and look only at PHP: **~83%** wrap rate; the PHP backend is in good shape.

---

## 4. Translation-readiness rating

**NOT READY** (overall ≈ 39% wrap rate; <50%).

Justifications:
1. The entire JS client has zero `_()` calls. Action buttons, tooltips, status banners, all 5 help-modal tabs, the 3 intro-modal slides, and the analyst-bonus / hacker-steal modal flows are unreachable to BGA's `Tools_for_translators` extractor.
2. The view template's 24 `{TXT_*}` placeholders are never substituted. The deployed game will render literal token strings in the UI shell (top bar, player panels, action bar, all 4 modals, sub-phone banner). This is also a functional bug, not just a localization gap.
3. The `type_name` substitutions in PHP notifications carry snake_case keys (e.g. `'comms_specialist'`) instead of localized labels.

The PHP server-side action-handler / state surface is well-disciplined (≈ 87% wrap rate; only `BgaVisibleSystemException` invariant strings and the GameEnd `win_reason_text` substitution are unwrapped). To reach READY status, the JS surface and the view-template `build_page` body need a sweep, plus a label-translation indirection for `AGENT_TYPES` / `INTEL_TYPES` so `${type_name}` substitutions are extractable.

Recommended fix priority:
1. **P0 (functional bug)**: Implement `build_page` in `view.php` to substitute every `{TXT_*}` placeholder with a `self::_("…")` call. Without this, the game shell shows `{TXT_SCORE}` literal.
2. **P0 (largest gap)**: Add `_()` wrapping throughout `hexpionage.js` for action button labels (lines 276–309), tooltip map (382–395), status banners (~25 sites), modal titles (4 sites), help-modal content (lines 1276–1308), and intro-modal slides (1319–1321).
3. **P1**: Wrap `help_modal.js` content (parallels P0; this file may be obsolete if `hexpionage.js` is the canonical source).
4. **P1**: Wrap notification substitution values for `type_name` (touch points listed in I18N-90) and the `win_reason_text` value in GameEnd.php (I18N-89).
5. **P2**: Wrap the 7 `BgaVisibleSystemException` invariants in `hexpionage.game.php` (I18N-58..64), or convert them to `BgaUserException` with wrapped messages or to internal log entries.

---

## 5. Translation key namespace recommendation

BGA's `Tools_for_translators` performs automatic key extraction from the source text passed to `clienttranslate()`, `self::_()`, `$this->_()`, `_()`, and `totranslate()`. Keys are the source text itself; no manual prefix or namespace is required.

Three practical recommendations beyond raw wrapping:

1. **Avoid string concatenation around translated tokens.** Use `dojo.string.substitute(_("Bag empty — ${side}-side draw skipped."), { side })` so the translator sees a single complete sentence. Sites I18N-11, I18N-23, I18N-28..29, I18N-31, I18N-37, I18N-45, I18N-47..48, I18N-51 follow the concat anti-pattern.
2. **Translate `AGENT_TYPES` / `INTEL_TYPES` labels via a helper.** Define `agent_label($type_id)` returning `clienttranslate('Comms Specialist')` etc., and call it at every `'type_name' => …` site. Fixes I18N-90.
3. **Reuse the same English source string everywhere** so translator sees one key (e.g. `"End of turn — cleaning up."` appears twice).

---

## Summary

- **Files scanned**: 17.
- **Findings**: 90 numbered IDs covering ~171 unwrapped strings (action buttons, tooltips, modal titles, help/intro modal copy, view-template placeholders, exception messages, and a snake_case substitution leak).
- **Wrap rate**: ≈ **39%** overall (PHP backend ≈ 87%; JS client ≈ 5%; view template ≈ 0%).
- **Rating**: **NOT READY**.
- **Top 3 unwrapped strings to fix first**:
  1. **The 27 `{TXT_*}` placeholders in `src/hexpionage.view.php`** — without a `build_page` body that resolves them, the game UI literally shows `{TXT_SCORE}`, `{TXT_TURN}`, etc. on screen. (I18N-65..88)
  2. **All action-bar button labels in `src/hexpionage.js:276–312`** — `Move`, `Transfer`, `Retire`, `Engineer`, `Smuggler`, `Comms`, `Hacker`, `Double Agent`, `Pass Spawn`, `End Turn`, plus every dropdown sub-option (`Place Adjacent (1A)`, etc.). These are the most-clicked surfaces in the entire game. (I18N-15..21)
  3. **The notification `type_name` substitution leak (I18N-90)** — every `${type_name}` token in PHP `clienttranslate(…)` log messages currently substitutes the snake_case identifier (`comms_specialist`, `state_secret`) instead of a translated label, which surfaces in the BGA log feed for every turn.

Recommended sequence: fix the view template first (functional defect), then sweep the JS file in one pass adding `_()` wrappers, then add a translatable label helper for AGENT_TYPES / INTEL_TYPES.
