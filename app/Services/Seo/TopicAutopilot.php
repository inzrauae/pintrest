<?php

namespace App\Services\Seo;

use App\Models\GeneratedPin;
use App\Models\SeoTopic;
use App\Services\Discovery\SiteDiscoveryService;
use App\Services\Pinterest\PinPublisher;
use App\Services\UrlSubmissionService;
use App\Support\ActivityLogger;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Per-topic automation: discover new pages → queue AI Pins (researched around the topic's keywords)
 * → put them on the topic's board → optionally approve them so the opt-in auto-publisher posts them.
 */
class TopicAutopilot
{
    public function __construct(
        private readonly SiteDiscoveryService $discovery,
        private readonly UrlSubmissionService $submissions,
        private readonly PinPublisher $publisher,
    ) {}

    /**
     * Finds pages on the topic's site that have no Pin yet and queues up to max_new_per_run of them.
     *
     * @return array{found:int, new:int, queued:int, batch_id:?int, source:?string, error:?string, ran_at:string}
     */
    public function run(SeoTopic $topic): array
    {
        $summary = ['found' => 0, 'new' => 0, 'queued' => 0, 'batch_id' => null, 'source' => null, 'error' => null, 'ran_at' => now()->toIso8601String()];
        $user = $topic->user;

        try {
            if (! $topic->source_url) {
                throw new \RuntimeException('Add a website or sitemap URL to discover pages for this topic.');
            }

            $found = $this->discovery->discover($topic->source_url, null, $topic->url_filters ?? []);
            $new = array_values(array_filter($found['urls'], fn ($url) => ! $this->submissions->findDuplicate($user, $url)));
            $queue = array_slice($new, 0, max(1, $topic->max_new_per_run));
            $summary = array_merge($summary, ['found' => $found['total_found'], 'new' => count($new), 'source' => $found['source']]);

            if ($queue !== []) {
                $batch = $this->submissions->createBatch($user, $queue, array_filter([
                    'name' => 'Topic: '.$topic->name.' — '.now()->format('Y-m-d H:i'),
                    'source_url' => $topic->source_url,
                    'language' => $topic->language,
                    'image_mode' => $topic->image_mode,
                    'template_id' => $topic->pin_template_id,
                    'topic_id' => $topic->id,
                    'skip_duplicates' => true,
                ], fn ($v) => $v !== null));
                $summary['queued'] = count($queue);
                $summary['batch_id'] = $batch->id;
            }
        } catch (ValidationException $e) {
            $summary['error'] = implode(' ', $e->validator->errors()->all());
        } catch (Throwable $e) {
            $summary['error'] = $e->getMessage();
        }

        $topic->update(['last_run_at' => now(), 'last_run' => $summary]);
        ActivityLogger::log($user->id, 'topic_autopilot', "Topic \"{$topic->name}\": ".($summary['error']
            ? 'discovery failed — '.$summary['error']
            : "{$summary['new']} new page(s) found, {$summary['queued']} queued for Pins."), $summary['error'] ? 'warning' : 'info');

        return $summary;
    }

    /** Applies the topic's board and automation settings to a Pin generated from one of its pages. */
    public function applyToPin(GeneratedPin $pin, SeoTopic $topic): void
    {
        $updates = ['seo_topic_id' => $topic->id];
        if ($topic->pinterest_account_id && $topic->board_id) {
            $updates += ['pinterest_account_id' => $topic->pinterest_account_id, 'board_id' => $topic->board_id];
        }
        $pin->update($updates);

        // The user confirmed on the topic that images on their own site are theirs to use.
        if ($topic->owns_images) {
            $pin->images()->where('source', 'extracted')->where('rights_confirmed', false)->update(['rights_confirmed' => true]);
        }

        if ($topic->auto_approve && $pin->status === 'draft') {
            $problems = $this->publisher->preflight($pin->fresh(), false, false);
            if ($problems === []) {
                $pin->update(['status' => 'approved', 'approved_at' => now()]);
                ActivityLogger::log($pin->user_id, 'pin_auto_approved', "Auto-approved \"{$pin->title}\" for topic \"{$topic->name}\".", pin: $pin);
            } else {
                ActivityLogger::log($pin->user_id, 'pin_auto_approve_skipped', "Left \"{$pin->title}\" as a draft: ".implode(' ', $problems), 'warning', $pin);
            }
        }
    }
}
