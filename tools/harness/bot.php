<?php
/**
 * bot.php — a "random legal move" policy used to drive full games in the harness.
 *
 * The bot deliberately reads ONLY the state args the real client receives
 * (`getArgs()`), so if the bot cannot find a legal move, the real UI could not
 * render one either. That makes the args payload part of what gets tested.
 *
 * The invocation shapes below must match:
 *   - src/modules/php/States/Actions.php::buildLegalActions()  (args schema)
 *   - src/modules/php/Game.php  act* signatures                (positional args)
 */

declare(strict_types=1);

final class RandomBot
{
    /** @var array<string,int> action name => times played */
    public array $coverage = [];

    public function __construct(private Engine $engine) {}

    /** Choose and perform one action. Returns the action name. */
    public function step(): string
    {
        $state = $this->engine->stateName();
        $args = $this->engine->args();

        [$action, $params] = match ($state) {
            'spawn' => $this->spawn($args),
            'actions' => $this->actions($args),
            'analystBonusDecision' => $this->analyst($args),
            default => throw new RuntimeException("Bot has no policy for state '$state'"),
        };

        $this->coverage[$action] = ($this->coverage[$action] ?? 0) + 1;
        $this->engine->perform($action, $params);
        return $action;
    }

    private static function pick(array $list)
    {
        return $list ? array_values($list)[bga_rand(0, count($list) - 1)] : null;
    }

    private function spawn(array $args): array
    {
        $pool = $args['available_agents_in_pool'] ?? [];
        $hexes = $args['available_spawn_hexes'] ?? [];
        $cap = (int)($args['spawn_cap_remaining'] ?? 0);
        // Pass sometimes even when spawning is legal, so both branches get hit.
        if ($pool && $hexes && $cap > 0 && bga_rand(1, 100) <= 85) {
            $agent = self::pick($pool);
            $hex = self::pick($hexes);
            return ['actSpawnAgent', [(int)$agent['agent_id'], (int)$hex['q'], (int)$hex['r']]];
        }
        return ['actPassSpawn', []];
    }

    private function analyst(array $args): array
    {
        return bga_rand(1, 2) === 1 ? ['actAnalystKeep', []] : ['actAnalystReturn', []];
    }

    private function actions(array $args): array
    {
        $candidates = [];
        foreach (($args['legal_actions'] ?? []) as $entry) {
            foreach ($this->expand($entry) as $inv) {
                $candidates[] = $inv;
            }
        }
        // Retiring is the only way to score, so bias towards it; otherwise the
        // bot wanders and never exercises scoring / win / depletion paths.
        // Retiring is the only way to score, so always cash in when possible.
        $retires = array_values(array_filter($candidates, static fn($c) => $c[0] === 'actRetireAgent'));
        if ($retires && bga_rand(1, 100) <= 85) {
            return self::pick($retires);
        }
        if (!$candidates || bga_rand(1, 100) > 90) {
            return ['actPassActions', []];
        }
        return self::pick($candidates);
    }

