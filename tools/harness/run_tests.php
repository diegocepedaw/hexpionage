<?php
/**
 * run_tests.php — Hexpionage offline test runner.
 *
 * Usage:
 *   php tools/harness/run_tests.php              # default: 40 seeded games
 *   php tools/harness/run_tests.php --games=200
 *   php tools/harness/run_tests.php --seed=7 --verbose
 *
 * What it checks
 *   1. STATIC   — every state class instantiates, ids are unique and not
 *                 BGA-reserved, every ACTIVE_PLAYER state has a description,
 *                 descriptionMyTurn and zombie() handler.
 *   2. SETUP    — setupNewGame() builds 24 agents, 47 intel, correct scores.
 *   3. CONTRACT — getAllDatas() exposes the keys the JS client reads, and
 *                 never leaks a bag tile's type_id.
 *   4. PLAYOUT  — N full random-legal-move games run to a terminal state with
 *                 per-action invariants asserted after every single action.
 *   5. COVERAGE — reports which act* handlers and notification types were
 *                 actually exercised.
 *
 * Exit code 0 = all green.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Any PHP warning/notice raised by game code is a defect: BGA logs them and
// they usually mean an undefined index or a bad type. Fail loudly.
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if (str_contains($file, '/src/')) {
        throw new ErrorException("$msg", 0, $no, $file, $line);
    }
    return false;
});

require_once __DIR__ . '/bga_stub.php';

HarnessPaths::$root = dirname(__DIR__, 1);
HarnessPaths::$root = dirname(__DIR__, 2);
HarnessPaths::$src = HarnessPaths::$root . '/src';

require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/bot.php';

// Matched case-insensitively: PHP resolves namespaces case-insensitively, so a
// case-sensitive prefix test here would silently fail to autoload and surface
// as a bogus "class not found" instead of the real error.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Bga\\Games\\hexpionage\\';
    if (stripos($class, $prefix) !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = HarnessPaths::$src . '/modules/php/' . $rel . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// ---------------------------------------------------------------------------
// Tiny assertion framework
// ---------------------------------------------------------------------------

final class T
{
    public static int $pass = 0;
    /** @var string[] */
    public static array $fail = [];
    public static array $notes = [];

    public static function ok(bool $cond, string $what): void
    {
        if ($cond) { self::$pass++; } else { self::$fail[] = $what; }
    }
    public static function eq($expected, $actual, string $what): void
    {
        self::ok($expected == $actual, $what . " (expected " . json_encode($expected) . ", got " . json_encode($actual) . ")");
    }
    public static function note(string $s): void { self::$notes[] = $s; }
}

// ---------------------------------------------------------------------------
// Options
// ---------------------------------------------------------------------------

$opts = getopt('', ['games::', 'seed::', 'verbose', 'trace']);
$numGames = (int)($opts['games'] ?? 40);
$baseSeed = (int)($opts['seed'] ?? 12345);
$verbose = isset($opts['verbose']);

const PLAYERS = [
    2323231 => ['player_name' => 'Alice', 'player_canal' => 'c1', 'player_avatar' => '000'],
    2323232 => ['player_name' => 'Bob',   'player_canal' => 'c2', 'player_avatar' => '000'],
];

function bootGame(int $seed): Engine
{
    HarnessRng::seed($seed);
    HarnessDb::install(HarnessPaths::$src . '/dbmodel.sql', array_keys(PLAYERS));
    $engine = new Engine();
    $engine->assertStateIdsValid();
    $engine->setup(PLAYERS);
    return $engine;
}

// ---------------------------------------------------------------------------
// 1. STATIC checks
// ---------------------------------------------------------------------------

echo "== 1. static state-machine checks ==\n";
HarnessDb::install(HarnessPaths::$src . '/dbmodel.sql', array_keys(PLAYERS));
$e = new Engine();
try {
    $e->assertStateIdsValid();
    T::ok(true, 'state ids are unique and non-reserved');
} catch (Throwable $ex) {
    T::ok(false, 'state ids: ' . $ex->getMessage());
}

foreach ($e->states as $fq => $state) {
    $short = (new ReflectionClass($state))->getShortName();
    if ($state->stateType === \Bga\GameFramework\StateType::ACTIVE_PLAYER) {
        T::ok((bool)$state->description, "$short declares description");
        T::ok((bool)$state->descriptionMyTurn, "$short declares descriptionMyTurn");
        T::ok(method_exists($state, 'zombie'), "$short declares zombie()");
        T::ok(method_exists($state, 'getArgs'), "$short declares getArgs()");
    } else {
        T::ok(method_exists($state, 'onEnteringState'), "$short (GAME) declares onEnteringState()");
    }
}

