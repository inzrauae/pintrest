<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class UrlSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'project_id', 'url_batch_id', 'url', 'normalized_url', 'url_hash', 'language',
        'image_mode', 'pinterest_account_id', 'board_id', 'status', 'error_message', 'attempts', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(UrlBatch::class, 'url_batch_id');
    }

    public function extracted(): HasOne
    {
        return $this->hasOne(ExtractedPageData::class);
    }

    public function pin(): HasOne
    {
        return $this->hasOne(GeneratedPin::class)->latestOfMany();
    }
}
