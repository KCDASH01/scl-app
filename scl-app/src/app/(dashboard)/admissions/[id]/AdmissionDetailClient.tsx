'use client';

import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { usePathSegment } from '@/lib/usePathSegment';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { admissionsApi, type AdmissionPayload } from '@/lib/api/admissions';
import { academicYearsApi } from '@/lib/api/academicYears';
import { batchesApi, coursesApi } from '@/lib/api/courses';
import FeePeriodEditor from '@/components/ui/FeePeriodEditor';
import type { FeePrice } from '@/lib/feePeriods';
import { AccessDenied } from '@/components/ui/AccessDenied';
import type { AcademicYear, AdmissionDocType, Batch, Course, StudentYear } from '@/types';

interface ApiError {
  response?: { data?: { message?: string; errors?: Record<string, string[]> } };
}

const STUDENT_YEARS: StudentYear[] = ['1st', '2nd', '3rd', '4th'];

const DOC_TYPES: { id: AdmissionDocType; label: string }[] = [
  { id: 'id_proof', label: 'ID proof' },
  { id: 'photo', label: 'Photo' },
  { id: 'marksheet', label: 'Marksheet' },
  { id: 'other', label: 'Other' },
];

function apiMessage(err: unknown, fallback: string) {
  const data = (err as ApiError)?.response?.data;
  const first = data?.errors ? Object.values(data.errors).flat()[0] : undefined;
  return first || data?.message || fallback;
}

function fieldError(err: unknown, field: string) {
  return (err as ApiError)?.response?.data?.errors?.[field]?.[0];
}

function docLabel(type: AdmissionDocType, label?: string | null) {
  if (type === 'other' && label) return label;
  return DOC_TYPES.find((d) => d.id === type)?.label ?? type;
}

