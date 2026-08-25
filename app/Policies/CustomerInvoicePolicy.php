<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\User;

class CustomerInvoicePolicy
{
    public function view(User $user, CustomerInvoice $invoice): bool
    {
        return $user->can('customer-payments.view')
            && $user->can('view', $invoice->customer);
    }

    public function create(User $user, Customer $customer): bool
    {
        return $user->can('customer-payments.manage')
            && $user->can('view', $customer);
    }

    public function update(User $user, CustomerInvoice $invoice): bool
    {
        return $this->manageActive($user, $invoice);
    }

    public function markPaid(User $user, CustomerInvoice $invoice): bool
    {
        return $this->manageActive($user, $invoice);
    }

    public function retire(User $user, CustomerInvoice $invoice): bool
    {
        return $this->manageActive($user, $invoice);
    }

    public function delete(User $user, CustomerInvoice $invoice): bool
    {
        return false;
    }

    private function manageActive(User $user, CustomerInvoice $invoice): bool
    {
        return $invoice->retired_at === null
            && $user->can('customer-payments.manage')
            && $user->can('view', $invoice->customer);
    }
}
