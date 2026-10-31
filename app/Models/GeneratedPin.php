<?php

namespace App\Models;

use App\Support\UrlNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GeneratedPin extends Model
{
    use HasFactory;

    public const EDITABLE_STATUSES = ['draft', 'approved', 'failed', 'scheduled'];

    protected $fillable = [
        'user_id', 'url_submission_id', 'project_id', 'pinterest_account_id', 'pin_template_id', 'board_id',
        'title', 'description', 'alt_text', 'destination_url', 'destination_hash', 'language',
        'suggested_board', 'image_selection_reason', 'image_generation_prompt', 'status',
        'pinterest_pin_id', 'approved_at', 'published_at', 'error_message', 'ai_metadata',
        'seo_score', 'focus_keyword', 'researched_keywords', 'seo_topic_id', 'analytics_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'ai_metadata' => 'array',
            'researched_keywords' => 'array',
            'analytics_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (GeneratedPin $pin) {
            if ($pin->isDirty('destination_url')) {
                $pin->destination_hash = UrlNormalizer::hash($pin->destination_url);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(UrlSubmission::class, 'url_submission_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PinterestAccount::class, 'pinterest_account_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PinTemplate::class, 'pin_template_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(PinImage::class);
    }

    public function selectedImage(): HasOne
    {
        return $this->hasOne(PinImage::class)->where('is_selected', true);
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(PinKeyword::class);
    }

    public function schedule(): HasOne
    {
        return $this->hasOne(ScheduledPin::class);
    }

    public function publishingJobs(): HasMany
    {
        return $this->hasMany(PublishingJob::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PublicationLog::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(SeoTopic::class, 'seo_topic_id');
    }

    public function analytics(): HasMany
    {
        return $this->hasMany(PinAnalytic::class);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }

    /** @return list<string> */
    public function keywordList(string $type = 'keyword'): array
    {
        return $this->keywords->where('type', $type)->pluck('keyword')->values()->all();
    }

    /** @param list<string> $keywords */
    public function syncKeywords(array $keywords, string $type = 'keyword'): void
    {
        $this->keywords()->where('type', $type)->delete();
        $clean = collect($keywords)
            ->map(fn ($k) => mb_substr(trim(strip_tags((string) $k)), 0, 100))
            ->filter()
            ->unique(fn ($k) => mb_strtolower($k))
            ->values();

        foreach ($clean as $keyword) {
            $this->keywords()->create(['keyword' => $keyword, 'type' => $type]);
        }
        $this->unsetRelation('keywords');
    }
}
