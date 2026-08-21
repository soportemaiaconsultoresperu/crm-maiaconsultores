<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Products are a global catalog: no owner-based data scope, only module
 * permissions (any user who can view the catalog sees every product).
 */
class ProductPolicy extends ModulePolicy
{
    protected function module(): string
    {
        return 'products';
    }

    public function viewAny(User $user): bool
    {
        return $user->can('products.view.any');
    }

    /**
     * @param  Product  $record
     */
    public function view(User $user, Model $record): bool
    {
        return $user->can('products.view.any');
    }

    public function create(User $user): bool
    {
        return $user->can('products.create');
    }

    public function update(User $user, Model $record): bool
    {
        return $user->can('products.update');
    }

    /**
     * @param  Product  $record
     */
    public function delete(User $user, Model $record): bool
    {
        return $user->can('products.deactivate');
    }

    /**
     * Not used for the global catalog.
     */
    protected function ownerId(Model $record): ?int
    {
        return null;
    }
}
