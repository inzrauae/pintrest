<?php

namespace Tests\Feature;

use App\Jobs\ProcessUrlSubmission;
use App\Models\SeoTopic;
use App\Models\UrlBatch;
use App\Models\User;
use App\Services\TaskStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunningTasksTest extends TestCase
{
    use RefreshDatabase;

    public function test_tasks_show_batches_generation_and_service_health(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $user->preferences()->update(['hourly_processing_limit' => 1]);
        $id = $this->actingAs($user)->postJson('/api/urls/bulk', ['urls' => "https://example.com/1\nhttps://example.com/2"])->json('data.id');

        $data = $this->actingAs($user)->getJson('/api/tasks')->assertOk()->json('data');

        $this->assertFalse($data['services']['worker']['running'], 'no heartbeat yet');
        $this->assertTrue($data['active']);
        $this->assertSame([1, 1], [$data['generation']['pending'], $data['generation']['waiting_for_quota']], 'pending, waiting for limit');
        $this->assertSame($id, $data['batches'][0]['id']);
        $this->assertSame(1, $data['batches'][0]['waiting_for_quota']);

        Cache::put(TaskStatusService::WORKER_HEARTBEAT, time(), 600);
        Cache::put(TaskStatusService::SCHEDULER_HEARTBEAT, time(), 600);
        $services = $this->actingAs($user)->getJson('/api/tasks')->json('data.services');
        $this->assertTrue($services['worker']['running']);
        $this->assertTrue($services['scheduler']['running']);
    }

    public function test_pause_all_stops_batches_publishing_and_autopilot_and_resume_restarts_them(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $id = $this->actingAs($user)->postJson('/api/urls/bulk', ['urls' => 'https://example.com/1'])->json('data.id');
        SeoTopic::create(['user_id' => $user->id, 'name' => 'T', 'keywords' => ['x'], 'source_url' => 'https://example.com/sitemap.xml']);

        $this->actingAs($user)->postJson('/api/tasks/pause-all', ['paused' => true])->assertOk()->assertJsonPath('data.paused', true);

        $this->assertSame('paused', UrlBatch::find($id)->status);
        $this->assertTrue((bool) $user->preferences()->fresh()->automation_paused);
        $this->artisan('seo:autopilot')->assertSuccessful();
        $this->assertNull(SeoTopic::first()->last_run_at, 'autopilot skips paused users');

        Queue::fake(); // count only what resume dispatches
        $this->actingAs($user)->postJson('/api/tasks/pause-all', ['paused' => false])->assertOk()->assertJsonPath('data.paused', false);

        $this->assertSame('processing', UrlBatch::find($id)->status);
        Queue::assertPushed(ProcessUrlSubmission::class, 1);
    }

    public function test_pages_in_progress_are_listed_for_the_pin_library(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/urls/bulk', ['urls' => "https://example.com/a\nhttps://example.com/b"])->assertCreated();
        $user->urlSubmissions()->where('url', 'https://example.com/b')->update(['status' => 'processing']);

        $items = $this->actingAs($user)->getJson('/api/tasks')->json('data.generation.items');

        $this->assertSame(['https://example.com/b', 'https://example.com/a'], array_column($items, 'url'), 'generating first, then queued');
        $this->assertSame(['processing', 'pending'], array_column($items, 'status'));
    }

    public function test_pin_library_lists_newest_first_even_within_the_same_second(): void
    {
        $user = User::factory()->create();
        $make = fn ($title) => \App\Models\GeneratedPin::create(['user_id' => $user->id, 'title' => $title, 'destination_url' => "https://example.com/{$title}", 'status' => 'draft']);
        $this->freezeTime();
        $older = $make('older');
        $newer = $make('newer');

        $ids = array_column($this->actingAs($user)->getJson('/api/pins')->assertOk()->json('data'), 'id');

        $this->assertSame([$newer->id, $older->id], $ids);
        $this->assertNotNull($this->actingAs($user)->getJson('/api/pins')->json('data.0.created_at'));
    }

    public function test_idle_panel_shows_recent_activity_and_todays_totals(): void
    {
        $user = User::factory()->create();
        \App\Support\ActivityLogger::log($user->id, 'pin_published', 'Published "Basil" to Pinterest.');
        \App\Support\ActivityLogger::log($user->id, 'pin_approved', 'Approved "Basil"'); // not shown: not an outcome

        $data = $this->actingAs($user)->getJson('/api/tasks')->json('data');

        $this->assertFalse($data['active']);
        $this->assertSame(['Published "Basil" to Pinterest.'], array_column($data['recent'], 'message'));
        $this->assertSame(0, $data['today']['generated']);
    }

    public function test_website_import_skips_pages_that_already_have_pins_and_says_so(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/urls/bulk', ['urls' => 'https://example.com/a'])->assertCreated();

        $r = $this->actingAs($user)->postJson('/api/sites/import', ['url' => 'https://example.com', 'urls' => ['https://example.com/a', 'https://example.com/b']])->assertCreated();
        $this->assertSame(1, $r->json('data.total'));
        $this->assertSame('Creating Pins for 1 new page(s); 1 already had Pins and were skipped.', $r->json('message'));

        $this->actingAs($user)->postJson('/api/sites/import', ['url' => 'https://example.com', 'urls' => ['https://example.com/a', 'https://example.com/b']])
            ->assertUnprocessable()
            ->assertJsonFragment(['All 2 page(s) already have Pins, so there is nothing new to create. Untick "Skip pages I already made Pins for" to make them again.']);
    }

    public function test_tasks_are_private(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $this->actingAs($owner)->postJson('/api/urls/bulk', ['urls' => 'https://example.com/1'])->assertCreated();

        $data = $this->actingAs(User::factory()->create())->getJson('/api/tasks')->json('data');

        $this->assertSame([], $data['batches']);
        $this->assertSame(0, $data['generation']['pending']);
    }
}
