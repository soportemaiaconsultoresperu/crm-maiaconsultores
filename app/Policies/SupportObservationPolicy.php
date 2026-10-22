<?php

namespace App\Policies;

use App\Models\SupportObservation;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SupportTicketScopeService;

/** Observations inherit the visibility of their ticket (used by the documents panel and uploads). */
class SupportObservationPolicy
{
    public function __construct(private readonly SupportTicketScopeService $scope) {}

    public function view(User $user, SupportObservation $observation): bool
    {
        // SupportObservation declares no ticket() relation: resolve the parent by key.
        $ticket = SupportTicket::query()->find($observation->ticket_id);

        return $ticket !== null && $this->scope->canView($user, $ticket);
    }
}
