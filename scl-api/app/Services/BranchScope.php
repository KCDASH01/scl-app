<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Branch;
use App\Models\Course;
use App\Models\ExpenseCategory;
use App\Models\Hostel;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BranchScope
{
    public function request(?Request $request = null): Request
    {
        return $request ?? request();
    }

    /**
     * Branch used for reads. Superadmin may pass all (null) or a specific id.
     */
    public function selectedBranchId(User $user, ?Request $request = null): ?int
    {
        if ($user->isSuperAdmin()) {
            $raw = $this->rawBranchInput($request);

            if ($raw === null || $raw === '' || strtolower((string) $raw) === 'all') {
                return null;
            }

            $id = (int) $raw;
            if ($id < 1 || ! Branch::query()->whereKey($id)->exists()) {
                throw ValidationException::withMessages([
                    'branch_id' => 'Invalid branch.',
                ]);
            }

            return $id;
        }

        return $user->branch_id ? (int) $user->branch_id : null;
    }

    /**
     * Branch required for creating or mutating branch-owned records.
     */
    public function requireWritableBranchId(User $user, mixed $requested = null, ?Request $request = null): int
    {
        if ($user->isSuperAdmin()) {
            $raw = $requested ?? $this->rawBranchInput($request);
            $id  = (int) $raw;

            if ($id < 1) {
                throw ValidationException::withMessages([
                    'branch_id' => 'Select a specific branch before creating this record. All Branches cannot own records.',
                ]);
            }

            $branch = Branch::query()->find($id);
            if (! $branch) {
                throw ValidationException::withMessages(['branch_id' => 'Invalid branch.']);
            }

            if (! $branch->is_active) {
                throw ValidationException::withMessages(['branch_id' => 'This branch is inactive.']);
            }

            return $id;
        }

        $this->assertUserBranchActive($user);

        if ($requested !== null && (int) $requested !== (int) $user->branch_id) {
            throw new AccessDeniedHttpException('You cannot use another branch.');
        }

        return (int) $user->branch_id;
    }

    public function constrainVisible(Builder $query, User $user, ?Request $request = null, string $column = 'branch_id'): Builder
    {
        $id = $this->selectedBranchId($user, $request);
        if ($id !== null) {
            $query->where($query->getModel()->qualifyColumn($column), $id);
        }

        return $query;
    }

    public function applyAssigneeFilter(Builder $query, User $user, ?Request $request = null, string $column = 'assigned_to'): Builder
    {
        $request ??= $this->request();

        if ($user->isEmployee()) {
            $query->where($column, $user->id);

            return $query;
        }

        if ($request->boolean('unassigned')) {
            $query->whereNull($column);
        } elseif ($assignedTo = $request->input('assigned_to')) {
            $query->where($column, (int) $assignedTo);
        }

        return $query;
    }

    public function assertUserBranchActive(User $user): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }

        $branch = $user->relationLoaded('branch') ? $user->branch : $user->branch()->first();

        if (! $user->branch_id || ! $branch) {
            throw new HttpException(403, 'You must be assigned to a branch.');
        }

        if (! $branch->is_active) {
            throw new HttpException(403, 'This branch is currently inactive.');
        }
    }

    public function assertRecordInScope(User $user, ?int $recordBranchId, ?int $assignedTo = null): void
    {
        if ($user->isSuperAdmin()) {
            $selected = $this->selectedBranchId($user);
            if ($selected !== null && $recordBranchId !== null && $selected !== $recordBranchId) {
                throw new AccessDeniedHttpException('This record belongs to another branch.');
            }

            return;
        }

        if ($recordBranchId === null || (int) $recordBranchId !== (int) $user->branch_id) {
            throw new AccessDeniedHttpException('This record belongs to another branch.');
        }

        if ($user->isEmployee() && $assignedTo !== null && (int) $assignedTo !== (int) $user->id) {
            throw new AccessDeniedHttpException('You can only access your assigned records.');
        }
    }

    public function assertUserInBranch(int $userId, int $branchId, array $roles = ['manager', 'employee', 'admin']): User
    {
        $user = User::query()
            ->whereKey($userId)
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->whereIn('role', $roles)
            ->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'assigned_to' => 'The selected user does not belong to this branch.',
            ]);
        }

        return $user;
    }

    public function assertPipelineInBranch(int $pipelineId, int $branchId): Pipeline
    {
        $pipeline = Pipeline::query()->whereKey($pipelineId)->first();

        if (! $pipeline) {
            throw ValidationException::withMessages([
                'pipeline_id' => 'The selected pipeline was not found.',
            ]);
        }

        return $pipeline;
    }

    public function assertStageInPipeline(int $stageId, int $pipelineId): Stage
    {
        $stage = Stage::query()->whereKey($stageId)->where('pipeline_id', $pipelineId)->first();

        if (! $stage) {
            throw ValidationException::withMessages([
                'stage_id' => 'The selected stage does not belong to this pipeline.',
            ]);
        }

        return $stage;
    }

    public function assertAssigneeAndPipeline(array $validated, int $branchId, array $assigneeRoles = ['manager', 'employee', 'admin']): void
    {
        if (! empty($validated['assigned_to'])) {
            $this->assertUserInBranch((int) $validated['assigned_to'], $branchId, $assigneeRoles);
        }

        if (! empty($validated['pipeline_id'])) {
            $this->assertPipelineInBranch((int) $validated['pipeline_id'], $branchId);
            if (! empty($validated['stage_id'])) {
                $this->assertStageInPipeline((int) $validated['stage_id'], (int) $validated['pipeline_id']);
            }
        }
    }

    public function assertCourseInBranch(int $courseId, int $branchId): Course
    {
        $course = Course::query()->whereKey($courseId)->where('branch_id', $branchId)->first();

        if (! $course) {
            throw ValidationException::withMessages([
                'course_id' => 'The selected course does not belong to this branch.',
            ]);
        }

        return $course;
    }

    public function assertBatchInCourse(int $batchId, int $courseId, ?int $branchId = null): Batch
    {
        $query = Batch::query()->whereKey($batchId)->where('course_id', $courseId);

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $batch = $query->first();

        if (! $batch) {
            throw ValidationException::withMessages([
                'batch_id' => 'The selected batch does not belong to this course.',
            ]);
        }

        if ((int) $batch->branch_id !== (int) $batch->course()->value('branch_id')) {
            throw ValidationException::withMessages([
                'batch_id' => 'Batch branch must match the course branch.',
            ]);
        }

        return $batch;
    }

    public function assertActiveCourseForAssignment(int $courseId, int $branchId): Course
    {
        $course = $this->assertCourseInBranch($courseId, $branchId);

        if (! $course->is_active) {
            throw ValidationException::withMessages([
                'course_id' => 'Inactive courses cannot be used for new assignments.',
            ]);
        }

        $branch = $course->relationLoaded('branch') ? $course->branch : $course->branch()->first();
        if (! $branch?->is_active) {
            throw ValidationException::withMessages([
                'branch_id' => 'Inactive branches cannot receive new courses or batches.',
            ]);
        }

        return $course;
    }

    public function assertHostelInBranch(int $hostelId, int $branchId, bool $requireActive = false): Hostel
    {
        $hostel = Hostel::query()->whereKey($hostelId)->where('branch_id', $branchId)->first();

        if (! $hostel) {
            throw ValidationException::withMessages([
                'hostel_id' => 'The selected hostel does not belong to this branch.',
            ]);
        }

        if ($requireActive && ! $hostel->is_active) {
            throw ValidationException::withMessages([
                'hostel_id' => 'Inactive hostels cannot be used for new expenses.',
            ]);
        }

        return $hostel;
    }

    public function assertExpenseCategoryInBranch(int $categoryId, int $branchId, bool $requireActive = false): ExpenseCategory
    {
        $category = ExpenseCategory::query()->whereKey($categoryId)->where('branch_id', $branchId)->first();

        if (! $category) {
            throw ValidationException::withMessages([
                'expense_category_id' => 'The selected expense type does not belong to this branch.',
            ]);
        }

        if ($requireActive && ! $category->is_active) {
            throw ValidationException::withMessages([
                'expense_category_id' => 'Inactive expense types cannot be used for new expenses.',
            ]);
        }

        return $category;
    }

    public function assertManagerInBranch(int $managerId, int $branchId): User
    {
        $manager = User::query()
            ->whereKey($managerId)
            ->where('branch_id', $branchId)
            ->where('role', 'manager')
            ->where('is_active', true)
            ->first();

        if (! $manager) {
            throw ValidationException::withMessages([
                'manager_id' => 'Select an active manager from this branch.',
            ]);
        }

        return $manager;
    }

    private function rawBranchInput(?Request $request = null): mixed
    {
        $request ??= $this->request();

        $header = $request->header('X-Branch-Id');
        if ($header !== null && $header !== '') {
            return $header;
        }

        return $request->query('branch_id');
    }
}
