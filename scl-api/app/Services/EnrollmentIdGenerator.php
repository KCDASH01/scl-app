<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\Branch;

class EnrollmentIdGenerator
{
    public function next(int $branchId, ?int $year = null): string
    {
        $year = $year ?? (int) now()->year;
        $branch = Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
        $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $branch->code) ?: 'BR');
        $prefix = "{$code}-{$year}-";

        $last = Admission::withTrashed()
            ->where('branch_id', $branchId)
            ->where('enrollment_id', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('enrollment_id')
            ->value('enrollment_id');

        $seq = 1;
        if (is_string($last) && preg_match('/-(\d+)$/', $last, $matches)) {
            $seq = ((int) $matches[1]) + 1;
        }

        return sprintf('%s%04d', $prefix, $seq);
    }
}
