<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceStudentResource;
use App\Models\Admission;
use App\Models\AttendanceRecord;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function subjects(Request $request): JsonResponse
    {
        $request->validate([
            'course_id' => ['required', 'integer'],
        ]);

        $course = Course::query()->find($request->integer('course_id'));
        if (! $course) {
            throw ValidationException::withMessages([
                'course_id' => ['Select a course.'],
            ]);
        }

        $this->branchScope()->assertRecordInScope($request->user(), $course->branch_id);

        $subjects = Subject::query()
            ->where('course_id', $course->id)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'course_id']);

        return response()->json(['data' => $subjects]);
    }

    public function students(Request $request): JsonResponse
    {
        $request->validate([
            'course_id' => ['required', 'integer'],
            'batch_id'  => ['required', 'integer'],
            'date'      => ['required', 'date'],
            'search'    => ['nullable', 'string', 'max:191'],
        ]);

        $batch = $this->resolveScopedBatch(
            $request,
            (int) $request->input('course_id'),
            (int) $request->input('batch_id')
        );

        $date = $this->attendanceDate($request->input('date'));
        $subject = $this->resolveSubject($request, (int) $batch->course_id);
        $students = $this->batchStudentsQuery($batch, $request->input('search'))->get();

        $records = AttendanceRecord::query()
            ->with('markedBy:id,name')
            ->where('batch_id', $batch->id)
            ->whereDate('attendance_date', $date)
            ->where('subject_key', $subject?->id ?? 0)
            ->whereIn('admission_id', $students->pluck('id'))
            ->get()
            ->keyBy('admission_id');

        $students->each(function (Admission $admission) use ($records): void {
            $admission->setAttribute('attendance_record', $records->get($admission->id));
        });

        $present = $records->where('status', 'present')->count();
        $absent = $records->where('status', 'absent')->count();

        return response()->json([
            'data' => [
                'branch'         => $batch->branch ? [
                    'id'   => $batch->branch->id,
                    'name' => $batch->branch->name,
                    'code' => $batch->branch->code,
                ] : null,
                'course'         => $batch->course ? [
                    'id'   => $batch->course->id,
                    'name' => $batch->course->name,
                    'code' => $batch->course->code,
                ] : null,
                'batch'          => [
                    'id'   => $batch->id,
                    'name' => $batch->name,
                    'code' => $batch->code,
                ],
                'date'           => $date,
                'subject'        => $subject ? [
                    'id'   => $subject->id,
                    'name' => $subject->name,
                    'code' => $subject->code,
                ] : null,
                'is_marked'      => $records->isNotEmpty(),
                'total'          => $students->count(),
                'present_count'  => $present,
                'absent_count'   => $absent,
                'students'       => AttendanceStudentResource::collection($students)->resolve(),
            ],
        ]);
    }

    public function mark(Request $request): JsonResponse
    {
        $request->validate([
            'course_id'              => ['required', 'integer'],
            'batch_id'               => ['required', 'integer'],
            'date'                   => ['required', 'date'],
            'subject_id'             => ['nullable', 'integer'],
            'marked_at'              => ['nullable', 'date'],
            'present_admission_ids'  => ['nullable', 'array'],
            'present_admission_ids.*'=> ['integer'],
        ]);

        $batch = $this->resolveWritableBatch(
            $request,
            (int) $request->input('course_id'),
            (int) $request->input('batch_id')
        );

        $date = $this->attendanceDate($request->input('date'));
        $subject = $this->resolveSubject($request, (int) $batch->course_id);
        $subjectKey = $subject?->id ?? 0;
        $markedAt = $request->filled('marked_at')
            ? Carbon::parse($request->input('marked_at'))
            : now();
        $presentIds = collect($request->input('present_admission_ids', []))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $students = $this->batchStudentsQuery($batch)->get();
        if ($students->isEmpty()) {
            throw ValidationException::withMessages([
                'batch_id' => ['No admitted students in this batch.'],
            ]);
        }

        $validIds = $students->pluck('id');
        $invalid = $presentIds->diff($validIds);
        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'present_admission_ids' => ['One or more selected students are not in this batch.'],
            ]);
        }

        DB::transaction(function () use ($students, $presentIds, $batch, $date, $markedAt, $request, $subject, $subjectKey): void {
            foreach ($students as $student) {
                $attributes = [
                    'branch_id'   => $batch->branch_id,
                    'course_id'   => $batch->course_id,
                    'batch_id'    => $batch->id,
                    'subject_id'  => $subject?->id,
                    'subject_key' => $subjectKey,
                    'status'      => $presentIds->contains($student->id) ? 'present' : 'absent',
                    'marked_at'   => $markedAt,
                    'marked_by'   => $request->user()->id,
                ];

                $record = AttendanceRecord::query()
                    ->where('admission_id', $student->id)
                    ->whereDate('attendance_date', $date)
                    ->where('subject_key', $subjectKey)
                    ->first();

                if ($record) {
                    $record->update($attributes);
                    continue;
                }

                AttendanceRecord::query()->create($attributes + [
                    'admission_id'    => $student->id,
                    'attendance_date' => $date,
                ]);
            }
        });

        $request->merge(['date' => $date]);

        return $this->students($request);
    }

    public function update(Request $request, int $attendanceRecord): JsonResponse
    {
        $attendanceRecord = AttendanceRecord::query()->findOrFail($attendanceRecord);
        $this->branchScope()->assertRecordInScope($request->user(), $attendanceRecord->branch_id);

        $validated = $request->validate([
            'status'    => ['required', Rule::in(AttendanceRecord::STATUSES)],
            'marked_at' => ['nullable', 'date'],
        ]);

        $attendanceRecord->update([
            'status'    => $validated['status'],
            'marked_at' => $validated['marked_at'] ?? $attendanceRecord->marked_at ?? now(),
            'marked_by' => $request->user()->id,
        ]);

        $attendanceRecord->load(['admission.lead:id,first_name,last_name,email,phone', 'markedBy:id,name']);
        $admission = $attendanceRecord->admission;
        $admission?->setAttribute('attendance_record', $attendanceRecord);

        return response()->json([
            'data' => $admission
                ? (new AttendanceStudentResource($admission))->resolve()
                : null,
        ]);
    }

    private function attendanceDate(string $date): string
    {
        $day = Carbon::parse($date)->startOfDay();

        if ($day->gt(now()->startOfDay())) {
            throw ValidationException::withMessages([
                'date' => ['Attendance cannot be marked for a future date.'],
            ]);
        }

        return $day->toDateString();
    }

    private function resolveSubject(Request $request, int $courseId): ?Subject
    {
        $subjects = Subject::query()->where('course_id', $courseId)->orderBy('name')->get();
        $raw = $request->input('subject_id');

        if ($subjects->isEmpty()) {
            if ($raw !== null && $raw !== '') {
                throw ValidationException::withMessages([
                    'subject_id' => ['This course has no subjects. Attendance is taken once for the day.'],
                ]);
            }

            return null;
        }

        $subject = $subjects->firstWhere('id', (int) $raw);
        if (! $subject) {
            throw ValidationException::withMessages([
                'subject_id' => ['Select a subject. Attendance for this course is taken for each subject.'],
            ]);
        }

        return $subject;
    }

    private function resolveScopedBatch(Request $request, int $courseId, int $batchId): Batch
    {
        $batch = $this->branchScope()->assertBatchInCourse($batchId, $courseId);
        $this->branchScope()->assertRecordInScope($request->user(), $batch->branch_id);
        $batch->load(['course:id,name,code,branch_id', 'branch:id,name,code']);

        return $batch;
    }

    private function resolveWritableBatch(Request $request, int $courseId, int $batchId): Batch
    {
        $batch = $this->resolveScopedBatch($request, $courseId, $batchId);
        $this->branchScope()->requireWritableBranchId($request->user(), $batch->branch_id);

        return $batch;
    }

    private function batchStudentsQuery(Batch $batch, ?string $search = null): Builder
    {
        $query = Admission::query()
            ->with(['lead:id,first_name,last_name,email,phone'])
            ->where('batch_id', $batch->id)
            ->where('course_id', $batch->course_id)
            ->where('branch_id', $batch->branch_id)
            ->where('status', 'admitted');

        $this->branchScope()->constrainVisible($query, request()->user());

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('enrollment_id', 'like', "%{$search}%")
                    ->orWhereHas('lead', function ($lq) use ($search) {
                        $lq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        return $query->orderBy('enrollment_id');
    }
}
