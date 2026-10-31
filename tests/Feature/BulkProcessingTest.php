<?php

namespace Tests\Feature;

use App\Jobs\ProcessUrlSubmission;
use App\Models\UrlBatch;
use App\Models\User;
use App\Services\PinGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BulkProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_failing_url_does_not_stop_the_batch(): void
    {
        Http::fake([
            'https://example.com/good*' => fn () => Http::response($this->articleHtml(), 200, ['Content-Type' => 'text/html']),
            'https://example.com/img/*' => fn () => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'https://example.com/broken' => Http::response('gone', 410),
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/urls/bulk', [
            'urls' => "https://example.com/good-1\nhttps://example.com/broken\nnot-a-url\nhttp://10.0.0.1/x\nhttps://example.com/good-2\nhttps://example.com/good-1",
        ])->assertCreated();

        $batch = UrlBatch::find($response->json('data.id'));
        $this->assertSame(4, $batch->total, 'non-URLs are ignored and exact duplicates collapsed');
        $statuses = $batch->submissions()->orderBy('id')->pluck('status', 'url')->all();

        $this->assertSame('generated', $statuses['https://example.com/good-1']);
        $this->assertSame('failed', $statuses['https://example.com/broken']);
        $this->assertArrayNotHasKey('not-a-url', $statuses);
        $this->assertSame('failed', $statuses['http://10.0.0.1/x']);
        $this->assertSame('generated', $statuses['https://example.com/good-2']);
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_previously_submitted_urls_are_marked_duplicate(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/urls/bulk', ['urls' => 'https://example.com/a'])->assertCreated();
        $id = $this->actingAs($user)->postJson('/api/urls/bulk', ['urls' => "https://example.com/a/\nhttps://example.com/b"])->json('data.id');

        $statuses = UrlBatch::find($id)->submissions()->pluck('status', 'url')->all();
        $this->assertSame('duplicate', $statuses['https://example.com/a/']);
        $this->assertSame('pending', $statuses['https://example.com/b']);
        Queue::assertPushed(ProcessUrlSubmission::class, 2);
    }

    public function test_csv_upload(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $csv = UploadedFile::fake()->createWithContent('urls.csv', "url,note\nhttps://example.com/1,first\n\"https://example.com/2\",second\nnot a url,x\n");

        $id = $this->actingAs($user)->post('/api/urls/bulk', ['csv' => $csv], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->assertSame(['https://example.com/1', 'https://example.com/2'], UrlBatch::find($id)->submissions()->orderBy('id')->pluck('url')->all());
    }

    public function test_rejected_duplicate_and_paused_urls_do_not_use_quota(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $user->preferences()->update(['hourly_processing_limit' => 2]);

        $this->actingAs($user)->postJson('/api/urls/bulk', ['urls' => "https://example.com/1\nhttp://10.0.0.1/x"])->assertCreated();
        $id = $this->actingAs($user)->postJson('/api/urls/bulk', ['urls' => "https://example.com/1\nhttps://example.com/2\nhttps://example.com/3"])->json('data.id');

        // Quota 2: one used by /1; the private URL, the duplicate /1 and the paused /3 cost nothing.
        $this->assertEquals(['duplicate' => 1, 'pending' => 1, 'paused' => 1], UrlBatch::find($id)->statusCounts());
        $this->assertSame(2, app(\App\Services\UsageLimiter::class)->processedInLastHour($user));
    }

    public function test_waiting_urls_resume_automatically_when_quota_frees_up(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $user->preferences()->update(['hourly_processing_limit' => 1]);
        $id = $this->actingAs($user)->postJson('/api/urls/bulk', ['urls' => "https://example.com/1\nhttps://example.com/2"])->json('data.id');
        $batch = UrlBatch::find($id);

        $this->artisan('pins:resume-waiting')->assertSuccessful();
        $this->assertEquals(['pending' => 1, 'paused' => 1], $batch->statusCounts(), 'still over quota');

        $this->travel(61)->minutes();
        $batch->submissions()->where('status', 'pending')->update(['status' => 'generated', 'attempts' => 1, 'updated_at' => now()->subMinutes(61)]);
        $this->artisan('pins:resume-waiting')->assertSuccessful();

        $this->assertEquals(['generated' => 1, 'pending' => 1], $batch->statusCounts());
        Queue::assertPushed(ProcessUrlSubmission::class, 2);
    }

    public function test_quota_pauses_overflow_and_resume_continues(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $user->preferences()->update(['hourly_processing_limit' => 2]);

        $id = $this->actingAs($user)->postJson('/api/urls/bulk', ['urls' => "https://example.com/1\nhttps://example.com/2\nhttps://example.com/3"])->json('data.id');
        $batch = UrlBatch::find($id);

        $this->assertEquals(['pending' => 2, 'paused' => 1], $batch->statusCounts());
        Queue::assertPushed(ProcessUrlSubmission::class, 2);
    }

    public function test_pause_cancel_and_retry(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $id = $this->actingAs($user)->postJson('/api/urls/bulk', ['urls' => "https://example.com/1\nhttps://example.com/2"])->json('data.id');
        $batch = UrlBatch::find($id);

        $this->actingAs($user)->postJson("/api/batches/{$id}/pause")->assertOk()->assertJsonPath('data.status', 'paused');

        // A queued job that starts while paused parks itself.
        (new ProcessUrlSubmission($batch->submissions()->first()->id))->handle(app(PinGenerationService::class));
        $this->assertSame('paused', $batch->submissions()->first()->status);

        $this->actingAs($user)->postJson("/api/batches/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(['cancelled' => 2], $batch->fresh()->statusCounts());

        $this->actingAs($user)->postJson("/api/batches/{$id}/retry", ['include_cancelled' => true])->assertOk();
        $this->assertSame(['pending' => 2], $batch->fresh()->statusCounts());
    }

    public function test_csv_export_neutralises_formulas(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response(str_replace('How to Grow Basil Indoors', '=HYPERLINK("http://x") Basil Guide For Everyone', $this->articleHtml()), 200, ['Content-Type' => 'text/html']),
        ]);
        $user = User::factory()->create();
        $id = $this->actingAs($user)->postJson('/api/urls/bulk', ['urls' => 'https://example.com/post', 'image_mode' => 'extract'])->json('data.id');

        $csv = $this->actingAs($user)->get("/api/batches/{$id}/export")->assertOk()->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }
}
