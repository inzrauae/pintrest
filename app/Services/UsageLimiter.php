<?php

namespace App\Services;

use App\Models\GeneratedPin;
use App\Models\User;
use App\Services\Pinterest\PinterestRateLimiter;

/**
 * User-configurable processing and publishing quotas (on top of Pinterest's own rate limits).
 */
class UsageLimiter
{
    public function processedInLastHour(User $user): int
    {
        return $this->processedSince($user, now()->subHour());
    }

    public function processedToday(User $user): int
    {
        return $this->processedSince($user, $user->preferences()->startOfLocalDay());
    }

    /**
     * URLs actually sent for processing (queued, running, or attempted). Rejected, duplicate, paused and
     * cancelled entries never cost quota, so a resumed URL is counted when it is queued, not when it was pasted.
     */
    private function processedSince(User $user, $since): int
    {
        return $user->urlSubmissions()
            ->where('updated_at', '>=', $since)
            ->where(fn ($q) => $q->where('attempts', '>', 0)->orWhereIn('status', ['pending', 'processing']))
            ->count();
    }

    /** Number of new URL submissions allowed right now. */
    public function remainingProcessing(User $user): int
    {
        $prefs = $user->preferences();

        return max(0, min(
            $prefs->hourly_processing_limit - $this->processedInLastHour($user),
            $prefs->daily_processing_limit - $this->processedToday($user),
        ));
    }

    public function publishedToday(User $user): int
    {
        return GeneratedPin::where('user_id', $user->id)
            ->whereIn('status', ['published', 'publishing'])
            ->where(fn ($q) => $q->where('published_at', '>=', $user->preferences()->startOfLocalDay())->orWhere('status', 'publishing'))
            ->count();
    }

    public function canPublish(User $user): bool
    {
        return $this->publishedToday($user) < $this->dailyPublishLimit($user);
    }

    /** The user's daily publish limit, never above Pinterest's limit (covers values saved before the cap existed). */
    public function dailyPublishLimit(User $user): int
    {
        return min((int) $user->preferences()->daily_publish_limit, app(PinterestRateLimiter::class)->maxDailyPublishes());
    }
}
