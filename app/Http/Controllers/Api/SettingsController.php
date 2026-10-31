<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\LlmProviderFactory;
use App\Services\Images\ImageGeneratorFactory;
use App\Services\Images\ImageProcessor;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function meta(): JsonResponse
    {
        return response()->json(['data' => [
            'languages' => config('pinbot.languages'),
            'ai_providers' => array_keys(LlmProviderFactory::PROVIDERS),
            'image_providers' => array_merge(['none'], array_keys(ImageGeneratorFactory::PROVIDERS)),
            'configured' => [
                'openai' => filled(config('services.openai.api_key')),
                'gemini' => filled(config('services.gemini.api_key')),
            ],
            'ai_configured' => filled(config('services.openai.api_key')) || filled(config('services.gemini.api_key')),
            'pinterest_configured' => filled(config('services.pinterest.client_id')) && filled(config('services.pinterest.client_secret')),
            'default_models' => [
                'openai' => ['text' => config('services.openai.model'), 'image' => config('services.openai.image_model')],
                'gemini' => ['text' => config('services.gemini.model'), 'image' => config('services.gemini.image_model')],
            ],
            'overlay_font_available' => app(ImageProcessor::class)->fontPath() !== null,
        ]]);
    }

    public function ai(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'text' => $request->user()->aiSettingsOrDefault(),
            'image' => $request->user()->imageSettingsOrDefault(),
        ]]);
    }

    public function updateAi(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text.provider' => ['required', Rule::in(array_keys(LlmProviderFactory::PROVIDERS))],
            'text.model' => ['nullable', 'string', 'max:100', 'regex:/^[\w.\-:\/]+$/'],
            'text.temperature' => ['required', 'numeric', 'between:0,1.5'],
            'text.tone' => ['required', 'string', 'max:50'],
            'text.extra_instructions' => ['nullable', 'string', 'max:1000'],
            'image.provider' => ['required', Rule::in(array_merge(['none'], array_keys(ImageGeneratorFactory::PROVIDERS)))],
            'image.model' => ['nullable', 'string', 'max:100', 'regex:/^[\w.\-:\/]+$/'],
            'image.quality' => ['required', Rule::in(['low', 'medium', 'high', 'auto', 'standard', 'hd'])],
        ]);

        $user = $request->user();
        $user->aiSettingsOrDefault()->update($data['text']);
        $user->imageSettingsOrDefault()->update($data['image']);

        return $this->ai($request);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->preferences()]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'language' => ['sometimes', Rule::in(array_keys(config('pinbot.languages')))],
            'timezone' => ['sometimes', 'timezone:all'],
            'image_mode' => ['sometimes', Rule::in(['extract', 'generate', 'both'])],
            'hourly_processing_limit' => ['sometimes', 'integer', 'between:1,1000'],
            'daily_processing_limit' => ['sometimes', 'integer', 'between:1,5000'],
            'daily_publish_limit' => ['sometimes', 'integer', 'between:1,500'],
            'publishing_window_start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'publishing_window_end' => ['sometimes', 'nullable', 'date_format:H:i'],
            'auto_publish' => ['sometimes', 'boolean'],
            'auto_publish_acknowledged' => ['sometimes', 'boolean'],
            'auto_publish_spacing_minutes' => ['sometimes', 'integer', 'between:5,1440'],
            'automation_paused' => ['sometimes', 'boolean'],
        ]);

        $prefs = $request->user()->preferences();

        // Safeguard: enabling automatic publishing requires an explicit acknowledgement.
        if (($data['auto_publish'] ?? false) && ! $prefs->auto_publish) {
            if (! ($data['auto_publish_acknowledged'] ?? false)) {
                throw ValidationException::withMessages([
                    'auto_publish_acknowledged' => 'Confirm that approved Pins will be published automatically without further review.',
                ]);
            }
            $data['auto_publish_acknowledged_at'] = now();
            ActivityLogger::log($request->user()->id, 'auto_publish_enabled', 'Automatic publishing of approved Pins was enabled.', 'warning');
        }
        if (array_key_exists('auto_publish', $data) && ! $data['auto_publish']) {
            $data['auto_publish_acknowledged_at'] = null;
        }
        unset($data['auto_publish_acknowledged']);

        $prefs->update($data);

        return response()->json(['data' => $prefs->fresh()]);
    }
}
