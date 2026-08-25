<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SupportObservationHistory extends Model { protected $guarded=[]; public function observation():BelongsTo{return $this->belongsTo(SupportObservation::class);} }
