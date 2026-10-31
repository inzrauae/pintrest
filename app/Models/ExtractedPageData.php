<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtractedPageData extends Model
{
    protected $table = 'extracted_page_data';

    protected $fillable = [
        'url_submission_id', 'final_url', 'canonical_url', 'http_status', 'title', 'meta_description',
        'og_title', 'og_description', 'og_image', 'twitter_image', 'images', 'headings', 'content',
        'page_language', 'content_hash',
    ];

    protected function casts(): array
    {
        return ['images' => 'array', 'headings' => 'array'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(UrlSubmission::class, 'url_submission_id');
    }
}
