<?php

namespace App\Policies;

use App\Models\SupportSessionDetail;
use App\Models\User;
use App\Services\SupportTicketScopeService;

/** Session (attendance) details inherit the visibility of their ticket (used by the documents panel and uploads). */
class SupportSessionDetailPolicy
{
    public function __construct(private readonly SupportTicketScopeService $scope) {}

    public function view(User $user, SupportSessionDetail $session): bool
    {
        return $session->ticket !== null && $this->scope->canView($user, $session->ticket);
    }
}
