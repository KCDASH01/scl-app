'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import { admissionsApi } from '@/lib/api/admissions';
import { coursesApi } from '@/lib/api/courses';
import { useBranchStore } from '@/store/branchStore';
import { keepPreviousIfSameBranch } from '@/lib/queryBranch';
import type { Admission, AdmissionStatus } from '@/types';

interface ApiError {
  response?: { data?: { message?: string } };
}

function apiMessage(err: unknown, fallback: string) {
  return (err as ApiError)?.response?.data?.message || fallback;
}

function statusClass(status: AdmissionStatus) {
  return status === 'admitted' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700';
}

function formatDate(value?: string | null) {
  if (!value) return '—';
  return new Date(`${value}T00:00:00`).toLocaleDateString('en-IN', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  });
}

export default function AdmissionsPage() {
  const selectedBranchId = useBranchStore((s) => s.selectedBranchId);
  const assignBranchId = selectedBranchId !== 'all' ? Number(selectedBranchId) : undefined;

  const [search, setSearch] = useState('');
  const [courseId, setCourseId] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  useEffect(() => { setPage(1); }, [search, courseId, status, selectedBranchId]);

  const listParams = {
    search: search || undefined,
    course_id: courseId ? Number(courseId) : undefined,
    status: status || undefined,
    page,
    per_page: 20,
  };

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['admissions', selectedBranchId, listParams],
    queryFn: () => admissionsApi.list(listParams),
    placeholderData: keepPreviousIfSameBranch(selectedBranchId),
  });

  const { data: courses = [] } = useQuery({
    queryKey: ['courses', 'options', assignBranchId],
    queryFn: () => coursesApi.options({ branch_id: assignBranchId }),
    staleTime: 5 * 60_000,
  });

  const rows: Admission[] = data?.data ?? [];
  const meta = data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex flex-col sm:flex-row sm:items-end gap-3">
        <div className="flex-1">
          <label className="block text-xs font-medium text-gray-500 mb-1">Search</label>
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Enrollment ID, name, phone, email"
            className="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>
        <div className="sm:w-56">
          <label className="block text-xs font-medium text-gray-500 mb-1">Course</label>
          <select
            value={courseId}
            onChange={(e) => setCourseId(e.target.value)}
            className="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >
            <option value="">All courses</option>
            {courses.map((c) => (
              <option key={c.id} value={c.id}>{c.name} ({c.code})</option>
            ))}
          </select>
        </div>
        <div className="sm:w-40">
          <label className="block text-xs font-medium text-gray-500 mb-1">Status</label>
          <select
            value={status}
            onChange={(e) => setStatus(e.target.value)}
            className="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >
            <option value="">All</option>
            <option value="pending">Pending</option>
            <option value="admitted">Admitted</option>
          </select>
        </div>
      </div>

      <div className="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        {isError ? (
          <div className="p-8 text-center text-sm text-red-600">
            {apiMessage(error, 'Failed to load admissions.')}
            <button onClick={() => refetch()} className="block mx-auto mt-2 text-indigo-600">Retry</button>
          </div>
        ) : isLoading ? (
          <div className="flex items-center justify-center h-48">
            <div className="w-6 h-6 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
          </div>
        ) : rows.length === 0 ? (
          <div className="px-6 py-12 text-center text-gray-400 text-sm">
            No converted students yet. Convert a lead to create an admission.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-[720px] w-full divide-y divide-gray-100">
              <thead className="bg-gray-50">
                <tr>
                  {['Enrollment ID', 'Student', 'Course', 'Batch', 'Admission date', 'Status', ''].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider whitespace-nowrap">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {rows.map((row) => (
                  <tr key={row.id} className="hover:bg-gray-50/60">
                    <td className="px-4 py-3 text-sm font-medium text-gray-900 whitespace-nowrap">{row.enrollment_id}</td>
                    <td className="px-4 py-3">
                      <div className="text-sm font-medium text-gray-900">{row.lead?.full_name ?? '—'}</div>
                      <div className="text-xs text-gray-400">{row.lead?.phone || row.lead?.email || ''}</div>
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                      {row.course ? `${row.course.name} (${row.course.code})` : '—'}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                      {row.batch ? `${row.batch.name} (${row.batch.code})` : '—'}
                    </td>
                    <td className="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">{formatDate(row.admission_date)}</td>
                    <td className="px-4 py-3">
                      <span className={`text-xs px-2 py-0.5 rounded-full font-medium capitalize ${statusClass(row.status)}`}>
                        {row.status}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <Link href={`/admissions/${row.id}`} className="text-xs text-indigo-600 font-medium">
                        Open
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {meta && meta.last_page > 1 && (
          <div className="px-4 py-3 border-t border-gray-100 flex flex-wrap items-center justify-between gap-2">
            <span className="text-xs text-gray-400">Page {meta.current_page} of {meta.last_page} · {meta.total} admissions</span>
            <div className="flex gap-2">
              <button onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page === 1} className="text-xs px-3 py-1.5 border border-gray-200 rounded-lg disabled:opacity-40">Previous</button>
              <button onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))} disabled={page === meta.last_page} className="text-xs px-3 py-1.5 border border-gray-200 rounded-lg disabled:opacity-40">Next</button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
