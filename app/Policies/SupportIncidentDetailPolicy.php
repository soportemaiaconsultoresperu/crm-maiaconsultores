<?php

namespace App\Policies;

use App\Models\SupportIncidentDetail;
use App\Models\User;
use App\Services\SupportTicketScopeService;

/** Incident details inherit the visibility of their ticket (used by the documents panel and uploads). */
class SupportIncidentDetailPolicy
{
    public function __construct(private readonly SupportTicketScopeService $scope) {}

    public function view(User $user, SupportIncidentDetail $incident): bool
    {
        return $incident->ticket !== null && $this->scope->canView($user, $incident->ticket);
    }
}
