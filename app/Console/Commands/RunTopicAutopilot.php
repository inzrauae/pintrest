<?php

namespace App\Console\Commands;

use App\Models\SeoTopic;
use App\Services\Seo\TopicAutopilot;
use Illuminate\Console\Command;

/**
 * Runs content discovery for every SEO topic that is due, queueing AI Pins for its new pages.
 */
class RunTopicAutopilot extends Command
{
    protected $signature = 'seo:autopilot {--topic= : Run only this topic ID, even if not due}';

    protected $description = 'Discover new pages for SEO topics and queue AI Pins for them';

    public function handle(TopicAutopilot $autopilot): int
    {
        $topics = $this->option('topic')
            ? SeoTopic::with('user')->whereKey($this->option('topic'))->get()
            : SeoTopic::with('user')->where('auto_discover', true)->whereNotNull('source_url')->get()
                // "Pause all" on the dashboard also stops the autopilot.
                ->filter(fn (SeoTopic $t) => $t->isDue() && ! $t->user->preferences()->automation_paused);

        foreach ($topics as $topic) {
            $summary = $autopilot->run($topic);
            $this->line("Topic #{$topic->id} {$topic->name}: ".($summary['error'] ?? "{$summary['new']} new, {$summary['queued']} queued"));
        }

        return self::SUCCESS;
    }
}