$actions = $e->allActions();
T::eq(18, count($actions), 'act* handler count');
foreach (['actSpawnAgent', 'actPassSpawn', 'actPassActions', 'actAnalystKeep', 'actAnalystReturn'] as $a) {
    T::ok(isset($actions[$a]), "handler $a is dispatchable");
}

// ---------------------------------------------------------------------------
// 2. SETUP checks
// ---------------------------------------------------------------------------

echo "== 2. setupNewGame checks ==\n";
$e = bootGame($baseSeed);
T::eq(24, (int)HarnessDb::exec("SELECT COUNT(*) FROM agent")->fetchColumn(), 'agent rows');
T::eq(47, (int)HarnessDb::exec("SELECT COUNT(*) FROM intel_tile")->fetchColumn(), 'intel rows');
T::eq(2, (int)HarnessDb::exec("SELECT COUNT(*) FROM player")->fetchColumn(), 'player rows');
T::eq(0, (int)HarnessDb::exec("SELECT SUM(player_score) FROM player")->fetchColumn(), 'scores start at 0');
T::eq(24, (int)HarnessDb::exec("SELECT SUM(agents_remaining) FROM player")->fetchColumn(), 'agent pools start full');
T::eq(6, (int)HarnessDb::exec("SELECT SUM(blockades_remaining) FROM player")->fetchColumn(), 'blockade pools start full');
$perType = HarnessDb::exec("SELECT type_id, COUNT(*) c FROM agent WHERE owner = 2323231 GROUP BY type_id")->fetchAll(PDO::FETCH_KEY_PAIR);
T::eq(6, count($perType), 'each player has all 6 agent types');
T::ok(array_sum($perType) === 12 && max($perType) === 2, 'each player has 2 of each agent type');

echo "== 2b. board layout checks ==\n";
require_once HarnessPaths::$src . '/material.inc.php';
T::eq(30, count(ALL_FIELD_HEXES), 'Field hex count (design/BOARD_LAYOUT.md)');
T::eq(14, count(ALL_ORANGE_HEXES), 'orange intel-rain hex count');
T::eq(9, count(ALL_SPAWN_ROW_HEXES), 'spawn row hex count');
T::eq(47, array_sum(INTEL_TILE_COUNTS), 'intel tile total');
$key = static fn(array $h) => $h['q'] . ',' . $h['r'];
$fieldKeys = array_map($key, ALL_FIELD_HEXES);
$orangeKeys = array_map($key, ALL_ORANGE_HEXES);
T::eq(count($fieldKeys), count(array_unique($fieldKeys)), 'no duplicate Field hexes');
T::eq(count($orangeKeys), count(array_unique($orangeKeys)), 'no duplicate orange hexes');
T::eq(0, count(array_intersect($fieldKeys, $orangeKeys)), 'Field and orange hexes do not overlap');
T::eq(0, count(array_diff(array_map($key, ALL_SPAWN_ROW_HEXES), $fieldKeys)), 'spawn row is a subset of the Field');
T::ok(hexpionage_is_orange_hex(INTEL_ENTRY_HEX_TOP_LEFT['q'], INTEL_ENTRY_HEX_TOP_LEFT['r']), 'left intel entry is an orange hex');
T::ok(hexpionage_is_orange_hex(INTEL_ENTRY_HEX_TOP_RIGHT['q'], INTEL_ENTRY_HEX_TOP_RIGHT['r']), 'right intel entry is an orange hex');
// Every board hex must be reachable from its neighbours (no isolated islands).
$all = array_merge($fieldKeys, $orangeKeys);
$isolated = [];
foreach (array_merge(ALL_FIELD_HEXES, ALL_ORANGE_HEXES) as $h) {
    $touching = 0;
    foreach (hexpionage_hex_neighbors($h['q'], $h['r']) as $n) {
        if (in_array($n['q'] . ',' . $n['r'], $all, true)) { $touching++; }
    }
    if ($touching === 0) { $isolated[] = $key($h); }
}
T::eq(0, count($isolated), 'no isolated board hexes (' . implode(' ', $isolated) . ')');

// ---------------------------------------------------------------------------
// 3. CONTRACT (getAllDatas) checks
// ---------------------------------------------------------------------------

echo "== 3. getAllDatas contract checks ==\n";
$datas = $e->getAllDatas();
foreach (['players', 'agents', 'intel_on_board', 'intel_revealed', 'scored_intel',
          'blockades', 'board_layout', 'bag_size', 'turn_id', 'phase',
          'active_player', 'actions_remaining', 'dice_state'] as $k) {
    T::ok(array_key_exists($k, $datas), "getAllDatas exposes '$k'");
}
T::ok(is_array($datas['board_layout'] ?? null) && !empty($datas['board_layout']), 'board_layout is populated');

$bagIds = array_map('intval', HarnessDb::exec(
    "SELECT id FROM intel_tile WHERE state IN (0,4)")->fetchAll(PDO::FETCH_COLUMN));
