# HAL Dry-Run Output (BGA Studio static checker)

> **Historical record, still-useful checklist.** This is the verbatim output of a
> BGA Studio dry-run build plus the modern-framework patterns it pointed to. Every
> item listed here has since been fixed locally; re-run the dry run after your first
> upload and expect a clean report.

Captured from `studio.boardgamearena.com/studiogame?game=hexpionage` "Dry run build" action.

## Verbatim output

```
Retrieve game from studio game server ✅
Project code checks ✅ ⚠️ ⚠️ ✅ ✅ ✅
** HAL says: All good for images!

You have a modules/php/Game.php file and a hexpionage.game.php file.
Remove the unused hexpionage.game.php from your FTP repository).

The player_score / player_score_aux fields can be manipulated using the
$this->bga->playerScore / $this->bga->playerScoreAux counters.

hexpionage/hexpionage.game.php:71 => self::DbQuery("UPDATE player SET player_score = 0, agents_remaining = 12, blockades_remaining = 3");
hexpionage/hexpionage.game.php:969 => "UPDATE player SET player_score = player_score + $score_delta WHERE player_id = $active");
hexpionage/hexpionage.game.php:1722 => self::DbQuery("UPDATE player SET player_score = player_score + $score_value WHERE player_id = $active");

The _ function is deprecated, please use clienttranslate instead.
See Managing errors and exceptions to include parameters in an exception translation.

[~80 lines of `self::_()` calls in hexpionage.game.php at lines 578-1772]
[20 lines of `self::_()` calls in hexpionage.view.php at lines 39-71]
hexpionage/hexpionage.view.php:134 => <?= $this->_('Hexpionage board') ?>

You have a modules/js/Game.js file and a hexpionage.js file.
Remove the unused hexpionage.js from your FTP repository.

The this.scoreCtrl array should not be accessed directly,
please use the this.bga.playerPanels.getScoreCounter function.

hexpionage/hexpionage.js:2182 => if (typeof this.scoreCtrl === "object" && this.scoreCtrl[a.player_id]) {
hexpionage/hexpionage.js:2183 => this.scoreCtrl[a.player_id].toValue(a.new_score);

** HAL says: All good for CSS files!

Reload game informations 🚨
Deprecated keys complexity, strategy, luck, diplomacy are no longer in use.
Please remove these keys and use the Game Metadata Manager instead.

ERROR: Wrong type for gameinfos.suggest_player_number, expected integer, got array.

Reload game options ✅
Reload game user preferences ✅
Reload statistics ✅

Done dry run on game hexpionage (6 ⚠️ 1 🚨).
```

## Required actions (extracted)

| # | Severity | What | Where | Fix |
|---|---|---|---|---|
| 1 | 🚨 ERROR | `suggest_player_number` is array, must be integer | `gameinfos.jsonc` | `[2]` → `2` |
| 2 | ⚠️ | Deprecated keys | `gameinfos.jsonc` | Remove `complexity`, `strategy`, `luck`, `diplomacy` |
| 3 | ⚠️ | Two main classes (legacy + modern coexist) | root | Delete `hexpionage.game.php` after migrating its content to `modules/php/Game.php` |
| 4 | ⚠️ | Two JS clients (legacy + modern coexist) | root | Delete `hexpionage.js` after migrating to `modules/js/Game.js` |
| 5 | ⚠️ | Direct SQL `UPDATE player_score` | game.php:71, :969, :1722 | Use `$this->bga->playerScore->initDb()` / `inc()` |
| 6 | ⚠️ | Deprecated `self::_()` translation function | game.php (~80 lines), view.php (~20 lines) | Replace with `clienttranslate()` |
| 7 | ⚠️ | Direct `this.scoreCtrl` access | hexpionage.js:2182-2183 | Use `this.bga.playerPanels.getScoreCounter()` |

## Architecture mismatches (also detected; encompassed by items 3+4)

These are larger structural fixes the refactor must cover, beyond what HAL flagged:
- Main class file/name/namespace: `hexpionage.game.php` (root, class `hexpionage`, no namespace) → `modules/php/Game.php` (class `Game`, namespace `Bga\Games\Hexpionage`, extends `\Bga\GameFramework\Table`).
- 10 state classes: property-based ID declaration → constructor-based per modern BGA framework.
- State transitions: string names (`'next' => 'trickleDrawRight'`) → class references (`return TrickleDrawRight::class;`).
- `PossibleAction` import: `Bga\GameFramework\Actions\PossibleAction` → `Bga\GameFramework\States\PossibleAction`.
- 3 BGA stub state classes (`PlayerTurn`, `NextPlayer`, `EndScore`) on the remote conflict with my state IDs (10 and 90); orchestrator will delete them post-refactor.

---

## Canonical reference: Reversi tutorial patterns

Source: https://en.doc.boardgamearena.com/Tutorial_reversi (most up-to-date reference per owner).

### Pattern 1 — Game.php main class

