'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { usePathSegment } from '@/lib/usePathSegment';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { batchesApi, coursesApi, type BatchPayload } from '@/lib/api/courses';
import {
  WEEKDAYS,
  composeSchedule,
  emptyScheduleParts,
  parseSchedule,
  scheduleTimeError,
  type ScheduleParts,
  type WeekdayId,
} from '@/lib/batchSchedule';
import { AccessDenied } from '@/components/ui/AccessDenied';
import { useAuthStore } from '@/store/authStore';
import type { Batch, BatchStatus, Course } from '@/types';

interface ApiError {
  response?: { data?: { message?: string; errors?: Record<string, string[]> } };
}

const STATUSES: BatchStatus[] = ['upcoming', 'ongoing', 'completed', 'cancelled'];

const emptyBatch: BatchPayload = {
  name: '',
  code: '',
  start_date: '',
  end_date: '',
  capacity: null,
  schedule: '',
  status: 'upcoming',
  notes: '',
};

function apiMessage(err: unknown, fallback: string) {
  const data = (err as ApiError)?.response?.data;
  const first = data?.errors ? Object.values(data.errors).flat()[0] : undefined;
  return first || data?.message || fallback;
}

function fieldError(err: unknown, field: string) {
  return (err as ApiError)?.response?.data?.errors?.[field]?.[0];
}

function statusClass(status: BatchStatus) {
  switch (status) {
    case 'ongoing': return 'bg-green-100 text-green-700';
    case 'upcoming': return 'bg-blue-100 text-blue-700';
    case 'completed': return 'bg-gray-100 text-gray-600';
    case 'cancelled': return 'bg-red-100 text-red-700';
    default: return 'bg-gray-100 text-gray-600';
  }
}

