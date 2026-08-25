<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SupportStatusPeriod extends Model { protected $guarded=[]; protected function casts():array{return ['started_at'=>'datetime','ended_at'=>'datetime','pauses_clock'=>'boolean'];} public function cycle():BelongsTo{return $this->belongsTo(SupportResolutionCycle::class,'cycle_id');} }
