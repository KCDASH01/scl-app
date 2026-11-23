'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { keepPreviousIfSameBranch } from '@/lib/queryBranch';
import { reportsApi } from '@/lib/api/reports';
import { employeesApi } from '@/lib/api/employees';
import { coursesApi } from '@/lib/api/courses';
import { VISIBLE_LEAD_STATUSES, LEAD_STATUS_LABELS } from '@/lib/leadStatuses';
import { Badge } from '@/components/ui/Badge';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { useBranchStore } from '@/store/branchStore';
import type { LeadReportFilters } from '@/types';

function fmtDate(iso?: string | null) {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
}

function fmtDateTime(iso?: string | null) {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('en-IN', {
    day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
  });
}

const SUMMARY_CARDS = [
  { key: 'total',         label: 'Total Leads',    color: 'text-indigo-600',  bg: 'bg-indigo-50'  },
  { key: 'new',           label: 'New Call',      color: 'text-blue-600',    bg: 'bg-blue-50'    },
  { key: 'contacted',     label: 'Contacted',      color: 'text-sky-600',     bg: 'bg-sky-50'     },
  { key: 'ringing',       label: 'Call Again',        color: 'text-emerald-600', bg: 'bg-emerald-50' },
  { key: 'proposal_sent', label: 'Proposal Sent',  color: 'text-violet-600',  bg: 'bg-violet-50'  },
  { key: 'won',           label: 'Admitted',      color: 'text-green-600',   bg: 'bg-green-50'   },
  { key: 'lost',          label: 'Lost',           color: 'text-red-600',     bg: 'bg-red-50'     },
] as const;

const inputCls = 'border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500';
const filterCls = `${inputCls} w-full sm:w-auto min-w-0`;

interface Props {
  assignedToFilter?: number;
  canFilterByMember?: boolean;
}

