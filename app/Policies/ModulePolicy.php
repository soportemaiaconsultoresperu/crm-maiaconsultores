<?php

namespace App\Policies;

use App\Models\User;
use App\Services\DataScopeService;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared record-level authorization logic for owner-scoped modules
 * (ADR-006 / RNF-SEG-004).
 *
 * Rules:
 * - Permission checks only ({module}.{action}.{scope}) — never role names.
 * - viewAny allows any of any/team/own; the list query itself is scoped
 *   by DataScopeService in controllers/Livewire components.
 * - record-level view()/update()/delete() require the record owner to be
 *   inside the user's resolved visibility scope.
 */
abstract class ModulePolicy
{
    public function __construct(
        protected DataScopeService $dataScope,
    ) {}

    /**
     * Permission prefix for this module (e.g. "leads").
     */
    abstract protected function module(): string;

    /**
     * Owner user id of the record (null when the record has no owner).
     */
    abstract protected function ownerId(Model $record): ?int;

    /**
     * Whether a user may see the module list at all. Any view scope
     * qualifies; queries apply the record filter.
     */
    public function viewAny(User $user): bool
    {
        return $user->can("{$this->module()}.view.any")
            || $user->can("{$this->module()}.view.team")
            || $user->can("{$this->module()}.view.own");
    }

    /**
     * Record-level visibility (ADR-006): unrestricted users see everything,
     * supervisors their team members, salespeople only their own records.
     */
    public function view(User $user, Model $record): bool
    {
        return $this->withinScope($user, $record);
    }

    public function create(User $user): bool
    {
        return $user->can("{$this->module()}.create");
    }

    public function update(User $user, Model $record): bool
    {
        return $user->can("{$this->module()}.update")
            && $this->withinScope($user, $record);
    }

    /**
     * "Delete" is always a deactivation, never a physical delete
     * (RNF-DAT-001).
     */
    public function delete(User $user, Model $record): bool
    {
        return $user->can("{$this->module()}.deactivate")
            && $this->withinScope($user, $record);
    }

    /**
     * True when the record owner is inside the user's data scope.
     */
    protected function withinScope(User $user, Model $record): bool
    {
        $ownerId = $this->ownerId($record);

        if ($ownerId === null) {
            return false;
        }

        $visible = $this->dataScope->visibleOwnerIds($user);

        return $visible === null || in_array($ownerId, $visible, true);
    }
}