```php
<?php
declare(strict_types=1);
namespace Bga\Games\Hexpionage;

use Bga\Games\Hexpionage\States\GameSetup;  // initial state (or whichever your first is)

class Game extends \Bga\GameFramework\Table
{
    public function __construct() {
        parent::__construct();
        // global var labels, counter factories, etc.
    }

    protected function setupNewGame($players, $options = []) {
        // ... setup ...
        return GameSetup::class;  // initial state — class reference, NOT string
    }

    protected function getAllDatas(int $currentPlayerId): array {
        // ... return public state, filtered by $currentPlayerId for hidden info ...
    }

    public function getGameProgression(): int { ... }
    public function upgradeTableDb($from_version) { /* migrations */ }
}
```

### Pattern 2 — State class with action methods (canonical)

**IMPORTANT**: in the modern framework, **action methods live on the state class** that authorizes them, not on the main Game class. They are decorated with `#[PossibleAction]` from `Bga\GameFramework\States\PossibleAction`.

```php
<?php
declare(strict_types=1);
namespace Bga\Games\Hexpionage\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\Hexpionage\Game;

class Spawn extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 20,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must spawn agents'),
            descriptionMyTurn: clienttranslate('${you} must spawn agents (or pass)'),
        );
    }

    public function getArgs(): array {
        // Returns per-state data the client uses to render this state.
        return [ 'available_agents' => [...], 'legal_hexes' => [...] ];
    }

    #[PossibleAction]
    public function actSpawnAgent(int $agent_id, int $hex_q, int $hex_r, int $activePlayerId): string {
        // validate inputs, throw UserException on invalid
        // mutate state via $this->game (DbQuery, etc.)
        // emit notifications via $this->game->bga->notify->all(...)
        // return next state class:
        return Spawn::class;  // self-loop until pass
    }

    #[PossibleAction]
    public function actPassSpawn(int $activePlayerId): string {
        return Actions::class;
    }

    public function zombie(int $playerId): string {
        return $this->actPassSpawn($playerId);
    }
}
```

Key notes:
- `description` and `descriptionMyTurn` use `clienttranslate('${actplayer} must X')` / `clienttranslate('${you} must X')` — these become the BGA status-bar text.
- Action methods accept `$activePlayerId` as a magic parameter the framework injects.
- Action methods return either `string` (state class name resolved via `::class`) or `?string` (null = stay).
- `getArgs()` is automatically called by the framework on state entry; its return is shipped to the client as `args` in `onEnteringState(args, isCurrentPlayerActive)`.

### Pattern 3 — Score updates

```php
$this->bga->playerScore->set($playerId, $tokens);  // sets to absolute value, auto-updates JS counter
$this->bga->playerScore->inc($playerId, $delta);   // increment
$this->bga->playerScore->initDb($playerIds, initialValue: 0);  // setup
```

The JS client sees this automatically via the framework — no manual notification needed.

### Pattern 4 — Notifications

```php
$this->bga->notify->all("eventName", clienttranslate('${player_name} did X'), [
    "player_id" => $playerId,
    "player_name" => $this->getPlayerNameById($playerId),
    // ... payload ...
]);
$this->bga->notify->player($playerId, "privateEvent", "", [...]);  // private to one player
```

### Pattern 5 — JS state class structure

JS client modules go in `modules/js/States/<StateName>.js` (or bundled into `modules/js/Game.js`). Each has:
```js
onEnteringState(args, isCurrentPlayerActive) {
    // setup the UI for this state using args
}
onLeavingState(stateName) {
    // cleanup
}
onUpdateActionButtons(stateName, args) {
    // add action buttons
}
// notification handlers as methods
notif_eventName(args) { ... }
```

Server actions called via `this.bgaPerformAction("actSpawnAgent", { agent_id: 5, hex_q: 0, hex_r: 3 })`.

### Pattern 6 — Player score JS access

DEPRECATED: `this.scoreCtrl[player_id].toValue(score)` — direct access to internal controllers.
USE: `this.bga.playerPanels.getScoreCounter(player_id)` or rely on framework auto-update from `$this->bga->playerScore->set()`.

### Pattern 7 — `getAllDatas` filtering

Returns public state filtered by `$currentPlayerId`. Hidden info (e.g., bag contents, private hands) must be filtered server-side. Use `$this->getCollectionFromDb` for tabular data, hand-build arrays for nested.

### Action-on-state migration (large delta from current code)

The Hexpionage codebase currently has ~16 `actX` methods on the `hexpionage` class with `#[PossibleAction]`. In the modern pattern, those methods belong on the relevant state class:

| Action | Current location | Should move to |
|---|---|---|
| `actSpawnAgent`, `actPassSpawn` | Game.php | Spawn.php |
| `actMoveAgent`, `actTransferIntel`, `actRetireAgent`, `actEngineerPlaceBlockadeAdjacent`, `actEngineerPlaceBlockadeAnywhere`, `actSmugglerBoostActions`, `actSmugglerSwapAgents`, `actCommsMoveIntelUp`, `actCommsMoveIntelDown`, `actDoubleAgentTransfer`, `actHackerPin`, `actHackerUnpin`, `actHackerStealIntel`, `actPassActions` | Game.php | Actions.php |
| `actAnalystKeep`, `actAnalystReturn` | Game.php | AnalystBonusDecision.php |

Within each method, `$this->game->...` replaces `$this->...` since logic helpers live on the Game class but the method runs in state-class scope.

This migration is part of the refactor scope — actions MUST live on state classes for modern BGA compatibility.
