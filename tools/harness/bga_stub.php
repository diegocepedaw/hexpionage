<?php
/**
 * bga_stub.php — a minimal, offline re-implementation of the slice of the BGA
 * framework that Hexpionage actually uses.
 *
 * PURPOSE
 *   BGA game code can normally only run inside BGA Studio. That makes it very
 *   slow to catch "does this even execute?" class bugs. This stub lets the real
 *   `src/modules/php/**` code run against an in-memory SQLite database on a
 *   laptop, so we can drive whole games and assert invariants in ~1 second.
 *
 * SCOPE / NON-GOALS
 *   This is NOT an emulator. It reproduces API *shapes* and *semantics* that
 *   Hexpionage depends on, nothing more. Passing here means "the code runs and
 *   the rules engine is self-consistent". It does NOT prove BGA Studio
 *   compatibility — only a Studio dry-run + test table can do that.
 *
 * Keep this file in sync with:
 *   - src/dbmodel.sql          (schema is translated automatically, see Db::install)
 *   - the framework surface listed in tools/harness/README.md
 */

declare(strict_types=1);

namespace {

// ---------------------------------------------------------------------------
// Database layer (SQLite standing in for BGA's MySQL)
// ---------------------------------------------------------------------------

final class HarnessDb
{
    public static ?PDO $pdo = null;
    /** @var string[] ring buffer of recent statements, for debugging */
    public static array $log = [];
    public const LOG_LIMIT = 500;

    public static function install(string $dbmodelPath, array $playerIds): void
    {
        self::$log = [];
        self::$pdo = new PDO('sqlite::memory:');
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // BGA supplies the `player` table; recreate the columns this game touches.
        self::$pdo->exec("CREATE TABLE player (
            player_id INTEGER PRIMARY KEY,
            player_no INTEGER NOT NULL DEFAULT 0,
            player_name TEXT NOT NULL DEFAULT '',
            player_color TEXT NOT NULL DEFAULT '',
            player_canal TEXT NOT NULL DEFAULT '',
            player_avatar TEXT NOT NULL DEFAULT '',
            player_score INTEGER NOT NULL DEFAULT 0,
            player_score_aux INTEGER NOT NULL DEFAULT 0,
            player_zombie INTEGER NOT NULL DEFAULT 0,
            player_eliminated INTEGER NOT NULL DEFAULT 0
        )");
        // NOTE: rows are NOT pre-inserted. Real BGA games INSERT their own
        // player rows inside setupNewGame(), and Hexpionage does exactly that.

        foreach (self::translate(file_get_contents($dbmodelPath)) as $stmt) {
            self::$pdo->exec($stmt);
        }
    }

    /**
     * Translate the MySQL dbmodel.sql into SQLite-compatible statements so the
     * harness never drifts from the real schema.
     *
     * @return string[]
     */
    public static function translate(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $sql = str_replace('`', '', $sql);

        $out = [];
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if (stripos($stmt, 'ALTER TABLE') === 0) {
                // SQLite allows only one ADD COLUMN per ALTER statement.
                if (!preg_match('/ALTER TABLE\s+(\w+)\s+(.*)/is', $stmt, $m)) {
                    continue;
                }
                $table = $m[1];
                foreach (preg_split('/,\s*(?=ADD COLUMN)/i', $m[2]) as $clause) {
                    $clause = self::normalizeTypes(trim($clause));
                    if ($clause !== '') {
                        $out[] = "ALTER TABLE $table $clause";
                    }
                }
                continue;
            }

            if (stripos($stmt, 'CREATE TABLE') === 0) {
                $stmt = preg_replace('/\)\s*ENGINE=.*$/is', ')', $stmt);
                // Drop secondary index declarations (SQLite: separate CREATE INDEX).
                $stmt = preg_replace('/,\s*KEY\s+\w+\s*\([^)]*\)/i', '', $stmt);
                // Fold `PRIMARY KEY (id)` into the autoincrement column.
                $stmt = preg_replace('/,\s*PRIMARY KEY\s*\(\s*\w+\s*\)/i', '', $stmt);
                $stmt = self::normalizeTypes($stmt);
                $stmt = preg_replace(
                    '/(\w+)\s+INTEGER\s+NOT NULL\s+AUTO_INCREMENT/i',
                    '$1 INTEGER PRIMARY KEY AUTOINCREMENT',
                    $stmt
                );
                $out[] = $stmt;
                continue;
            }

            $out[] = $stmt;
        }
        return $out;
    }

