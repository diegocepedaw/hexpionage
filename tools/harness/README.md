# `tools/harness` — offline BGA test rig

Runs the real `src/` game logic on a laptop, without BGA Studio.

```bash
../check.sh                                   # everything
php run_tests.php --games=100                 # simulate games
php run_tests.php --games=1 --seed=999 --verbose
php check_contract.php                        # static PHP <-> JS cross-check
```

Requires PHP 8.1+ with PDO SQLite (both are in Homebrew's `php`). Node is only used
by `check.sh` for a JS syntax check.

## Why it exists

BGA game code normally only runs inside Studio, which makes the edit→test loop
minutes long and hides whole classes of bug until a live table. This rig replaces the
thin slice of framework Hexpionage actually touches, so a full game runs in ~0.2 s and
300 games run in ~70 s.

## Files

| File | Role |
|---|---|
| `bga_stub.php` | Re-implements the BGA API surface the game uses: `\Bga\GameFramework\Table` (DB accessors, stats, player helpers), `States\GameState`, `StateType`, the `#[PossibleAction]` attribute, `$this->bga->{globals,notify,playerScore}`, `$this->gamestate`, `bga_rand()`, `clienttranslate()`, `BgaUserException`. Backs the DB with in-memory SQLite. |
| `engine.php` | The state-machine driver. Instantiates the state classes, runs `setupNewGame()`, then loops: GAME states run `onEnteringState()` and follow the returned state; ACTIVE_PLAYER states run `getArgs()` and wait. Resolves a returned class-string / int id / `null` the same way BGA does, and dispatches actions state-class-first then Game-class-fallback. |
| `bot.php` | Picks a random legal move **using only the `getArgs()` payload the real client receives**. If the bot can't find a move, the UI couldn't render one either. |
| `run_tests.php` | Static checks, setup checks, board-layout checks, `getAllDatas()` contract checks, N seeded playouts with per-action invariant assertions, and a coverage report. |
| `check_contract.php` | Pure static analysis of `src/`: notification names, notification handlers, action names, **action parameter names**, state names vs client `case` branches, and config-file sanity. |

## Design rules

- **The stub never patches game behaviour.** If something needs a workaround to run,
  that is a bug in `src/`, not in the stub.
- **The schema is not duplicated.** `HarnessDb::translate()` converts `src/dbmodel.sql`
  from MySQL to SQLite at runtime (strips backticks/`ENGINE=`/`KEY` lines, folds
  `AUTO_INCREMENT` + `PRIMARY KEY` into `INTEGER PRIMARY KEY AUTOINCREMENT`, splits
  multi-clause `ALTER TABLE`). Change `dbmodel.sql` and the harness follows.
- **Config is not duplicated.** `getGameinfos()` parses `src/gameinfos.jsonc`.
- **RNG is seeded and deterministic** (xorshift32, not `mt_rand`), so every failure is
  reproducible from the seed printed in the message.
- **PHP warnings from `src/` are fatal** to the test run.

## Adding to it

- New rule invariant → add a SQL check to `assertInvariants()` in `run_tests.php`.
  It then has to hold after every action of every simulated game.
- New action → add its `legal_actions` shape to `RandomBot::expand()` so the
  simulation can play it; `check_contract.php` will already be checking its parameter
  names against the client.
- New framework API used by `src/` → add the smallest possible stub for it, and note
  that it is now unverified against the real framework until Studio testing.

## What it does not cover

Rendering, CSS, animations, real framework API fidelity, multiplayer/turn timers,
zombie players, spectators, and the ~128 KB state-args payload cap. Those need a
Studio test table (see `ONBOARDING.md` §5.2).
