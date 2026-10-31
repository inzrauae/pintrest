<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PinTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'name', 'text_overlay', 'overlay_position', 'background_color', 'background_opacity',
        'text_color', 'font_size', 'image_style', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'text_overlay' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
