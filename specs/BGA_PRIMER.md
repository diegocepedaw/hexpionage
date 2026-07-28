# BGA Studio Platform Primer

> Author: A1 — BGA Platform Research Agent. Platform-only; no Hexpionage rules.
> Citation rule: every major claim has an inline `[URL]` reference. Items not covered by official docs are tagged `[NOT CONFIRMED]`.

---

## 1. Project skeleton

A BGA game is a flat directory of files plus a few subdirectories. The Studio docs enumerate the canonical file set:

- Server-side: `dbmodel.sql`, `Game.php` (or `<gamename>.game.php`), `material.inc.php`, `states.inc.php`, `<gamename>.action.php`, `stats.jsonc` / `stats.inc.php`, plus `modules/php/` for additional classes [https://en.doc.boardgamearena.com/Studio].
- Client-side: `Game.js` (or `<gamename>.js`), `<gamename>_<gamename>.tpl` template, optional `view.php`, `<gamename>.css`, `img/`, `sounds/`, `fonts/` [https://en.doc.boardgamearena.com/Studio].
- Configuration: `gameinfos.jsonc` (or `gameinfos.inc.php`), `gameoptions.json` / `gameoptions.inc.php`, `gamepreferences.json` [https://en.doc.boardgamearena.com/Studio] [https://en.doc.boardgamearena.com/Game_options_and_preferences:_gameoptions.inc.php].
- Supporting: `modules/`, `misc/` (studio-only), `States/` directory for modern state classes [https://en.doc.boardgamearena.com/Studio].

### Modern vs legacy framework split

There is an explicit modernization in flight. The Complete Walkthrough advises new games to **avoid** the legacy `states.inc.php`, `material.inc.php`, `gameoptions.inc.php`, and `stats.inc.php` array files in favor of:

- PHP state classes in `modules/php/States/` extending `Bga\GameFramework\States\GameState` [https://en.doc.boardgamearena.com/Create_a_game_in_BGA_Studio:_Complete_Walkthrough] [https://en.doc.boardgamearena.com/State_classes:_State_directory].
- Action methods annotated with the `#[PossibleAction]` attribute (no `*.action.php` file needed) [https://en.doc.boardgamearena.com/Create_a_game_in_BGA_Studio:_Complete_Walkthrough] [https://en.doc.boardgamearena.com/State_classes:_State_directory].
- JSON configuration files: `gameinfos.jsonc`, `gameoptions.json`, `gamepreferences.json`, `stats.json` [https://en.doc.boardgamearena.com/Create_a_game_in_BGA_Studio:_Complete_Walkthrough].
- Optional TypeScript and SCSS pipelines [https://en.doc.boardgamearena.com/Create_a_game_in_BGA_Studio:_Complete_Walkthrough].
- Vanilla JS or modern framework on the client; "you don't need to use the outdated Dojo framework, as vanilla JS is now able to do the same things" [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js].

The legacy stack still works and is still documented; it remains the safer default if the team is unfamiliar with the modern toolchain. Either way the runtime is **PHP 8.4 + MySQL 5.7/8.0** [https://en.doc.boardgamearena.com/Studio].

### Naming conventions

- Action handlers: `act<ActionName>()` [https://en.doc.boardgamearena.com/Players_actions:_yourgamename.action.php].
- State entry callbacks for `GAME` (auto) states: `st<StateName>()` [https://en.doc.boardgamearena.com/Your_game_state_machine:_states.inc.php].
- State args methods: `arg<StateName>()`, returning an array [https://en.doc.boardgamearena.com/Your_game_state_machine:_states.inc.php].
- Element identifiers prefer reverse-DNS style, e.g. `card_yellow_magic_2` (instance #2 of yellow magic cards) shared across DB rows, DOM IDs, and CSS sprite classes [https://en.doc.boardgamearena.com/Create_a_game_in_BGA_Studio:_Complete_Walkthrough].

---

## 2. Backend (PHP)

### Lifecycle of the main game class

A new `Game` instance is constructed for every request — there is no in-memory persistence between actions [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php]. State must round-trip the database or the BGA globals store.

Key lifecycle hooks:

- `setupNewGame(array $players, array $options)` — runs once at table creation. Must not call `getCurrentPlayerId()` (no current user during setup) [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].
- `getAllDatas(?int $currentPlayerId)` — supplies the entire visible client state on load and on F5. Must include `players[<player_id>].score` for refresh correctness [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php]. Cannot return objects mixing scalars and functions [https://en.doc.boardgamearena.com/Troubleshooting].
- `getGameProgression()` — integer 0–100 displayed in the UI; required before alpha [https://en.doc.boardgamearena.com/Pre-release_checklist].
- `zombieTurn($state, $active_player_id)` — handles disconnected players. Use the parameter, not `getCurrentPlayerId()` [https://en.doc.boardgamearena.com/Troubleshooting].
- `upgradeTableDb($from_version)` — schema migration after release [https://en.doc.boardgamearena.com/Game_database_model:_dbmodel.sql].

### Action attribute routing (modern)

Modern actions are methods on a state class, marked `#[PossibleAction]`, with auto-wired parameters by type and name (`int $activePlayerId`, `int $playerId`, `array $args`) [https://en.doc.boardgamearena.com/State_classes:_State_directory]. The handler can return a state via class name, integer ID, or transition string — the framework moves the state machine accordingly [https://en.doc.boardgamearena.com/State_classes:_State_directory].

Legacy: `*.action.php` extracts arguments via `getArg($name, $type, $mandatory, $default)` (types include `AT_int`, `AT_posint`, `AT_bool`, `AT_alphanum`, `AT_json`), wraps the call in `setAjaxMode()` / `ajaxResponse()`, and delegates to `Game.php`. The handler in `Game.php` calls `checkAction(...)` first and is responsible for all rule validation [https://en.doc.boardgamearena.com/Players_actions:_yourgamename.action.php].

### DB access patterns

Provided by the framework on the game class [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php]:

- `DbQuery($sql)` — generic execute (SELECT/UPDATE/INSERT/DELETE).
- `getUniqueValueFromDB($sql)` — single scalar or null; throws if multiple rows.
- `getCollectionFromDB($sql, $bSingleValue=false)` — assoc array keyed by the first column.
- `getNonEmptyObjectFromDB($sql)` — one row as an array; throws if empty.
- `getObjectListFromDB($sql, $bUniqueValue=false)` — list of row arrays.
- `escapeStringForDB($s)` — required for any user-provided string before interpolation.

DB results return string-typed values; always cast `(int)$value` when needed [https://en.doc.boardgamearena.com/Troubleshooting].

The schema lives in `dbmodel.sql`. Tables must use `CREATE TABLE IF NOT EXISTS ... ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`, define a primary key, and place SQL comments on their own lines (a comment sharing a line with code commented-out the code) [https://en.doc.boardgamearena.com/Game_database_model:_dbmodel.sql]. The `player`, `global`, `stats`, `gamelog`, and `bga_*` tables exist by default; only the `player` table may be extended (additional columns), never altered [https://en.doc.boardgamearena.com/Game_database_model:_dbmodel.sql]. Total DB size cap is **64MB** [https://en.doc.boardgamearena.com/Game_database_model:_dbmodel.sql]. Schema is **immutable during gameplay**; post-release migrations require `upgradeTableDb()` [https://en.doc.boardgamearena.com/Game_database_model:_dbmodel.sql].

### Randomness — `bga_rand`

The BGA-supplied `bga_rand($min, $max)` is the only sanctioned RNG; it is cryptographically seeded and reproducible for replays [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php]. PHP's `array_rand`/`shuffle` are flagged as insufficient entropy; the docs recommend `random_int()`-based custom shuffles or the Deck component's shuffle for cards [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php]. The cookbook is explicit: "All dice rolling must be done using bga_rand() function in php" [https://en.doc.boardgamearena.com/BGA_Studio_Cookbook].

### Transactions and undo savepoints

Database mutations performed by an action handler are queued and committed only when the action returns successfully; throwing an exception rolls everything back and suppresses notifications too [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php]. Pre-release checklist forbids manual `BEGIN/COMMIT` during gameplay because they break the implicit transaction [https://en.doc.boardgamearena.com/Pre-release_checklist] [https://en.doc.boardgamearena.com/Game_database_model:_dbmodel.sql].

For undo, set `'db_undo_support' => true` in `gameinfos`, then call `$this->undoSavepoint()` at points of stable state (start of a turn, after hidden info reveal). `undoRestorePoint()` rolls back to the last savepoint, and you must call `$this->gamestate->reloadState()` afterward to refresh cached state [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php]. The restore must keep the same active player and not unreveal hidden info [https://en.doc.boardgamearena.com/BGA_Undo_policy].

### Globals

Modern: `$this->bga->globals->set/get/inc($name, ...)`, JSON-serializable values [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php]. Legacy: numeric `initGameStateLabels()` with IDs 10–89 (game globals) and 100–199 (options) [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].

---

## 3. State machine

### State types

Four types declared in `states.inc.php` (or via constructor on a state class):

- `GAME` — automatic, no active player; runs an `action` callback (`st<Name>`) and transitions on its return [https://en.doc.boardgamearena.com/Your_game_state_machine:_states.inc.php].
- `ACTIVE_PLAYER` — exactly one active player who must choose from `possibleactions` [https://en.doc.boardgamearena.com/Your_game_state_machine:_states.inc.php].
- `MULTIPLE_ACTIVE_PLAYER` — multiple simultaneously-active players [https://en.doc.boardgamearena.com/Your_game_state_machine:_states.inc.php].
- `PRIVATE` — sub-state used inside `MULTIPLE_ACTIVE_PLAYER` for per-player parallel flows; the parent state names the entry private state via `initialprivate` [https://en.doc.boardgamearena.com/Your_game_state_machine:_states.inc.php].

There is also an implicit terminal `gameEnd` state with reserved id `99` (and id `1` is the standard `gameSetup`) [https://en.doc.boardgamearena.com/Your_game_state_machine:_states.inc.php].

### State definition shape

Required fields: `id`, `name` (no spaces), `type`, `description`, `descriptionmyturn`, `transitions`. Conditional: `action` (mandatory for `GAME` states), `possibleactions` (mandatory for player-driven states). Optional: `args`, `updateGameProgression`, `initialprivate` [https://en.doc.boardgamearena.com/Your_game_state_machine:_states.inc.php].

`args` runs *before* the action handler and packs the data the frontend needs for the state. It must return an array (not a scalar) [https://en.doc.boardgamearena.com/Your_game_state_machine:_states.inc.php]. To send hidden state to specific players, return `'_private' => ['active' => [...]]` or per-player keys [https://en.doc.boardgamearena.com/Your_game_state_machine:_states.inc.php].

`onEnteringState` (modern state classes) runs once on entry and can immediately redirect by returning a class, ID, or transition name [https://en.doc.boardgamearena.com/State_classes:_State_directory]. The frontend pairs with `onEnteringState(stateName, args)` on the JS side [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js].

### Transitions

`transitions` is a name → target-id map. Multiple transition names can target the same state [https://en.doc.boardgamearena.com/Your_game_state_machine:_states.inc.php]. Every state should have a `zombiePass` transition for zombie handling [https://en.doc.boardgamearena.com/Troubleshooting].

### `possibleactions`

Strings of `act<X>` names. The action attribute / handler must call `checkAction('actX')` first or the framework will refuse the call [https://en.doc.boardgamearena.com/Players_actions:_yourgamename.action.php]. Modern state classes declare these implicitly through `#[PossibleAction]` methods [https://en.doc.boardgamearena.com/State_classes:_State_directory].

### Zombie / timeout

`zombieTurn($state, $active_player_id)` is called when the framework forces a disconnected player past a state. Common pattern: take the `zombiePass` transition; never read `getCurrentPlayerId()` here [https://en.doc.boardgamearena.com/Troubleshooting]. Modern state classes implement `zombie()` directly on the class [https://en.doc.boardgamearena.com/State_classes:_State_directory].

The recommendation in the walkthrough: "if you find yourself with a machine with more than 20 states it's probably not the way to go" — collapse trivial states into `args`/client-state where possible [https://en.doc.boardgamearena.com/Create_a_game_in_BGA_Studio:_Complete_Walkthrough].

---

## 4. Notifications

Notifications are the *only* mechanism by which the server tells the client something changed. They queue during the action and are dispatched atomically when the action returns [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].

### Public vs private

- `$this->bga->notify->all($type, $message, $args)` — broadcast to all players and spectators [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].
- `$this->bga->notify->player($player_id, $type, $message, $args)` — single recipient. Use this for any private data (hand contents, hidden draws) [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].

### Naming conventions

The notification `$type` becomes the JS handler suffix: `notify_<type>` (legacy) or auto-detected by `setupPromiseNotifications()` (modern) [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js]. Conventional naming is camelCase verb-noun: `cardPlayed`, `scoreUpdated`, `tilesRevealed`. Keep the count low — fewer named events make the JS handler easier to maintain.

### Payload patterns

- `$message` accepts `${variable}` interpolation; pass values as `$args`. Including a `player_id` key whose value matches a player's ID auto-colors `${player_name}` [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].
- Wrap human-visible strings in `clienttranslate('...')` so they extract for translation [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].
- Keep payloads small; the documented total cap is **128KB per action bundle** [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].
- Use `preserve` selectively — preserved fields persist into the replay log, which contributes to the 64MB DB ceiling [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].

### Hidden-info filtering

The discipline is enforced by code, not by the platform — the framework will faithfully relay whatever you give `notify->all`. Standard rules:

- Never put bag/deck contents, opponent hand identities, or upcoming-draw queues in any `notify->all` payload [https://en.doc.boardgamearena.com/BGA_Studio_Guidelines].
- Use `notify->player` for per-player hidden views.
- Filter the same data inside `getAllDatas()` based on `$currentPlayerId` so F5 reload does not leak it [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].
- Spectator audit: with the `spectatorMode` CSS class active, the page must hide private elements [https://en.doc.boardgamearena.com/Game_interface_stylesheet:_yourgamename.css].

---

## 5. Frontend (JS)

Lifecycle hooks on the main JS class:

- `setup(gamedatas)` — initial render from the `getAllDatas()` payload [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js].
- `onEnteringState(stateName, args)` — adapt UI per state [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js].
- `onLeavingState(stateName)` — teardown listeners, classes [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js].
- `onUpdateActionButtons(stateName, args)` — wire action bar buttons; runs on active-player change too [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js].
- `setupNotifications()` — register handlers; modern alternative `setupPromiseNotifications()` returns Promises and supports sequential animation timing via `setSynchronous()` [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js].

### Sending an action

Modern: `this.bga.actions.performAction('actX', args, { lock: true, checkAction: true })` returns a Promise [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js]. Use only in response to user input — never inside callbacks/loops, or you can race the server and disable replays [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js] [https://en.doc.boardgamearena.com/Pre-release_checklist].

Legacy: `this.ajaxcall('/<gamename>/<gamename>/<action>.html', args, this, onSuccess, onFail)` [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js].

### Animation primitives

- `slideToObject(node, target, duration, delay)` — returns a Dojo animation object; call `.play()` [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js].
- `attachToNewParent(node, parent)` — re-parents while recomputing relative position so there is no visual jump [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js].
- `placeOnObject(node, target)` — instant placement, typically used as a setup before `slideToObject` [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js].
- `fadeOutAndDestroy(node, duration, delay)` — fade then remove [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js].
- Modern `bga-animations` component exposes `slideAndAttach()` and Promise-based animation built on `Element.animate()` [https://en.doc.boardgamearena.com/BgaAnimations].
- Always check `bgaAnimationsActive()` before animating; the framework disables animations during fast replay or background tabs [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js].
- The cookbook recommends styling animatable nodes with **class selectors only**, since the framework may clone them or change parents [https://en.doc.boardgamearena.com/BgaAnimations].

### Client states

`setClientState(name, { descriptionmyturn, possibleactions })` and `restoreServerGameState()` allow multi-step UI flows (e.g., select source then target) without a server round-trip [https://en.doc.boardgamearena.com/BGA_Studio_Cookbook].

---

## 6. Frontend layout (HTML/CSS/template)

### Templates

Static markup goes in `<gamename>_<gamename>.tpl`. Dynamic markup may use a `view.php` for one-pass server render. Modern games often inline templating via JS template literals such as `` `<div id="meeple_${color}_${num}" class="meeple meeple_${color}"></div>` `` [https://en.doc.boardgamearena.com/Create_a_game_in_BGA_Studio:_Complete_Walkthrough].

### CSS rules

- **Single CSS file**: "ALL the CSS directives for your game must be included in this CSS file. You can't create additional CSS files and import them" [https://en.doc.boardgamearena.com/Game_interface_stylesheet:_yourgamename.css].
- **Z-index < 900** because BGA dialogs occupy 950 [https://en.doc.boardgamearena.com/Game_interface_stylesheet:_yourgamename.css].
- **Sprite sheets** via `background-image` + `background-position`. Use consistent units across `background-size` and `background-position` to avoid Safari rounding glitches [https://en.doc.boardgamearena.com/Game_interface_stylesheet:_yourgamename.css].
- **Dark mode**: target `[data-theme="dark"]` on the `html` element rather than `@media (prefers-color-scheme: dark)` [https://en.doc.boardgamearena.com/Game_interface_stylesheet:_yourgamename.css].
- **Spectator mode**: BGA toggles a `spectatorMode` class for spectator viewing — use it to hide private DOM [https://en.doc.boardgamearena.com/Game_interface_stylesheet:_yourgamename.css].
- **Performance**: avoid `drop-shadow` filter on Safari; prefer `box-shadow` or pre-baked image shadows; honor the `dj_safari` class for Safari-only fallbacks [https://en.doc.boardgamearena.com/Game_interface_stylesheet:_yourgamename.css].
- **Responsive**: keep dimensions as integer pixels at the active `gameinterface_zoomFactor` to avoid sub-pixel artifacts [https://en.doc.boardgamearena.com/Game_interface_stylesheet:_yourgamename.css].
- **CSS class prefix**: per pre-release, prefix all custom classes with a short game tag (e.g., `vla_selected`) to avoid collisions [https://en.doc.boardgamearena.com/Pre-release_checklist].

---

## 7. Hex grids on BGA `[NOT CONFIRMED]`

There is **no official BGA documentation page on hex grids**. The Studio docs index does not list one, and `Tile_grid` 404s [https://en.doc.boardgamearena.com/Studio]. The Cookbook only mentions hex tiles in passing, recommending `clip-path: polygon()` with aspect-ratio constraints to render hexagons [https://en.doc.boardgamearena.com/BGA_Studio_Cookbook]. Everything below is community-only and should be tagged accordingly in implementation notes.

Standard community techniques (cf. Red Blob Games' canonical guide):

- **Pointy-top vs flat-top** is purely visual — both are mathematically equivalent. Pointy-top fits boards that are taller than wide and uses `(q, r)` columns offset by row [https://www.redblobgames.com/grids/hexagons/].
- **Axial coordinates `(q, r)`** support vector arithmetic and any board shape; **offset coordinates** are simpler for rectangular grids but break under add/subtract [https://www.redblobgames.com/grids/hexagons/].
- **Hit-testing** by converting pixel → axial → cube-rounded coordinates handles arbitrary rotation cleanly [https://www.redblobgames.com/grids/hexagons/].

Two implementation strategies seen in the community `[NOT CONFIRMED]`:

1. **CSS Grid with offset rows** — even/odd rows shift half a hex width via CSS variables. Easier to lay out, harder to do vector math.
2. **Absolute axial positioning** — each hex is `position: absolute` with `left/top` computed from `(q, r)`. Click targets are the hex DOM nodes themselves; rounding is unnecessary because the click is on the element. Recommended for non-rectangular boards.

For either approach, BGA-specific constraints still apply: single CSS file, sprite sheets, z-index < 900, integer pixel dimensions at zoom factor.

---

## 8. Hidden information

The platform supplies the *plumbing*; correctness is on the developer.

- Server-only data lives in DB tables not surfaced in `getAllDatas()` (e.g., a `bag` table) [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].
- Per-player private state in `getAllDatas()` should be filtered against `$currentPlayerId` [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].
- Per-player state args use `'_private'` keyed by `'active'` or per-player IDs in the args return value [https://en.doc.boardgamearena.com/Your_game_state_machine:_states.inc.php].
- Hands and identity-of-drawn-tile go via `notify->player`, not `notify->all` [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].
- Audit by inspecting WebSocket payloads in browser dev tools; the pre-release checklist treats hidden-info leakage as a blocker [https://en.doc.boardgamearena.com/Pre-release_checklist].

---

## 9. Multiplayer synchronization

- **Server is authoritative**. Client validation is UX only; the action handler must re-validate, citing rules and throwing on violation [https://en.doc.boardgamearena.com/Players_actions:_yourgamename.action.php].
- **Race prevention**: `lock: true` on `performAction` plus the rule that `performAction` is only fired in response to user input [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js].
- **Atomic mutation**: framework-level transaction means partial state is impossible — exceptions roll back DB and notifications together [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].
- **Replay reproducibility**: `bga_rand` is seeded so replays produce the same dice/draws [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].

---

## 10. Testing & debugging

### Studio test table

Studio provides per-game test tables with start/stop and player-switch one-click controls, plus save and restore of up to three game-state snapshots [https://en.doc.boardgamearena.com/Testing_by_developer]. The recommended workflow is to save before each tricky scenario, then iterate by reloading.

### Logs

- **BGA Request & SQL logs** — surface SQL queries and custom `trace()` / `dump()` output, with timing breakdown [https://en.doc.boardgamearena.com/Studio_logs].
- **Unexpected Exceptions log** — server stack traces [https://en.doc.boardgamearena.com/Studio_logs].
- **Production error reports** — accessed via "Display recent errors from production" on the studio game page [https://en.doc.boardgamearena.com/Studio_logs].
- **Sentry-style aggregation** is the modern interface for filtering by browser/device/user [https://en.doc.boardgamearena.com/Studio_logs].

Server log helpers on the game class: `dump('name', $var)`, `trace($msg)`, `debug`, `warn`, `error` (the last two are visible in production) [https://en.doc.boardgamearena.com/Practical_debugging].

### Multi-account testing

BGA Studio provides ten developer accounts (`dev0` through `dev9`); use a second browser (or incognito session) for the second account. Do not invite non-developers to register Studio accounts — that is against BGA policy [https://en.doc.boardgamearena.com/Testing_by_developer].

### Automated testing

PHPUnit setup is documented in the Cookbook for unit-testing pure server logic locally [https://en.doc.boardgamearena.com/Testing_by_developer].

---

## 11. Common pitfalls

Explicitly called out in the docs:

- **`getCurrentPlayerId()` in setup or zombie** — there is no current player; use the parameter instead [https://en.doc.boardgamearena.com/Troubleshooting] [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].
- **`getAllDatas()` returning mixed scalar+function objects** — JSON serialization fails [https://en.doc.boardgamearena.com/Troubleshooting].
- **Missing `ajaxResponse()` in legacy action.php** — "Ajaxcall error: empty answer" [https://en.doc.boardgamearena.com/Troubleshooting].
- **Missing notification after a successful action** — client hangs on "waiting for update forever" [https://en.doc.boardgamearena.com/Troubleshooting].
- **`+` instead of `.`** for PHP string concatenation; **`==` vs `===`** for hex color comparisons; DB strings need explicit `(int)` casts [https://en.doc.boardgamearena.com/Troubleshooting].
- **Manual `BEGIN`/`COMMIT`** during gameplay — breaks the framework's transaction; pre-release checklist forbids it [https://en.doc.boardgamearena.com/Pre-release_checklist].
- **DDL outside `upgradeTableDb()`** — the implicit MySQL commit on DDL also breaks transactions [https://en.doc.boardgamearena.com/Game_database_model:_dbmodel.sql].
- **Programmatic `performAction` / `ajaxcall`** outside user-input handlers — race conditions; pre-release blocker [https://en.doc.boardgamearena.com/Pre-release_checklist].
- **`incStat()` with empty string** — duplicate-key DB error [https://en.doc.boardgamearena.com/Troubleshooting].
- **Z-index ≥ 900** — collides with BGA dialogs [https://en.doc.boardgamearena.com/Game_interface_stylesheet:_yourgamename.css].
- **`drop-shadow` on Safari** during animation leaves shadow ghosts [https://en.doc.boardgamearena.com/Game_interface_stylesheet:_yourgamename.css].
- **Multiple CSS files** — disallowed [https://en.doc.boardgamearena.com/Game_interface_stylesheet:_yourgamename.css].
- **RNG not via `bga_rand`** — fails replay reproducibility and the pre-release bar [https://en.doc.boardgamearena.com/BGA_Studio_Cookbook].

Community-known traps `[NOT CONFIRMED]`: per-piece notifications during a multi-piece atomic resolution can leak hidden state through arrival order; large sprite sheets above 4MB violate the per-image cap [https://en.doc.boardgamearena.com/Pre-release_checklist].

---

## 12. Submission process

Three lifecycle stages: dev → alpha → beta → production [https://en.doc.boardgamearena.com/BGA_Studio_Guidelines].

### Dev to Alpha

The pre-release checklist is binding [https://en.doc.boardgamearena.com/Pre-release_checklist]. Highlights:

- License from the publisher must already be in place — even for alpha [https://en.doc.boardgamearena.com/Pre-release_checklist].
- Server must implement `getGameProgression()`, `zombieTurn()`, `giveExtraTime()`, meaningful stats, tiebreaker logic [https://en.doc.boardgamearena.com/Pre-release_checklist].
- Client side: `ajaxcall`/`bgaPerformAction` only on user input; no programmatic action loops [https://en.doc.boardgamearena.com/Pre-release_checklist].
- All English strings checked for grammar; all gameplay-relevant strings wrapped in translation calls [https://en.doc.boardgamearena.com/Pre-release_checklist].
- Image budget: each image < 4MB, total assets < 15MB; sprite-sheet anything that can be sprited [https://en.doc.boardgamearena.com/Pre-release_checklist].
- All custom CSS classes prefixed; no spurious `console.log`; copyright headers in every file [https://en.doc.boardgamearena.com/Pre-release_checklist].
- "Dry run build" + "Check project" static analysis must be clean [https://en.doc.boardgamearena.com/Pre-release_checklist].
- Spectator mode, in-game replay, and full replay all verified manually [https://en.doc.boardgamearena.com/Pre-release_checklist].
- Chrome and Firefox required; Edge and Safari recommended; mobile responsive [https://en.doc.boardgamearena.com/Pre-release_checklist].
- Click "Request ALPHA status"; reply with the formal name, production username, license/approval emails, and any exception requests [https://en.doc.boardgamearena.com/Pre-release_checklist].

### Alpha to Beta

- Publisher approval required [https://en.doc.boardgamearena.com/Pre-release_checklist].
- 10+ approvals from reviewers with rank > 3.5 (or game-rating ≥ 4.5 to bypass that) [https://en.doc.boardgamearena.com/Pre-release_checklist].

### Quality bar

The BGA team and the publisher both retain veto. Three foundational principles for the implementation [https://en.doc.boardgamearena.com/BGA_Studio_Guidelines]:

1. "If a player knows the real board game, they should be able to play your adaptation with no learning."
2. "Fidelity to the original game is an absolute requirement."
3. "Don't try to create a video game: make your game interface as close as possible to how the original board game looks."
