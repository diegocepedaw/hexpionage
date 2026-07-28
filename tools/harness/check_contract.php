<?php
/**
 * check_contract.php — static cross-check between the PHP server and the JS client.
 *
 * Catches the single most common class of BGA bug: a notification that the
 * server emits but the client never registered (silent no-op in the UI), or an
 * action the client calls that the server does not implement (hard error mid-game).
 *
 * Usage: php tools/harness/check_contract.php
 * Exit code 0 = contracts agree.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$src = $root . '/src';

function readAll(string $dir, string $ext): string
{
    $out = '';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === $ext) {
            $out .= "\n" . file_get_contents($f->getPathname());
        }
    }
    return $out;
}

$php = readAll($src . '/modules/php', 'php');
$js = readAll($src . '/modules/js', 'js');

// ---- notifications --------------------------------------------------------
preg_match_all('/notify->all\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $php, $m1);
preg_match_all('/notify->player\(\s*[^,]+,\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $php, $m2);
$emitted = array_values(array_unique(array_merge($m1[1], $m2[1])));
sort($emitted);

preg_match_all('/\[\s*"([A-Za-z0-9_]+)"\s*,\s*\d+\s*\]/', $js, $m3);
// Handlers are `notif_x(n) {` as ES-class methods, or `notif_x: function` in the
// legacy Dojo object-literal form. Accept both so the check survives either shape.
preg_match_all('/notif_([A-Za-z0-9_]+)\s*[:(]/', $js, $m4);
$registered = array_values(array_unique($m3[1]));
$handlers = array_values(array_unique($m4[1]));
sort($registered);
sort($handlers);

// ---- actions --------------------------------------------------------------
// Server: name => ordered parameter names (BGA autowires by NAME, not position).
preg_match_all('/function\s+(act[A-Z][A-Za-z0-9_]*)\s*\(([^)]*)\)/', $php, $m5, PREG_SET_ORDER);
$serverParams = [];
foreach ($m5 as $hit) {
    $params = [];
    foreach (array_filter(array_map('trim', explode(',', $hit[2]))) as $p) {
        if (preg_match('/\$([A-Za-z0-9_]+)/', $p, $pm)) {
            $params[] = $pm[1];
        }
    }
    $serverParams[$hit[1]] = $params;
}
$serverActions = array_keys($serverParams);
sort($serverActions);

// Client: name => payload keys passed to the action call. Modern BGA uses
// this.bga.actions.performAction(); the legacy alias was bgaPerformAction().
preg_match_all(
    '/(?:bgaPerformAction|performAction)\(\s*[\'"](act[A-Za-z0-9_]+)[\'"]\s*(?:,\s*\{(.*?)\}\s*)?\)/s',
    $js, $m6, PREG_SET_ORDER);
$clientParams = [];
foreach ($m6 as $hit) {
    $keys = [];
    foreach (preg_split('/,(?![^\[\]]*\])/', $hit[2] ?? '') as $kv) {
        if (preg_match('/^\s*([A-Za-z0-9_]+)\s*:/', $kv, $km)) {
            $keys[] = $km[1];
        }
    }
    $clientParams[$hit[1]] = array_values(array_unique(array_merge($clientParams[$hit[1]] ?? [], $keys)));
}
$clientActions = array_keys($clientParams);
sort($clientActions);

// Some call sites dispatch a variable action name (e.g. the shared
// pin/unpin branch). Collect their payload keys so those actions can still be
// matched by parameter shape instead of being reported as unreachable.
preg_match_all(
    '/(?:bgaPerformAction|performAction)\(\s*([A-Za-z_$][A-Za-z0-9_$.]*)\s*,\s*\{(.*?)\}\s*\)/s',
    $js, $m6b, PREG_SET_ORDER);
$dynamicPayloads = [];
foreach ($m6b as $hit) {
    $keys = [];
    foreach (preg_split('/,(?![^\[\]]*\])/', $hit[2]) as $kv) {
        if (preg_match('/^\s*([A-Za-z0-9_]+)\s*:/', $kv, $km)) {
            $keys[] = $km[1];
        }
    }
    sort($keys);
    $dynamicPayloads[] = $keys;
}
foreach ($serverParams as $action => $params) {
    if (isset($clientParams[$action])) {
        continue;
    }
    $expected = $params;
    sort($expected);
    foreach ($dynamicPayloads as $payload) {
        if ($payload === $expected) {
            $clientParams[$action] = $payload;
            break;
        }
    }
}
$clientActions = array_keys($clientParams);
sort($clientActions);

// ---- state names ----------------------------------------------------------
preg_match_all('/name:\s*\'([A-Za-z0-9_]+)\'/', $php, $m7);
$serverStates = array_values(array_unique(array_filter($m7[1], static fn($n) => $n !== 'actX')));
sort($serverStates);

preg_match_all('/case\s+"([A-Za-z0-9_]+)":/', $js, $m8);
$jsCases = array_values(array_unique($m8[1]));

// ---- report ---------------------------------------------------------------
$problems = [];

$report = static function (string $title, array $a, array $b, string $aName, string $bName) use (&$problems) {
    $missing = array_values(array_diff($a, $b));
    $extra = array_values(array_diff($b, $a));
    printf("%-28s %s=%d  %s=%d\n", $title, $aName, count($a), $bName, count($b));
    if ($missing) {
        echo "   in $aName but not $bName: " . implode(', ', $missing) . "\n";
        $problems[] = "$title: $aName-only -> " . implode(', ', $missing);
    }
    if ($extra) {
        echo "   in $bName but not $aName: " . implode(', ', $extra) . "\n";
        $problems[] = "$title: $bName-only -> " . implode(', ', $extra);
    }
};

$report('notifications', $emitted, $registered, 'server', 'client');
$report('notification handlers', $registered, $handlers, 'registered', 'notif_*');
$report('player actions', $serverActions, $clientActions, 'server', 'client');

// Parameter-name agreement. BGA matches bgaPerformAction payload keys to PHP
// parameter names, so any mismatch is a hard runtime failure for that action.
echo "action parameter names\n";
$paramProblems = 0;
$paramCompared = 0;
foreach ($serverParams as $action => $params) {
    if (!isset($clientParams[$action])) {
        continue; // reported above
    }
    $paramCompared++;
    $expected = array_values(array_diff($params, ['activePlayerId', 'active_player_id', 'currentPlayerId', 'current_player_id', 'args']));
    $sent = $clientParams[$action];
    sort($expected);
    $sortedSent = $sent;
    sort($sortedSent);
    if ($expected !== $sortedSent) {
        printf("   %-34s php(%s) != js(%s)\n", $action, implode(',', $expected), implode(',', $sortedSent));
        $problems[] = "params $action: php(" . implode(',', $expected) . ") != js(" . implode(',', $sortedSent) . ")";
        $paramProblems++;
    }
}
// This check guards BGA's autowire-by-name footgun, so it must never pass just
// because the client-side regex matched nothing (e.g. after a JS syntax change).
// Silence here has to mean "compared and agreed", not "found nothing to compare".
if ($serverParams && $paramCompared === 0) {
    echo "   NO client action calls parsed — the JS extraction is broken\n";
    $problems[] = 'action parameter names: no client action calls parsed (JS extraction broken)';
} elseif ($paramProblems === 0) {
    printf("   all %d actions agree (%d compared)\n", count($serverParams), $paramCompared);
}

$missingStates = array_values(array_diff($serverStates, $jsCases));
printf("%-28s server=%d  client-cases=%d\n", 'state branches', count($serverStates), count($jsCases));
if ($missingStates) {
    echo "   states with no client branch: " . implode(', ', $missingStates) . "\n";
    $problems[] = 'state branches: ' . implode(', ', $missingStates);
}

// ---- config files ---------------------------------------------------------
echo "config files\n";
$jsonc = static function (string $path): array {
    $raw = file_get_contents($path);
    $raw = preg_replace('#/\*.*?\*/#s', '', $raw);
    $raw = preg_replace('#^\s*//.*$#m', '', $raw);
    $raw = preg_replace('/,(\s*[}\]])/', '$1', $raw);
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
};

