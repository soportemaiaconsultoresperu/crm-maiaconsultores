<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Contracts\Automation\ActionContract;
use App\Models\AutomationAction;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Registry of action classes keyed by `automation_actions.type`.
 *
 * Stored as a singleton in AutomationServiceProvider. The listener resolves
 * the action through this registry at dispatch time so that action
 * implementations can be swapped without touching the engine.
 */
class ActionRegistry
{
    /**
     * @var array<string, class-string<ActionContract>>
     */
    private array $actions = [];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Register an action class. Re-registering the same key overwrites.
     *
     * @param  class-string<ActionContract>  $actionClass
     */
    public function register(string $type, string $actionClass): void
    {
        if (! is_subclass_of($actionClass, ActionContract::class)) {
            throw new InvalidArgumentException(
                "Action class {$actionClass} must implement ".ActionContract::class
            );
        }

        $this->actions[$type] = $actionClass;
    }

    /**
     * Resolve a fresh action instance for the given type.
     */
    public function resolve(string $type): ActionContract
    {
        if (! isset($this->actions[$type])) {
            throw new InvalidArgumentException(
                "No automation action registered for type \"{$type}\"."
            );
        }

        return $this->container->make($this->actions[$type]);
    }

    /**
     * Map of registered action type => class name.
     *
     * @return array<string, class-string<ActionContract>>
     */
    public function registered(): array
    {
        return $this->actions;
    }

    /**
     * Convenience helper: resolve the action for an AutomationAction row.
     */
    public function resolveForAction(AutomationAction $action): ActionContract
    {
        return $this->resolve($action->type);
    }
}