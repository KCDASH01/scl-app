'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { academicYearsApi } from '@/lib/api/academicYears';
import { useAuthStore } from '@/store/authStore';
import { AccessDenied } from '@/components/ui/AccessDenied';

interface ApiError {
  response?: { data?: { message?: string; errors?: Record<string, string[]> } };
}

function message(err: unknown) {
  const data = (err as ApiError)?.response?.data;
  return data?.errors?.name?.[0] || data?.message || 'Could not save the batch year.';
}

export default function BatchYearsPage() {
  const qc = useQueryClient();
  const canManage = useAuthStore((s) => s.canManageTeam());
  const [name, setName] = useState('');
  const [error, setError] = useState('');

  const { data: years = [], isLoading } = useQuery({
    queryKey: ['academic-years'],
    queryFn: academicYearsApi.list,
    enabled: canManage,
  });

  const createMutation = useMutation({
    mutationFn: (value: string) => academicYearsApi.create(value),
    onSuccess: () => {
      setName('');
      setError('');
      qc.invalidateQueries({ queryKey: ['academic-years'] });
    },
    onError: (err) => setError(message(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => academicYearsApi.remove(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['academic-years'] }),
    onError: (err) => setError(message(err)),
  });

  if (!canManage) {
    return <AccessDenied reason="Batch years can be managed by superadmins, admins, and managers." />;
  }

  const add = () => {
    const value = name.trim();
    const start = Number(value.slice(0, 4));
    const expected = String((start + 1) % 100).padStart(2, '0');
    if (!/^\d{4}-\d{2}$/.test(value) || value.slice(5) !== expected) {
      setError('Enter a batch year like 2026-27.');
      return;
    }
    setError('');
    createMutation.mutate(value);
  };

  return (
    <div className="space-y-5 max-w-xl">
      <div>
        <h1 className="text-xl font-bold text-gray-900">Batch Year</h1>
        <p className="text-sm text-gray-500 mt-1">Add academic years such as 2026-27. They appear on Student Admission.</p>
      </div>

      <div className="bg-white rounded-2xl border border-gray-200 p-5 space-y-3">
        <label className="block text-xs font-medium text-gray-700">New batch year</label>
        <div className="flex flex-col sm:flex-row gap-2">
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') add(); }}
            placeholder="2026-27"
            className="flex-1 border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
          <button
            onClick={add}
            disabled={createMutation.isPending}
            className="px-4 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-medium disabled:opacity-50"
          >
            {createMutation.isPending ? 'Adding…' : 'Add'}
          </button>
        </div>
        {error ? <p className="text-sm text-red-600">{error}</p> : null}
      </div>

      <div className="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        {isLoading ? (
          <div className="flex items-center justify-center h-32">
            <div className="w-6 h-6 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
          </div>
        ) : years.length === 0 ? (
          <p className="px-5 py-10 text-sm text-gray-400 text-center">No batch years yet.</p>
        ) : (
          <ul className="divide-y divide-gray-100">
            {years.map((year) => (
              <li key={year.id} className="px-5 py-3 flex items-center justify-between gap-3">
                <span className="text-sm font-semibold text-gray-900">{year.name}</span>
                <button
                  onClick={() => {
                    if (confirm(`Delete ${year.name}?`)) deleteMutation.mutate(year.id);
                  }}
                  className="text-xs text-red-500 font-medium"
                >
                  Delete
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