foreach (['gameinfos.jsonc', 'gameoptions.jsonc', 'gamepreferences.jsonc', 'stats.jsonc'] as $f) {
    $path = $src . '/' . $f;
    if (!is_file($path)) {
        $problems[] = "missing config file $f";
        echo "   MISSING $f\n";
        continue;
    }
    $raw = file_get_contents($path);
    $stripped = preg_replace('#/\*.*?\*/#s', '', $raw);
    $stripped = preg_replace('#^\s*//.*$#m', '', $stripped);
    $stripped = preg_replace('/,(\s*[}\]])/', '$1', $stripped);
    if (json_decode($stripped, true) === null) {
        $problems[] = "$f is not valid JSONC: " . json_last_error_msg();
        echo "   INVALID $f\n";
    }
}

// A legacy .inc.php twin of a .jsonc config silently drifts; modern BGA reads
// the .jsonc, so the .inc.php must not exist.
foreach (['gameinfos', 'gameoptions', 'gamepreferences', 'stats'] as $f) {
    if (is_file($src . "/$f.inc.php") && is_file($src . "/$f.jsonc")) {
        $problems[] = "both $f.inc.php and $f.jsonc exist (modern BGA reads the .jsonc; delete the .inc.php)";
        echo "   DUPLICATE $f.inc.php + $f.jsonc\n";
    }
}

