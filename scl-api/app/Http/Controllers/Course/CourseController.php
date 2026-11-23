<?php

namespace App\Http\Controllers\Course;

use App\Http\Controllers\Controller;
use App\Http\Requests\Course\CourseRequest;
use App\Http\Resources\CourseResource;
use App\Models\Branch;
use App\Models\Course;
use App\Services\FeeScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Course::query()
            ->with('branch:id,name,code,is_active')
            ->withCount('batches');

        $this->branchScope()->constrainVisible($query, $user);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active') && $request->input('is_active') !== '' && $request->input('is_active') !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $courses = $query->orderBy('name')->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => CourseResource::collection($courses),
            'meta' => [
                'total' => $courses->total(),
                'per_page' => $courses->perPage(),
                'current_page' => $courses->currentPage(),
                'last_page' => $courses->lastPage(),
            ],
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Course::query()
            ->with('branch:id,name,code,is_active')
            ->where('is_active', true)
            ->orderBy('name');

        $this->branchScope()->constrainVisible($query, $user);

        return response()->json(['data' => CourseResource::collection($query->get())]);
    }

    public function store(CourseRequest $request): JsonResponse
    {
        $validated = $this->pricing($request->validated());
        $branchId = $this->branchScope()->requireWritableBranchId($request->user(), $validated['branch_id'] ?? null);
        $this->assertActiveBranch($branchId);

        $course = Course::create(array_merge($validated, ['branch_id' => $branchId]));

        return response()->json([
            'data' => new CourseResource($course->load('branch')->loadCount('batches')),
            'message' => 'Course created successfully.',
        ], 201);
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $course->branch_id);

        $course->load('branch:id,name,code,is_active')->loadCount('batches');

        return response()->json(['data' => new CourseResource($course)]);
    }

    public function update(CourseRequest $request, Course $course): JsonResponse
    {
        $validated = $this->pricing($request->validated(), $course);

        if (array_key_exists('branch_id', $validated) && (int) $validated['branch_id'] !== (int) $course->branch_id) {
            if ($course->hasLinkedRecords()) {
                throw ValidationException::withMessages([
                    'branch_id' => 'This course cannot be moved to another branch because it already has batches or other linked records.',
                ]);
            }
            $this->branchScope()->requireWritableBranchId($request->user(), $validated['branch_id']);
            $this->assertActiveBranch((int) $validated['branch_id']);
        } else {
            unset($validated['branch_id']);
        }

        DB::transaction(function () use ($course, $validated, $request) {
            $locked = Course::whereKey($course->id)->lockForUpdate()->firstOrFail();
            $locked->update($validated);
            if (array_intersect(['duration_value', 'duration_unit', 'period_prices', 'price'], array_keys($request->validated()))) {
                app(FeeScheduleService::class)->extendCourse($locked, $request->user());
            }
        });

        return response()->json([
            'data' => new CourseResource($course->fresh()->load('branch')->loadCount('batches')),
            'message' => 'Course updated successfully.',
        ]);
    }

    public function toggleActive(Request $request, Course $course): JsonResponse
    {
        if (! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Only superadmin can change course status.'], 403);
        }

        $this->branchScope()->assertRecordInScope($request->user(), $course->branch_id);

        $course->update(['is_active' => ! $course->is_active]);

        return response()->json([
            'data' => new CourseResource($course->fresh()->load('branch')->loadCount('batches')),
            'message' => $course->is_active
                ? 'Course activated.'
                : 'Course deactivated. New batches cannot be added until it is reactivated.',
        ]);
    }

    public function destroy(Request $request, Course $course): JsonResponse
    {
        if (! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Only superadmin can delete courses.'], 403);
        }

        $this->branchScope()->assertRecordInScope($request->user(), $course->branch_id);

        if ($course->hasLinkedRecords()) {
            return response()->json([
                'message' => 'This course cannot be deleted because it still has batches, leads, or admissions. Remove those first, or deactivate the course instead.',
            ], 422);
        }

        $course->delete();

        return response()->json(['message' => 'Course deleted successfully.']);
    }

    private function pricing(array $data, ?Course $course = null): array
    {
        $duration = array_key_exists('duration_value', $data) ? $data['duration_value'] : $course?->duration_value;
        $unit = array_key_exists('duration_unit', $data) ? $data['duration_unit'] : $course?->duration_unit;
        $prices = array_key_exists('period_prices', $data) ? $data['period_prices'] : $course?->period_prices;
        $price = $data['price'] ?? $course?->price;
        if (! $duration || ! $unit) {
            if ($price === null) {
                throw ValidationException::withMessages(['price' => 'Enter the course price.']);
            }

            return array_merge($data, ['duration_value' => null, 'duration_unit' => null, 'period_prices' => null, 'price' => $price]);
        }
        if (! in_array($unit, Course::DURATION_UNITS, true)) {
            throw ValidationException::withMessages(['duration_unit' => 'Select a valid course unit.']);
        }
        if (! $prices) {
            if ($price === null) {
                throw ValidationException::withMessages(['price' => 'Enter the first period price.']);
            }
            $prices = [['period' => 1, 'amount' => (float) $price]];
        }
        $prices = app(FeeScheduleService::class)->normalize($prices, (int) $duration);

        return array_merge($data, ['duration_value' => $duration, 'duration_unit' => $unit,
            'period_prices' => $prices, 'price' => array_sum(array_column($prices, 'amount'))]);
    }

    private function assertActiveBranch(int $branchId): Branch
    {
        $branch = Branch::query()->find($branchId);
        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'Invalid branch.']);
        }
        if (! $branch->is_active) {
            throw ValidationException::withMessages([
                'branch_id' => 'Inactive branches cannot receive new courses or batches.',
            ]);
        }

        return $branch;
    }
}
