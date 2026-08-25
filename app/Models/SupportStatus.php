<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportStatus extends Model
{
    use HasFactory;

    public const SLUG_NEW = 'nuevo';
    public const SLUG_ASSIGNED = 'asignado';
    public const SLUG_SCHEDULED = 'programado';
    public const SLUG_IN_PROGRESS = 'en-atencion';
    public const SLUG_WAITING_CUSTOMER = 'en-espera-del-cliente';
    public const SLUG_WAITING_INTERNAL = 'en-espera-interna';
    public const SLUG_RESOLVED = 'resuelto';
    public const SLUG_CLOSED = 'cerrado';
    public const SLUG_CANCELLED = 'cancelado';
    public const SLUG_REOPENED = 'reabierto';

    protected $fillable = ['name', 'slug', 'description', 'is_terminal', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_terminal' => 'boolean', 'is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
