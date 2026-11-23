'use client';

import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { staffAttendanceApi } from '@/lib/api/staffAttendance';
import { useAuthStore } from '@/store/authStore';
import { useBranchStore } from '@/store/branchStore';
import { AccessDenied } from '@/components/ui/AccessDenied';
import { roleLabel } from '@/lib/roles';
import type { AttendanceStatus, StaffAttendanceEmployee, StaffAttendanceSession } from '@/types';

interface ApiError {
  response?: { data?: { message?: string; errors?: Record<string, string[]> } };
}

function apiMessage(err: unknown, fallback: string) {
  const data = (err as ApiError)?.response?.data;
  if (data?.message) return data.message;
  const first = data?.errors ? Object.values(data.errors).flat()[0] : null;
  return first || fallback;
}

function pad(n: number) {
  return String(n).padStart(2, '0');
}

function todayDate() {
  const d = new Date();
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

function nowTime() {
  const d = new Date();
  return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function toMarkedAt(date: string, time: string) {
  return `${date} ${time}:00`;
}

function formatMarkedAt(value?: string | null) {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '—';
  return `${pad(d.getDate())}-${pad(d.getMonth() + 1)}-${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function statusClass(status?: AttendanceStatus | null) {
  if (status === 'present') return 'bg-emerald-100 text-emerald-700';
  if (status === 'absent') return 'bg-rose-100 text-rose-700';
  return 'bg-gray-100 text-gray-500';
}

const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500';

export default function StaffAttendancePage() {
  const qc = useQueryClient();
  const canManage = useAuthStore((s) => s.canManageTeam());
  const selectedBranchId = useBranchStore((s) => s.selectedBranchId);
  const [date, setDate] = useState(todayDate);
  const [time, setTime] = useState(nowTime);
  const [search, setSearch] = useState('');
  const [checked, setChecked] = useState<Set<number>>(new Set());
  const [error, setError] = useState('');

  const today = todayDate();
  const dateInFuture = date > today;
  const timeInFuture = !dateInFuture && date === today && time > nowTime();

  const { data: session, isLoading, isFetching } = useQuery({
    queryKey: ['staff-attendance', selectedBranchId, date],
    queryFn: () => staffAttendanceApi.list(date),
    enabled: canManage && !!date && !dateInFuture,
  });

  useEffect(() => {
    if (!session) return;
    setChecked(new Set(session.employees.filter((row) => row.status === 'present').map((row) => row.user_id)));
    const stamped = session.employees.find((row) => row.marked_at)?.marked_at;
    if (stamped) {
      const saved = new Date(stamped);
      if (!Number.isNaN(saved.getTime())) {
        setTime(`${pad(saved.getHours())}:${pad(saved.getMinutes())}`);
      }
    }
  }, [session]);

  const employees = session?.employees ?? [];
  const visible = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return employees;
    return employees.filter((row) =>
      [row.name, row.email, row.phone, row.branch_name, roleLabel(row.role)]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(q))
    );
  }, [employees, search]);

  const visibleIds = visible.map((row) => row.user_id);
  const allVisibleChecked = visibleIds.length > 0 && visibleIds.every((id) => checked.has(id));

  const applySession = (data: StaffAttendanceSession) => {
    setError('');
    qc.setQueryData(['staff-attendance', selectedBranchId, date], data);
  };

  const markMutation = useMutation({
    mutationFn: () => staffAttendanceApi.mark({
      date,
      marked_at: toMarkedAt(date, time),
      present_user_ids: Array.from(checked),
    }),
    onSuccess: applySession,
    onError: (err) => setError(apiMessage(err, 'Failed to save attendance.')),
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: AttendanceStatus }) =>
      staffAttendanceApi.update(id, { status, marked_at: toMarkedAt(date, time) }),
    onSuccess: (row) => {
      setError('');
      qc.setQueryData(
        ['staff-attendance', selectedBranchId, date],
        (prev: StaffAttendanceSession | undefined) => {
          if (!prev) return prev;
          const employees = prev.employees.map((item) => (item.user_id === row.user_id ? row : item));
          return {
            ...prev,
            employees,
            is_marked: employees.some((item) => item.attendance_id),
            present_count: employees.filter((item) => item.status === 'present').length,
            absent_count: employees.filter((item) => item.status === 'absent').length,
          };
        }
      );
      setChecked((prev) => {
        const next = new Set(prev);
        if (row.status === 'present') next.add(row.user_id);
        else next.delete(row.user_id);
        return next;
      });
    },
    onError: (err) => setError(apiMessage(err, 'Failed to update status.')),
  });

  const toggleOne = (id: number) => {
    setChecked((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const toggleAllVisible = () => {
    setChecked((prev) => {
      const next = new Set(prev);
      if (allVisibleChecked) visibleIds.forEach((id) => next.delete(id));
      else visibleIds.forEach((id) => next.add(id));
      return next;
    });
  };

  const flipStatus = (row: StaffAttendanceEmployee) => {
    if (!row.attendance_id || !row.status) return;
    updateMutation.mutate({
      id: row.attendance_id,
      status: row.status === 'present' ? 'absent' : 'present',
    });
  };

  const onDateChange = (value: string) => {
    if (value && value > todayDate()) {
      setError('Attendance cannot be marked for a future date.');
      return;
    }
    setError('');
    setDate(value);
  };

  const onTimeChange = (value: string) => {
    if (date === todayDate() && value > nowTime()) {
      setError('Attendance time cannot be in the future.');
      return;
    }
    setError('');
    setTime(value);
  };

  if (!canManage) {
    return <AccessDenied reason="Staff attendance can be marked by superadmins and branch managers." />;
  }

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-xl font-bold text-gray-900">Staff Attendance</h1>
        <p className="text-xs text-gray-400 mt-0.5">
          Mark each employee present or absent once for a date. You can update that day, including earlier dates. Future dates are not allowed.
        </p>
      </div>

      <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 max-w-xl">
          <div>
            <label className="block text-xs font-medium text-gray-500 mb-1">Date</label>
            <input
              type="date"
              value={date}
              max={today}
              onChange={(e) => onDateChange(e.target.value)}
              className={inputCls}
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500 mb-1">Time</label>
            <input
              type="time"
              value={time}
              max={date === today ? nowTime() : undefined}
              onChange={(e) => onTimeChange(e.target.value)}
              className={inputCls}
            />
          </div>
        </div>
        <p className="text-xs text-gray-400 mt-3">
          Only employees are listed. A superadmin sees every employee. A branch manager sees the employees assigned to them.
        </p>
      </div>

      {error && (
        <div className="bg-rose-50 border border-rose-100 text-rose-700 text-sm rounded-xl px-4 py-3">{error}</div>
      )}

      {isLoading ? (
        <div className="bg-white rounded-2xl border border-gray-100 flex items-center justify-center h-48">
          <div className="w-6 h-6 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
        </div>
      ) : (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
          <div className="px-4 py-3 border-b border-gray-100 flex flex-wrap items-center gap-3">
            <input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search name, email, phone…"
              className={`${inputCls} sm:w-72`}
            />
            <label className="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
              <input
                type="checkbox"
                checked={allVisibleChecked}
                onChange={toggleAllVisible}
                className="w-4 h-4 rounded border-gray-300 text-indigo-600"
              />
              Select all
            </label>
            <div className="sm:ml-auto flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
              {isFetching && !isLoading && <span>Updating…</span>}
              <span>{checked.size} selected present</span>
              {session?.is_marked && (
                <span>{session.present_count} present · {session.absent_count} absent</span>
              )}
            </div>
            <button
              type="button"
              onClick={() => markMutation.mutate()}
              disabled={markMutation.isPending || employees.length === 0 || dateInFuture || timeInFuture}
              className="w-full sm:w-auto px-4 py-2 text-sm font-semibold bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 disabled:opacity-50"
            >
              {markMutation.isPending ? 'Saving…' : session?.is_marked ? 'Update attendance' : 'Mark as present'}
            </button>
          </div>

          {employees.length === 0 ? (
            <div className="px-6 py-16 text-center text-sm text-gray-400">
              No employees to mark.
            </div>
          ) : visible.length === 0 ? (
            <div className="px-6 py-12 text-center text-sm text-gray-400">No employees match your search.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-[720px] w-full text-sm">
                <thead>
                  <tr className="bg-gray-50 border-b border-gray-100">
                    {['Present', 'Employee', 'Role', 'Branch', 'Phone', 'Status', 'Date & time', ''].map((heading) => (
                      <th key={heading} className="px-4 py-3 text-left text-[11px] font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">{heading}</th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {visible.map((row) => (
                    <tr key={row.user_id} className="hover:bg-gray-50/60">
                      <td className="px-4 py-3">
                        <input
                          type="checkbox"
                          checked={checked.has(row.user_id)}
                          onChange={() => toggleOne(row.user_id)}
                          aria-label={`Present ${row.name}`}
                          className="w-4 h-4 rounded border-gray-300 text-indigo-600"
                        />
                      </td>
                      <td className="px-4 py-3">
                        <div className="font-medium text-gray-900">{row.name}</div>
                        {row.email && <div className="text-xs text-gray-400">{row.email}</div>}
                      </td>
                      <td className="px-4 py-3 text-gray-600 whitespace-nowrap">{roleLabel(row.role)}</td>
                      <td className="px-4 py-3 text-gray-600 whitespace-nowrap">{row.branch_name ?? '—'}</td>
                      <td className="px-4 py-3 text-gray-600 whitespace-nowrap">{row.phone ?? '—'}</td>
                      <td className="px-4 py-3">
                        <span className={`text-xs px-2 py-0.5 rounded-full font-medium capitalize ${statusClass(row.status)}`}>
                          {row.status ?? 'not marked'}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-gray-600 whitespace-nowrap">{formatMarkedAt(row.marked_at)}</td>
                      <td className="px-4 py-3">
                        {row.attendance_id && (
                          <button
                            type="button"
                            onClick={() => flipStatus(row)}
                            disabled={updateMutation.isPending}
                            className="text-xs font-semibold text-indigo-600 hover:text-indigo-800 disabled:opacity-50 whitespace-nowrap"
                          >
                            Mark {row.status === 'present' ? 'absent' : 'present'}
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      <p className="text-xs text-gray-400">
        Checked employees are saved as present. Unchecked employees on this list are saved as absent for the selected date. Saving the same date again updates that attendance.
      </p>
    </div>
  );
}
