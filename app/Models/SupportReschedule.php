<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SupportReschedule extends Model { protected $guarded=[]; protected function casts():array{return ['old_scheduled_at'=>'datetime','new_scheduled_at'=>'datetime'];} public function activity(): BelongsTo{return $this->belongsTo(Activity::class);} }
