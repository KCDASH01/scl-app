<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\StaffAttendanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffAttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->assertCanMark($request->user());

        $request->validate([
            'date' => ['required', 'date'],
        ]);

        $date = $this->attendanceDate($request->input('date'));
        $staff = $this->staffQuery($request->user())->get();

        return response()->json([
            'data' => $this->payload($staff, $date),
        ]);
    }

    public function mark(Request $request): JsonResponse
    {
        $this->assertCanMark($request->user());

        $request->validate([
            'date'              => ['required', 'date'],
            'marked_at'         => ['nullable', 'date'],
            'present_user_ids'  => ['nullable', 'array'],
            'present_user_ids.*'=> ['integer'],
        ]);

        $date = $this->attendanceDate($request->input('date'));
        $markedAt = $this->markedAt($request);
        $staff = $this->staffQuery($request->user())->get();

        if ($staff->isEmpty()) {
            throw ValidationException::withMessages([
                'date' => ['No employees to mark for this date.'],
            ]);
        }

        $presentIds = collect($request->input('present_user_ids', []))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $invalid = $presentIds->diff($staff->pluck('id'));
        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'present_user_ids' => ['One or more selected employees are not in your list.'],
            ]);
        }

        $actorId = $request->user()->id;

        DB::transaction(function () use ($staff, $presentIds, $date, $actorId, $markedAt): void {
            foreach ($staff as $employee) {
                $attributes = [
                    'branch_id' => $employee->branch_id,
                    'status'    => $presentIds->contains($employee->id) ? 'present' : 'absent',
                    'marked_at' => $markedAt,
                    'marked_by' => $actorId,
                ];

                $record = StaffAttendanceRecord::query()
                    ->where('user_id', $employee->id)
                    ->whereDate('attendance_date', $date)
                    ->first();

                if ($record) {
                    $record->update($attributes);
                    continue;
                }

                StaffAttendanceRecord::query()->create($attributes + [
                    'user_id'         => $employee->id,
                    'attendance_date' => $date,
                ]);
            }
        });

        return response()->json([
            'data' => $this->payload($staff, $date),
        ]);
    }

    public function update(Request $request, int $staffAttendance): JsonResponse
    {
        $this->assertCanMark($request->user());

        $validated = $request->validate([
            'status'    => ['required', Rule::in(StaffAttendanceRecord::STATUSES)],
            'marked_at' => ['nullable', 'date'],
        ]);
        $markedAt = $this->markedAt($request);

        $record = StaffAttendanceRecord::query()->findOrFail($staffAttendance);
        $this->attendanceDate($record->attendance_date->toDateString());

        $employee = $this->staffQuery($request->user())->whereKey($record->user_id)->first();
        if (! $employee) {
            abort(403, 'You cannot change this attendance.');
        }

        $record->update([
            'branch_id' => $employee->branch_id,
            'status'    => $validated['status'],
            'marked_at' => $markedAt,
            'marked_by' => $request->user()->id,
        ]);

        $date = $record->attendance_date->toDateString();
        $row = $this->rows($this->staffQuery($request->user())->whereKey($employee->id)->get(), $date)->first();

        return response()->json(['data' => $row]);
    }

    private function assertCanMark(User $user): void
    {
        if (! $user->isSuperAdmin() && ! $user->isManager()) {
            abort(403, 'You do not have permission to mark staff attendance.');
        }
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

    private function markedAt(Request $request): Carbon
    {
        $markedAt = $request->filled('marked_at')
            ? Carbon::parse($request->input('marked_at'))
            : now();

        if ($markedAt->gt(now())) {
            throw ValidationException::withMessages([
                'marked_at' => ['Attendance time cannot be in the future.'],
            ]);
        }

        return $markedAt;
    }

    private function staffQuery(User $auth): Builder
    {
        $query = User::query()
            ->with('branch:id,name,code')
            ->where('is_active', true)
            ->whereNotNull('branch_id');

        $query->where('role', 'employee');

        if ($auth->isSuperAdmin()) {
            $branchId = $this->branchScope()->selectedBranchId($auth);
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }

            return $query->orderBy('name');
        }

        return $query
            ->where('branch_id', $auth->branch_id)
            ->where('role', 'employee')
            ->where('manager_id', $auth->id)
            ->orderBy('name');
    }

    /**
     * @param  Collection<int, User>  $staff
     * @return array<string, mixed>
     */
    private function payload(Collection $staff, string $date): array
    {
        $rows = $this->rows($staff, $date);
        $marked = $rows->whereNotNull('attendance_id');

        return [
            'date'          => $date,
            'is_marked'     => $marked->isNotEmpty(),
            'total'         => $rows->count(),
            'present_count' => $marked->where('status', 'present')->count(),
            'absent_count'  => $marked->where('status', 'absent')->count(),
            'employees'     => $rows->values(),
        ];
    }

    /**
     * @param  Collection<int, User>  $staff
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(Collection $staff, string $date): Collection
    {
        $records = StaffAttendanceRecord::query()
            ->with('markedBy:id,name')
            ->whereDate('attendance_date', $date)
            ->whereIn('user_id', $staff->pluck('id'))
            ->get()
            ->keyBy('user_id');

        return $staff->map(function (User $employee) use ($records) {
            $record = $records->get($employee->id);

            return [
                'user_id'        => $employee->id,
                'name'           => $employee->name,
                'email'          => $employee->email,
                'phone'          => $employee->phone,
                'role'           => $employee->role,
                'branch_id'      => $employee->branch_id,
                'branch_name'    => $employee->branch?->name,
                'attendance_id'  => $record?->id,
                'status'         => $record?->status,
                'marked_at'      => $record?->marked_at?->toIso8601String(),
                'marked_by'      => $record?->markedBy?->name,
            ];
        });
    }
}
