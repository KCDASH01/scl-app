<?php

namespace App\Http\Controllers\Admission;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admission\AdmissionRequest;
use App\Http\Resources\AdmissionDocumentResource;
use App\Http\Resources\AdmissionResource;
use App\Models\Admission;
use App\Models\AdmissionDocument;
use App\Models\Course;
use App\Models\Deal;
use App\Services\AdmissionService;
use App\Services\FeeScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdmissionController extends Controller
{
    public function __construct(private readonly AdmissionService $admissions) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Admission::query()
            ->with([
                'lead.assignedTo:id,name',
                'course:id,name,code,is_active,branch_id',
                'batch:id,name,code,status,course_id',
                'academicYear:id,name,is_active',
                'branch:id,name,code,is_active',
            ]);

        $this->branchScope()->constrainVisible($query, $user);

        if ($user->isEmployee()) {
            $query->whereHas('lead', fn ($q) => $q->where('assigned_to', $user->id));
        } elseif ($assignedTo = $request->input('assigned_to')) {
            $query->whereHas('lead', fn ($q) => $q->where('assigned_to', (int) $assignedTo));
        }

        if ($search = $request->input('search')) {
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

        if ($courseId = $request->input('course_id')) {
            $query->where('course_id', (int) $courseId);
        }

        if ($batchId = $request->input('batch_id')) {
            $query->where('batch_id', (int) $batchId);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($request->exists('hostel_stay')) {
            $query->where('hostel_stay', $request->boolean('hostel_stay'));
        }

        if ($from = $request->input('date_from')) {
            $query->whereDate('admission_date', '>=', $from);
        }

        if ($to = $request->input('date_to')) {
            $query->whereDate('admission_date', '<=', $to);
        }

        $admissions = $query->latest('id')->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => AdmissionResource::collection($admissions),
            'meta' => [
                'current_page' => $admissions->currentPage(),
                'last_page' => $admissions->lastPage(),
                'total' => $admissions->total(),
                'per_page' => $admissions->perPage(),
            ],
        ]);
    }

    public function show(Request $request, Admission $admission): JsonResponse
    {
        $this->authorizeAdmission($request, $admission);

        $admission->load([
            'lead.assignedTo:id,name',
            'course:id,name,code,is_active,branch_id',
            'batch:id,name,code,status,course_id',
            'academicYear:id,name,is_active',
            'branch:id,name,code,is_active',
            'documents.uploadedBy:id,name',
        ]);

        return response()->json(['data' => new AdmissionResource($admission)]);
    }

    public function update(AdmissionRequest $request, Admission $admission): JsonResponse
    {
        $this->authorizeAdmission($request, $admission);

        return DB::transaction(function () use ($request, $admission) {
            $admission = Admission::whereKey($admission->id)->lockForUpdate()->firstOrFail();
            $validated = $request->validated();
            $courseId = array_key_exists('course_id', $validated) ? $validated['course_id'] : $admission->course_id;
            $batchId = array_key_exists('batch_id', $validated) ? $validated['batch_id'] : $admission->batch_id;

            if ($courseId) {
                $this->branchScope()->assertCourseInBranch((int) $courseId, (int) $admission->branch_id);
            }

            $courseChanged = (int) ($courseId ?? 0) !== (int) ($admission->course_id ?? 0);
            $oldFeeDeal = null;
            if ($courseChanged && $admission->fee_schedule) {
                $feeDeals = Deal::where('lead_id', $admission->lead_id)->orderBy('id')->lockForUpdate()->get();
                if ($feeDeals->contains(fn ($deal) => $deal->payments()->exists())) {
                    throw ValidationException::withMessages(['course_id' => 'The course cannot change after payments have been recorded. Existing fee history must be preserved.']);
                }
                $course = Course::find($courseId);
                if (! $course?->period_prices || ! isset($validated['fee_periods'])) {
                    throw ValidationException::withMessages(['course_id' => 'Choose a course with unit prices and review its fees before saving.']);
                }
                $masterRows = app(FeeScheduleService::class)->normalize($course->period_prices, $course->duration_value);
                $oldFeeDeal = $feeDeals->firstWhere('fee_kind', 'course')?->toArray();
                $admission->fee_schedule = ['unit' => $course->duration_unit, 'duration' => $course->duration_value,
                    'rows' => array_map(fn ($r) => array_merge($r, ['hostel_stay' => false, 'hostel_amount' => null]), $masterRows)];
            }
            if ($courseChanged && ! array_key_exists('batch_id', $validated)) {
                $batchId = null;
            }

            if ($batchId) {
                if (! $courseId) {
                    return response()->json([
                        'message' => 'Select a course before allocating a batch.',
                        'errors' => ['batch_id' => ['Select a course before allocating a batch.']],
                    ], 422);
                }
                $this->branchScope()->assertBatchInCourse((int) $batchId, (int) $courseId, (int) $admission->branch_id);
            }

            $hostelStay = array_key_exists('hostel_stay', $validated)
                ? (bool) $validated['hostel_stay']
                : (bool) $admission->hostel_stay;
            $hostelAmount = $admission->hostel_amount;
            if (! $hostelStay) {
                $hostelAmount = null;
            } elseif (array_key_exists('hostel_amount', $validated)) {
                $hostelAmount = $validated['hostel_amount'];
            }

            $admission->update([
                'course_id' => $courseId,
                'batch_id' => $batchId,
                'admission_date' => array_key_exists('admission_date', $validated)
                    ? $validated['admission_date']
                    : $admission->admission_date,
                'status' => $batchId ? 'admitted' : 'pending',
                'hostel_stay' => $hostelStay,
                'hostel_amount' => $hostelAmount,
                'academic_year_id' => array_key_exists('academic_year_id', $validated)
                    ? $validated['academic_year_id']
                    : $admission->academic_year_id,
                'student_year' => array_key_exists('student_year', $validated)
                    ? $validated['student_year']
                    : $admission->student_year,
            ]);

            if ($admission->fee_schedule) {
                app(FeeScheduleService::class)->syncAdmission($admission, $validated['fee_periods'] ?? $admission->fee_schedule['rows'], $request->user(), $validated['fee_change_reason'] ?? null);
            } else {
                if (isset($validated['fee_periods'])) {
                    throw ValidationException::withMessages(['fee_periods' => 'Existing whole-course fees are preserved as legacy records.']);
                }
                $this->admissions->syncHostelFee($admission->fresh()->load('lead'), $request->user());
            }

            if ($oldFeeDeal) {
                $feeDeal = Deal::find($oldFeeDeal['id']);
                app(FeeScheduleService::class)->audit($feeDeal, $request->user(), 'course_changed', ['schedule' => $oldFeeDeal], ['schedule' => $feeDeal->toArray()], null, $validated['fee_change_reason'] ?? 'Course changed before any payments');
                $admission->lead()->update(['course_id' => $courseId]);
            }

            return $this->show($request, $admission->fresh());
        });
    }

    public function storeDocument(Request $request, Admission $admission): JsonResponse
    {
        $this->authorizeAdmission($request, $admission);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'doc_type' => ['required', Rule::in(AdmissionDocument::TYPES)],
            'doc_label' => [
                Rule::requiredIf(fn () => $request->input('doc_type') === 'other'),
                'nullable',
                'string',
                'max:100',
            ],
        ]);

        $file = $request->file('file');
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName()) ?: 'document';
        $path = $file->storeAs('admissions/'.$admission->id, uniqid().'_'.$safeName, 'local');

        $document = AdmissionDocument::create([
            'admission_id' => $admission->id,
            'file_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'doc_type' => $validated['doc_type'],
            'doc_label' => $validated['doc_type'] === 'other' ? trim((string) ($validated['doc_label'] ?? '')) : null,
            'uploaded_by' => $request->user()->id,
        ]);

        $document->load('uploadedBy:id,name');

        return response()->json(['data' => new AdmissionDocumentResource($document)], 201);
    }

    public function destroyDocument(Request $request, Admission $admission, AdmissionDocument $document): JsonResponse
    {
        $this->authorizeAdmission($request, $admission);

        if ((int) $document->admission_id !== (int) $admission->id) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        if ($document->path) {
            Storage::disk('local')->delete($document->path);
        }
        $document->delete();

        return response()->json(['message' => 'Document deleted.']);
    }

    public function downloadDocument(Request $request, Admission $admission, AdmissionDocument $document): StreamedResponse|JsonResponse
    {
        $this->authorizeAdmission($request, $admission);

        if ((int) $document->admission_id !== (int) $admission->id) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        if (! $document->path || ! Storage::disk('local')->exists($document->path)) {
            return response()->json(['message' => 'File is no longer available.'], 404);
        }

        return Storage::disk('local')->download($document->path, $document->file_name);
    }

    private function authorizeAdmission(Request $request, Admission $admission): void
    {
        $lead = $admission->relationLoaded('lead') ? $admission->lead : $admission->lead()->first();
        $this->branchScope()->assertRecordInScope(
            $request->user(),
            $admission->branch_id,
            $lead?->assigned_to
        );
    }
}
