<?php

namespace App\Http\Controllers\AcademicYear;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AcademicYearController extends Controller
{
    public function index(): JsonResponse
    {
        $years = AcademicYear::query()->orderByDesc('name')->get();

        return response()->json([
            'data' => $years->map(fn (AcademicYear $year) => $this->payload($year))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:16', 'unique:academic_years,name'],
        ]);

        if (! AcademicYear::isValidName($validated['name'])) {
            return response()->json([
                'message' => 'Enter a batch year like 2026-27.',
                'errors'  => ['name' => ['Enter a batch year like 2026-27.']],
            ], 422);
        }

        $year = AcademicYear::create([
            'name'      => $validated['name'],
            'is_active' => true,
        ]);

        return response()->json([
            'data'    => $this->payload($year),
            'message' => 'Batch year added.',
        ], 201);
    }

    public function update(Request $request, AcademicYear $academicYear): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:16', Rule::unique('academic_years', 'name')->ignore($academicYear->id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['name']) && ! AcademicYear::isValidName($validated['name'])) {
            return response()->json([
                'message' => 'Enter a batch year like 2026-27.',
                'errors'  => ['name' => ['Enter a batch year like 2026-27.']],
            ], 422);
        }

        $academicYear->update($validated);

        return response()->json([
            'data'    => $this->payload($academicYear->fresh()),
            'message' => 'Batch year updated.',
        ]);
    }

    public function destroy(AcademicYear $academicYear): JsonResponse
    {
        if ($academicYear->admissions()->exists()) {
            return response()->json([
                'message' => 'This batch year is already used on a student admission. It cannot be deleted.',
            ], 422);
        }

        $academicYear->delete();

        return response()->json(['message' => 'Batch year deleted.']);
    }

    private function payload(AcademicYear $year): array
    {
        return [
            'id'        => $year->id,
            'name'      => $year->name,
            'is_active' => (bool) $year->is_active,
        ];
    }
}
