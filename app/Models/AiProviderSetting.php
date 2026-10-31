<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProviderSetting extends Model
{
    protected $fillable = ['user_id', 'provider', 'model', 'temperature', 'tone', 'extra_instructions'];

    protected $attributes = [
        'provider' => 'openai',
        'temperature' => 0.7,
        'tone' => 'helpful',
    ];

    protected function casts(): array
    {
        return ['temperature' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
