<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->projects()->withCount('pins')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        return response()->json(['data' => $request->user()->projects()->create($data)], 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);
        $project->update($this->validated($request));

        return response()->json(['data' => $project]);
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('delete', $project);
        $project->delete();

        return response()->json(['message' => 'Project deleted.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'language' => ['nullable', Rule::in(array_keys(config('pinbot.languages')))],
        ]);
    }
}
