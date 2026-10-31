<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['https://api.pinterest.com/v5/pins' => Http::response(['id' => '42'], 201)]);
    }

    public function test_schedule_then_dispatch_when_due(): void
    {
        [$user, $pin] = $this->publishablePin();

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/schedule", ['scheduled_at' => now()->addHours(2)->toDateTimeString(), 'timezone' => 'UTC'])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');

        $this->artisan('pins:dispatch-scheduled')->assertSuccessful();
        $this->assertSame('scheduled', $pin->fresh()->status, 'not yet due');

        $this->travel(3)->hours();
        $this->artisan('pins:dispatch-scheduled')->assertSuccessful();

        $pin->refresh();
        $this->assertSame('published', $pin->status);
        $this->assertSame('published', $pin->schedule->status);
    }

    public function test_past_times_are_rejected(): void
    {
        [$user, $pin] = $this->publishablePin();
        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/schedule", ['scheduled_at' => now()->subHour()->toDateTimeString(), 'timezone' => 'UTC'])
            ->assertUnprocessable();
    }

    public function test_paused_automation_and_publishing_window_hold_scheduled_pins(): void
    {
        [$user, $pin] = $this->publishablePin();
        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/schedule", ['scheduled_at' => now()->addMinutes(10)->toDateTimeString(), 'timezone' => 'UTC'])->assertOk();
        $this->travel(15)->minutes();

        $user->preferences()->update(['automation_paused' => true]);
        $this->artisan('pins:dispatch-scheduled');
        $this->assertSame('scheduled', $pin->fresh()->status);

        $outside = now()->addHours(3)->format('H:i');
        $user->preferences()->update(['automation_paused' => false, 'publishing_window_start' => $outside, 'publishing_window_end' => now()->addHours(4)->format('H:i')]);
        $this->artisan('pins:dispatch-scheduled');
        $this->assertSame('scheduled', $pin->fresh()->status, 'outside publishing window');

        $user->preferences()->update(['publishing_window_start' => null, 'publishing_window_end' => null]);
        $this->artisan('pins:dispatch-scheduled');
        $this->assertSame('published', $pin->fresh()->status);
    }

    public function test_unschedule_returns_pin_to_approved(): void
    {
        [$user, $pin] = $this->publishablePin();
        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/schedule", ['scheduled_at' => now()->addDay()->toDateTimeString(), 'timezone' => 'UTC']);

        $this->actingAs($user)->deleteJson("/api/pins/{$pin->id}/schedule")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('cancelled', $pin->fresh()->schedule->status);
    }

    public function test_auto_publish_requires_explicit_acknowledgement_and_only_publishes_approved_pins(): void
    {
        [$user, $pin] = $this->publishablePin();

        $this->actingAs($user)->putJson('/api/settings/preferences', ['auto_publish' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('auto_publish_acknowledged');

        $this->actingAs($user)->putJson('/api/settings/preferences', ['auto_publish' => true, 'auto_publish_acknowledged' => true])->assertOk();

        $this->artisan('pins:auto-publish');
        $this->assertSame('draft', $pin->fresh()->status, 'unreviewed drafts are never auto-published');

        $this->actingAs($user)->postJson("/api/pins/{$pin->id}/approve")->assertOk();
        $this->artisan('pins:auto-publish');
        $this->assertSame('published', $pin->fresh()->status);
    }

    public function test_publishing_window_wraps_midnight(): void
    {
        [$user] = $this->publishablePin();
        $prefs = $user->preferences();
        $prefs->fill(['publishing_window_start' => '22:00', 'publishing_window_end' => '06:00', 'timezone' => 'UTC']);

        $this->assertTrue($prefs->withinPublishingWindow(now()->setTime(23, 30)));
        $this->assertTrue($prefs->withinPublishingWindow(now()->setTime(5, 0)));
        $this->assertFalse($prefs->withinPublishingWindow(now()->setTime(12, 0)));
    }
}
