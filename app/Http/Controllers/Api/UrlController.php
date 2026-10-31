<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PinResource;
use App\Http\Resources\SubmissionResource;
use App\Jobs\ProcessUrlSubmission;
use App\Models\UrlSubmission;
use App\Services\UrlSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UrlController extends Controller
{
    public function __construct(private readonly UrlSubmissionService $submissions) {}

    /** Single-URL mode: validate, de-duplicate, then extract + generate synchronously for an instant preview. */
    public function analyze(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'language' => ['nullable', Rule::in(array_keys(config('pinbot.languages')))],
            'image_mode' => ['nullable', Rule::in(['extract', 'generate', 'both'])],
            'project_id' => ['nullable', Rule::exists('projects', 'id')->where('user_id', $request->user()->id)],
            'force' => ['boolean'],
        ]);
        $user = $request->user();
        $url = trim($data['url']);

        if ($error = $this->submissions->validationError($url)) {
            throw ValidationException::withMessages(['url' => $error]);
        }

        if (! ($data['force'] ?? false) && ($existing = $this->submissions->findDuplicate($user, $url))) {
            return response()->json([
                'message' => 'You already submitted this URL.',
                'duplicate' => true,
                'existing' => (new SubmissionResource($existing))->resolve(),
            ], 409);
        }

        $this->submissions->assertWithinLimits($user);
        $submission = $this->submissions->create($user, $url, $data);

        @set_time_limit(300);
        ProcessUrlSubmission::dispatchSync($submission->id, false);
        $submission->refresh()->load(['extracted', 'pin']);

        if ($submission->status !== 'generated') {
            return response()->json([
                'message' => $submission->error_message ?: 'The page could not be processed.',
                'data' => new SubmissionResource($submission),
            ], 422);
        }

        $pin = $submission->pin->load(['images', 'keywords', 'schedule', 'account']);

        return response()->json([
            'data' => new SubmissionResource($submission),
            'pin' => new PinResource($pin),
        ], 201);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'urls' => ['nullable', 'string', 'max:500000'],
            'csv' => ['nullable', 'file', 'mimes:csv,txt', 'max:2048'],
            'name' => ['nullable', 'string', 'max:100'],
            'language' => ['nullable', Rule::in(array_keys(config('pinbot.languages')))],
            'image_mode' => ['nullable', Rule::in(['extract', 'generate', 'both'])],
            'project_id' => ['nullable', Rule::exists('projects', 'id')->where('user_id', $request->user()->id)],
            'skip_duplicates' => ['nullable', 'boolean'],
        ]);

        $urls = preg_split('/[\r\n,;\s]+/', (string) ($data['urls'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if ($request->hasFile('csv')) {
            $urls = array_merge($urls, $this->urlsFromCsv($request->file('csv')->getRealPath()));
        }

        $batch = $this->submissions->createBatch($request->user(), $urls, $data + ['skip_duplicates' => $request->boolean('skip_duplicates', true)]);

        return response()->json(['data' => $batch->fresh()], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->urlSubmissions()->with('pin')->latest();
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->query('search')) {
            $query->where('url', 'like', '%'.addcslashes($search, '%_').'%');
        }

        return SubmissionResource::collection($query->paginate(min((int) $request->query('per_page', 20), 100)))->response();
    }

    public function show(Request $request, UrlSubmission $submission): JsonResponse
    {
        Gate::authorize('view', $submission);

        return response()->json(['data' => new SubmissionResource($submission->load(['extracted', 'pin']))]);
    }

    /** @return list<string> */
    private function urlsFromCsv(string $path): array
    {
        $urls = [];
        $handle = fopen($path, 'r');
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            foreach ($row as $cell) {
                $cell = trim((string) $cell);
                if (preg_match('#^https?://#i', $cell)) {
                    $urls[] = $cell;
                    break; // one URL per row
                }
            }
        }
        fclose($handle);

        return $urls;
    }
}
