<?php

namespace App\Console\Commands;

use App\Services\Seo\PinAnalyticsService;
use Illuminate\Console\Command;

class SyncPinAnalytics extends Command
{
    protected $signature = 'pins:sync-analytics {--limit=100}';

    protected $description = 'Fetch Pinterest analytics (impressions, saves, clicks) for published Pins';

    public function handle(PinAnalyticsService $analytics): int
    {
        $this->info('Synced analytics for '.$analytics->syncDue((int) $this->option('limit')).' Pin(s).');

        return self::SUCCESS;
    }
}