    private static function normalizeTypes(string $s): string
    {
        $s = preg_replace('/\b(TINYINT|SMALLINT|MEDIUMINT|BIGINT|INT)\b\s*(\(\d+\))?\s*(UNSIGNED|SIGNED)?/i', 'INTEGER', $s);
        return preg_replace('/\s+/', ' ', $s);
    }

    public static function exec(string $sql)
    {
        self::$log[] = $sql;
        if (count(self::$log) > self::LOG_LIMIT) {
            array_shift(self::$log);
        }
        return self::$pdo->query($sql);
    }
}

// ---------------------------------------------------------------------------
// Framework helper functions
// ---------------------------------------------------------------------------

if (!function_exists('bga_rand')) {
    /** BGA's audited RNG. Deterministic here so failures are reproducible. */
    function bga_rand(int $min, int $max): int
    {
        return HarnessRng::next($min, $max);
    }
}

final class HarnessRng
{
    private static int $seed = 1;
    public static function seed(int $s): void { self::$seed = $s ?: 1; }
    public static function next(int $min, int $max): int
    {
        // xorshift32 — stable across PHP versions, unlike mt_rand.
        $x = self::$seed;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= ($x >> 17);
        $x ^= ($x << 5) & 0xFFFFFFFF;
        self::$seed = $x & 0xFFFFFFFF;
        return $min + (self::$seed % max(1, $max - $min + 1));
    }
}

if (!function_exists('clienttranslate')) {
    function clienttranslate(string $s): string { return $s; }
}
if (!function_exists('totranslate')) {
    function totranslate(string $s): string { return $s; }
}

class BgaUserException extends Exception {}
class BgaVisibleSystemException extends Exception {}
class BgaSystemException extends Exception {}

// ---------------------------------------------------------------------------
// $this->bga facade
// ---------------------------------------------------------------------------

final class HarnessGlobals
{
    private array $store = [];
    public function set(string $k, $v): void { $this->store[$k] = $v; }
    public function get(string $k, $default = null) { return $this->store[$k] ?? $default; }
    public function inc(string $k, $delta = 1) { $this->store[$k] = (int)($this->store[$k] ?? 0) + $delta; return $this->store[$k]; }
    public function all(): array { return $this->store; }
}

final class HarnessNotify
{
    /** @var array<int,array{scope:string,to:?int,type:string,msg:string,args:array}> */
    public array $log = [];
    public function all(string $type, string $msg = '', array $args = []): void
    {
        $this->log[] = ['scope' => 'all', 'to' => null, 'type' => $type, 'msg' => $msg, 'args' => $args];
    }
    public function player(int $playerId, string $type, string $msg = '', array $args = []): void
    {
        $this->log[] = ['scope' => 'player', 'to' => $playerId, 'type' => $type, 'msg' => $msg, 'args' => $args];
    }
    public function alwaysMergePrivate(): void {}
    public function types(): array
    {
        return array_values(array_unique(array_column($this->log, 'type')));
    }
}

final class HarnessPlayerScore
{
    public function initDb(array $playerIds, int $initialValue = 0): void
    {
        $ids = implode(',', array_map('intval', $playerIds));
        HarnessDb::exec("UPDATE player SET player_score = $initialValue WHERE player_id IN ($ids)");
    }
    public function set(int $playerId, int $value): void
    {
        HarnessDb::exec("UPDATE player SET player_score = $value WHERE player_id = $playerId");
    }
    public function inc(int $playerId, int $delta): void
    {
        HarnessDb::exec("UPDATE player SET player_score = player_score + $delta WHERE player_id = $playerId");
    }
    public function get(int $playerId): int
    {
        return (int)HarnessDb::exec("SELECT player_score FROM player WHERE player_id = $playerId")->fetchColumn();
    }
}

final class HarnessBga
{
    public HarnessGlobals $globals;
    public HarnessNotify $notify;
    public HarnessPlayerScore $playerScore;
    public function __construct()
    {
        $this->globals = new HarnessGlobals();
        $this->notify = new HarnessNotify();
        $this->playerScore = new HarnessPlayerScore();
    }
}

