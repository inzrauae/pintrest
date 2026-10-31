<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImageProviderSetting extends Model
{
    protected $fillable = ['user_id', 'provider', 'model', 'quality'];

    protected $attributes = [
        'provider' => 'none',
        'quality' => 'medium',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
