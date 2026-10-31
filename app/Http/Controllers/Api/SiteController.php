<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Discovery\SiteDiscoveryService;
use App\Services\Fetching\FetchException;
use App\Services\Fetching\UnsafeUrlException;
use App\Services\UrlSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Website to Pins": find every page of a site (sitemap or crawl) and turn each into a draft Pin.
 */
class SiteController extends Controller
{
    public function __construct(
        private readonly SiteDiscoveryService $discovery,
        private readonly UrlSubmissionService $submissions,
    ) {}

    /** Preview: lists the pages that would get a Pin, without creating anything. */
    public function discover(Request $request): JsonResponse
    {
        $data = $request->validate($this->discoveryRules());

        $result = $this->run($data);
        $user = $request->user();
        $result['already_submitted'] = array_values(array_filter($result['urls'], fn ($u) => $this->submissions->findDuplicate($user, $u)));

        return response()->json(['data' => $result]);
    }

    /** Creates a batch for the given (or freshly discovered) pages; each page becomes a draft Pin in the background. */
    public function import(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate($this->discoveryRules() + [
            'urls' => ['nullable', 'array', 'max:'.config('pinbot.discovery.max_urls')],
            'urls.*' => ['string', 'max:2048'],
            'name' => ['nullable', 'string', 'max:100'],
            'language' => ['nullable', Rule::in(array_keys(config('pinbot.languages')))],
            'image_mode' => ['nullable', Rule::in(['extract', 'generate', 'both'])],
            'project_id' => ['nullable', Rule::exists('projects', 'id')->where('user_id', $user->id)],
            'template_id' => ['nullable', Rule::exists('pin_templates', 'id')->where('user_id', $user->id)],
            'skip_duplicates' => ['nullable', 'boolean'],
        ] + UrlSubmissionService::boardRules($user));
        $this->submissions->assertBoard($data);

        $urls = $data['urls'] ?? $this->run($data)['urls'];
        if ($urls === []) {
            throw ValidationException::withMessages(['url' => 'No pages were found on this website.']);
        }

        // Leave out pages that already have Pins up front, so the batch (and the message) only covers new work.
        $skipped = 0;
        if ($request->boolean('skip_duplicates', true)) {
            $new = array_values(array_filter($urls, fn ($u) => ! $this->submissions->findDuplicate($user, $u)));
            $skipped = count($urls) - count($new);
            $urls = $new;
        }
        if ($urls === []) {
            throw ValidationException::withMessages(['url' => "All {$skipped} page(s) already have Pins, so there is nothing new to create. Untick \"Skip pages I already made Pins for\" to make them again."]);
        }

        $host = parse_url($data['url'], PHP_URL_HOST);
        $batch = $this->submissions->createBatch($user, $urls, [
            'name' => $data['name'] ?? ($host.' — '.now()->format('Y-m-d H:i')),
            'source_url' => $data['url'],
            'skip_duplicates' => $request->boolean('skip_duplicates', true),
        ] + array_intersect_key($data, array_flip(['language', 'image_mode', 'project_id', 'template_id', 'pinterest_account_id', 'board_id'])));

        return response()->json([
            'data' => $batch->fresh(),
            'skipped' => $skipped,
            'message' => 'Creating Pins for '.count($urls).' new page(s)'.($skipped ? "; {$skipped} already had Pins and were skipped." : '.'),
        ], 201);
    }

    private function discoveryRules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', 'regex:#^https?://#i'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('pinbot.discovery.max_urls')],
            'include' => ['nullable', 'string', 'max:500'],
            'exclude' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function run(array $data): array
    {
        @set_time_limit(300);
        $terms = fn (?string $s) => array_values(array_filter(array_map('trim', explode(',', (string) $s))));

        try {
            return $this->discovery->discover(trim($data['url']), $data['limit'] ?? null, $terms($data['include'] ?? null), $terms($data['exclude'] ?? null));
        } catch (UnsafeUrlException|FetchException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }
    }
}
