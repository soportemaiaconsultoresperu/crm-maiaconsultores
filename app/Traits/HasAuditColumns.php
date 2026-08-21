<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;

/**
 * Automatically fills the created_by / updated_by audit columns from the
 * authenticated user. Safe in console contexts (seeds, commands, queue
 * jobs): when there is no authenticated user the columns stay null.
 *
 * Intended for Eloquent models that own the audit columns:
 *
 * @property int|null $created_by
 * @property int|null $updated_by
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasAuditColumns
{
    /**
     * Boot the trait listeners.
     */
    protected static function bootHasAuditColumns(): void
    {
        static::creating(function ($model): void {
            if (Auth::check() && blank($model->created_by)) {
                $model->created_by = Auth::id();
            }
        });

        static::updating(function ($model): void {
            if (Auth::check() && blank($model->updated_by)) {
                $model->updated_by = Auth::id();
            }
        });
    }
}
