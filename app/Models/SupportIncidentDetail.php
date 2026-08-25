<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\SoftDeletes; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SupportIncidentDetail extends Model { use SoftDeletes; protected $guarded=[]; public function ticket(): BelongsTo{return $this->belongsTo(SupportTicket::class);} }
