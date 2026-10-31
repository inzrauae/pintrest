<?php

namespace App\Console\Commands;

use App\Models\PinterestAccount;
use App\Services\Pinterest\PinterestOAuthService;
use Illuminate\Console\Command;
use Throwable;

class RefreshPinterestTokens extends Command
{
    protected $signature = 'pinterest:refresh-tokens {--days=3 : Refresh tokens expiring within this many days}';

    protected $description = 'Proactively refresh Pinterest access tokens that are about to expire';

    public function handle(PinterestOAuthService $oauth): int
    {
        $accounts = PinterestAccount::where('status', 'active')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addDays((int) $this->option('days')))
            ->get();

        foreach ($accounts as $account) {
            try {
                $oauth->refresh($account);
                $this->info("Refreshed account #{$account->id}");
            } catch (Throwable $e) {
                $this->warn("Account #{$account->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
