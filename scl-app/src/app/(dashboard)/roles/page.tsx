'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { staffRolesApi, type StaffRolePayload } from '@/lib/api/staffRoles';
import { useAuthStore } from '@/store/authStore';
import { AccessDenied } from '@/components/ui/AccessDenied';
import type { StaffRole } from '@/types';

interface ApiError {
  response?: { data?: { message?: string; errors?: Record<string, string[]> } };
}

function message(err: unknown, fallback: string) {
  const data = (err as ApiError)?.response?.data;
  const first = data?.errors ? Object.values(data.errors).flat()[0] : null;
  return first || data?.message || fallback;
}

const emptyForm: StaffRolePayload = { name: '' };

function isSystemRole(role: StaffRole) {
  return role.is_system || role.name === 'Employee' || role.name === 'Manager';
}

export default function RolesPage() {
  const qc = useQueryClient();
  const isOwner = useAuthStore((s) => s.isOwner);
  const [form, setForm] = useState<StaffRolePayload>(emptyForm);
  const [editing, setEditing] = useState<StaffRole | null>(null);
  const [error, setError] = useState('');
  const [saved, setSaved] = useState('');

  const { data: roles = [], isLoading } = useQuery({
    queryKey: ['staff-roles'],
    queryFn: staffRolesApi.list,
    enabled: isOwner(),
  });

  const saveMutation = useMutation({
    mutationFn: (payload: StaffRolePayload) =>
      editing ? staffRolesApi.update(editing.id, payload) : staffRolesApi.create(payload),
    onSuccess: () => {
      setForm(emptyForm);
      setEditing(null);
      setError('');
      setSaved(editing ? 'Role updated.' : 'Role added.');
      qc.invalidateQueries({ queryKey: ['staff-roles'] });
    },
    onError: (err) => {
      setSaved('');
      setError(message(err, 'Could not save the role.'));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => staffRolesApi.remove(id),
    onSuccess: () => {
      setError('');
      setSaved('Role deleted.');
      qc.invalidateQueries({ queryKey: ['staff-roles'] });
    },
    onError: (err) => {
      setSaved('');
      setError(message(err, 'Could not delete the role.'));
    },
  });

  if (!isOwner()) {
    return <AccessDenied reason="Roles can be added by the superadmin." />;
  }

  const save = () => {
    const name = form.name.trim();
    if (!name) {
      setSaved('');
      setError('Enter a role name.');
      return;
    }
    setError('');
    saveMutation.mutate({ name });
  };

  const startEdit = (role: StaffRole) => {
    if (isSystemRole(role)) return;
    setEditing(role);
    setForm({ name: role.name });
    setError('');
    setSaved('');
  };

  return (
    <div className="space-y-5 max-w-2xl">
      <div>
        <h1 className="text-xl font-bold text-gray-900">Add Role</h1>
        <p className="text-sm text-gray-500 mt-1">
          Create roles such as Teacher or Accountant. A new role does not open any panel. Employee and Manager stay as they are.
        </p>
      </div>

      <div className="bg-white rounded-2xl border border-gray-200 p-5 space-y-3">
        <div>
          <label className="block text-xs font-medium text-gray-700 mb-1">Role name</label>
          <input
            value={form.name}
            onChange={(e) => setForm({ name: e.target.value })}
            onKeyDown={(e) => { if (e.key === 'Enter') save(); }}
            placeholder="Teacher"
            className="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>
        {saved ? <p className="text-sm text-green-700">{saved}</p> : null}
        {error ? <p className="text-sm text-red-600">{error}</p> : null}
        <div className="flex gap-2">
          <button
            onClick={save}
            disabled={saveMutation.isPending}
            className="px-4 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-medium disabled:opacity-50"
          >
            {saveMutation.isPending ? 'Saving…' : editing ? 'Save role' : 'Add role'}
          </button>
          {editing ? (
            <button
              onClick={() => { setEditing(null); setForm(emptyForm); setError(''); }}
              className="px-4 py-2.5 rounded-xl border border-gray-200 text-sm text-gray-600"
            >
              Cancel
            </button>
          ) : null}
        </div>
      </div>

      <div className="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        {isLoading ? (
          <div className="flex items-center justify-center h-32">
            <div className="w-6 h-6 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
          </div>
        ) : roles.length === 0 ? (
          <p className="px-5 py-10 text-sm text-gray-400 text-center">No roles yet.</p>
        ) : (
          <ul className="divide-y divide-gray-100">
            {roles.map((role) => (
              <li key={role.id} className="px-5 py-3 flex items-center justify-between gap-3">
                <div>
                  <p className="text-sm font-semibold text-gray-900">{role.name}</p>
                  <p className="text-xs text-gray-400">
                    {isSystemRole(role) ? 'Built-in' : 'No panel access'}
                    {typeof role.users_count === 'number' ? ` · ${role.users_count} member${role.users_count === 1 ? '' : 's'}` : ''}
                  </p>
                </div>
                {isSystemRole(role) ? (
                  <span className="text-xs text-gray-400">Locked</span>
                ) : (
                  <div className="flex items-center gap-3">
                    <button onClick={() => startEdit(role)} className="text-xs text-indigo-600 font-medium">Edit</button>
                    <button
                      onClick={() => {
                        if (confirm(`Delete ${role.name}?`)) deleteMutation.mutate(role.id);
                      }}
                      className="text-xs text-red-500 font-medium"
                    >
                      Delete
                    </button>
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
