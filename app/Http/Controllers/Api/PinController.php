<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PinResource;
use App\Jobs\ProcessUrlSubmission;
use App\Models\GeneratedPin;
use App\Models\PinterestBoard;
use App\Models\UrlSubmission;
use App\Services\Images\PinImageService;
use App\Services\PinGenerationService;
use App\Services\Pinterest\PinPublisher;
use App\Services\PublishingService;
use App\Support\ActivityLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PinController extends Controller
{
    private const RELATIONS = ['images', 'keywords', 'schedule', 'account', 'submission'];

    public function index(Request $request): JsonResponse
    {
        $query = GeneratedPin::where('user_id', $request->user()->id)
            ->with(['images' => fn ($q) => $q->where('is_selected', true), 'schedule', 'account'])
            ->latest();

        if ($status = $request->query('status')) {
            $query->whereIn('status', explode(',', $status));
        }
        if ($project = $request->query('project_id')) {
            $query->where('project_id', $project);
        }
        if ($search = $request->query('search')) {
            $like = '%'.addcslashes($search, '%_').'%';
            $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('destination_url', 'like', $like));
        }

        $pins = $query->paginate(min((int) $request->query('per_page', 24), 100));
        $this->attachBoardNames($pins->getCollection());

        return PinResource::collection($pins)->response();
    }

    public function show(Request $request, GeneratedPin $pin, PinPublisher $publisher): JsonResponse
    {
        Gate::authorize('view', $pin);
        $pin->load(self::RELATIONS);
        $this->attachBoardNames(collect([$pin]));

        return response()->json([
            'data' => new PinResource($pin),
            'preflight' => $pin->isEditable() ? $publisher->preflight($pin) : [],
            'duplicate_of' => $publisher->duplicateOf($pin)?->only(['id', 'title', 'pinterest_pin_id']),
            'image_candidates' => $pin->submission?->extracted?->images ?? [],
        ]);
    }

    /** (Re)generates a Pin from an existing URL submission. */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate(['url_submission_id' => ['required', 'integer']]);
        $submission = UrlSubmission::findOrFail($data['url_submission_id']);
        Gate::authorize('update', $submission);

        if ($submission->pin && ! $submission->pin->isEditable()) {
            throw ValidationException::withMessages(['url_submission_id' => 'This URL already has a published Pin.']);
        }

        @set_time_limit(300);
        $submission->update(['status' => 'pending']);
        ProcessUrlSubmission::dispatchSync($submission->id, false);
        $submission->refresh();

        if ($submission->status !== 'generated') {
            return response()->json(['message' => $submission->error_message ?: 'Generation failed.'], 422);
        }

        return response()->json(['data' => new PinResource($submission->pin->load(self::RELATIONS))], 201);
    }

    public function update(Request $request, GeneratedPin $pin, PinImageService $images): JsonResponse
    {
        Gate::authorize('update', $pin);
        $this->assertEditable($pin);
        $userId = $request->user()->id;

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'destination_url' => ['sometimes', 'required', 'url:http,https', 'max:2048'],
            'keywords' => ['sometimes', 'array', 'max:20'],
            'keywords.*' => ['string', 'max:100'],
            'hashtags' => ['sometimes', 'array', 'max:10'],
            'hashtags.*' => ['string', 'max:60'],
            'image_generation_prompt' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'pinterest_account_id' => ['sometimes', 'nullable', Rule::exists('pinterest_accounts', 'id')->where('user_id', $userId)],
            'board_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'pin_template_id' => ['sometimes', 'nullable', Rule::exists('pin_templates', 'id')->where('user_id', $userId)],
            'project_id' => ['sometimes', 'nullable', Rule::exists('projects', 'id')->where('user_id', $userId)],
        ]);

        $fields = collect($data)->except(['keywords', 'hashtags'])
            ->map(fn ($v) => is_string($v) ? trim(strip_tags($v)) : $v)
            ->all();

        $rerender = (array_key_exists('pin_template_id', $fields) && $fields['pin_template_id'] != $pin->pin_template_id)
            || (array_key_exists('title', $fields) && $fields['title'] !== $pin->title && $pin->template?->text_overlay);

        $pin->update($fields);
        if (array_key_exists('keywords', $data)) {
            $pin->syncKeywords($data['keywords'], 'keyword');
        }
        if (array_key_exists('hashtags', $data)) {
            $pin->syncKeywords(array_map(fn ($t) => '#'.ltrim(preg_replace('/\s+/', '', $t), '#'), $data['hashtags']), 'hashtag');
        }
        if ($rerender) {
            $images->rerender($pin->fresh('template'));
        }

        return $this->show($request, $pin->fresh(), app(PinPublisher::class));
    }

    public function regenerate(Request $request, GeneratedPin $pin, PinGenerationService $service): JsonResponse
    {
        Gate::authorize('update', $pin);
        $this->assertEditable($pin);
        $data = $request->validate([
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => [Rule::in(['title', 'description', 'keywords', 'hashtags', 'alt_text', 'image_prompt', 'board'])],
        ]);

        @set_time_limit(180);
        $pin = $service->regenerate($pin, $data['fields']);

        return response()->json(['data' => new PinResource($pin->load(self::RELATIONS))]);
    }

    public function approve(Request $request, GeneratedPin $pin): JsonResponse
    {
        Gate::authorize('update', $pin);
        if (! in_array($pin->status, ['draft', 'failed'], true)) {
            throw ValidationException::withMessages(['status' => 'Only draft or failed Pins can be approved.']);
        }
        $pin->update(['status' => 'approved', 'approved_at' => now(), 'error_message' => null]);
        ActivityLogger::log($pin->user_id, 'pin_approved', 'Approved "'.$pin->title.'"', pin: $pin);

        return response()->json(['data' => new PinResource($pin->load(self::RELATIONS))]);
    }

    public function publish(Request $request, GeneratedPin $pin, PublishingService $publishing): JsonResponse
    {
        Gate::authorize('update', $pin);
        $job = $publishing->queue($pin, 'manual', $request->boolean('allow_duplicate'));

        return response()->json([
            'message' => 'Publishing started.',
            'data' => new PinResource($pin->fresh(self::RELATIONS)),
            'job' => $job->fresh(),
        ], 202);
    }

    public function schedule(Request $request, GeneratedPin $pin, PublishingService $publishing): JsonResponse
    {
        Gate::authorize('update', $pin);
        $this->assertEditable($pin);
        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'timezone' => ['nullable', 'timezone:all'],
            'allow_duplicate' => ['boolean'],
        ]);

        $tz = $data['timezone'] ?? $request->user()->preferences()->timezone;
        $at = Carbon::parse($data['scheduled_at'], $tz)->utc();
        if ($at->lte(now()->addMinute())) {
            throw ValidationException::withMessages(['scheduled_at' => 'Choose a time in the future.']);
        }

        $pin = $publishing->schedule($pin, $at, $request->boolean('allow_duplicate'));
        ActivityLogger::log($pin->user_id, 'pin_scheduled', 'Scheduled "'.$pin->title.'" for '.$at->toDayDateTimeString().' UTC', pin: $pin);

        return response()->json(['data' => new PinResource($pin->load(self::RELATIONS))]);
    }

    public function unschedule(Request $request, GeneratedPin $pin, PublishingService $publishing): JsonResponse
    {
        Gate::authorize('update', $pin);

        return response()->json(['data' => new PinResource($publishing->unschedule($pin)->load(self::RELATIONS))]);
    }

    public function destroy(Request $request, GeneratedPin $pin, PinImageService $images): JsonResponse
    {
        Gate::authorize('delete', $pin);
        if (in_array($pin->status, ['publishing'], true)) {
            throw ValidationException::withMessages(['status' => 'A Pin cannot be deleted while it is being published.']);
        }
        $pin->images->each(fn ($img) => $images->delete($img));
        $pin->delete();

        return response()->json(['message' => 'Pin deleted.']);
    }

    public function export(Request $request): StreamedResponse
    {
        $pins = GeneratedPin::where('user_id', $request->user()->id)
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->with('keywords')
            ->latest()
            ->limit(5000)
            ->get();

        return response()->streamDownload(function () use ($pins) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'status', 'title', 'description', 'keywords', 'hashtags', 'destination_url', 'board_id', 'pinterest_pin_id', 'published_at', 'created_at']);
            foreach ($pins as $pin) {
                fputcsv($out, array_map([BatchController::class, 'csvSafe'], [
                    $pin->id, $pin->status, $pin->title, $pin->description, implode(', ', $pin->keywordList()),
                    implode(' ', $pin->keywordList('hashtag')), $pin->destination_url, $pin->board_id, $pin->pinterest_pin_id,
                    $pin->published_at?->toIso8601String(), $pin->created_at?->toIso8601String(),
                ]));
            }
            fclose($out);
        }, 'pins-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function assertEditable(GeneratedPin $pin): void
    {
        if (! $pin->isEditable()) {
            throw ValidationException::withMessages(['status' => 'This Pin can no longer be edited (status: '.$pin->status.').']);
        }
    }

    private function attachBoardNames($pins): void
    {
        $boardIds = $pins->pluck('board_id')->filter()->unique();
        if ($boardIds->isEmpty()) {
            return;
        }
        $names = PinterestBoard::whereIn('board_id', $boardIds)
            ->whereIn('pinterest_account_id', $pins->pluck('pinterest_account_id')->filter()->unique())
            ->pluck('name', 'board_id');
        $pins->each(fn ($p) => $p->setAttribute('board_name', $names[$p->board_id] ?? null));
    }
}
