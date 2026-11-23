'use client';

import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { attendanceApi } from '@/lib/api/attendance';
import { batchesApi, coursesApi } from '@/lib/api/courses';
import { useBranchStore } from '@/store/branchStore';
import type { AttendanceStatus, AttendanceStudent } from '@/types';

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

function statusClass(status?: AttendanceStatus | null) {
  if (status === 'present') return 'bg-emerald-100 text-emerald-700';
  if (status === 'absent') return 'bg-rose-100 text-rose-700';
  return 'bg-gray-100 text-gray-500';
}

const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500';

export default function AttendancePage() {
  const qc = useQueryClient();
  const selectedBranchId = useBranchStore((s) => s.selectedBranchId);
  const assignBranchId = selectedBranchId !== 'all' ? Number(selectedBranchId) : undefined;

  const [courseId, setCourseId] = useState('');
  const [subjectId, setSubjectId] = useState('');
  const [batchId, setBatchId] = useState('');
  const [date, setDate] = useState(todayDate);
  const [time, setTime] = useState(nowTime);
  const [search, setSearch] = useState('');
  const [checked, setChecked] = useState<Set<number>>(new Set());
  const [initialized, setInitialized] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    setCourseId('');
    setSubjectId('');
    setBatchId('');
    setChecked(new Set());
    setInitialized(false);
    setSearch('');
  }, [selectedBranchId]);

  const { data: courses = [] } = useQuery({
    queryKey: ['courses', 'options', assignBranchId],
    queryFn: () => coursesApi.options({ branch_id: assignBranchId }),
    staleTime: 5 * 60_000,
  });

  const { data: batches = [] } = useQuery({
    queryKey: ['batches', 'options', assignBranchId, courseId],
    queryFn: () => batchesApi.options({
      branch_id: assignBranchId,
      course_id: courseId ? Number(courseId) : undefined,
    }),
    enabled: !!courseId,
    staleTime: 5 * 60_000,
  });

  const { data: courseSubjects = [], isSuccess: subjectsLoaded } = useQuery({
    queryKey: ['attendance-subjects', courseId],
    queryFn: () => attendanceApi.subjects(Number(courseId)),
    enabled: !!courseId,
  });
  const subjectRequired = subjectsLoaded && courseSubjects.length > 0;
  const today = todayDate();
  const dateInFuture = !!date && date > today;
  const canLoad = !!courseId && !!batchId && !!date && !dateInFuture && subjectsLoaded && (!subjectRequired || !!subjectId);

  const attendanceKey = ['attendance-students', selectedBranchId, courseId, batchId, subjectId, date] as const;

  const { data: session, isLoading, isFetching } = useQuery({
    queryKey: attendanceKey,
    queryFn: () => attendanceApi.students({
      course_id: Number(courseId),
      batch_id: Number(batchId),
      date,
      subject_id: subjectId ? Number(subjectId) : undefined,
    }),
    enabled: canLoad,
  });

  useEffect(() => {
    if (!session) {
      setInitialized(false);
      return;
    }
    const present = new Set(
      session.students.filter((s) => s.status === 'present').map((s) => s.admission_id)
    );
    setChecked(present);
    setInitialized(true);
  }, [session]);

  const students = session?.students ?? [];
  const visible = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return students;
    return students.filter((s) =>
      [s.enrollment_id, s.student_name, s.phone, s.email]
        .filter(Boolean)
        .some((v) => String(v).toLowerCase().includes(q))
    );
  }, [students, search]);

  const visibleIds = visible.map((s) => s.admission_id);
  const allVisibleChecked = visibleIds.length > 0 && visibleIds.every((id) => checked.has(id));
  const presentSelected = checked.size;

  const markMutation = useMutation({
    mutationFn: () => attendanceApi.mark({
      course_id: Number(courseId),
      batch_id: Number(batchId),
      date,
      subject_id: subjectId ? Number(subjectId) : undefined,
      marked_at: toMarkedAt(date, time),
      present_admission_ids: Array.from(checked),
    }),
    onSuccess: (data) => {
      setError('');
      qc.setQueryData(attendanceKey, data);
    },
    onError: (err) => setError(apiMessage(err, 'Failed to save attendance.')),
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: AttendanceStatus }) =>
      attendanceApi.update(id, { status, marked_at: toMarkedAt(date, time) }),
    onSuccess: (row) => {
      setError('');
      qc.setQueryData(
        attendanceKey,
        (prev: typeof session) => {
          if (!prev) return prev;
          return {
            ...prev,
            students: prev.students.map((s) => (s.admission_id === row.admission_id ? row : s)),
            present_count: prev.students.map((s) => (s.admission_id === row.admission_id ? row : s))
              .filter((s) => s.status === 'present').length,
            absent_count: prev.students.map((s) => (s.admission_id === row.admission_id ? row : s))
              .filter((s) => s.status === 'absent').length,
            is_marked: true,
          };
        }
      );
      setChecked((prev) => {
        const next = new Set(prev);
        if (row.status === 'present') next.add(row.admission_id);
        else next.delete(row.admission_id);
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
      if (allVisibleChecked) {
        visibleIds.forEach((id) => next.delete(id));
      } else {
        visibleIds.forEach((id) => next.add(id));
      }
      return next;
    });
  };

  const flipStatus = (student: AttendanceStudent) => {
    if (!student.attendance_id) return;
    const next: AttendanceStatus = student.status === 'present' ? 'absent' : 'present';
    updateMutation.mutate({ id: student.attendance_id, status: next });
  };

  const hasSelection = !!courseId || !!batchId || !!subjectId || !!search || date !== todayDate();

  const clearSelection = () => {
    setCourseId('');
    setSubjectId('');
    setBatchId('');
    setDate(todayDate());
    setTime(nowTime());
    setSearch('');
    setChecked(new Set());
    setInitialized(false);
    setError('');
  };

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-xl font-bold text-gray-900">Student Attendance</h1>
        <p className="text-xs text-gray-400 mt-0.5">
          Select a course and batch, then mark students present. Unchecked students are saved as absent. If the course has subjects, attendance is taken once for each subject on that day.
        </p>
      </div>

      <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
        <div className={`grid grid-cols-1 sm:grid-cols-2 ${subjectRequired ? 'lg:grid-cols-5' : 'lg:grid-cols-4'} gap-3`}>
          <div>
            <label className="block text-xs font-medium text-gray-500 mb-1">Course</label>
            <select
              value={courseId}
              onChange={(e) => { setCourseId(e.target.value); setSubjectId(''); setBatchId(''); setInitialized(false); }}
              className={inputCls}
            >
              <option value="">Select course</option>
              {courses.map((c) => (
                <option key={c.id} value={c.id}>{c.name} ({c.code})</option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500 mb-1">Batch</label>
            <select
              value={batchId}
              onChange={(e) => { setBatchId(e.target.value); setInitialized(false); }}
              disabled={!courseId}
              className={`${inputCls} disabled:bg-gray-50`}
            >
              <option value="">{courseId ? 'Select batch' : 'Select a course first'}</option>
              {batches.map((b) => (
                <option key={b.id} value={b.id}>{b.name} ({b.code})</option>
              ))}
            </select>
          </div>
          {subjectRequired && (
            <div>
              <label className="block text-xs font-medium text-gray-500 mb-1">Subject</label>
              <select
                value={subjectId}
                onChange={(e) => { setSubjectId(e.target.value); setInitialized(false); }}
                className={inputCls}
              >
                <option value="">Select subject</option>
                {courseSubjects.map((subject) => (
                  <option key={subject.id} value={subject.id}>{subject.name} ({subject.code})</option>
                ))}
              </select>
            </div>
          )}
          <div>
            <label className="block text-xs font-medium text-gray-500 mb-1">Date</label>
            <input
              type="date"
              value={date}
              max={today}
              onChange={(e) => {
                const next = e.target.value;
                if (next && next > todayDate()) return;
                setDate(next);
              }}
              className={inputCls}
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500 mb-1">Time</label>
            <input type="time" value={time} onChange={(e) => setTime(e.target.value)} className={inputCls} />
          </div>
        </div>
        <div className="flex flex-wrap items-center justify-between gap-3 mt-3">
          {session?.branch ? (
            <p className="text-xs text-gray-400">
              Branch: <span className="font-medium text-gray-600">{session.branch.name}</span>
              {session.course && <> · {session.course.name}</>}
              {session.batch && <> · {session.batch.name}</>}
              {session.subject && <> · {session.subject.name}</>}
            </p>
          ) : <span />}
          <button
            type="button"
            onClick={clearSelection}
            disabled={!hasSelection}
            className="text-sm font-medium px-4 py-2 rounded-xl border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-40 whitespace-nowrap"
          >
            Clear
          </button>
        </div>
      </div>

      {error && (
        <div className="bg-rose-50 border border-rose-100 text-rose-700 text-sm rounded-xl px-4 py-3">{error}</div>
      )}

      {dateInFuture ? (
        <div className="bg-rose-50 border border-rose-100 text-rose-700 text-sm rounded-xl px-4 py-3">
          Attendance cannot be marked for a future date.
        </div>
      ) : !canLoad ? (
        <div className="bg-white rounded-2xl border border-gray-100 px-6 py-16 text-center text-sm text-gray-400">
          {subjectRequired && !subjectId
            ? 'Select a subject to load students. Each subject is marked separately for this date.'
            : 'Select a course and batch to load students.'}
        </div>
      ) : isLoading ? (
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
              placeholder="Search enrollment, name, phone…"
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
              <span>{presentSelected} selected present</span>
              {session?.is_marked && (
                <span>{session.present_count} present · {session.absent_count} absent</span>
              )}
            </div>
            <button
              onClick={() => markMutation.mutate()}
              disabled={markMutation.isPending || students.length === 0 || dateInFuture}
              className="w-full sm:w-auto px-4 py-2 text-sm font-semibold bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 disabled:opacity-50"
            >
              {markMutation.isPending ? 'Saving…' : session?.is_marked ? 'Update attendance' : 'Mark as present'}
            </button>
          </div>

          {students.length === 0 ? (
            <div className="px-6 py-16 text-center text-sm text-gray-400">
              No admitted students in this batch. Allocate a batch on Student Admission first.
            </div>
          ) : visible.length === 0 ? (
            <div className="px-6 py-12 text-center text-sm text-gray-400">No students match your search.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-[640px] w-full text-sm">
                <thead>
                  <tr className="bg-gray-50 border-b border-gray-100">
                    {['Present', 'Enrollment ID', 'Student', 'Phone', 'Status', ''].map((h) => (
                      <th key={h} className="px-4 py-3 text-left text-[11px] font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {visible.map((row) => (
                    <tr key={row.admission_id} className="hover:bg-gray-50/60">
                      <td className="px-4 py-3">
                        <input
                          type="checkbox"
                          checked={checked.has(row.admission_id)}
                          onChange={() => toggleOne(row.admission_id)}
                          className="w-4 h-4 rounded border-gray-300 text-indigo-600"
                        />
                      </td>
                      <td className="px-4 py-3 font-medium text-gray-900 whitespace-nowrap">{row.enrollment_id}</td>
                      <td className="px-4 py-3">
                        <div className="font-medium text-gray-900">{row.student_name ?? '—'}</div>
                        {row.email && <div className="text-xs text-gray-400">{row.email}</div>}
                      </td>
                      <td className="px-4 py-3 text-gray-600 whitespace-nowrap">{row.phone ?? '—'}</td>
                      <td className="px-4 py-3">
                        <span className={`text-xs px-2 py-0.5 rounded-full font-medium capitalize ${statusClass(row.status)}`}>
                          {row.status ?? 'not marked'}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        {row.attendance_id && (
                          <button
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

      {canLoad && !isLoading && (
        <p className="text-xs text-gray-400">
          Checked students are saved as present. Unchecked students in this batch become absent.
          {subjectRequired ? ' Saving again for the same subject and date updates that attendance. Other subjects on this date stay separate.' : ' This course has no subjects, so attendance is saved once for the day.'}
          {initialized && session?.is_marked ? ' You can also flip a saved status from the row action.' : ''}
        </p>
      )}
    </div>
  );
}