    /**
     * Turn one `legal_actions` entry into zero or more concrete positional
     * invocations of the matching Game::act* method.
     *
     * @return array<int,array{0:string,1:array}>
     */
    private function expand(array $e): array
    {
        $name = $e['name'] ?? null;
        if ($name === null) {
            return [];
        }
        $out = [];

        switch ($name) {
            case 'actMoveAgent':
                foreach ($e['agents'] ?? [] as $a) {
                    foreach ($a['legal_targets'] ?? [] as $h) {
                        $out[] = [$name, [(int)$a['agent_id'], (int)$h['q'], (int)$h['r']]];
                    }
                }
                break;

            case 'actTransferIntel':
                foreach ($e['transfers'] ?? [] as $t) {
                    foreach ($t['transferable_intel_ids'] ?? [] as $iid) {
                        $out[] = [$name, [(int)$t['source_agent_id'], (int)$t['target_agent_id'], (int)$iid]];
                    }
                }
                break;

            case 'actRetireAgent':
                foreach ($e['agents'] ?? [] as $a) {
                    // Retiring is permanent and drains the agent pool, so a
                    // scoreless retire is almost always a blunder. Skipping it
                    // keeps games long enough to exercise the score-20 win path.
                    if ((int)($a['expected_score_delta'] ?? 0) <= 0) {
                        continue;
                    }
                    $out[] = [$name, [(int)$a['agent_id']]];
                }
                break;

            case 'actEngineerPlaceBlockadeAdjacent':
                foreach ($e['engineers'] ?? [] as $g) {
                    foreach ($g['legal_target_hexes'] ?? [] as $h) {
                        $out[] = [$name, [(int)$g['agent_id'], (int)$h['q'], (int)$h['r']]];
                    }
                }
                break;

            case 'actEngineerPlaceBlockadeAnywhere':
                foreach ($e['engineers'] ?? [] as $g) {
                    $iid = self::pick($g['intel_paid_options'] ?? []);
                    $h = self::pick($g['legal_target_hexes'] ?? []);
                    if ($iid === null || $h === null) continue;
                    $out[] = [$name, [(int)$g['agent_id'], (int)$iid, (int)$h['q'], (int)$h['r']]];
                }
                break;

            case 'actSmugglerBoostActions':
                foreach ($e['smugglers'] ?? [] as $s) {
                    foreach ($s['intel_paid_options'] ?? [] as $iid) {
                        $out[] = [$name, [(int)$s['agent_id'], (int)$iid]];
                    }
                }
                break;

            case 'actSmugglerSwapAgents':
                foreach ($e['smugglers'] ?? [] as $s) {
                    $iid = self::pick($s['intel_paid_options'] ?? []);
                    $pair = self::pick($s['legal_pairs'] ?? []);
                    if ($iid === null || $pair === null) continue;
                    $out[] = [$name, [(int)$s['agent_id'], (int)$iid, (int)$pair[0], (int)$pair[1]]];
                }
                break;

            case 'actCommsMoveIntelUp':
                foreach ($e['moves'] ?? [] as $m) {
                    foreach ($m['legal_targets'] ?? [] as $h) {
                        $out[] = [$name, [(int)$m['comms_agent_id'], (int)$m['intel_id'], (int)$h['q'], (int)$h['r']]];
                    }
                }
                break;

            case 'actCommsMoveIntelDown':
                foreach ($e['moves'] ?? [] as $m) {
                    $paid = self::pick($m['intel_paid_options'] ?? []);
                    $h = self::pick($m['legal_targets'] ?? []);
                    if ($paid === null || $h === null) continue;
                    $out[] = [$name, [(int)$m['comms_agent_id'], (int)$paid, (int)$m['intel_id'], (int)$h['q'], (int)$h['r']]];
                }
                break;

            case 'actDoubleAgentTransfer':
                foreach ($e['double_agents'] ?? [] as $d) {
                    $iid = self::pick($d['transferable_intel_ids'] ?? []);
                    $tgt = self::pick($d['legal_target_agents'] ?? []);
                    if ($iid === null || $tgt === null) continue;
                    $out[] = [$name, [(int)$d['agent_id'], (int)$tgt, (int)$iid]];
                }
                break;

            case 'actHackerPin':
            case 'actHackerUnpin':
                foreach ($e['hackers'] ?? [] as $h) {
                    foreach ($h['legal_target_agents'] ?? [] as $tgt) {
                        $out[] = [$name, [(int)$h['agent_id'], (int)$tgt]];
                    }
                }
                break;

            case 'actHackerStealIntel':
                foreach ($e['hackers'] ?? [] as $h) {
                    $paid = self::pick($h['intel_paid_options'] ?? []);
                    $tgt = self::pick($h['legal_targets'] ?? []);
                    if ($paid === null || $tgt === null) continue;
                    $stolen = self::pick($tgt['stealable_intel_ids'] ?? []);
                    if ($stolen === null) continue;
                    $out[] = [$name, [(int)$h['agent_id'], (int)$paid, (int)$tgt['target_agent_id'], (int)$stolen]];
                }
                break;
        }

        return $out;
    }
}