final class HarnessGamestate
{
    public int $activePlayerId = 0;
    public array $transitionLog = [];
    public function changeActivePlayer(int $pid): void { $this->activePlayerId = $pid; }
    public function getActivePlayerId(): int { return $this->activePlayerId; }
    public function nextState(string $t = ''): void { $this->transitionLog[] = $t; }
    public function setAllPlayersMultiactive(): void {}
    public function getCurrentMainState(): array { return []; }
}

// ---------------------------------------------------------------------------
// Bga\GameFramework namespace stubs
// ---------------------------------------------------------------------------


}

namespace Bga\GameFramework {

class StateType
{
    const ACTIVE_PLAYER = 'activeplayer';
    const MULTIPLE_ACTIVE_PLAYER = 'multipleactiveplayer';
    const PRIVATE = 'private';
    const GAME = 'game';
}

class UserException extends \BgaUserException {}

class Table
{
    public \HarnessBga $bga;
    public \HarnessGamestate $gamestate;
    /** @var array<string,int> */
    public array $stats = [];
    public int $undoSavepoints = 0;
    public array $players = [];

    public function __construct()
    {
        $this->bga = new \HarnessBga();
        $this->gamestate = new \HarnessGamestate();
    }

    // ---- DB ------------------------------------------------------------
    public static function DbQuery(string $sql)
    {
        return \HarnessDb::exec($sql);
    }

    public static function getObjectListFromDB(string $sql, bool $bSingleValue = false): array
    {
        $rows = \HarnessDb::exec($sql)->fetchAll(\PDO::FETCH_ASSOC);
        if ($bSingleValue) {
            return array_map(static fn($r) => reset($r), $rows);
        }
        return $rows;
    }

