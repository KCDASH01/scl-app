<?php

namespace App\Http\Controllers\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pipeline\PipelineRequest;
use App\Http\Resources\PipelineResource;
use App\Models\Pipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineController extends Controller
{
    // ── GET /api/pipelines ────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $pipelines = Pipeline::query()
                             ->with('stages')
                             ->orderBy('sort_order')
                             ->get();

        return response()->json(['data' => PipelineResource::collection($pipelines)]);
    }

    // ── POST /api/pipelines ───────────────────────────────────────────────────

    public function store(PipelineRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $branchId = $this->branchScope()->requireWritableBranchId($request->user(), $validated['branch_id'] ?? null);
        $validated['branch_id'] = $branchId;

        if (($validated['is_default'] ?? false) === true) {
            Pipeline::query()->update(['is_default' => false]);
        }

        $pipeline = Pipeline::create($validated);

        return response()->json([
            'data'    => new PipelineResource($pipeline->load('stages')),
            'message' => 'Pipeline created successfully.',
        ], 201);
    }

    // ── GET /api/pipelines/{pipeline} ─────────────────────────────────────────

    public function show(Request $request, Pipeline $pipeline): JsonResponse
    {
        return response()->json([
            'data' => new PipelineResource($pipeline->load('stages')),
        ]);
    }

    // ── PUT /api/pipelines/{pipeline} ─────────────────────────────────────────

    public function update(PipelineRequest $request, Pipeline $pipeline): JsonResponse
    {
        $validated = $request->validated();

        if (($validated['is_default'] ?? false) === true) {
            Pipeline::where('id', '!=', $pipeline->id)->update(['is_default' => false]);
        }

        $pipeline->update($validated);

        return response()->json([
            'data'    => new PipelineResource($pipeline->fresh('stages')),
            'message' => 'Pipeline updated successfully.',
        ]);
    }

    // ── DELETE /api/pipelines/{pipeline} ──────────────────────────────────────

    public function destroy(Request $request, Pipeline $pipeline): JsonResponse
    {
        $remaining = Pipeline::where('id', '!=', $pipeline->id)->count();

        if ($remaining === 0) {
            return response()->json(['message' => 'Cannot delete the only remaining pipeline.'], 422);
        }

        if ($pipeline->is_default) {
            return response()->json(['message' => 'Cannot delete the default pipeline. Set another pipeline as default first.'], 422);
        }

        $pipeline->delete();

        return response()->json(['message' => 'Pipeline deleted successfully.']);
    }
}
