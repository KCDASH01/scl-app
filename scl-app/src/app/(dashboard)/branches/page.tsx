'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { branchesApi, type BranchPayload } from '@/lib/api/branches';
import { useAuthStore } from '@/store/authStore';
import { AccessDenied } from '@/components/ui/AccessDenied';
import type { Branch } from '@/types';

interface ApiError {
  response?: { data?: { message?: string } };
}

const emptyForm: BranchPayload = { name: '', code: '', address: '', phone: '', email: '' };

export default function BranchesPage() {
  const qc = useQueryClient();
  const { isOwner } = useAuthStore();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [modal, setModal] = useState<Branch | null | undefined>(undefined);
  const [form, setForm] = useState<BranchPayload>(emptyForm);
  const [formError, setFormError] = useState<string | null>(null);
  const [detail, setDetail] = useState<Branch | null>(null);

  if (!isOwner()) {
    return <AccessDenied reason="Branch management is only available to superadmins." />;
  }

  const { data: branches = [], isLoading, isError, error, refetch } = useQuery({
    queryKey: ['branches', search, status],
    queryFn: () => branchesApi.list({
      search: search || undefined,
      is_active: status === '' ? undefined : status === 'active',
    }),
  });

  const { data: detailData, isLoading: detailLoading } = useQuery({
    queryKey: ['branches', detail?.id],
    queryFn: () => branchesApi.get(detail!.id),
    enabled: Boolean(detail?.id),
  });

  const saveMutation = useMutation({
    mutationFn: (payload: BranchPayload) =>
      modal?.id ? branchesApi.update(modal.id, payload) : branchesApi.create(payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['branches'] });
      setModal(undefined);
      setFormError(null);
    },
    onError: (err: unknown) => {
      setFormError((err as ApiError)?.response?.data?.message ?? 'Could not save branch.');
    },
  });

  const toggleMutation = useMutation({
    mutationFn: (id: number) => branchesApi.toggle(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['branches'] }),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => branchesApi.delete(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['branches'] });
      setDetail(null);
    },
    onError: (err: unknown) => {
      alert((err as ApiError)?.response?.data?.message ?? 'Could not delete branch.');
    },
  });

  const openCreate = () => {
    setForm(emptyForm);
    setFormError(null);
    setModal(null);
  };

  const openEdit = (branch: Branch) => {
    setForm({
      name: branch.name,
      code: branch.code,
      address: branch.address ?? '',
      phone: branch.phone ?? '',
      email: branch.email ?? '',
    });
    setFormError(null);
    setModal(branch);
  };

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between gap-4 flex-wrap">
        <h1 className="text-xl font-bold text-gray-900">Branches</h1>
        <button
          onClick={openCreate}
          className="text-sm font-semibold px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white"
        >
          + Add Branch
        </button>
      </div>

      <div className="flex flex-col sm:flex-row gap-2 sm:gap-3 sm:flex-wrap">
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search branches..."
          className="border border-gray-200 rounded-xl px-3 py-2 text-sm w-full sm:w-64 focus:outline-none focus:ring-2 focus:ring-indigo-500"
        />
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
            {(error as ApiError)?.response?.data?.message ?? 'Failed to load branches.'}
            <button onClick={() => refetch()} className="block mx-auto mt-2 text-indigo-600">Retry</button>
          </div>
        ) : isLoading ? (
          <div className="flex items-center justify-center h-48">
            <div className="w-6 h-6 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
          </div>
        ) : branches.length === 0 ? (
          <div className="px-6 py-12 text-center text-gray-400 text-sm">
            No branches yet.{' '}
            <button onClick={openCreate} className="text-indigo-600 hover:underline">Create the first branch</button>
          </div>
        ) : (
          <div className="divide-y divide-gray-50">
            {branches.map((branch) => (
              <div key={branch.id} className="px-4 sm:px-6 py-4 flex flex-wrap items-center gap-x-4 gap-y-2">
                <div className="flex-1 min-w-0 basis-full sm:basis-0">
                  <p className="font-medium text-gray-900 text-sm">{branch.name}</p>
                  <p className="text-xs text-gray-400">{branch.code}{branch.address ? ` · ${branch.address}` : ''}</p>
                </div>
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <span className="text-xs text-gray-500">{branch.managers_count ?? 0} managers · {branch.employees_count ?? 0} employees</span>
                <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${branch.is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                  {branch.is_active ? 'Active' : 'Inactive'}
                </span>
                <button onClick={() => setDetail(branch)} className="text-xs text-indigo-600 font-medium">Details</button>
                <button onClick={() => openEdit(branch)} className="text-xs text-indigo-600 font-medium">Edit</button>
                <button
                  onClick={() => toggleMutation.mutate(branch.id)}
                  className="text-xs text-amber-600 font-medium"
                >
                  {branch.is_active ? 'Deactivate' : 'Activate'}
                </button>
                <button
                  onClick={() => {
                    if (confirm('Delete this branch? This only works if it has no users or records.')) {
                      deleteMutation.mutate(branch.id);
                    }
                  }}
                  className="text-xs text-red-500 font-medium"
                >
                  Delete
                </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {modal !== undefined && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-md max-h-[90dvh] overflow-y-auto p-5 sm:p-6">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">{modal ? 'Edit Branch' : 'Add Branch'}</h2>
            {formError && <div className="mb-4 px-4 py-3 rounded-xl bg-red-50 text-sm text-red-700">{formError}</div>}
            <div className="space-y-3">
              {(['name', 'code', 'address', 'phone', 'email'] as const).map((key) => (
                <div key={key}>
                  <label className="block text-xs font-medium text-gray-700 mb-1 capitalize">
                    {key}{key === 'name' || key === 'code' ? ' *' : ''}
                  </label>
                  <input
                    value={form[key] ?? ''}
                    onChange={(e) => setForm({ ...form, [key]: e.target.value })}
                    className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
              ))}
            </div>
            <div className="flex gap-3 mt-6">
              <button
                onClick={() => saveMutation.mutate(form)}
                disabled={saveMutation.isPending}
                className="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2.5 rounded-xl text-sm disabled:opacity-60"
              >
                {modal ? 'Save Changes' : 'Create Branch'}
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
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-lg p-5 sm:p-6 max-h-[90dvh] sm:max-h-[80vh] overflow-y-auto">
            <div className="flex items-start justify-between mb-4">
              <div>
                <h2 className="text-lg font-semibold text-gray-900">{detail.name}</h2>
                <p className="text-xs text-gray-400">{detail.code}</p>
              </div>
              <button onClick={() => setDetail(null)} className="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            {detailLoading ? (
              <div className="py-10 flex justify-center">
                <div className="w-6 h-6 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
              </div>
            ) : (
              <>
                <p className="text-sm text-gray-500 mb-4">{detailData?.address || 'No address'} · {detailData?.email || 'No email'}</p>
                <h3 className="text-sm font-semibold text-gray-800 mb-2">Managers</h3>
                <div className="space-y-2 mb-4">
                  {(detailData?.managers ?? []).length ? detailData!.managers!.map((m) => (
                    <div key={m.id} className="text-sm text-gray-700">{m.name} <span className="text-gray-400">{m.email}</span></div>
                  )) : <p className="text-sm text-gray-400">No managers yet.</p>}
                </div>
                <h3 className="text-sm font-semibold text-gray-800 mb-2">Employees</h3>
                <div className="space-y-2">
                  {(detailData?.employees ?? []).length ? detailData!.employees!.map((m) => (
                    <div key={m.id} className="text-sm text-gray-700">
                      {m.name} <span className="text-gray-400">{m.email}</span>
                      {m.manager ? <span className="text-xs text-gray-400"> · reports to {m.manager.name}</span> : null}
                    </div>
                  )) : <p className="text-sm text-gray-400">No employees yet.</p>}
                </div>
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
