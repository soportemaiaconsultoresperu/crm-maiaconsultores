<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SupportTicketScopeService;

class SupportTicketPolicy
{
    public function __construct(private readonly SupportTicketScopeService $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('support.view.any')
            || $user->can('support.view.team')
            || $user->can('support.view.own');
    }

    public function view(User $user, SupportTicket $ticket): bool
    {
        return $this->scope->canView($user, $ticket);
    }

    public function create(User $user): bool
    {
        return $user->can('support.create');
    }

    public function update(User $user, SupportTicket $ticket): bool
    {
        return $user->can('support.update') && $this->scope->canView($user, $ticket);
    }

    public function assign(User $user, SupportTicket $ticket): bool
    {
        return $user->can('support.assign') && $this->scope->canView($user, $ticket);
    }

    public function reassign(User $user, SupportTicket $ticket): bool
    {
        return $user->can('support.reassign') && $this->scope->canView($user, $ticket);
    }

    public function cancel(User $user, SupportTicket $ticket): bool
    {
        return $user->can('support.cancel') && $this->scope->canView($user, $ticket);
    }

    public function addUpdate(User $user, SupportTicket $ticket): bool
    {
        return $user->can('support.updates.create') && $this->scope->canView($user, $ticket);
    }
}
