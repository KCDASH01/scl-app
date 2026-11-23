<?php

namespace App\Http\Controllers\Batch;

use App\Http\Controllers\Controller;
use App\Http\Requests\Batch\BatchRequest;
use App\Http\Resources\BatchResource;
use App\Models\Batch;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BatchController extends Controller
{
    public function index(Request $request, ?Course $course = null): JsonResponse
    {
        $user = $request->user();
        $query = Batch::query()->with(['course:id,name,code,is_active', 'branch:id,name,code,is_active']);

        if ($course) {
            $this->branchScope()->assertRecordInScope($user, $course->branch_id);
            $query->where('course_id', $course->id);
        } else {
            $this->branchScope()->constrainVisible($query, $user);
            if ($courseId = $request->input('course_id')) {
                $query->where('course_id', (int) $courseId);
            }
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('schedule', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $batches = $query->orderByDesc('start_date')->orderBy('name')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => BatchResource::collection($batches),
            'meta' => [
                'total'        => $batches->total(),
                'per_page'     => $batches->perPage(),
                'current_page' => $batches->currentPage(),
                'last_page'    => $batches->lastPage(),
            ],
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Batch::query()
            ->with(['course:id,name,code,is_active', 'branch:id,name,code,is_active'])
            ->whereIn('status', ['upcoming', 'ongoing'])
            ->orderBy('name');

        $this->branchScope()->constrainVisible($query, $user);

        if ($courseId = $request->input('course_id')) {
            $query->where('course_id', (int) $courseId);
        }

        $query->whereHas('course', fn ($q) => $q->where('is_active', true));

        return response()->json(['data' => BatchResource::collection($query->get())]);
    }

    public function store(BatchRequest $request, Course $course): JsonResponse
    {
        $this->assertCanReceiveBatch($course);
        $this->rejectRelationshipTampering($request, $course);

        $validated = $request->validated();
        unset($validated['course_id'], $validated['branch_id']);

        $batch = Batch::create(array_merge($validated, [
            'course_id' => $course->id,
            'branch_id' => $course->branch_id,
            'status'    => $validated['status'] ?? 'upcoming',
        ]));

        return response()->json([
            'data'    => new BatchResource($batch->load(['course', 'branch'])),
            'message' => 'Batch created successfully.',
        ], 201);
    }

    public function show(Request $request, Batch $batch): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $batch->branch_id);

        return response()->json([
            'data' => new BatchResource($batch->load(['course', 'branch'])),
        ]);
    }

    public function update(BatchRequest $request, Batch $batch): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $batch->branch_id);

        $validated = $request->validated();

        if (! empty($validated['course_id']) && (int) $validated['course_id'] !== (int) $batch->course_id) {
            throw ValidationException::withMessages([
                'course_id' => 'A batch cannot be moved to another course in this phase.',
            ]);
        }
        if (! empty($validated['branch_id']) && (int) $validated['branch_id'] !== (int) $batch->branch_id) {
            throw ValidationException::withMessages([
                'branch_id' => 'A batch cannot be moved to another branch in this phase.',
            ]);
        }

        unset($validated['course_id'], $validated['branch_id']);
        $batch->update($validated);

        return response()->json([
            'data'    => new BatchResource($batch->fresh(['course', 'branch'])),
            'message' => 'Batch updated successfully.',
        ]);
    }

    public function destroy(Request $request, Batch $batch): JsonResponse
    {
        if (! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Only superadmin can delete batches.'], 403);
        }

        $this->branchScope()->assertRecordInScope($request->user(), $batch->branch_id);

        if ($batch->hasLinkedRecords()) {
            return response()->json([
                'message' => 'This batch cannot be deleted because other records still reference it. Cancel it instead.',
            ], 422);
        }

        $batch->delete();

        return response()->json(['message' => 'Batch deleted successfully.']);
    }

    private function assertCanReceiveBatch(Course $course): void
    {
        $this->branchScope()->assertRecordInScope(request()->user(), $course->branch_id);

        $branch = $course->branch;
        if (! $branch?->is_active) {
            throw ValidationException::withMessages([
                'course_id' => 'Inactive branches cannot receive new courses or batches.',
            ]);
        }

        if (! $course->is_active) {
            throw ValidationException::withMessages([
                'course_id' => 'Inactive courses cannot receive new batches.',
            ]);
        }
    }

    private function rejectRelationshipTampering(Request $request, Course $course): void
    {
        if ($request->filled('course_id') && (int) $request->input('course_id') !== (int) $course->id) {
            throw ValidationException::withMessages([
                'course_id' => 'Batch course does not match the selected course.',
            ]);
        }

        if ($request->filled('branch_id') && (int) $request->input('branch_id') !== (int) $course->branch_id) {
            throw ValidationException::withMessages([
                'branch_id' => 'Batch branch must match the course branch.',
            ]);
        }
    }
}
