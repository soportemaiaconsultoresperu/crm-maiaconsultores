<?php

namespace App\Policies;

use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends ModulePolicy<Quotation>
 */
class QuotationPolicy extends ModulePolicy
{
    protected function module(): string
    {
        return 'quotations';
    }

    /**
     * @param  Quotation  $record
     */
    protected function ownerId(Model $record): ?int
    {
        return $record->owner_id;
    }

    /**
     * Quotation acceptance (RF-COT-004): permission + record scope.
     */
    public function accept(User $user, Model $record): bool
    {
        return $user->can('quotations.accept')
            && $this->withinScope($user, $record);
    }
}