$gi = $jsonc($src . '/gameinfos.jsonc');
foreach (['complexity', 'strategy', 'luck', 'diplomacy'] as $dep) {
    if (array_key_exists($dep, $gi)) {
        $problems[] = "gameinfos.jsonc still declares deprecated key '$dep'";
        echo "   DEPRECATED KEY $dep\n";
    }
}
if (isset($gi['suggest_player_number']) && !is_int($gi['suggest_player_number'])) {
    $problems[] = 'gameinfos.jsonc suggest_player_number must be an integer';
    echo "   suggest_player_number is not an integer\n";
}
// Promotes PHP warnings to exceptions on Studio, matching this harness, which
// already fails a run on any warning from src/. Without it, a warning-class bug
// is invisible on a live table. Flagged by the 2026-07-28 Studio dry run.
if (($gi['exception_on_warning'] ?? false) !== true) {
    $problems[] = 'gameinfos.jsonc must set exception_on_warning to true';
    echo "   exception_on_warning is not true\n";
}

// ---- client entry point ---------------------------------------------------
// BGA imports modules/js/Game.js as an ES module and calls `new gameModule.Game()`.
// The legacy Dojo define()/declare() form exports nothing at this path, which
// fails at runtime with "gameModule.Game is not a constructor".
echo "client entry point\n";
$gamejs = $src . '/modules/js/Game.js';
if (!preg_match('/^export\s+class\s+Game\b/m', file_get_contents($gamejs))) {
    $problems[] = 'modules/js/Game.js must declare `export class Game` (modern BGA entry point)';
    echo "   Game.js does not export a Game class\n";
} else {
    echo "   Game.js exports class Game\n";
}

// ---- view / template split ------------------------------------------------
// The phplib engine renders <game>_<game>.tpl and substitutes the {VARS} that
// view.php assigns. HTML placed after view.php's PHP close tag is never seen
// by the engine, so players get raw {TXT_*} tokens on screen.
echo "view/template\n";
$tplPath  = $src . '/hexpionage_hexpionage.tpl';
$viewPath = $src . '/hexpionage.view.php';
if (!is_file($tplPath)) {
    $problems[] = 'missing src/hexpionage_hexpionage.tpl (the game HTML template)';
    echo "   MISSING hexpionage_hexpionage.tpl\n";
} else {
    $tpl  = file_get_contents($tplPath);
    $view = file_get_contents($viewPath);

    if (preg_match('/\?>\s*\S/', $view)) {
        $problems[] = 'hexpionage.view.php has content after its PHP close tag — HTML belongs in the .tpl';
        echo "   view.php has markup after the PHP close tag\n";
    }
    preg_match_all('/\{([A-Z][A-Z0-9_]*)\}/', $tpl, $tv);
    preg_match_all('/\$this->tpl\[\'([A-Z][A-Z0-9_]*)\'\]/', $view, $pv);
    $tplVars = array_values(array_unique($tv[1]));
    $phpVars = array_values(array_unique($pv[1]));
    sort($tplVars); sort($phpVars);
    $unassigned = array_values(array_diff($tplVars, $phpVars));
    $unused     = array_values(array_diff($phpVars, $tplVars));
    printf("   tpl vars=%d  assigned=%d\n", count($tplVars), count($phpVars));
    if ($unassigned) {
        echo "   in .tpl but never assigned: " . implode(', ', $unassigned) . "\n";
        $problems[] = 'tpl vars never assigned: ' . implode(', ', $unassigned);
    }
    if ($unused) {
        echo "   assigned but not in .tpl: " . implode(', ', $unused) . "\n";
        $problems[] = 'view.php assigns unused tpl vars: ' . implode(', ', $unused);
    }
}

// Every stat referenced from PHP must be declared in stats.jsonc, and vice versa.
$stats = $jsonc($src . '/stats.jsonc');
$declared = array_merge(
    array_keys($stats['table'] ?? []),
    array_keys($stats['player'] ?? []));
// initStat($scope, $name, ...) — the name is the SECOND argument.
preg_match_all('/initStat\(\s*[\'"](?:table|player)[\'"]\s*,\s*[\'"]([a-z_0-9]+)[\'"]/', $php, $m9a);
// incStat($delta, $name, ...) / setStat($value, $name, ...) — name is second.
preg_match_all('/(?:incStat|setStat)\(\s*[^,]+,\s*[\'"]([a-z_0-9]+)[\'"]/', $php, $m9b);
$used = array_values(array_unique(array_merge($m9a[1], $m9b[1])));
$undeclared = array_values(array_diff($used, $declared));
$unused = array_values(array_diff($declared, $used));
printf("   stats declared=%d used=%d\n", count($declared), count($used));
if ($undeclared) {
    echo "   used in PHP but not declared in stats.jsonc: " . implode(', ', $undeclared) . "\n";
    $problems[] = 'undeclared stats: ' . implode(', ', $undeclared);
}
if ($unused) {
    echo "   declared but never written: " . implode(', ', $unused) . "\n";
}

echo "\n";
if ($problems) {
    echo "CONTRACT MISMATCHES (" . count($problems) . "):\n";
    foreach ($problems as $p) {
        echo "  - $p\n";
    }
    exit(1);
}
echo "server/client contract is consistent\n";
exit(0);
