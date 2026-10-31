<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoTopic extends Model
{
    protected $fillable = [
        'user_id', 'name', 'keywords', 'source_url', 'url_filters', 'pinterest_account_id', 'board_id',
        'pin_template_id', 'language', 'image_mode', 'owns_images', 'auto_discover', 'auto_approve',
        'max_new_per_run', 'frequency_hours', 'last_run_at', 'last_run',
    ];

    protected $attributes = [
        'language' => 'en',
        'image_mode' => 'extract',
        'owns_images' => false,
        'auto_discover' => true,
        'auto_approve' => false,
        'max_new_per_run' => 10,
        'frequency_hours' => 24,
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'url_filters' => 'array',
            'owns_images' => 'boolean',
            'auto_discover' => 'boolean',
            'auto_approve' => 'boolean',
            'last_run_at' => 'datetime',
            'last_run' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PinterestAccount::class, 'pinterest_account_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PinTemplate::class, 'pin_template_id');
    }

    public function pins(): HasMany
    {
        return $this->hasMany(GeneratedPin::class);
    }

    public function isDue(): bool
    {
        return $this->auto_discover && $this->source_url
            && (! $this->last_run_at || $this->last_run_at->lte(now()->subHours(max(1, $this->frequency_hours))));
    }
}
