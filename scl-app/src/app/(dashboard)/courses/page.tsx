'use client';

import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { branchesApi } from '@/lib/api/branches';
import { coursesApi, type CoursePayload } from '@/lib/api/courses';
import { subjectsApi } from '@/lib/api/subjects';
import { AccessDenied } from '@/components/ui/AccessDenied';
import { useAuthStore } from '@/store/authStore';
import { useBranchStore } from '@/store/branchStore';
import FeePeriodEditor from '@/components/ui/FeePeriodEditor';
import { feeTotal } from '@/lib/feePeriods';
import { formatDealMoney } from '@/lib/currency';
import type { Course, CourseDurationUnit } from '@/types';
import { SubjectManager } from './SubjectManager';

interface ApiError {
  response?: { data?: { message?: string; errors?: Record<string, string[]> } };
}

const DURATION_UNITS: CourseDurationUnit[] = ['days', 'weeks', 'months', 'years'];

const emptyForm: CoursePayload = {
  branch_id: 0,
  name: '',
  code: '',
  description: '',
  duration_value: null,
  duration_unit: null,
  price: null,
  period_prices: null,
  is_active: true,
};

function apiMessage(err: unknown, fallback: string) {
  const data = (err as ApiError)?.response?.data;
  const first = data?.errors ? Object.values(data.errors).flat()[0] : undefined;
  return first || data?.message || fallback;
}

function fieldError(err: unknown, field: string) {
  return (err as ApiError)?.response?.data?.errors?.[field]?.[0];
}

