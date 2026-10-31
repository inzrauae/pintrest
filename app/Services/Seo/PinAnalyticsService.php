<?php

namespace App\Services\Seo;

use App\Models\GeneratedPin;
use App\Models\PinAnalytic;
use App\Services\Pinterest\PinterestApiException;
use App\Services\Pinterest\PinterestClient;
use App\Services\Pinterest\PinterestRateLimiter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pulls daily Pin metrics from GET /v5/pins/{id}/analytics (scope pins:read).
 * Pinterest serves analytics for production Pins only (not in the sandbox).
 */
class PinAnalyticsService
{
    public const METRICS = ['IMPRESSION' => 'impressions', 'SAVE' => 'saves', 'PIN_CLICK' => 'pin_clicks', 'OUTBOUND_CLICK' => 'outbound_clicks'];

    public function __construct(private readonly PinterestClient $client) {}

    public function canSync(GeneratedPin $pin): bool
    {
        return $pin->status === 'published' && $pin->pinterest_pin_id && $pin->account?->environment === 'production' && $pin->account->status === 'active';
    }

    /** @return bool whether metrics were stored */
    public function sync(GeneratedPin $pin, int $days = 30): bool
    {
        $pin->loadMissing('account');
        if (! $this->canSync($pin)) {
            return false;
        }

        // Pinterest allows up to 90 days back and dates in UTC.
        $start = max(now('UTC')->subDays(min(90, $days))->startOfDay(), ($pin->published_at ?? now())->copy()->utc()->startOfDay());

        try {
            $data = $this->client->get($pin->account, 'pins/'.rawurlencode($pin->pinterest_pin_id).'/analytics', [
                'start_date' => $start->toDateString(),
                'end_date' => now('UTC')->toDateString(),
                'metric_types' => implode(',', array_keys(self::METRICS)),
                'app_types' => 'ALL',
                'split_field' => 'NO_SPLIT',
            ], PinterestRateLimiter::PRIORITY_LOW);
        } catch (PinterestApiException $e) {
            // Out of budget / rate limited: leave it due so the next run picks it up.
            if (! $e->retryable) {
                $pin->forceFill(['analytics_synced_at' => now()])->saveQuietly();
            }

            return false;
        }

        // The response is keyed by app type ("all" for NO_SPLIT).
        $block = collect($data)->first(fn ($v) => is_array($v) && isset($v['daily_metrics'])) ?? [];

        DB::transaction(function () use ($pin, $block) {
            foreach ($block['daily_metrics'] ?? [] as $day) {
                if (empty($day['date']) || ! is_array($day['metrics'] ?? null)) {
                    continue;
                }
                $values = [];
                foreach (self::METRICS as $metric => $column) {
                    $values[$column] = max(0, (int) round($day['metrics'][$metric] ?? 0));
                }
                PinAnalytic::updateOrCreate(['generated_pin_id' => $pin->id, 'date' => $day['date']], $values);
            }
        });
        $pin->forceFill(['analytics_synced_at' => now()])->saveQuietly();

        return true;
    }

    /** Syncs published Pins whose metrics are older than $staleHours, newest Pins first. */
    public function syncDue(int $limit = 100, int $staleHours = 12, ?int $userId = null): int
    {
        // Trial access allows ~1,000 requests a day for everything: refresh each Pin at most daily.
        if ($staleHours > 0 && app(PinterestRateLimiter::class)->level() === 'trial') {
            $staleHours = max($staleHours, 24);
        }

        $pins = GeneratedPin::with('account')
            ->where('status', 'published')
            ->whereNotNull('pinterest_pin_id')
            ->where('published_at', '>=', now()->subDays(90))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->where(fn ($q) => $q->whereNull('analytics_synced_at')->orWhere('analytics_synced_at', '<', now()->subHours($staleHours)))
            ->whereHas('account', fn ($q) => $q->where('environment', 'production')->where('status', 'active'))
            ->latest('published_at')
            ->limit($limit)
            ->get();

        return $pins->filter(fn ($pin) => $this->sync($pin))->count();
    }

    /**
     * Totals and a daily series for a set of Pins.
     *
     * @return array{totals:array<string,int>, ctr:?float, daily:list<array>}
     */
    public function summary(Collection|array $pinIds, int $days = 30): array
    {
        $since = now()->subDays($days)->toDateString();
        $rows = PinAnalytic::whereIn('generated_pin_id', $pinIds)->where('date', '>=', $since);

        $totals = (clone $rows)->selectRaw('coalesce(sum(impressions),0) impressions, coalesce(sum(saves),0) saves, coalesce(sum(pin_clicks),0) pin_clicks, coalesce(sum(outbound_clicks),0) outbound_clicks')->first();
        $totals = array_map('intval', $totals?->only(array_values(self::METRICS)) ?? array_fill_keys(array_values(self::METRICS), 0));

        $daily = (clone $rows)
            ->selectRaw('date, sum(impressions) impressions, sum(saves) saves, sum(pin_clicks) pin_clicks, sum(outbound_clicks) outbound_clicks')
            ->groupBy('date')->orderBy('date')->get()
            ->map(fn ($r) => ['date' => substr((string) $r->date, 0, 10)] + array_map('intval', $r->only(array_values(self::METRICS))))
            ->all();

        return [
            'totals' => $totals,
            'ctr' => $totals['impressions'] ? round($totals['outbound_clicks'] / $totals['impressions'] * 100, 2) : null,
            'daily' => $daily,
        ];
    }
}
