<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeneratedPin;
use App\Models\PinImage;
use App\Services\Ai\AiException;
use App\Services\Fetching\FetchException;
use App\Services\Fetching\UnsafeUrlException;
use App\Services\Images\ImageProcessingException;
use App\Services\Images\PinImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PinImageController extends Controller
{
    public function __construct(private readonly PinImageService $images) {}

    /** Streams an image to its owner. Images are never on a public disk. */
    public function file(Request $request, PinImage $image): Response
    {
        Gate::authorize('view', $image->pin);
        $variant = $request->query('original') ? $image->original_path : $image->path;
        $disk = Storage::disk(config('pinbot.images.disk'));
        abort_unless($variant && $disk->exists($variant), 404);

        return $disk->response($variant, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function upload(Request $request, GeneratedPin $pin): JsonResponse
    {
        Gate::authorize('update', $pin);
        $request->validate([
            'image' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', 'max:'.config('pinbot.images.max_upload_kb')],
        ]);

        return $this->attempt(fn () => $this->images->importUpload($pin, $request->file('image')));
    }

    /** Adds one of the page's image candidates (or another URL) to the Pin. */
    public function import(Request $request, GeneratedPin $pin): JsonResponse
    {
        Gate::authorize('update', $pin);
        $data = $request->validate(['url' => ['required', 'url:http,https', 'max:2048']]);

        return $this->attempt(fn () => $this->images->importFromUrl($pin, $data['url'], 50));
    }

    public function generate(Request $request, GeneratedPin $pin): JsonResponse
    {
        Gate::authorize('update', $pin);
        $data = $request->validate(['prompt' => ['nullable', 'string', 'max:2000']]);
        @set_time_limit(300);

        return $this->attempt(fn () => $this->images->generate($pin, $data['prompt'] ?? null));
    }

    public function select(Request $request, PinImage $image): JsonResponse
    {
        Gate::authorize('update', $image->pin);
        abort_unless($image->status === 'ready', 422, 'This image is not ready.');
        $this->images->select($image->pin, $image);

        return response()->json(['data' => $image->fresh()]);
    }

    public function update(Request $request, PinImage $image): JsonResponse
    {
        Gate::authorize('update', $image->pin);
        $data = $request->validate([
            'rights_confirmed' => ['sometimes', 'boolean'],
            'crop' => ['sometimes', 'nullable', 'array'],
            'crop.x' => ['required_with:crop', 'numeric', 'between:0,1'],
            'crop.y' => ['required_with:crop', 'numeric', 'between:0,1'],
            'crop.width' => ['required_with:crop', 'numeric', 'between:0.05,1'],
            'crop.height' => ['required_with:crop', 'numeric', 'between:0.05,1'],
        ]);

        if (array_key_exists('rights_confirmed', $data)) {
            $image->update(['rights_confirmed' => $data['rights_confirmed']]);
        }
        if (array_key_exists('crop', $data)) {
            return $this->attempt(fn () => $this->images->recrop($image, $data['crop']), 200);
        }

        return response()->json(['data' => $image->fresh()]);
    }

    public function destroy(Request $request, PinImage $image): JsonResponse
    {
        Gate::authorize('update', $image->pin);
        $this->images->delete($image);

        return response()->json(['message' => 'Image removed.']);
    }

    private function attempt(callable $fn, int $status = 201): JsonResponse
    {
        try {
            return response()->json(['data' => $fn()], $status);
        } catch (ImageProcessingException|UnsafeUrlException|FetchException|AiException $e) {
            throw ValidationException::withMessages(['image' => $e->getMessage()]);
        }
    }
}