export function LeadReportTab({ assignedToFilter, canFilterByMember = true }: Props) {
  const selectedBranchId = useBranchStore((s) => s.selectedBranchId);
  const [search, setSearch]           = useState('');
  const [status, setStatus]             = useState('');
  const [courseId, setCourseId]         = useState('');
  const [assignedTo, setAssignedTo]     = useState('');
  const [dateFrom, setDateFrom]         = useState('');
  const [dateTo, setDateTo]             = useState('');
  const [page, setPage]                 = useState(1);
  const [exporting, setExporting]       = useState<'csv' | 'xlsx' | null>(null);
  const assignBranchId = selectedBranchId !== 'all' ? Number(selectedBranchId) : undefined;

  const filters: LeadReportFilters = {
    search:      search || undefined,
    status:      status || undefined,
    course_id:   courseId ? Number(courseId) : undefined,
    assigned_to: canFilterByMember
      ? (assignedTo ? Number(assignedTo) : undefined)
      : assignedToFilter,
    date_from:   dateFrom || undefined,
    date_to:     dateTo || undefined,
    page,
    per_page:    20,
  };

  const { data: employees } = useQuery({
    queryKey: ['employees', selectedBranchId, { role: 'employee' }],
    queryFn:  () => employeesApi.list({ role: 'employee' }),
    staleTime: 5 * 60_000,
    enabled:  canFilterByMember,
  });

  const { data: courses = [] } = useQuery({
    queryKey: ['courses', 'options', assignBranchId],
    queryFn:  () => coursesApi.options({ branch_id: assignBranchId }),
    staleTime: 5 * 60_000,
  });

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['reports-leads', selectedBranchId, filters],
    queryFn:  () => reportsApi.leadReport(filters),
    placeholderData: keepPreviousIfSameBranch(selectedBranchId),
  });

  const summary = data?.data.summary;
  const rows    = data?.data.rows ?? [];
  const meta    = data?.meta;

  const handleExport = async (format: 'csv' | 'xlsx') => {
    setExporting(format);
    try {
      const { page: _page, per_page: _perPage, ...exportFilters } = filters;
      await reportsApi.exportLeadReport(exportFilters, format);
    } finally {
      setExporting(null);
    }
  };

  const clearFilters = () => {
    setSearch('');
    setStatus('');
    setCourseId('');
    setAssignedTo('');
    setDateFrom('');
    setDateTo('');
    setPage(1);
  };

  const hasFilters = !!(search || status || courseId || assignedTo || dateFrom || dateTo);

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h2 className="text-sm font-semibold text-gray-900">Lead Report</h2>
          <p className="text-xs text-gray-400 mt-0.5">
            {canFilterByMember
              ? 'Student leads with course, enrollment, and conversion status'
              : 'Your assigned student leads with filters and export'}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <button
            onClick={() => handleExport('csv')}
            disabled={!!exporting}
            className="px-3 py-2 text-xs font-semibold border border-gray-200 rounded-xl hover:bg-gray-50 disabled:opacity-60"
          >
            {exporting === 'csv' ? 'Exporting…' : 'Export CSV'}
          </button>
          <button
            onClick={() => handleExport('xlsx')}
            disabled={!!exporting}
            className="px-3 py-2 text-xs font-semibold bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 disabled:opacity-60"
          >
            {exporting === 'xlsx' ? 'Exporting…' : 'Export Excel'}
          </button>
        </div>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 gap-3">
        {SUMMARY_CARDS.map((card) => (
          <div key={card.key} className={`${card.bg} rounded-2xl p-4 border border-white`}>
            <p className="text-[10px] font-semibold text-gray-500 uppercase tracking-wide">{card.label}</p>
            <p className={`text-xl font-bold mt-1 ${card.color}`}>
              {(summary?.[card.key] ?? 0).toLocaleString()}
            </p>
          </div>
        ))}
      </div>

      <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:gap-3 sm:items-center">
        <input
          type="search"
          placeholder="Search name, phone, enrollment…"
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1); }}
          className={`${inputCls} col-span-2 w-full sm:w-64`}
        />
        <select value={courseId} onChange={(e) => { setCourseId(e.target.value); setPage(1); }} className={filterCls}>
          <option value="">All courses</option>
          {courses.map((c) => (
            <option key={c.id} value={c.id}>{c.name}</option>
          ))}
        </select>
        <select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} className={filterCls}>
          <option value="">All statuses</option>
          {VISIBLE_LEAD_STATUSES.map((s) => (
            <option key={s} value={s}>{LEAD_STATUS_LABELS[s]}</option>
          ))}
        </select>
        {canFilterByMember && (
          <select value={assignedTo} onChange={(e) => { setAssignedTo(e.target.value); setPage(1); }} className={filterCls}>
            <option value="">All members</option>
            {(employees ?? []).map((emp) => (
              <option key={emp.id} value={emp.id}>{emp.name}</option>
            ))}
          </select>
        )}
        <input type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(1); }} className={filterCls} />
        <input type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(1); }} className={filterCls} />
        {hasFilters && (
          <button onClick={clearFilters} className="px-3 py-2 text-xs font-medium text-gray-600 border border-gray-200 rounded-xl hover:bg-gray-50">
            Clear filters
          </button>
        )}
        {isFetching && !isLoading && (
          <span className="text-xs text-gray-400">Updating…</span>
        )}
      </div>

      <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        {isLoading ? (
          <SkeletonTable rows={8} cols={8} />
        ) : rows.length === 0 ? (
          <div className="px-6 py-16 text-center text-sm text-gray-400">No leads match your filters.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead>
                <tr className="bg-gray-50 border-b border-gray-100">
                  {['Branch', 'Student', 'Enrollment', 'Course', 'Batch', 'Phone', 'Email', 'Assigned', 'Source', 'Status', 'Created', 'Follow-up'].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-[11px] font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {rows.map((row) => (
                  <tr key={row.id} className="hover:bg-gray-50/60">
                    <td className="px-4 py-3 text-gray-600 whitespace-nowrap">{row.branch ?? '—'}</td>
                    <td className="px-4 py-3 font-medium text-gray-900 whitespace-nowrap">{row.student_name ?? row.lead_name}</td>
                    <td className="px-4 py-3 text-gray-600 whitespace-nowrap">{row.enrollment_id ?? '—'}</td>
                    <td className="px-4 py-3 text-gray-600 whitespace-nowrap">{row.course ?? '—'}</td>
                    <td className="px-4 py-3 text-gray-600 whitespace-nowrap">{row.batch ?? '—'}</td>
                    <td className="px-4 py-3 text-gray-600 whitespace-nowrap">{row.phone ?? '—'}</td>
                    <td className="px-4 py-3 text-gray-600 whitespace-nowrap">{row.email ?? '—'}</td>
                    <td className="px-4 py-3 text-gray-600 whitespace-nowrap">{row.assigned_member ?? 'Unassigned'}</td>
                    <td className="px-4 py-3 text-gray-600 whitespace-nowrap capitalize">{row.source?.replace(/_/g, ' ') ?? '—'}</td>
                    <td className="px-4 py-3"><Badge value={row.status} /></td>
                    <td className="px-4 py-3 text-gray-500 whitespace-nowrap">{fmtDate(row.created_at)}</td>
                    <td className="px-4 py-3 text-gray-500 whitespace-nowrap">{fmtDateTime(row.follow_up_date)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {meta && meta.last_page > 1 && (
          <div className="px-4 sm:px-5 py-4 border-t border-gray-100 flex flex-wrap items-center justify-between gap-2">
            <span className="text-xs text-gray-400">Page {meta.current_page} of {meta.last_page} · {meta.total} leads</span>
            <div className="flex gap-2">
              <button onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page === 1}
                className="px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-medium disabled:opacity-40 hover:bg-gray-50">← Prev</button>
              <button onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))} disabled={page === meta.last_page}
                className="px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-medium disabled:opacity-40 hover:bg-gray-50">Next →</button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
