<?php

namespace App\Http\Controllers\Subject;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subject\SubjectRequest;
use App\Http\Resources\SubjectResource;
use App\Models\Course;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SubjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'course_id' => ['required', 'integer'],
        ], [
            'course_id.required' => 'Select a course.',
        ]);

        $course = $this->courseInScope($request, (int) $request->input('course_id'));

        $subjects = Subject::query()
            ->with(['course:id,name,code', 'branch:id,name,code'])
            ->where('course_id', $course->id)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => SubjectResource::collection($subjects)]);
    }

    public function store(SubjectRequest $request): JsonResponse
    {
        $course = $this->courseInScope($request, (int) $request->validated('course_id'));
        $payload = [
            'branch_id' => $course->branch_id,
            'course_id' => $course->id,
            'name'      => $request->validated('name'),
            'code'      => $request->validated('code'),
            'is_active' => true,
        ];

        $trashed = Subject::onlyTrashed()
            ->where('course_id', $course->id)
            ->where('code', $payload['code'])
            ->first();

        if ($trashed) {
            $trashed->restore();
            $trashed->update($payload);
            $subject = $trashed;
        } else {
            $subject = Subject::create($payload);
        }

        return response()->json([
            'data'    => new SubjectResource($subject->load(['course:id,name,code', 'branch:id,name,code'])),
            'message' => 'Subject created successfully.',
        ], 201);
    }

    public function update(SubjectRequest $request, Subject $subject): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $subject->branch_id);
        $course = $this->courseInScope($request, (int) $request->validated('course_id'));

        $subject->update([
            'branch_id' => $course->branch_id,
            'course_id' => $course->id,
            'name'      => $request->validated('name'),
            'code'      => $request->validated('code'),
        ]);

        return response()->json([
            'data'    => new SubjectResource($subject->fresh()->load(['course:id,name,code', 'branch:id,name,code'])),
            'message' => 'Subject updated successfully.',
        ]);
    }

    public function destroy(Request $request, Subject $subject): JsonResponse
    {
        if (! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Only superadmin can delete subjects.'], 403);
        }

        $this->branchScope()->assertRecordInScope($request->user(), $subject->branch_id);
        $subject->delete();

        return response()->json(['message' => 'Subject deleted successfully.']);
    }

    private function courseInScope(Request $request, int $courseId): Course
    {
        $course = Course::query()->find($courseId);

        if (! $course) {
            throw ValidationException::withMessages([
                'course_id' => 'Select a course.',
            ]);
        }

        $this->branchScope()->assertRecordInScope($request->user(), $course->branch_id);

        return $course;
    }
}