    public static function getObjectFromDB(string $sql): ?array
    {
        $r = \HarnessDb::exec($sql)->fetch(\PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    public static function getNonEmptyObjectFromDB(string $sql): array
    {
        $r = self::getObjectFromDB($sql);
        if ($r === null) {
            throw new \BgaVisibleSystemException("getNonEmptyObjectFromDB returned no row: $sql");
        }
        return $r;
    }

    public static function getUniqueValueFromDB(string $sql)
    {
        $v = \HarnessDb::exec($sql)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function getCollectionFromDB(string $sql, bool $bSingleValue = false): array
    {
        $rows = \HarnessDb::exec($sql)->fetchAll(\PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $key = reset($row);
            if ($bSingleValue) {
                $vals = array_values($row);
                $out[$key] = $vals[1] ?? null;
            } else {
                $out[$key] = $row;
            }
        }
        return $out;
    }

    public static function DbGetLastId(): int
    {
        return (int)\HarnessDb::$pdo->lastInsertId();
    }

    // ---- Players -------------------------------------------------------
    public function getPlayerNameById($playerId): string
    {
        return (string)self::getUniqueValueFromDB(
            "SELECT player_name FROM player WHERE player_id = " . (int)$playerId);
    }

    public function getCurrentPlayerId(): int { return $this->gamestate->activePlayerId; }
    public function getActivePlayerId(): int { return $this->gamestate->activePlayerId; }
    public function getActivePlayerName(): string { return $this->getPlayerNameById($this->gamestate->activePlayerId); }
    public function getPlayersNumber(): int
    {
        return (int)self::getUniqueValueFromDB("SELECT COUNT(*) FROM player");
    }
    public function getPlayerAfter($playerId): int
    {
        $ids = self::getObjectListFromDB("SELECT player_id FROM player ORDER BY player_no", true);
        $ids = array_map('intval', $ids);
        $i = array_search((int)$playerId, $ids, true);
        return $ids[($i + 1) % count($ids)];
    }
    public function reloadPlayersBasicInfos(): void
    {
        // BGA assigns player_no by seating order; approximate with insertion order.
        $ids = self::getObjectListFromDB("SELECT player_id FROM player ORDER BY player_id", true);
        $no = 1;
        foreach ($ids as $pid) {
            self::DbQuery("UPDATE player SET player_no = $no WHERE player_id = " . (int)$pid);
            $no++;
        }
    }
    public function reattributeColorsBasedOnPreferences($players, $colors): void {}
    public function giveExtraTime($playerId, $extra = null): void {}

    public static function getGameinfos(): array
    {
        static $infos = null;
        if ($infos === null) {
            // Modern BGA reads gameinfos.jsonc; it is the single source of truth.
            $infos = \HarnessJsonc::load(\HarnessPaths::$src . '/gameinfos.jsonc');
        }
        return $infos;
    }

    // ---- Stats ---------------------------------------------------------
    public function initStat(string $scope, string $name, $value, $playerId = null): void
    {
        $this->stats[$scope . ':' . $name . ':' . ($playerId ?? '-')] = $value;
    }
    public function incStat($delta, string $name, $playerId = null): void
    {
        foreach (array_keys($this->stats) as $k) {
            if (str_ends_with($k, ':' . $name . ':' . ($playerId ?? '-'))) {
                $this->stats[$k] += $delta;
                return;
            }
        }
        $this->stats['?:' . $name . ':' . ($playerId ?? '-')] = $delta;
    }
    public function setStat($value, string $name, $playerId = null): void
    {
        foreach (array_keys($this->stats) as $k) {
            if (str_ends_with($k, ':' . $name . ':' . ($playerId ?? '-'))) {
                $this->stats[$k] = $value;
                return;
            }
        }
    }

    // ---- Misc ----------------------------------------------------------
    public function initGameStateLabels(array $labels): void {}
    public function undoSavepoint(): void { $this->undoSavepoints++; }
    public function getGameProgression(): int { return 0; }
    public function notifyAllPlayers(string $type, string $msg, array $args): void
    {
        $this->bga->notify->all($type, $msg, $args);
    }
}


}

namespace Bga\GameFramework\States {

#[\Attribute(\Attribute::TARGET_METHOD)]
class PossibleAction
{
    public function __construct(public bool $optionalAction = false) {}
}

abstract class GameState
{
    public int $id;
    public string $stateType;
    public string $stateName;
    public ?string $description;
    public ?string $descriptionMyTurn;
    public array $transitions;
    public bool $updateGameProgression;
    public $initialPrivate;

    public function __construct(
        $game,
        int $id,
        string $type,
        ?string $name = null,
        ?string $description = null,
        ?string $descriptionMyTurn = null,
        array $transitions = [],
        bool $updateGameProgression = false,
        $initialPrivate = null,
    ) {
        $this->id = $id;
        $this->stateType = $type;
        $this->stateName = $name ?? (new \ReflectionClass($this))->getShortName();
        $this->description = $description;
        $this->descriptionMyTurn = $descriptionMyTurn;
        $this->transitions = $transitions;
        $this->updateGameProgression = $updateGameProgression;
        $this->initialPrivate = $initialPrivate;
    }
}


}

namespace Bga\GameFramework\Actions {

#[\Attribute(\Attribute::TARGET_METHOD)]
class CheckAction
{
    public function __construct(public bool $enabled = true) {}
}

#[\Attribute(\Attribute::TARGET_METHOD)]
class PossibleAction
{
    public function __construct(public bool $optionalAction = false) {}
}


}

namespace Bga\GameFramework\Actions\Types {

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class IntParam
{
    public function __construct(public ?int $min = null, public ?int $max = null) {}
}

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class JsonParam
{
    public function __construct(public bool $alphanum = false) {}
}

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class StringParam
{
    public function __construct(public bool $alphanum = false, public ?int $maxLength = null) {}
}

}

namespace {

/** Reads BGA's .jsonc config files (JSON with // and /* *\/ comments). */
final class HarnessJsonc
{
    public static function load(string $path): array
    {
        $raw = file_get_contents($path);
        $raw = preg_replace('#/\*.*?\*/#s', '', $raw);
        $raw = preg_replace('#^\s*//.*$#m', '', $raw);
        $raw = preg_replace('/,(\s*[}\]])/', '$1', $raw);
        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            throw new \RuntimeException("Invalid JSONC in $path: " . json_last_error_msg());
        }
        return $decoded;
    }
}

/** Resolved once by the harness entry point. */
final class HarnessPaths
{
    public static string $root = '';
    public static string $src = '';
}

}
