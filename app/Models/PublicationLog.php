<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicationLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'generated_pin_id', 'pinterest_account_id', 'level', 'event', 'message', 'details', 'http_status',
    ];

    protected function casts(): array
    {
        return ['details' => 'array'];
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
