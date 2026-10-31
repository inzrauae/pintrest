<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Discovery\SiteDiscoveryService;
use App\Services\UrlSubmissionService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Unattended "Website to Pins": discover pages and queue a draft Pin for each new one.
 * Safe to schedule repeatedly — already-submitted pages are skipped, so only new posts get Pins.
 */
class ImportSitePins extends Command
{
    protected $signature = 'pins:from-site {url : Website or sitemap URL} {--user= : User ID or email}
        {--limit= : Maximum pages} {--image-mode= : extract|generate|both} {--template= : Pin template ID}
        {--include= : Comma-separated URL keywords to keep} {--exclude= : Comma-separated URL keywords to skip}';

    protected $description = 'Create draft Pins for every new page of a website (sitemap or crawl)';

    public function handle(SiteDiscoveryService $discovery, UrlSubmissionService $submissions): int
    {
        $ref = (string) $this->option('user');
        $user = User::where(is_numeric($ref) ? 'id' : 'email', $ref)->first();
        if (! $user) {
            $this->error('Pass an existing user with --user=<id|email>.');

            return self::FAILURE;
        }

        $terms = fn ($s) => array_values(array_filter(array_map('trim', explode(',', (string) $s))));
        $url = (string) $this->argument('url');
        $found = $discovery->discover($url, $this->option('limit') ? (int) $this->option('limit') : null, $terms($this->option('include')), $terms($this->option('exclude')));
        $new = array_values(array_filter($found['urls'], fn ($u) => ! $submissions->findDuplicate($user, $u)));

        $this->info("Found {$found['total_found']} page(s) via {$found['source']}; ".count($new).' new.');
        if ($new === []) {
            return self::SUCCESS;
        }

        $template = $this->option('template') ? $user->templates()->find($this->option('template'))?->id : null;

        try {
            $batch = $submissions->createBatch($user, $new, array_filter([
                'name' => parse_url($url, PHP_URL_HOST).' — '.now()->format('Y-m-d H:i'),
                'source_url' => $url,
                'image_mode' => $this->option('image-mode'),
                'template_id' => $template,
                'skip_duplicates' => true,
            ], fn ($v) => $v !== null));
        } catch (ValidationException $e) {
            $this->error(implode(' ', $e->validator->errors()->all()));

            return self::FAILURE;
        }

        $this->info("Batch #{$batch->id} queued. Pins will appear as drafts for review.");

        return self::SUCCESS;
    }
}
