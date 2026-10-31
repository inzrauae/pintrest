<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PinImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'generated_pin_id', 'source', 'source_url', 'original_path', 'path', 'mime', 'width', 'height',
        'size_bytes', 'crop', 'score', 'is_selected', 'rights_confirmed', 'status', 'error_message',
    ];

    // Storage paths stay server-side; the frontend uses the authenticated file route.
    protected $hidden = ['original_path', 'path'];

    protected $appends = ['url'];

    protected function casts(): array
    {
        return [
            'crop' => 'array',
            'is_selected' => 'boolean',
            'rights_confirmed' => 'boolean',
        ];
    }

    public function pin(): BelongsTo
    {
        return $this->belongsTo(GeneratedPin::class, 'generated_pin_id');
    }

    public function getUrlAttribute(): ?string
    {
        if (! $this->path) {
            return null;
        }

        return url("/api/pin-images/{$this->id}/file").'?v='.($this->updated_at?->timestamp ?? 0);
    }

    /** Images taken from a third-party page need explicit user confirmation before publishing. */
    public function requiresRightsConfirmation(): bool
    {
        return $this->source === 'extracted';
    }
}
