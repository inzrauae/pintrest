<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishingJob extends Model
{
    protected $fillable = [
        'user_id', 'generated_pin_id', 'trigger', 'status', 'attempts', 'next_attempt_at', 'last_error',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'next_attempt_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function pin(): BelongsTo
    {
        return $this->belongsTo(GeneratedPin::class, 'generated_pin_id');
    }
}
