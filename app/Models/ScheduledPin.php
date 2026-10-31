<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledPin extends Model
{
    protected $fillable = ['user_id', 'generated_pin_id', 'scheduled_at', 'status'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }

    public function pin(): BelongsTo
    {
        return $this->belongsTo(GeneratedPin::class, 'generated_pin_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
