<?php
/**
 * engine.php — offline state-machine driver for Hexpionage.
 *
 * Reproduces the parts of BGA's engine that matter for testing:
 *   - instantiate the state classes and index them by id / name
 *   - run setupNewGame(), then loop:
 *       GAME states           -> onEnteringState(), follow the returned state
 *       ACTIVE_PLAYER states  -> getArgs(), ask a policy for an action,
 *                                dispatch it, follow the returned state
 *   - resolve a returned value (class-string | int id | null) to the next state
 *
 * Action dispatch mirrors BGA's rule: look for the `act*` method on the current
 * state class first, then fall back to the Game class (global actions).
 */

declare(strict_types=1);

use Bga\Games\Hexpionage\Game;

final class Engine
{
    public Game $game;
    /** @var array<string,object> class-string => state instance */
    public array $states = [];
    /** @var array<int,object> id => state instance */
    public array $byId = [];
    public array $trace = [];
    public bool $verbose = false;
    public int $maxSteps = 20000;
    public ?string $current = null;
    public bool $finished = false;

    private const STATE_CLASSES = [
        'GameSetup', 'TrickleDrawLeft', 'TrickleDrawRight', 'TrickleRoll',
        'TrickleResolve', 'Spawn', 'Actions', 'AnalystBonusDecision',
        'EndOfTurnCleanup', 'GameEnd',
    ];

    public function __construct()
    {
        $this->game = new Game();
        foreach (self::STATE_CLASSES as $short) {
            $fq = 'Bga\\Games\\Hexpionage\\States\\' . $short;
            $inst = new $fq($this->game);
            $this->states[$fq] = $inst;
            if (isset($this->byId[$inst->id])) {
                throw new RuntimeException("Duplicate state id {$inst->id} on $fq");
            }
            $this->byId[$inst->id] = $inst;
        }
    }

    /** Assert BGA's reserved-id rules; 1 and 99 belong to the framework. */
    public function assertStateIdsValid(): void
    {
        foreach ($this->byId as $id => $state) {
            if ($id === 1 || $id === 99) {
                throw new RuntimeException(
                    "State " . get_class($state) . " uses BGA-reserved id $id");
            }
            if ($id < 2) {
                throw new RuntimeException("State id $id must be >= 2");
            }
        }
    }

    public function setup(array $players): void
    {
        $m = new ReflectionMethod($this->game, 'setupNewGame');
        $next = $m->invoke($this->game, $players, []);
        $this->current = $this->resolve($next);
    }

    public function getAllDatas(): array
    {
        $m = new ReflectionMethod($this->game, 'getAllDatas');
        return $m->invoke($this->game);
    }

    /** Resolve an onEnteringState/act* return value to a state class-string. */
    private function resolve($value): ?string
    {
        if ($value === null) {
            return $this->current;
        }
        if (is_int($value)) {
            if ($value === 99) {
                $this->finished = true;
                return null;
            }
            if (!isset($this->byId[$value])) {
                throw new RuntimeException("Unknown state id $value");
            }
            return get_class($this->byId[$value]);
        }
        if (is_string($value)) {
            if (isset($this->states[$value])) {
                return $value;
            }
            throw new RuntimeException("Unknown state class '$value' (transition names are not supported; declare `transitions:` if you want them)");
        }
        throw new RuntimeException('Bad state return value: ' . gettype($value));
    }

    public function stateInstance(): object
    {
        return $this->states[$this->current];
    }

    public function stateName(): string
    {
        return $this->current === null ? 'END' : $this->stateInstance()->stateName;
    }

    public function args(): array
    {
        $s = $this->stateInstance();
        return method_exists($s, 'getArgs') ? $s->getArgs() : [];
    }

    /**
     * Run until an ACTIVE_PLAYER state needs input, or the game ends.
     * Returns false when the game is over.
     */
    public function runToDecision(): bool
    {
        $steps = 0;
        while (!$this->finished && $this->current !== null) {
            if ($steps++ > $this->maxSteps) {
                throw new RuntimeException('State machine did not settle (possible infinite loop) at ' . $this->stateName());
            }
            $state = $this->stateInstance();
            $entering = method_exists($state, 'onEnteringState')
                ? $state->onEnteringState()
                : null;
            $this->trace[] = ['enter' => $state->stateName];
            $next = $this->resolve($entering);
            if ($next !== $this->current) {
                $this->current = $next;
                continue;
            }
            if ($state->stateType === \Bga\GameFramework\StateType::GAME) {
                throw new RuntimeException("GAME state {$state->stateName} did not transition");
            }
            return true; // waiting on a player
        }
        return false;
    }

    /**
     * Dispatch a player action the way BGA does: state class first, then Game.
     * @param array<int,mixed> $params positional args
     */
    public function perform(string $action, array $params = []): void
    {
        $state = $this->stateInstance();
        $target = null;
        if (method_exists($state, $action)) {
            $target = $state;
        } elseif (method_exists($this->game, $action)) {
            $target = $this->game;
        } else {
            throw new RuntimeException("No handler for action '$action'");
        }

        $ref = new ReflectionMethod($target, $action);
        if (!self::hasPossibleAction($ref)) {
            throw new RuntimeException("'$action' is missing the #[PossibleAction] attribute");
        }

        $this->trace[] = ['act' => $action, 'params' => $params, 'state' => $state->stateName];
        $ret = $ref->invokeArgs($target, $params);
        $this->current = $this->resolve($ret);
    }

    private static function hasPossibleAction(ReflectionMethod $m): bool
    {
        foreach ($m->getAttributes() as $a) {
            if (str_ends_with($a->getName(), 'PossibleAction')) {
                return true;
            }
        }
        return false;
    }

    /** Every act* method reachable by the client, for coverage reporting. */
    public function allActions(): array
    {
        $out = [];
        foreach (array_merge([$this->game], array_values($this->states)) as $obj) {
            foreach ((new ReflectionClass($obj))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
                if (str_starts_with($m->getName(), 'act') && self::hasPossibleAction($m)) {
                    $out[$m->getName()] = get_class($obj);
                }
            }
        }
        ksort($out);
        return $out;
    }
}
