<?php

namespace App\Services;

use App\Models\GeneratedPin;
use App\Models\User;

/**
 * User-configurable processing and publishing quotas (on top of Pinterest's own rate limits).
 */
class UsageLimiter
{
    public function processedInLastHour(User $user): int
    {
        return $user->urlSubmissions()->where('created_at', '>=', now()->subHour())->count();
    }

    public function processedToday(User $user): int
    {
        return $user->urlSubmissions()->where('created_at', '>=', $user->preferences()->startOfLocalDay())->count();
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
        return $this->publishedToday($user) < $user->preferences()->daily_publish_limit;
    }
}
