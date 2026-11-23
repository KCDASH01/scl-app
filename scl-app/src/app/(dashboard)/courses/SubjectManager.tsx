'use client';

import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { coursesApi } from '@/lib/api/courses';
import { subjectsApi } from '@/lib/api/subjects';
import { useBranchStore } from '@/store/branchStore';
import type { Course } from '@/types';

interface ApiError {
  response?: { data?: { message?: string; errors?: Record<string, string[]> } };
}

function apiMessage(err: unknown, fallback: string) {
  const data = (err as ApiError)?.response?.data;
  const first = data?.errors ? Object.values(data.errors).flat()[0] : undefined;
  return first || data?.message || fallback;
}

export function SubjectManager({
  open,
  course,
  onClose,
}: {
  open: boolean;
  course?: Course | null;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const selectedBranchId = useBranchStore((s) => s.selectedBranchId);
  const [courseId, setCourseId] = useState('');
  const [editingId, setEditingId] = useState<number | null>(null);
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    if (!open) return;
    setCourseId(course ? String(course.id) : '');
    setEditingId(null);
    setName('');
    setCode('');
    setError('');
  }, [open, course]);

  const { data: courses = [] } = useQuery({
    queryKey: ['courses', 'options', selectedBranchId],
    queryFn: () => coursesApi.options(),
    enabled: open,
  });

  const { data: subjects = [], isLoading, isFetching } = useQuery({
    queryKey: ['subjects', selectedBranchId, courseId],
    queryFn: () => subjectsApi.list(Number(courseId)),
    enabled: open && Boolean(courseId),
  });

  const resetForm = () => {
    setEditingId(null);
    setName('');
    setCode('');
    setError('');
  };

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload = { course_id: Number(courseId), name: name.trim(), code: code.trim() };
      return editingId ? subjectsApi.update(editingId, payload) : subjectsApi.create(payload);
    },
    onSuccess: () => {
      resetForm();
      qc.invalidateQueries({ queryKey: ['subjects'] });
    },
    onError: (err) => setError(apiMessage(err, 'Could not save the subject.')),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => subjectsApi.delete(id),
    onSuccess: () => {
      resetForm();
      qc.invalidateQueries({ queryKey: ['subjects'] });
    },
    onError: (err) => setError(apiMessage(err, 'Could not delete the subject.')),
  });

  if (!open) return null;

  const submit = () => {
    if (!courseId) {
      setError('Select a course.');
      return;
    }
    if (!name.trim() || !code.trim()) {
      setError('Enter the subject name and code.');
      return;
    }
    setError('');
    saveMutation.mutate();
  };

  return (
    <div className="fixed inset-0 bg-black/40 z-[60] flex items-center justify-center p-4">
      <div className="bg-white rounded-2xl shadow-xl w-full max-w-2xl p-5 sm:p-6 max-h-[90dvh] overflow-y-auto">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-gray-900">Subjects</h2>
          <button
            type="button"
            onClick={onClose}
            className="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100"
            aria-label="Close"
          >
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <label className="block text-xs font-medium text-gray-700 mb-1">Course *</label>
        {course ? (
          <input
            readOnly
            value={`${course.name} (${course.code})${course.branch?.name ? ` · ${course.branch.name}` : ''}`}
            className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm bg-gray-50 text-gray-700"
          />
        ) : (
          <select
            value={courseId}
            onChange={(e) => {
              setCourseId(e.target.value);
              resetForm();
            }}
            className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >
            <option value="">Select a course</option>
            {courses.map((item) => (
              <option key={item.id} value={item.id}>
                {item.name} ({item.code}){item.branch?.name ? ` · ${item.branch.name}` : ''}
              </option>
            ))}
          </select>
        )}

        {!courseId ? (
          <p className="mt-4 text-sm text-gray-400">Select a course to add, edit, or delete its subjects.</p>
        ) : (
          <div className="mt-4 space-y-4">
            {error ? (
              <div className="px-4 py-3 rounded-xl bg-red-50 text-sm text-red-700">{error}</div>
            ) : null}

            <div className="grid grid-cols-1 sm:grid-cols-[1fr_140px_auto] gap-2 items-end">
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Subject name *</label>
                <input
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="e.g. Physics"
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Code *</label>
                <input
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  placeholder="PHY"
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={submit}
                  disabled={saveMutation.isPending}
                  className="px-4 py-2 text-sm font-semibold rounded-xl bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                  {saveMutation.isPending ? 'Saving…' : editingId ? 'Save' : 'Add'}
                </button>
                {editingId ? (
                  <button type="button" onClick={resetForm} className="px-3 py-2 text-sm text-gray-500 border border-gray-200 rounded-xl">
                    Cancel
                  </button>
                ) : null}
              </div>
            </div>

            <div className="border border-gray-100 rounded-xl overflow-hidden">
              {isLoading ? (
                <div className="flex items-center justify-center h-24">
                  <div className="w-5 h-5 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
                </div>
              ) : subjects.length === 0 ? (
                <p className="px-4 py-8 text-center text-sm text-gray-400">No subjects for this course yet.</p>
              ) : (
                <table className="min-w-full text-sm">
                  <thead className="bg-gray-50">
                    <tr>
                      {['Subject', 'Code', ''].map((heading) => (
                        <th key={heading || 'actions'} className="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 uppercase tracking-wide">{heading}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-50">
                    {subjects.map((subject) => (
                      <tr key={subject.id}>
                        <td className="px-4 py-2.5 font-medium text-gray-900">{subject.name}</td>
                        <td className="px-4 py-2.5 text-gray-600">{subject.code}</td>
                        <td className="px-4 py-2.5 text-right whitespace-nowrap">
                          <button
                            type="button"
                            onClick={() => {
                              setEditingId(subject.id);
                              setName(subject.name);
                              setCode(subject.code);
                              setError('');
                            }}
                            className="text-xs font-medium text-indigo-600 mr-3"
                          >
                            Edit
                          </button>
                          <button
                            type="button"
                            onClick={() => {
                              if (confirm(`Delete ${subject.name}?`)) deleteMutation.mutate(subject.id);
                            }}
                            disabled={deleteMutation.isPending}
                            className="text-xs font-medium text-red-500"
                          >
                            Delete
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
              {isFetching && !isLoading ? <p className="px-4 py-2 text-xs text-gray-400">Updating…</p> : null}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
