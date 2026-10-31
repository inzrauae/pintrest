<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PinTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->templates()->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $template = DB::transaction(fn () => $this->save($request, new PinTemplate(['user_id' => $request->user()->id])));

        return response()->json(['data' => $template], 201);
    }

    public function update(Request $request, PinTemplate $template): JsonResponse
    {
        Gate::authorize('update', $template);

        return response()->json(['data' => DB::transaction(fn () => $this->save($request, $template))]);
    }

    public function destroy(Request $request, PinTemplate $template): JsonResponse
    {
        Gate::authorize('delete', $template);
        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    private function save(Request $request, PinTemplate $template): PinTemplate
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'text_overlay' => ['boolean'],
            'overlay_position' => [Rule::in(['top', 'bottom'])],
            'background_color' => ['regex:/^#[0-9a-fA-F]{6}$/'],
            'background_opacity' => ['integer', 'between:0,100'],
            'text_color' => ['regex:/^#[0-9a-fA-F]{6}$/'],
            'font_size' => ['integer', 'between:20,120'],
            'image_style' => ['nullable', 'string', 'max:500'],
            'is_default' => ['boolean'],
        ]);

        if ($data['is_default'] ?? false) {
            PinTemplate::where('user_id', $template->user_id)->update(['is_default' => false]);
        }
        $template->fill($data)->save();

        return $template;
    }
}