function formatBytes(size?: number | null) {
  if (!size) return '';
  if (size < 1024) return `${size} B`;
  if (size < 1024 * 1024) return `${Math.round(size / 1024)} KB`;
  return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

export default function AdmissionDetailPage() {
  const admissionId = Number(usePathSegment(1));
  const qc = useQueryClient();

  const [courseId, setCourseId] = useState<number | ''>('');
  const [batchId, setBatchId] = useState<number | ''>('');
  const [admissionDate, setAdmissionDate] = useState('');
  const [academicYearId, setAcademicYearId] = useState<number | ''>('');
  const [studentYear, setStudentYear] = useState<StudentYear | ''>('');
  const [feeRows, setFeeRows] = useState<FeePrice[]>([]);
  const [feeReason, setFeeReason] = useState('');
  const [hostelStay, setHostelStay] = useState(false);
  const [hostelAmount, setHostelAmount] = useState('');
  const [saveError, setSaveError] = useState<unknown>(null);
  const [saveMessage, setSaveMessage] = useState('');
  const [docType, setDocType] = useState<AdmissionDocType>('id_proof');
  const [docName, setDocName] = useState('');
  const [docError, setDocError] = useState<unknown>(null);

  const { data: admission, isLoading, isError, error } = useQuery({
    queryKey: ['admissions', admissionId],
    queryFn: () => admissionsApi.get(admissionId),
    enabled: Number.isFinite(admissionId),
  });

  useEffect(() => {
    if (!admission) return;
    setFeeRows(admission.fee_schedule?.rows ?? []);
    setFeeReason('');
    setCourseId(admission.course_id ?? '');
    setBatchId(admission.batch_id ?? '');
    setAcademicYearId(admission.academic_year_id ?? '');
    setStudentYear(admission.student_year ?? '');
    setAdmissionDate(admission.admission_date ?? '');
    setHostelStay(Boolean(admission.hostel_stay));
    setHostelAmount(admission.hostel_amount != null ? String(admission.hostel_amount) : '');
    setSaveError(null);
  }, [admission]);

  const branchId = admission?.branch_id;

  const { data: courses = [] } = useQuery({
    queryKey: ['courses', 'options', branchId],
    queryFn: () => coursesApi.options({ branch_id: branchId }),
    enabled: Boolean(branchId),
    staleTime: 5 * 60_000,
  });

  const { data: academicYears = [] } = useQuery({
    queryKey: ['academic-years'],
    queryFn: academicYearsApi.list,
    staleTime: 60_000,
  });

  const { data: batches = [] } = useQuery({
    queryKey: ['batches', 'options', courseId || 0],
    queryFn: () => batchesApi.options({ course_id: Number(courseId) }),
    enabled: Boolean(courseId),
    staleTime: 60_000,
  });

  const yearOptions: AcademicYear[] = useMemo(() => {
    const current = admission?.academic_year;
    if (current && !academicYears.some((year) => year.id === current.id)) {
      return [current, ...academicYears];
    }
    return academicYears;
  }, [admission, academicYears]);

  const courseOptions: Course[] = useMemo(() => {
    const current = admission?.course;
    if (current && !courses.some((c) => c.id === current.id)) {
      return [{
        id: current.id,
        branch_id: current.branch_id ?? admission?.branch_id ?? 0,
        name: current.name,
        code: current.code,
        is_active: current.is_active,
      }, ...courses];
    }
    return courses;
  }, [admission, courses]);

  const batchOptions: Batch[] = useMemo(() => {
    const current = admission?.batch;
    if (current && Number(courseId) === current.course_id && !batches.some((b) => b.id === current.id)) {
      return [{
        id: current.id,
        branch_id: admission?.branch_id ?? 0,
        course_id: current.course_id,
        name: current.name,
        code: current.code,
        start_date: '',
        status: current.status as Batch['status'],
      }, ...batches];
    }
    return batches;
  }, [admission, batches, courseId]);

  const saveMutation = useMutation({
    mutationFn: (payload: AdmissionPayload) => admissionsApi.update(admissionId, payload),
    onSuccess: (data) => {
      qc.setQueryData(['admissions', admissionId], data);
      qc.invalidateQueries({ queryKey: ['admissions'] });
      qc.invalidateQueries({ queryKey: ['deals'] });
      qc.invalidateQueries({ queryKey: ['deal-payments'] });
      setSaveError(null);
      setSaveMessage('Admission saved.');
    },
    onError: (err) => {
      setSaveMessage('');
      setSaveError(err);
    },
  });

  const uploadMutation = useMutation({
    mutationFn: ({ file, type, label }: { file: File; type: AdmissionDocType; label?: string }) =>
      admissionsApi.uploadDocument(admissionId, file, type, label),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admissions', admissionId] });
      setDocName('');
      setDocError(null);
    },
    onError: (err) => setDocError(err),
  });

  const deleteDocMutation = useMutation({
    mutationFn: (documentId: number) => admissionsApi.deleteDocument(admissionId, documentId),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admissions', admissionId] }),
  });

  const save = () => {
    setSaveMessage('');
    const amount = hostelAmount.trim() === '' ? null : Number(hostelAmount);
    if (!admission?.fee_schedule && hostelStay && (amount == null || !Number.isFinite(amount) || amount <= 0)) {
      setSaveError({ response: { data: { errors: { hostel_amount: ['Enter a hostel amount greater than 0.'] } } } });
      return;
    }
    saveMutation.mutate({
      course_id: courseId === '' ? null : Number(courseId),
      batch_id: batchId === '' ? null : Number(batchId),
      academic_year_id: academicYearId === '' ? null : Number(academicYearId),
      student_year: studentYear || null,
      admission_date: admissionDate || null,
      ...(admission?.fee_schedule ? { fee_periods: feeRows, fee_change_reason: feeReason } : {}),
      hostel_stay: admission?.fee_schedule ? feeRows.some((row) => row.hostel_stay) : hostelStay,
      ...(admission?.fee_schedule ? {} : { hostel_amount: hostelStay ? amount : null }),
    });
  };

  const onCourseChange = (value: string) => {
    if (admission?.fee_schedule) {
      if (Number(value) === admission.course_id) setFeeRows(admission.fee_schedule.rows);
      else {
        const chosen = courseOptions.find((course) => course.id === Number(value));
        if (!chosen?.period_prices?.length) {
          setSaveError({ response: { data: { message: 'Select a course with unit prices. Existing fee history will be preserved.' } } });
          return;
        }
        setFeeRows(chosen.period_prices.map((row) => ({ ...row, hostel_stay: false, hostel_amount: null })));
      }
    }
    setCourseId(value ? Number(value) : '');
    setBatchId('');
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="w-6 h-6 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }

  if (isError || !admission) {
    const status = (error as { response?: { status?: number } })?.response?.status;
    if (status === 403 || status === 404) return <AccessDenied />;
    return <p className="text-sm text-red-600">{apiMessage(error, 'Admission not found.')}</p>;
  }

  const documents = admission.documents ?? [];

  return (
    <div className="space-y-6 max-w-4xl">
      <div className="flex items-start sm:items-center justify-between gap-3">
        <div className="min-w-0">
          <Link href="/admissions" className="text-xs text-indigo-600 font-medium">← Student Admission</Link>
          <h1 className="mt-1 text-xl font-semibold text-gray-900">{admission.lead?.full_name ?? 'Student'}</h1>
          <p className="text-sm text-gray-500">Enrollment ID {admission.enrollment_id}</p>
        </div>
        <span className={`text-xs px-2.5 py-1 rounded-full font-medium capitalize ${admission.status === 'admitted' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'}`}>
          {admission.status}
        </span>
      </div>

      <div className="bg-white rounded-2xl border border-gray-200 p-5">
        <h2 className="text-sm font-semibold text-gray-900 mb-3">Student details</h2>
        <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
          <div>
            <dt className="text-xs text-gray-500">Name</dt>
            <dd className="text-gray-900">{admission.lead?.full_name ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-xs text-gray-500">Phone</dt>
            <dd className="text-gray-900">{admission.lead?.phone || '—'}</dd>
          </div>
          <div>
            <dt className="text-xs text-gray-500">Email</dt>
            <dd className="text-gray-900">{admission.lead?.email || '—'}</dd>
          </div>
          <div>
            <dt className="text-xs text-gray-500">City</dt>
            <dd className="text-gray-900">{admission.lead?.city || '—'}</dd>
          </div>
          <div>
            <dt className="text-xs text-gray-500">Assigned to</dt>
            <dd className="text-gray-900">{admission.lead?.assigned_to?.name || '—'}</dd>
          </div>
          <div>
            <dt className="text-xs text-gray-500">Branch</dt>
            <dd className="text-gray-900">{admission.branch?.name || '—'}</dd>
          </div>
        </dl>
        {admission.lead_id ? (
          <Link href={`/leads/${admission.lead_id}`} className="inline-block mt-4 text-xs text-indigo-600 font-medium">
            View lead
          </Link>
        ) : null}
      </div>

      <div className="bg-white rounded-2xl border border-gray-200 p-5 space-y-4">
        <h2 className="text-sm font-semibold text-gray-900">Admission</h2>
        {saveMessage ? (
          <div className="px-4 py-3 rounded-xl bg-green-50 border border-green-200 text-sm text-green-700">
            {saveMessage}
          </div>
        ) : null}
        {saveError ? (
          <div className="px-4 py-3 rounded-xl bg-red-50 text-sm text-red-700">
            {apiMessage(saveError, 'Could not save admission.')}
          </div>
        ) : null}

        <div>
          <label className="block text-xs font-medium text-gray-700 mb-1">Enrollment ID</label>
          <input
            readOnly
            value={admission.enrollment_id}
            className="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-gray-50 text-gray-600"
          />
          <p className="mt-1 text-xs text-gray-400">Created at convert. This ID never changes.</p>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Course</label>
            <select
              value={courseId}
              onChange={(e) => onCourseChange(e.target.value)}
              className="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="">Select course</option>
              {courseOptions.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name} ({c.code}){c.is_active ? '' : ' — inactive'}
                </option>
              ))}
            </select>
            {fieldError(saveError, 'course_id') ? (
              <p className="mt-1 text-xs text-red-600">{fieldError(saveError, 'course_id')}</p>
            ) : null}
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Batch</label>
            <select
              value={batchId}
              disabled={!courseId}
              onChange={(e) => setBatchId(e.target.value ? Number(e.target.value) : '')}
              className="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50"
            >
              <option value="">{courseId ? 'Allocate batch' : 'Select a course first'}</option>
              {batchOptions.map((b) => (
                <option key={b.id} value={b.id}>{b.name} ({b.code})</option>
              ))}
            </select>
            {fieldError(saveError, 'batch_id') ? (
              <p className="mt-1 text-xs text-red-600">{fieldError(saveError, 'batch_id')}</p>
            ) : null}
          </div>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Batch year</label>
            <select
              value={academicYearId}
              onChange={(e) => setAcademicYearId(e.target.value ? Number(e.target.value) : '')}
              className="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="">Select batch year</option>
              {yearOptions.map((year) => (
                <option key={year.id} value={year.id}>{year.name}</option>
              ))}
            </select>
            {fieldError(saveError, 'academic_year_id') ? (
              <p className="mt-1 text-xs text-red-600">{fieldError(saveError, 'academic_year_id')}</p>
            ) : null}
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Student year</label>
            <select
              value={studentYear}
              onChange={(e) => setStudentYear(e.target.value as StudentYear | '')}
              className="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
              <option value="">Select year</option>
              {STUDENT_YEARS.map((year) => (
                <option key={year} value={year}>{year}</option>
              ))}
            </select>
            {fieldError(saveError, 'student_year') ? (
              <p className="mt-1 text-xs text-red-600">{fieldError(saveError, 'student_year')}</p>
            ) : null}
          </div>
        </div>

        <div className="sm:w-64">
          <label className="block text-xs font-medium text-gray-700 mb-1">Date of admission</label>
          <input
            type="date"
            value={admissionDate}
            onChange={(e) => setAdmissionDate(e.target.value)}
            className="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>

        {admission.fee_schedule ? <div className="space-y-3">
          <h3 className="text-sm font-semibold text-gray-900">Agreed period fees</h3>
          <p className="text-xs text-gray-500">Change this student&apos;s prices and hostel stay per period. New course periods appear here automatically; hostel is charged only for periods marked Stay with a price. Periods with payments require a manager and a reason to change their price.</p>
          <FeePeriodEditor rows={feeRows} onChange={setFeeRows} unit={Number(courseId) === admission.course_id ? admission.fee_schedule.unit : courseOptions.find((course) => course.id === Number(courseId))?.duration_unit} duration={Number(courseId) === admission.course_id ? admission.fee_schedule.duration : courseOptions.find((course) => course.id === Number(courseId))?.duration_value} hostel />
          <label className="block text-xs text-gray-600">Reason for fee changes
            <input value={feeReason} onChange={(e) => setFeeReason(e.target.value)} className="mt-1 w-full border rounded-xl px-3 py-2" placeholder="Required when correcting prices for paid periods" />
          </label>
        </div> : <>
        <div className="space-y-3">
          <label className="inline-flex items-center gap-2 text-sm text-gray-800 cursor-pointer">
            <input
              type="checkbox"
              checked={hostelStay}
              onChange={(e) => {
                setHostelStay(e.target.checked);
                if (!e.target.checked) setHostelAmount('');
              }}
              className="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
            />
            Hostel stay
          </label>
          {hostelStay ? (
            <div className="sm:w-64">
              <label className="block text-xs font-medium text-gray-700 mb-1">Hostel amount</label>
              <input
                type="number"
                min={0.01}
                step="0.01"
                value={hostelAmount}
                onChange={(e) => setHostelAmount(e.target.value)}
                placeholder="0.00"
                className="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
              {fieldError(saveError, 'hostel_amount') ? (
                <p className="mt-1 text-xs text-red-600">{fieldError(saveError, 'hostel_amount')}</p>
              ) : null}
            </div>
          ) : null}
        </div>
        <p className="text-xs text-gray-500">Legacy whole-course fee: existing transactions and balances are preserved.</p>
        </>}

        <button
          onClick={save}
          disabled={saveMutation.isPending}
          className="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-medium disabled:opacity-50"
        >
          {saveMutation.isPending ? 'Saving…' : 'Save admission'}
        </button>
      </div>

      <div className="bg-white rounded-2xl border border-gray-200 p-5 space-y-4">
        <h2 className="text-sm font-semibold text-gray-900">Documents</h2>
        {docError ? (
          <div className="px-4 py-3 rounded-xl bg-red-50 text-sm text-red-700">
            {apiMessage(docError, 'Could not upload document.')}
          </div>
        ) : null}

        <div className="flex flex-col sm:flex-row gap-3 sm:items-end">
          <div className="sm:w-44">
            <label className="block text-xs font-medium text-gray-700 mb-1">Type</label>
            <select
              value={docType}
              onChange={(e) => {
                setDocType(e.target.value as AdmissionDocType);
                setDocError(null);
              }}
              className="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white"
            >
              {DOC_TYPES.map((t) => (
                <option key={t.id} value={t.id}>{t.label}</option>
              ))}
            </select>
          </div>
          {docType === 'other' ? (
            <div className="sm:w-56">
              <label className="block text-xs font-medium text-gray-700 mb-1">Document name</label>
              <input
                value={docName}
                onChange={(e) => setDocName(e.target.value)}
                placeholder="e.g. Caste certificate"
                className="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>
          ) : null}
          <div className="flex-1">
            <label className="block text-xs font-medium text-gray-700 mb-1">File (PDF, JPG, PNG · max 5 MB)</label>
            <input
              type="file"
              accept=".pdf,.jpg,.jpeg,.png"
              disabled={uploadMutation.isPending}
              onChange={(e) => {
                const file = e.target.files?.[0];
                e.target.value = '';
                if (!file) return;
                const label = docName.trim();
                if (docType === 'other' && !label) {
                  setDocError({ response: { data: { message: 'Enter a document name before choosing the file.' } } });
                  return;
                }
                uploadMutation.mutate({ file, type: docType, label: docType === 'other' ? label : undefined });
              }}
              className="block w-full text-sm text-gray-600 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-indigo-50 file:text-indigo-700"
            />
          </div>
        </div>

        {documents.length === 0 ? (
          <p className="text-sm text-gray-400">No documents uploaded yet.</p>
        ) : (
          <ul className="divide-y divide-gray-100 border border-gray-100 rounded-xl">
            {documents.map((doc) => (
              <li key={doc.id} className="px-4 py-3 flex items-center justify-between gap-3">
                <div className="min-w-0">
                  <div className="text-sm font-medium text-gray-900 break-all">{doc.file_name}</div>
                  <div className="text-xs text-gray-400">
                    {docLabel(doc.doc_type, doc.doc_label)}
                    {doc.size ? ` · ${formatBytes(doc.size)}` : ''}
                    {doc.uploaded_by ? ` · ${doc.uploaded_by.name}` : ''}
                  </div>
                </div>
                <div className="flex gap-3 shrink-0">
                  <button
                    onClick={() => admissionsApi.downloadDocument(admission.id, doc.id, doc.file_name)}
                    className="text-xs text-indigo-600 font-medium"
                  >
                    Download
                  </button>
                  <button
                    onClick={() => {
                      if (confirm('Delete this document?')) deleteDocMutation.mutate(doc.id);
                    }}
                    className="text-xs text-red-500 font-medium"
                  >
                    Delete
                  </button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