export default function CourseDetailPage() {
  const courseId = Number(usePathSegment(1));
  const qc = useQueryClient();
  const { isOwner } = useAuthStore();

  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [modal, setModal] = useState<Batch | null | undefined>(undefined);
  const [detail, setDetail] = useState<Batch | null>(null);
  const [form, setForm] = useState<BatchPayload>(emptyBatch);
  const [scheduleParts, setScheduleParts] = useState<ScheduleParts>(emptyScheduleParts());
  const [saveError, setSaveError] = useState<unknown>(null);

  useEffect(() => { setPage(1); }, [search, status]);

  const { data: course, isLoading: courseLoading, isError: courseError, error: courseErr } = useQuery({
    queryKey: ['courses', courseId],
    queryFn: () => coursesApi.get(courseId),
    enabled: Number.isFinite(courseId),
  });

  const batchParams = {
    search: search || undefined,
    status: status || undefined,
    page,
    per_page: 20,
  };

  const { data: batchesData, isLoading: batchesLoading } = useQuery({
    queryKey: ['courses', courseId, 'batches', batchParams],
    queryFn: () => coursesApi.batches(courseId, batchParams),
    enabled: Number.isFinite(courseId),
  });

  const batches = batchesData?.data ?? [];
  const meta = batchesData?.meta;

  const saveMutation = useMutation({
    mutationFn: (payload: BatchPayload) =>
      modal?.id ? batchesApi.update(modal.id, payload) : coursesApi.createBatch(courseId, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['courses'] });
      setModal(undefined);
      setSaveError(null);
    },
    onError: (err) => setSaveError(err),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => batchesApi.delete(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['courses'] });
      setDetail(null);
    },
    onError: (err: unknown) => {
      alert(apiMessage(err, 'Could not delete batch.'));
    },
  });

  const openCreate = () => {
    setForm(emptyBatch);
    setScheduleParts(emptyScheduleParts());
    setSaveError(null);
    setModal(null);
  };

  const openEdit = (batch: Batch) => {
    setForm({
      name: batch.name,
      code: batch.code,
      start_date: batch.start_date,
      end_date: batch.end_date ?? '',
      capacity: batch.capacity ?? null,
      schedule: batch.schedule ?? '',
      status: batch.status,
      notes: batch.notes ?? '',
    });
    setScheduleParts(parseSchedule(batch.schedule));
    setSaveError(null);
    setModal(batch);
  };

  const toggleDay = (id: WeekdayId) => {
    setScheduleParts((current) => ({
      ...current,
      days: current.days.includes(id)
        ? current.days.filter((day) => day !== id)
        : [...current.days, id],
    }));
  };

  const submit = () => {
    const timeError = scheduleTimeError(scheduleParts);
    if (timeError) {
      setSaveError({ response: { data: { errors: { schedule: [timeError] } } } });
      return;
    }
    saveMutation.mutate({
      ...form,
      end_date: form.end_date || null,
      capacity: form.capacity || null,
      schedule: composeSchedule(scheduleParts) || null,
      notes: form.notes || null,
    });
  };

  if (!isOwner()) {
    return <AccessDenied reason="Course and batch management is only available to superadmins." />;
  }

  if (courseLoading) {
    return (
      <div className="flex items-center justify-center h-48">
        <div className="w-6 h-6 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }

  if (courseError || !course) {
    return (
      <div className="p-8 text-center text-sm text-red-600">
        {apiMessage(courseErr, 'Course not found.')}
        <Link href="/courses" className="block mx-auto mt-2 text-indigo-600">Back to courses</Link>
      </div>
    );
  }

  return (
    <div className="space-y-5">
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <Link href="/courses" className="text-xs text-indigo-600 font-medium">← Courses</Link>
          <h1 className="text-xl font-bold text-gray-900 mt-1">{course.name}</h1>
          <p className="text-sm text-gray-500">{course.code} · {course.branch?.name ?? '—'}</p>
        </div>
        <button
          onClick={openCreate}
          disabled={!course.is_active || course.branch?.is_active === false}
          title={!course.is_active ? 'Inactive courses cannot receive new batches.' : course.branch?.is_active === false ? 'Inactive branches cannot receive new batches.' : undefined}
          className="text-sm font-semibold px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white disabled:opacity-50"
        >
          + Add Batch
        </button>
      </div>

      <CourseSummary course={course} />

      <div className="flex items-center justify-between gap-3 flex-wrap">
        <h2 className="text-base font-semibold text-gray-900">Batches</h2>
        <div className="flex flex-col sm:flex-row gap-2 sm:gap-3 sm:flex-wrap w-full sm:w-auto">
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search batches..."
            className="border border-gray-200 rounded-xl px-3 py-2 text-sm w-full sm:w-56 focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
          <select
            value={status}
            onChange={(e) => setStatus(e.target.value)}
            className="w-full sm:w-auto border border-gray-200 rounded-xl px-3 py-2 text-sm"
          >
            <option value="">All statuses</option>
            {STATUSES.map((s) => (
              <option key={s} value={s}>{s}</option>
            ))}
          </select>
        </div>
      </div>

      <div className="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        {batchesLoading ? (
          <div className="flex items-center justify-center h-40">
            <div className="w-6 h-6 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
          </div>
        ) : batches.length === 0 ? (
          <div className="px-6 py-12 text-center text-gray-400 text-sm">
            No batches added for this course.
            {course.is_active && course.branch?.is_active !== false ? (
              <> <button onClick={openCreate} className="text-indigo-600 hover:underline">Add Batch</button></>
            ) : null}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-100">
              <thead className="bg-gray-50">
                <tr>
                  {['Batch', 'Code', 'Dates', 'Capacity', 'Schedule', 'Status', 'Actions'].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider whitespace-nowrap">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {batches.map((batch) => (
                  <tr key={batch.id} className="hover:bg-gray-50/60">
                    <td className="px-4 py-3 text-sm font-medium text-gray-900 whitespace-nowrap">{batch.name}</td>
                    <td className="px-4 py-3 text-sm text-gray-600">{batch.code}</td>
                    <td className="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                      {batch.start_date}{batch.end_date ? ` – ${batch.end_date}` : ''}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600">{batch.capacity ?? '—'}</td>
                    <td className="px-4 py-3 text-sm text-gray-600 min-w-[160px]">{batch.schedule || '—'}</td>
                    <td className="px-4 py-3">
                      <span className={`text-xs px-2 py-0.5 rounded-full font-medium capitalize ${statusClass(batch.status)}`}>
                        {batch.status}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-2 min-w-[140px]">
                        <button onClick={() => setDetail(batch)} className="text-xs text-indigo-600 font-medium">View Details</button>
                        <button onClick={() => openEdit(batch)} className="text-xs text-indigo-600 font-medium">Edit</button>
                        <button
                          onClick={() => {
                            if (confirm('Delete this batch? This only works if no other records reference it.')) {
                              deleteMutation.mutate(batch.id);
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
            <span className="text-xs text-gray-400">Page {meta.current_page} of {meta.last_page} · {meta.total} batches</span>
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
            <h2 className="text-lg font-semibold text-gray-900 mb-4">{modal ? 'Edit Batch' : 'Add Batch'}</h2>
            {saveError ? (
              <div className="mb-4 px-4 py-3 rounded-xl bg-red-50 text-sm text-red-700">
                {apiMessage(saveError, 'Could not save batch.')}
              </div>
            ) : null}
            <div className="space-y-3">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Course</label>
                  <input readOnly value={course.name} className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm bg-gray-50 text-gray-600" />
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Branch</label>
                  <input readOnly value={course.branch?.name ?? ''} className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm bg-gray-50 text-gray-600" />
                </div>
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Batch name *</label>
                <input
                  value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })}
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                {fieldError(saveError, 'name') && <p className="mt-1 text-xs text-red-600">{fieldError(saveError, 'name')}</p>}
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Batch code *</label>
                <input
                  value={form.code}
                  onChange={(e) => setForm({ ...form, code: e.target.value })}
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                {fieldError(saveError, 'code') && <p className="mt-1 text-xs text-red-600">{fieldError(saveError, 'code')}</p>}
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Start date *</label>
                  <input
                    type="date"
                    value={form.start_date}
                    onChange={(e) => setForm({ ...form, start_date: e.target.value })}
                    className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">End date</label>
                  <input
                    type="date"
                    value={form.end_date ?? ''}
                    onChange={(e) => setForm({ ...form, end_date: e.target.value })}
                    className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                  {fieldError(saveError, 'end_date') && <p className="mt-1 text-xs text-red-600">{fieldError(saveError, 'end_date')}</p>}
                </div>
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Capacity</label>
                <input
                  type="number"
                  min={1}
                  value={form.capacity ?? ''}
                  onChange={(e) => setForm({ ...form, capacity: e.target.value ? Number(e.target.value) : null })}
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
                <p className="mt-1 text-xs text-gray-400">Informational only until admissions is added.</p>
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Schedule / timing</label>
                <div className="flex flex-wrap gap-1.5 mb-2">
                  {WEEKDAYS.map((day) => {
                    const selected = scheduleParts.days.includes(day.id);
                    return (
                      <button
                        key={day.id}
                        type="button"
                        onClick={() => toggleDay(day.id)}
                        className={`px-2.5 py-1.5 rounded-lg text-xs font-medium border ${
                          selected
                            ? 'bg-indigo-600 text-white border-indigo-600'
                            : 'bg-white text-gray-600 border-gray-200 hover:border-indigo-300'
                        }`}
                      >
                        {day.short}
                      </button>
                    );
                  })}
                </div>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-[11px] text-gray-500 mb-1">Start time</label>
                    <input
                      type="time"
                      value={scheduleParts.start}
                      onChange={(e) => setScheduleParts({ ...scheduleParts, start: e.target.value })}
                      className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                  </div>
                  <div>
                    <label className="block text-[11px] text-gray-500 mb-1">End time</label>
                    <input
                      type="time"
                      value={scheduleParts.end}
                      onChange={(e) => setScheduleParts({ ...scheduleParts, end: e.target.value })}
                      className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                  </div>
                </div>
                {composeSchedule(scheduleParts) ? (
                  <p className="mt-1.5 text-xs text-gray-500">{composeSchedule(scheduleParts)}</p>
                ) : (
                  <p className="mt-1.5 text-xs text-gray-400">Optional. Choose days and class timing.</p>
                )}
                {fieldError(saveError, 'schedule') && <p className="mt-1 text-xs text-red-600">{fieldError(saveError, 'schedule')}</p>}
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Status *</label>
                <select
                  value={form.status}
                  onChange={(e) => setForm({ ...form, status: e.target.value as BatchStatus })}
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                  {STATUSES.map((s) => (
                    <option key={s} value={s}>{s}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Notes</label>
                <textarea
                  value={form.notes ?? ''}
                  onChange={(e) => setForm({ ...form, notes: e.target.value })}
                  rows={3}
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>
            </div>
            <div className="flex gap-3 mt-6">
              <button
                onClick={submit}
                disabled={saveMutation.isPending}
                className="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2.5 rounded-xl text-sm disabled:opacity-60"
              >
                {saveMutation.isPending ? 'Saving…' : modal ? 'Save Changes' : 'Create Batch'}
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
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-lg p-5 sm:p-6 max-h-[90dvh] overflow-y-auto">
            <div className="flex items-start justify-between mb-4">
              <div>
                <h2 className="text-lg font-semibold text-gray-900">{detail.name}</h2>
                <p className="text-xs text-gray-400">{detail.code}</p>
              </div>
              <button onClick={() => setDetail(null)} className="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between gap-4"><dt className="text-gray-500">Course</dt><dd className="text-right">{course.name}</dd></div>
              <div className="flex justify-between gap-4"><dt className="text-gray-500">Branch</dt><dd className="text-right">{course.branch?.name ?? '—'}</dd></div>
              <div className="flex justify-between gap-4"><dt className="text-gray-500">Dates</dt><dd className="text-right">{detail.start_date}{detail.end_date ? ` – ${detail.end_date}` : ''}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500">Capacity</dt><dd>{detail.capacity ?? '—'}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500">Schedule</dt><dd className="text-right max-w-[60%]">{detail.schedule || '—'}</dd></div>
              <div className="flex justify-between capitalize"><dt className="text-gray-500">Status</dt><dd>{detail.status}</dd></div>
            </dl>
            {detail.notes ? <p className="mt-4 text-sm text-gray-600">{detail.notes}</p> : null}
          </div>
        </div>
      )}
    </div>
  );
}

function CourseSummary({ course }: { course: Course }) {
  return (
    <div className="bg-white rounded-2xl border border-gray-200 p-4 sm:p-5 grid grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
      <div>
        <p className="text-xs text-gray-400">Branch</p>
        <p className="font-medium text-gray-900">{course.branch?.name ?? '—'}</p>
      </div>
      <div>
        <p className="text-xs text-gray-400">Duration</p>
        <p className="font-medium text-gray-900">{course.duration || '—'}</p>
      </div>
      <div>
        <p className="text-xs text-gray-400">Status</p>
        <p className="font-medium text-gray-900">{course.is_active ? 'Active' : 'Inactive'}</p>
      </div>
      <div>
        <p className="text-xs text-gray-400">Batches</p>
        <p className="font-medium text-gray-900">{course.batches_count ?? 0}</p>
      </div>
      {course.description ? (
        <div className="col-span-2 lg:col-span-4">
          <p className="text-xs text-gray-400">Description</p>
          <p className="text-gray-700">{course.description}</p>
        </div>
      ) : null}
    </div>
  );
}
