<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    protected $fillable = [
        'user_id', 'language', 'timezone', 'image_mode', 'hourly_processing_limit', 'daily_processing_limit',
        'daily_publish_limit', 'publishing_window_start', 'publishing_window_end', 'auto_publish',
        'auto_publish_acknowledged_at', 'auto_publish_spacing_minutes', 'automation_paused',
        'default_pinterest_account_id', 'default_board_id', 'keyword_research', 'trends_region',
    ];

    protected $attributes = [
        'language' => 'en',
        'timezone' => 'UTC',
        'image_mode' => 'extract',
        'hourly_processing_limit' => 30,
        'daily_processing_limit' => 200,
        'daily_publish_limit' => 20,
        'auto_publish' => false,
        'auto_publish_spacing_minutes' => 60,
        'automation_paused' => false,
        'keyword_research' => true,
        'trends_region' => 'US',
    ];

    protected function casts(): array
    {
        return [
            'auto_publish' => 'boolean',
            'automation_paused' => 'boolean',
            'keyword_research' => 'boolean',
            'auto_publish_acknowledged_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether $at (any timezone) falls inside the configured publishing window in the user's timezone. */
    public function withinPublishingWindow(CarbonInterface $at): bool
    {
        if (! $this->publishing_window_start || ! $this->publishing_window_end) {
            return true;
        }

        $local = $at->copy()->setTimezone($this->timezone ?: 'UTC')->format('H:i');
        $start = $this->publishing_window_start;
        $end = $this->publishing_window_end;

        // A window like 22:00-06:00 wraps past midnight.
        return $start <= $end
            ? ($local >= $start && $local < $end)
            : ($local >= $start || $local < $end);
    }

    /** Start of the user's current local day, expressed in UTC. */
    public function startOfLocalDay(): CarbonInterface
    {
        return now($this->timezone ?: 'UTC')->startOfDay()->utc();
    }
}
