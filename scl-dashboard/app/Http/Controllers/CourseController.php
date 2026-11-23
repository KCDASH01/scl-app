<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Course;

class CourseController extends Controller
{
    public function index()
    {
        $courses = Course::query()
            ->with('branch:id,name,code')
            ->withCount('batches')
            ->when(request('branch_id'), fn ($q, $id) => $q->where('branch_id', $id))
            ->when(request('search'), function ($q, $search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                          ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $branches = Branch::query()->orderBy('name')->get(['id', 'name', 'code']);

        return view('courses.index', compact('courses', 'branches'));
    }

    public function show(Course $course)
    {
        $course->load(['branch', 'batches' => fn ($q) => $q->orderByDesc('start_date')->orderBy('name')]);

        return view('courses.show', compact('course'));
    }
}
