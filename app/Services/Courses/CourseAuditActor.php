<?php

namespace App\Services\Courses;

use App\Models\User;
use Closure;
use Spatie\Activitylog\CauserResolver;

/**
 * Attributes a course domain write to the actor the service received.
 *
 * `LogsActivity` resolves the causer of a model-backed entry from the
 * AUTHENTICATED user (`CauserResolver` falls back to `auth()->user()`), while
 * every course service that owns an actor receives it as an explicit parameter
 * (`$actor`). The two are the same person on the HTTP path and differ everywhere
 * else: a service called from a job, a command or a test with no session
 * produced entries with a NULL causer, and a service called while a DIFFERENT
 * user was authenticated attributed the change to that session user. A material
 * change recorded without the responsible actor does not satisfy the audit
 * requirement, so the actor the domain already knows is the one the entry must
 * name.
 *
 * The rule belongs here, in the service layer, because the service is the only
 * place that knows the responsible actor: `LogOptions` has no causer API, and
 * the model cannot see a parameter its events never receive.
 *
 * Note the two different audit mechanisms this does NOT unify: this sets the
 * activity-log causer only. `HasAuditColumns` fills `created_by`/`updated_by`
 * from the authenticated user under its own documented rule (and leaves them
 * null in console contexts); it shares no state with this helper.
 *
 * Not re-entrant: the override is cleared on exit, so a nested call restores
 * "no override" (i.e. the authenticated user) rather than the outer actor. Every
 * call site is a flat domain write, which is why the simpler contract is the
 * honest one.
 */
final class CourseAuditActor
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function asActor(?User $actor, Closure $callback): mixed
    {
        if (! $actor instanceof User) {
            return $callback();
        }

        $resolver = app(CauserResolver::class);
        $resolver->setCauser($actor);

        try {
            return $callback();
        } finally {
            $resolver->setCauser(null);
        }
    }
}