$leaked = 0;
foreach (['intel_on_board', 'intel_revealed', 'scored_intel'] as $bucket) {
    foreach (($datas[$bucket] ?? []) as $tile) {
        if (in_array((int)($tile['id'] ?? -1), $bagIds, true)) {
            $leaked++;
        }
    }
}
T::eq(0, $leaked, 'no in-bag tile is shipped to the client (hidden-info filter)');
T::eq(47 - count($bagIds), count($datas['intel_on_board'] ?? []) + count($datas['scored_intel'] ?? []),
    'every non-bag tile is accounted for in the client payload');

// ---------------------------------------------------------------------------
// 4. PLAYOUT
// ---------------------------------------------------------------------------

echo "== 4. random playouts ($numGames games) ==\n";

/** Invariants that must hold after EVERY action, in every game. */
function assertInvariants(Engine $engine, string $ctx): array
{
    $errs = [];
    $one = static fn(string $sql) => HarnessDb::exec($sql)->fetchColumn();

    if ((int)$one("SELECT COUNT(*) FROM agent") !== 24) $errs[] = 'agent row count changed';
    if ((int)$one("SELECT COUNT(*) FROM intel_tile") !== 47) $errs[] = 'intel row count changed';

    // An on-board agent must have coordinates; a pooled/removed one must not.
    if ((int)$one("SELECT COUNT(*) FROM agent WHERE state = 1 AND (hex_q IS NULL OR hex_r IS NULL)") > 0) {
        $errs[] = 'on-board agent without coordinates';
    }
    if ((int)$one("SELECT COUNT(*) FROM agent WHERE state != 1 AND hex_q IS NOT NULL") > 0) {
        $errs[] = 'off-board agent still has coordinates';
    }
    // No two agents on the same hex.
    if ((int)$one("SELECT COUNT(*) FROM (SELECT hex_q, hex_r FROM agent WHERE state = 1 GROUP BY hex_q, hex_r HAVING COUNT(*) > 1)") > 0) {
        $errs[] = 'two agents share a hex';
    }
    // Spawn cap: at most 3 on board per player.
    foreach (array_keys(PLAYERS) as $pid) {
        if ((int)$one("SELECT COUNT(*) FROM agent WHERE owner = $pid AND state = 1") > 3) {
            $errs[] = "player $pid has more than 3 agents on board";
        }
        $pool = (int)$one("SELECT agents_remaining FROM player WHERE player_id = $pid");
        $notPooled = (int)$one("SELECT COUNT(*) FROM agent WHERE owner = $pid AND state = 0");
        if ($pool !== $notPooled) {
            $errs[] = "player $pid agents_remaining ($pool) != in-pool rows ($notPooled)";
        }
        if ((int)$one("SELECT player_score FROM player WHERE player_id = $pid") < 0) {
            $errs[] = "player $pid has a negative score";
        }
    }
    // Intel state/foreign-key consistency.
    if ((int)$one("SELECT COUNT(*) FROM intel_tile WHERE state = 2 AND agent_id IS NULL") > 0) {
        $errs[] = 'held intel with no agent';
    }
    if ((int)$one("SELECT COUNT(*) FROM intel_tile i JOIN agent a ON a.id = i.agent_id WHERE i.state = 2 AND a.state != 1") > 0) {
        $errs[] = 'intel held by an agent that is not on the board';
    }
    if ((int)$one("SELECT COUNT(*) FROM intel_tile WHERE state = 1 AND (hex_q IS NULL OR hex_r IS NULL)") > 0) {
        $errs[] = 'loose intel without coordinates';
    }
    if ((int)$one("SELECT COUNT(*) FROM intel_tile WHERE state IN (0,4) AND (agent_id IS NOT NULL OR hex_q IS NOT NULL)") > 0) {
        $errs[] = 'bagged intel still has a location';
    }
    if ((int)$one("SELECT COUNT(*) FROM intel_tile WHERE state = 3 AND scored_by IS NULL") > 0) {
        $errs[] = 'scored intel with no owner';
    }
    // A hex can never hold both an agent and loose intel (INVARIANT-PICKUP, D-21).
    if ((int)$one("SELECT COUNT(*) FROM agent a JOIN intel_tile i ON i.hex_q = a.hex_q AND i.hex_r = a.hex_r
                    WHERE a.state = 1 AND i.state = 1") > 0) {
        $errs[] = 'INVARIANT-PICKUP violated: loose intel sits under an agent';
    }
    // Carrying limit: 3 intel per agent.
    if ((int)$one("SELECT COUNT(*) FROM (SELECT agent_id FROM intel_tile WHERE state = 2 GROUP BY agent_id HAVING COUNT(*) > 3)") > 0) {
        $errs[] = 'agent carrying more than 3 intel';
    }
    // Blockade cap: 3 per player on board.
    foreach (array_keys(PLAYERS) as $pid) {
        if ((int)$one("SELECT COUNT(*) FROM blockade WHERE owner = $pid AND state = 1") > 3) {
            $errs[] = "player $pid has more than 3 blockades on board";
        }
    }
    // Score bookkeeping must equal the sum of scored tiles.
    foreach (array_keys(PLAYERS) as $pid) {
        $score = (int)$one("SELECT player_score FROM player WHERE player_id = $pid");
        $tiles = (int)$one("SELECT COALESCE(SUM(score_value),0) FROM intel_tile WHERE state = 3 AND scored_by = $pid");
        if ($score !== $tiles) {
            $errs[] = "player $pid score ($score) != sum of scored tiles ($tiles)";
        }
    }

    return array_map(static fn($m) => "$ctx: $m", $errs);
}

$totalCoverage = [];
$notifTypes = [];
$outcomes = ['score_20' => 0, 'depletion' => 0, 'step_limit' => 0];
$failures = [];
$turnsSeen = [];

for ($g = 0; $g < $numGames; $g++) {
    $seed = $baseSeed + $g * 7919;
    try {
        $engine = bootGame($seed);
        $bot = new RandomBot($engine);
        $actionsPlayed = 0;

        foreach (assertInvariants($engine, "seed $seed / setup") as $err) {
            $failures[] = $err;
        }

        while ($engine->runToDecision()) {
            $state = $engine->stateName();
            $action = $bot->step();
            $actionsPlayed++;
            $errs = assertInvariants($engine, "seed $seed after $state/$action (#$actionsPlayed)");
            if ($errs) {
                $failures = array_merge($failures, $errs);
                break;
            }
            if ($actionsPlayed > 4000) {
                $outcomes['step_limit']++;
                break;
            }
        }

        $turnsSeen[] = (int)$engine->game->bga->globals->get('turn_id');
        foreach ($bot->coverage as $k => $v) {
            $totalCoverage[$k] = ($totalCoverage[$k] ?? 0) + $v;
        }
        foreach ($engine->game->bga->notify->log as $n) {
            $notifTypes[$n['type']] = ($notifTypes[$n['type']] ?? 0) + 1;
            if ($n['type'] === 'gameEnded') {
                $outcomes[$n['args']['win_reason'] ?? '?'] =
                    ($outcomes[$n['args']['win_reason'] ?? '?'] ?? 0) + 1;
            }
        }
        if ($verbose) {
            printf("  seed %-8d turns=%-4d actions=%-5d final=%s\n",
                $seed, (int)$engine->game->bga->globals->get('turn_id'), $actionsPlayed,
                json_encode(HarnessDb::exec("SELECT player_id, player_score FROM player")->fetchAll(PDO::FETCH_KEY_PAIR)));
        }
    } catch (Throwable $ex) {
        $failures[] = sprintf("seed %d threw %s: %s\n      at %s:%d",
            $seed, get_class($ex), $ex->getMessage(), $ex->getFile(), $ex->getLine());
        if ($verbose) {
            echo $ex->getTraceAsString() . "\n";
        }
    }
}

T::ok(empty($failures), 'all playouts completed without invariant violations');
T::ok(($outcomes['score_20'] + $outcomes['depletion']) > 0, 'at least one game reached a terminal state');

// ---------------------------------------------------------------------------
// 5. COVERAGE
// ---------------------------------------------------------------------------

echo "== 5. coverage ==\n";
ksort($totalCoverage);
$never = [];
foreach (array_keys($actions) as $a) {
    if (!isset($totalCoverage[$a])) {
        $never[] = $a;
    }
}
foreach ($totalCoverage as $a => $n) {
    printf("  %-36s %6d\n", $a, $n);
}
if ($never) {
    echo "  NOT EXERCISED: " . implode(', ', $never) . "\n";
}
ksort($notifTypes);
echo "  notification types emitted: " . count($notifTypes) . " (" . implode(', ', array_keys($notifTypes)) . ")\n";
echo "  outcomes: " . json_encode($outcomes) . "\n";
if ($turnsSeen) {
    printf("  turns per game: min=%d max=%d avg=%.1f\n",
        min($turnsSeen), max($turnsSeen), array_sum($turnsSeen) / count($turnsSeen));
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

echo "\n";
if ($failures) {
    echo "FAILURES (" . count($failures) . ", showing first 25):\n";
    foreach (array_slice(array_unique($failures), 0, 25) as $f) {
        echo "  - $f\n";
    }
    echo "\n";
}
foreach (T::$fail as $f) {
    echo "  ASSERT FAIL: $f\n";
}
printf("%d assertions passed, %d failed, %d playout failures\n",
    T::$pass, count(T::$fail), count($failures));

exit((T::$fail || $failures) ? 1 : 0);