export default function CoursesPage() {
  const qc = useQueryClient();
  const { isOwner } = useAuthStore();
  const selectedBranchId = useBranchStore((s) => s.selectedBranchId);
  const topbarLocked = selectedBranchId !== 'all';

  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [branchFilter, setBranchFilter] = useState('');
  const [page, setPage] = useState(1);
  const [modal, setModal] = useState<Course | null | undefined>(undefined);
  const [subjectsOpen, setSubjectsOpen] = useState(false);
  const [subjectCourse, setSubjectCourse] = useState<Course | null>(null);
  const [detail, setDetail] = useState<Course | null>(null);
  const [form, setForm] = useState<CoursePayload>(emptyForm);
  const [saveError, setSaveError] = useState<unknown>(null);

  useEffect(() => { setPage(1); }, [search, status, branchFilter, selectedBranchId]);

  const listParams = {
    search: search || undefined,
    is_active: status === '' ? undefined : status === 'active',
    branch_id: !topbarLocked && branchFilter ? Number(branchFilter) : undefined,
    page,
    per_page: 20,
  };

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['courses', listParams, selectedBranchId],
    queryFn: () => coursesApi.list(listParams),
  });

  const { data: branches = [] } = useQuery({
    queryKey: ['branches'],
    queryFn: () => branchesApi.list(),
  });

  const courses = data?.data ?? [];
  const meta = data?.meta;
  const branchScoped = topbarLocked || Boolean(branchFilter);

  const formBranches = useMemo(() => {
    const currentId = form.branch_id || modal?.branch_id;
    return branches.filter((b) => b.is_active || b.id === currentId);
  }, [branches, form.branch_id, modal?.branch_id]);

  const saveMutation = useMutation({
    mutationFn: (payload: CoursePayload) =>
      modal?.id ? coursesApi.update(modal.id, payload) : coursesApi.create(payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['courses'] });
      qc.invalidateQueries({ queryKey: ['admissions'] });
      qc.invalidateQueries({ queryKey: ['deals'] });
      qc.invalidateQueries({ queryKey: ['deal-payments'] });
      setModal(undefined);
      setSaveError(null);
    },
    onError: (err) => setSaveError(err),
  });

  const toggleMutation = useMutation({
    mutationFn: (id: number) => coursesApi.toggle(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['courses'] }),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => coursesApi.delete(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['courses'] });
      setDetail(null);
    },
    onError: (err: unknown) => {
      alert(apiMessage(err, 'Could not delete course.'));
    },
  });

  const lockedBranchId = topbarLocked ? Number(selectedBranchId) : undefined;
  const branchLockedOnEdit = Boolean(modal?.id && (modal.batches_count ?? 0) > 0);

  const openCreate = () => {
    setForm({
      ...emptyForm,
      branch_id: lockedBranchId ?? 0,
    });
    setSaveError(null);
    setModal(null);
  };

  const openEdit = (course: Course) => {
    setForm({
      branch_id: course.branch_id,
      name: course.name,
      code: course.code,
      description: course.description ?? '',
      duration_value: course.duration_value ?? null,
      duration_unit: course.duration_unit ?? null,
      price: course.price ?? null,
      period_prices: course.period_prices?.length ? course.period_prices : course.duration_value && course.duration_unit && course.price != null ? [{ period: 1, amount: course.price }] : null,
      is_active: course.is_active,
    });
    setSaveError(null);
    setModal(course);
  };

  const setDuration = (value: number | null, unit: CourseDurationUnit | null) => {
    setForm((previous) => ({
      ...previous,
      duration_value: value,
      duration_unit: unit,
      period_prices: value && unit ? previous.period_prices?.length ? previous.period_prices
        : previous.price != null ? [{ period: 1, amount: previous.price }] : [] : null,
    }));
  };

  const submit = () => {
    const branchId = lockedBranchId || form.branch_id;
    if (!branchId) {
      setSaveError({ response: { data: { errors: { branch_id: ['Select a specific branch.'] } } } });
      return;
    }
    if (form.duration_value && form.duration_unit && !form.period_prices?.length) {
      setSaveError({ response: { data: { message: 'Add at least one period price.' } } });
      return;
    }
    saveMutation.mutate({
      ...form,
      branch_id: branchId,
      description: form.description || null,
      duration_value: form.duration_value || null,
      duration_unit: form.duration_value ? form.duration_unit : null,
      price: form.period_prices?.length ? feeTotal(form.period_prices) : form.price ?? null,
      period_prices: form.duration_value && form.duration_unit ? form.period_prices : null,
    });
  };

  const { data: detailBatches, isLoading: batchesLoading } = useQuery({
    queryKey: ['courses', detail?.id, 'batches'],
    queryFn: () => coursesApi.batches(detail!.id, { per_page: 100 }),
    enabled: Boolean(detail?.id),
  });

  const { data: detailSubjects = [], isLoading: subjectsLoading } = useQuery({
    queryKey: ['subjects', 'course-detail', detail?.id],
    queryFn: () => subjectsApi.list(detail!.id),
    enabled: Boolean(detail?.id),
  });

  const selectedBranchName = (id?: number) =>
    branches.find((b) => b.id === id)?.name ?? (id ? `Branch #${id}` : '');

  if (!isOwner()) {
    return <AccessDenied reason="Course management is only available to superadmins." />;
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between gap-4 flex-wrap">
        <h1 className="text-xl font-bold text-gray-900">Courses</h1>
        <div className="flex flex-wrap gap-2">
          <button
            onClick={openCreate}
            className="text-sm font-semibold px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white"
          >
            + Add Course
          </button>
          <button
            type="button"
            onClick={() => { setSubjectCourse(null); setSubjectsOpen(true); }}
            className="text-sm font-semibold px-4 py-2 rounded-xl border border-indigo-200 text-indigo-700 hover:bg-indigo-50"
          >
            + Add Subject
          </button>
        </div>
      </div>

      <div className="flex flex-col sm:flex-row gap-2 sm:gap-3 sm:flex-wrap">
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search courses..."
          className="border border-gray-200 rounded-xl px-3 py-2 text-sm w-full sm:w-64 focus:outline-none focus:ring-2 focus:ring-indigo-500"
        />
        {!topbarLocked && (
          <select
            value={branchFilter}
            onChange={(e) => setBranchFilter(e.target.value)}
            className="w-full sm:w-auto border border-gray-200 rounded-xl px-3 py-2 text-sm"
          >
            <option value="">All branches</option>
            {branches.map((b) => (
              <option key={b.id} value={b.id}>{b.name}</option>
            ))}
          </select>
        )}
        <select
          value={status}
          onChange={(e) => setStatus(e.target.value)}
          className="w-full sm:w-auto border border-gray-200 rounded-xl px-3 py-2 text-sm"
        >
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>

      <div className="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        {isError ? (
          <div className="p-8 text-center text-sm text-red-600">
            {apiMessage(error, 'Failed to load courses.')}
            <button onClick={() => refetch()} className="block mx-auto mt-2 text-indigo-600">Retry</button>
          </div>
        ) : isLoading ? (
          <div className="flex items-center justify-center h-48">
            <div className="w-6 h-6 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
          </div>
        ) : courses.length === 0 ? (
          <div className="px-6 py-12 text-center text-gray-400 text-sm">
            {branchScoped ? 'No courses added for this branch.' : 'No courses yet.'}{' '}
            <button onClick={openCreate} className="text-indigo-600 hover:underline">Add Course</button>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-100">
              <thead className="bg-gray-50">
                <tr>
                  {['Course name', 'Code', 'Branch', 'Duration', 'Price', 'Status', 'Batches', 'Actions'].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider whitespace-nowrap">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {courses.map((course) => (
                  <tr key={course.id} className="hover:bg-gray-50/60">
                    <td className="px-4 py-3 text-sm font-medium text-gray-900 whitespace-nowrap">{course.name}</td>
                    <td className="px-4 py-3 text-sm text-gray-600">{course.code}</td>
                    <td className="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                      {course.branch?.name ?? '—'}
                      {course.branch && !course.branch.is_active ? (
                        <span className="ml-1 text-xs text-red-500">(inactive)</span>
                      ) : null}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">{course.duration || '—'}</td>
                    <td className="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">{formatDealMoney(course.price)}</td>
                    <td className="px-4 py-3">
                      <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${course.is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                        {course.is_active ? 'Active' : 'Inactive'}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">{course.batches_count ?? 0}</td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-2 min-w-[200px]">
                        <button onClick={() => setDetail(course)} className="text-xs text-indigo-600 font-medium">View Details</button>
                        <Link href={`/courses/${course.id}`} className="text-xs text-indigo-600 font-medium">Manage Batches</Link>
                        <button onClick={() => openEdit(course)} className="text-xs text-indigo-600 font-medium">Edit</button>
                        <button
                          onClick={() => toggleMutation.mutate(course.id)}
                          disabled={toggleMutation.isPending}
                          className="text-xs text-amber-600 font-medium"
                        >
                          {course.is_active ? 'Deactivate' : 'Activate'}
                        </button>
                        <button
                          onClick={() => {
                            if (confirm('Delete this course? This only works if it has no batches or other linked records.')) {
                              deleteMutation.mutate(course.id);
                            }
                          }}
                          className="text-xs text-red-500 font-medium"
                        >
                          Delete
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {meta && meta.last_page > 1 && (
          <div className="px-4 py-3 border-t border-gray-100 flex flex-wrap items-center justify-between gap-2">
            <span className="text-xs text-gray-400">Page {meta.current_page} of {meta.last_page} · {meta.total} courses</span>
            <div className="flex gap-2">
              <button onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page === 1} className="text-xs px-3 py-1.5 border border-gray-200 rounded-lg disabled:opacity-40">Previous</button>
              <button onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))} disabled={page === meta.last_page} className="text-xs px-3 py-1.5 border border-gray-200 rounded-lg disabled:opacity-40">Next</button>
            </div>
          </div>
        )}
      </div>

      {modal !== undefined && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-lg p-5 sm:p-6 max-h-[90dvh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-semibold text-gray-900">{modal ? 'Edit Course' : 'Add Course'}</h2>
              <button
                type="button"
                onClick={() => setModal(undefined)}
                className="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100"
                aria-label="Close"
              >
                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            {saveError ? (
              <div className="mb-4 px-4 py-3 rounded-xl bg-red-50 text-sm text-red-700">
                {apiMessage(saveError, 'Could not save course.')}
              </div>
            ) : null}
            <div className="space-y-3">
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Branch *</label>
                {lockedBranchId ? (
                  <input
                    readOnly
                    value={selectedBranchName(lockedBranchId)}
                    className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm bg-gray-50 text-gray-600"
                  />
                ) : (
                  <select
                    value={form.branch_id || ''}
                    disabled={branchLockedOnEdit}
                    onChange={(e) => setForm({ ...form, branch_id: e.target.value ? Number(e.target.value) : 0 })}
                    className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50"
                  >
                    <option value="">Select an active branch</option>
                    {formBranches.map((b) => (
                      <option key={b.id} value={b.id}>
                        {b.name}{b.is_active ? '' : ' (inactive)'}
                      </option>
                    ))}
                  </select>
                )}
                {branchLockedOnEdit && (
                  <p className="mt-1 text-xs text-gray-400">Branch cannot be changed because this course already has batches.</p>
                )}
                {fieldError(saveError, 'branch_id') && <p className="mt-1 text-xs text-red-600">{fieldError(saveError, 'branch_id')}</p>}
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Course name *</label>
                <input
                  value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })}
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                {fieldError(saveError, 'name') && <p className="mt-1 text-xs text-red-600">{fieldError(saveError, 'name')}</p>}
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Course code *</label>
                <input
                  value={form.code}
                  onChange={(e) => setForm({ ...form, code: e.target.value })}
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                {fieldError(saveError, 'code') && <p className="mt-1 text-xs text-red-600">{fieldError(saveError, 'code')}</p>}
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Description</label>
                <textarea
                  value={form.description ?? ''}
                  onChange={(e) => setForm({ ...form, description: e.target.value })}
                  rows={3}
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Duration value</label>
                  <input
                    type="number"
                    min={1}
                    value={form.duration_value ?? ''}
                    onChange={(e) => setDuration(e.target.value ? Number(e.target.value) : null, e.target.value ? form.duration_unit ?? null : null)}
                    className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Duration unit{form.duration_value ? ' *' : ''}</label>
                  <select
                    value={form.duration_unit ?? ''}
                    onChange={(e) => setDuration(form.duration_value ?? null, (e.target.value || null) as CourseDurationUnit | null)}
                    className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  >
                    <option value="">Select unit</option>
                    {DURATION_UNITS.map((unit) => (
                      <option key={unit} value={unit}>{unit}</option>
                    ))}
                  </select>
                </div>
              </div>
              <div className="space-y-3">
                {form.duration_value && form.duration_unit ? <>
                  <h3 className="text-sm font-semibold">Unit prices</h3>
                  <FeePeriodEditor rows={form.period_prices ?? []} onChange={(rows) => setForm({ ...form, period_prices: rows, price: feeTotal(rows) })}
                    unit={form.duration_unit} duration={form.duration_value} editablePeriods />
                  <p className="text-xs text-gray-500">New later periods also apply to existing students and reopen paid course fees when a balance is added. Previously assigned student prices stay unchanged. Future hostel fees require confirming each student&apos;s stay in Admission.</p>
                </> : <>
                  <label className="block text-xs font-medium text-gray-700">Price (INR) *</label>
                  <input type="number" min={0} step="0.01" value={form.price ?? ''}
                    onChange={(e) => setForm({ ...form, price: e.target.value === '' ? null : Number(e.target.value) })}
                    className="w-full border border-gray-200 rounded-xl px-3 py-2" placeholder="Enter course price" />
                  <p className="text-xs text-gray-500">Duration is optional. Without a duration, this remains the single course fee.</p>
                </>}
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Status *</label>
                <select
                  value={form.is_active ? 'active' : 'inactive'}
                  onChange={(e) => setForm({ ...form, is_active: e.target.value === 'active' })}
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
            </div>
            <div className="flex gap-3 mt-6">
              <button
                onClick={submit}
                disabled={saveMutation.isPending}
                className="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2.5 rounded-xl text-sm disabled:opacity-60"
              >
                {saveMutation.isPending ? 'Saving…' : modal ? 'Save Changes' : 'Create Course'}
              </button>
              <button onClick={() => setModal(undefined)} className="px-4 text-sm text-gray-500 border border-gray-200 rounded-xl">
                Cancel
              </button>
            </div>
          </div>
        </div>
      )}

      {detail && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-2xl p-5 sm:p-6 max-h-[90dvh] overflow-y-auto">
            <div className="flex items-start justify-between mb-4">
              <div>
                <h2 className="text-lg font-semibold text-gray-900">{detail.name}</h2>
                <p className="text-xs text-gray-400">{detail.code}</p>
              </div>
              <button onClick={() => setDetail(null)} className="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between gap-4"><dt className="text-gray-500">Branch</dt><dd className="text-right">{detail.branch?.name ?? '—'}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500">Duration</dt><dd>{detail.duration || '—'}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500">Price</dt><dd>{formatDealMoney(detail.price)}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500">Status</dt><dd>{detail.is_active ? 'Active' : 'Inactive'}</dd></div>
            </dl>
            {detail.description ? <p className="mt-4 text-sm text-gray-600">{detail.description}</p> : null}

            <div className="mt-5">
              <h3 className="text-sm font-semibold text-gray-900 mb-2">Batches</h3>
              {batchesLoading ? (
                <p className="text-xs text-gray-400">Loading batches…</p>
              ) : (detailBatches?.data.length ?? 0) === 0 ? (
                <p className="text-sm text-gray-400">No batches yet.</p>
              ) : (
                <ul className="divide-y divide-gray-100 border border-gray-100 rounded-xl">
                  {detailBatches?.data.map((batch) => (
                    <li key={batch.id} className="px-3 py-2.5 flex items-center justify-between gap-3">
                      <div>
                        <p className="text-sm font-medium text-gray-900">{batch.name}</p>
                        <p className="text-xs text-gray-400">{batch.code}{batch.schedule ? ` · ${batch.schedule}` : ''}</p>
                      </div>
                      <div className="text-right">
                        <p className="text-xs font-medium text-gray-600 capitalize">{batch.status}</p>
                        <p className="text-xs text-gray-400">{batch.start_date}{batch.end_date ? ` – ${batch.end_date}` : ''}</p>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </div>

            <div className="mt-5">
              <h3 className="text-sm font-semibold text-gray-900 mb-2">Subjects</h3>
              {subjectsLoading ? (
                <p className="text-xs text-gray-400">Loading subjects…</p>
              ) : detailSubjects.length === 0 ? (
                <p className="text-sm text-gray-400">No subjects yet.</p>
              ) : (
                <ul className="divide-y divide-gray-100 border border-gray-100 rounded-xl">
                  {detailSubjects.map((subject) => (
                    <li key={subject.id} className="px-3 py-2.5 flex items-center justify-between gap-3">
                      <p className="text-sm font-medium text-gray-900">{subject.name}</p>
                      <p className="text-xs text-gray-500">{subject.code}</p>
                    </li>
                  ))}
                </ul>
              )}
            </div>

            <div className="mt-5 flex justify-end gap-2">
              <button
                type="button"
                onClick={() => { setSubjectCourse(detail); setSubjectsOpen(true); }}
                className="text-sm font-semibold px-4 py-2 rounded-xl border border-indigo-200 text-indigo-700 hover:bg-indigo-50"
              >
                + Add Subject
              </button>
              <Link
                href={`/courses/${detail.id}`}
                className="text-sm font-semibold px-4 py-2 rounded-xl bg-indigo-600 text-white"
              >
                Manage Batches
              </Link>
            </div>
          </div>
        </div>
      )}

      <SubjectManager
        open={subjectsOpen}
        course={subjectCourse}
        onClose={() => { setSubjectsOpen(false); setSubjectCourse(null); }}
      />
    </div>
  );
}
