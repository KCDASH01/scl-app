'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { employeesApi, type EmployeePayload } from '@/lib/api/employees';
import { staffRolesApi } from '@/lib/api/staffRoles';
import { branchesApi } from '@/lib/api/branches';
import { useAuthStore } from '@/store/authStore';
import { useBranchStore } from '@/store/branchStore';
import { AccessDenied } from '@/components/ui/AccessDenied';
import { roleBadgeClass, roleLabel } from '@/lib/roles';
import type { User } from '@/types';

interface ApiError {
  response?: { data?: { message?: string; errors?: Record<string, string[]> } };
}

function formMessage(err: unknown, fallback: string) {
  const data = (err as ApiError)?.response?.data;
  const first = data?.errors ? Object.values(data.errors).flat()[0] : null;
  return first || data?.message || fallback;
}

export default function TeamPage() {
  const qc = useQueryClient();
  const { canManageTeam, isAdmin, isOwner, user } = useAuthStore();
  const selectedBranchId = useBranchStore((s) => s.selectedBranchId);
  const manageTeam = canManageTeam();

  const [listBranch, setListBranch] = useState<string>('');
  const [listRole, setListRole] = useState<string>('');

  const [modal,     setModal]     = useState<User | null | undefined>(undefined);
  const [form, setForm] = useState<EmployeePayload>({ name: '', email: '', password: '', role: 'employee' });
  const [formError, setFormError] = useState<string | null>(null);

  const [pwModal,   setPwModal]   = useState<User | null>(null);
  const [pwForm,    setPwForm]    = useState({ password: '', confirm: '' });
  const [pwError,   setPwError]   = useState<string | null>(null);
  const [pwSuccess, setPwSuccess] = useState(false);

  const { data: employees, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['employees', 'team', selectedBranchId, listBranch, listRole],
    queryFn:  () => employeesApi.list({
      branch_id: listBranch ? Number(listBranch) : 'all',
      staff_role_id: listRole ? Number(listRole) : undefined,
    }),
    enabled:  manageTeam,
  });

  const { data: staffRoles = [] } = useQuery({
    queryKey: ['staff-roles'],
    queryFn: staffRolesApi.list,
    enabled: manageTeam,
  });

  const { data: branchOptions = [] } = useQuery({
    queryKey: ['branches', 'active'],
    queryFn: () => branchesApi.list({ is_active: true }),
    enabled: manageTeam && isOwner(),
  });

  const selectedFormBranch = form.branch_id;
  const { data: branchManagers = [], isFetching: loadingManagers } = useQuery({
    queryKey: ['branches', selectedFormBranch, 'managers'],
    queryFn: () => branchesApi.managers(selectedFormBranch as number),
    enabled: isOwner() && Boolean(selectedFormBranch) && (form.role ?? 'employee') === 'employee',
  });

  const createMutation = useMutation({
    mutationFn: (p: EmployeePayload) => employeesApi.create(p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['employees'] });
      setModal(undefined);
      setFormError(null);
    },
    onError: (err: unknown) => {
      setFormError(formMessage(err, 'Failed to add member. Please try again.'));
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, p }: { id: number; p: Partial<EmployeePayload> }) => employeesApi.update(id, p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['employees'] });
      setModal(undefined);
      setFormError(null);
    },
    onError: (err: unknown) => setFormError(formMessage(err, 'Failed to update member. Please try again.')),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => employeesApi.delete(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['employees'] }),
  });

  const resetPasswordMutation = useMutation({
    mutationFn: ({ id, password }: { id: number; password: string }) =>
      employeesApi.update(id, { password }),
    onSuccess: () => {
      setPwSuccess(true);
      setPwError(null);
      setTimeout(() => {
        setPwModal(null);
        setPwForm({ password: '', confirm: '' });
        setPwSuccess(false);
      }, 1500);
    },
    onError: (err: unknown) => {
      const data = (err as {
        response?: { data?: { message?: string; errors?: Record<string, string[]> } };
      })?.response?.data;
      const fieldMsg =
        data?.errors?.password?.[0] ??
        data?.errors?.email?.[0] ??
        data?.errors?.name?.[0];
      setPwError(fieldMsg || data?.message || 'Failed to reset password. Please try again.');
    },
  });

  const openResetPassword = (emp: User) => {
    setPwForm({ password: '', confirm: '' });
    setPwError(null);
    setPwSuccess(false);
    setPwModal(emp);
  };

  const handleResetPassword = () => {
    if (!pwModal) return;
    if (pwForm.password.length < 8) {
      setPwError('Password must be at least 8 characters.');
      return;
    }
    if (pwForm.password !== pwForm.confirm) {
      setPwError('Passwords do not match.');
      return;
    }
    setPwError(null);
    resetPasswordMutation.mutate({ id: pwModal.id, password: pwForm.password });
  };

  const openCreate = () => {
    const employeeRole = staffRoles.find((role) => role.access === 'employee');
    setForm({
      name: '',
      email: '',
      password: '',
      role: 'employee',
      staff_role_id: employeeRole?.id,
      branch_id: isOwner() ? undefined : user?.branch_id ?? undefined,
      manager_id: isOwner() ? undefined : user?.id,
    });
    setFormError(null);
    setModal(null);
  };

  const openEdit = (emp: User) => {
    setForm({
      name: emp.name,
      email: emp.email,
      password: '',
      role: emp.staff_role?.access === 'manager' || emp.role === 'manager' ? 'manager' : 'employee',
      staff_role_id: emp.staff_role_id ?? emp.staff_role?.id ?? undefined,
      branch_id: emp.branch_id ?? undefined,
      manager_id: emp.manager_id ?? undefined,
    });
    setFormError(null);
    setModal(emp);
  };

  const handleSubmit = () => {
    setFormError(null);
    const selectedRole = staffRoles.find((role) => role.id === form.staff_role_id);
    const role = selectedRole?.access === 'manager' ? 'manager' : 'employee';
    if (!form.staff_role_id) {
      setFormError('Select a role.');
      return;
    }
    if (isOwner()) {
      if (!form.branch_id) {
        setFormError('Select a branch.');
        return;
      }
      if (role === 'employee' && !form.manager_id) {
        setFormError('No active manager is available in this branch. Add a manager first.');
        return;
      }
    }
    const payload: EmployeePayload = isOwner()
      ? { ...form, role, staff_role_id: form.staff_role_id }
      : { ...form, role: 'employee', staff_role_id: form.staff_role_id, branch_id: user?.branch_id ?? undefined, manager_id: user?.id };
    if (modal?.id) {
      updateMutation.mutate({ id: modal.id, p: payload });
    } else {
      createMutation.mutate(payload);
    }
  };

  const isPending = createMutation.isPending || updateMutation.isPending;

  if (!manageTeam) {
    return <AccessDenied reason="Team management is only available to superadmins and managers." />;
  }

  const loadError = isError
    ? (error as ApiError)?.response?.data?.message ?? 'Failed to load team members.'
    : null;

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between gap-4 flex-wrap">
        <h1 className="text-xl font-bold text-gray-900">Team Members</h1>
        {isAdmin() && (
          <button
            onClick={openCreate}
            className="text-sm font-semibold px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white transition-colors"
          >
            + Add Member
          </button>
        )}
      </div>

      {isOwner() && (
        <div className="flex flex-col sm:flex-row sm:flex-wrap gap-2 sm:gap-3">
          <select
            value={listBranch}
            onChange={(e) => setListBranch(e.target.value)}
            className="w-full sm:w-auto border border-gray-200 rounded-xl px-3 py-2 text-sm"
          >
            <option value="">All listed branches</option>
            {branchOptions.map((b) => (
              <option key={b.id} value={b.id}>{b.name}</option>
            ))}
          </select>
          <select
            value={listRole}
            onChange={(e) => setListRole(e.target.value)}
            className="w-full sm:w-auto border border-gray-200 rounded-xl px-3 py-2 text-sm"
          >
            <option value="">All roles</option>
            {staffRoles.map((role) => (
              <option key={role.id} value={role.id}>{role.name}</option>
            ))}
          </select>
        </div>
      )}

      <div className="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        {loadError ? (
          <div className="flex flex-col items-center justify-center h-48 px-6 text-center gap-3">
            <p className="text-sm text-red-600">{loadError}</p>
            <button
              onClick={() => refetch()}
              className="text-sm font-semibold text-indigo-600 hover:text-indigo-700"
            >
              Retry
            </button>
          </div>
        ) : isLoading ? (
          <div className="flex items-center justify-center h-48">
            <div className="w-6 h-6 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
          </div>
        ) : (
          <div className="divide-y divide-gray-50">
            {employees?.length ? employees.map((emp) => (
              <div key={emp.id} className="px-4 sm:px-6 py-4 flex flex-wrap items-center gap-x-4 gap-y-2">
                <div className="w-10 h-10 bg-gray-100 rounded-full flex items-center justify-center shrink-0">
                  <span className="text-gray-600 font-semibold">{emp.name[0]?.toUpperCase()}</span>
                </div>
                <div className="flex-1 min-w-0">
                  <p className="font-medium text-gray-900 text-sm truncate">{emp.name}</p>
                  <p className="text-xs text-gray-400 truncate">{emp.email}</p>
                </div>
                <div className="flex flex-wrap items-center gap-3 w-full pl-14 sm:w-auto sm:pl-0 sm:shrink-0">
                  <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${roleBadgeClass(emp.staff_role?.access ?? emp.role)}`}>
                    {emp.staff_role?.name ?? roleLabel(emp.role)}
                  </span>
                  <span className="text-xs text-gray-400 hidden sm:inline">{emp.branch?.name ?? '—'}</span>
                  {emp.role === 'employee' && (
                    <span className="text-xs text-gray-400 hidden md:inline">{emp.manager?.name ?? 'No manager'}</span>
                  )}
                  <span className={`w-2 h-2 rounded-full ${emp.is_active ? 'bg-green-400' : 'bg-red-400'}`} />
                  {isAdmin() && (
                    <>
                      <button onClick={() => openEdit(emp)} className="text-xs text-indigo-600 hover:text-indigo-700 font-medium">Edit</button>
                      {isOwner() && (
                        <button
                          onClick={() => openResetPassword(emp)}
                          className="text-xs text-amber-600 hover:text-amber-700 font-medium"
                          title="Reset password"
                        >
                          Reset Password
                        </button>
                      )}
                      <button
                        onClick={() => { if (confirm('Remove this member?')) deleteMutation.mutate(emp.id); }}
                        className="text-xs text-red-500 hover:text-red-700 font-medium"
                      >
                        Remove
                      </button>
                    </>
                  )}
                </div>
              </div>
            )) : (
              <div className="px-6 py-12 text-center text-gray-400 text-sm">
                No team members yet.{' '}
                <button onClick={openCreate} className="text-indigo-600 hover:underline">
                  Add your first member
                </button>
              </div>
            )}
          </div>
        )}
      </div>

      {pwModal && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-sm max-h-[90dvh] overflow-y-auto p-5 sm:p-6">
            <div className="flex items-center justify-between mb-1">
              <h2 className="text-lg font-bold text-gray-900">Reset Password</h2>
              <button
                onClick={() => setPwModal(null)}
                className="text-gray-400 hover:text-gray-600 text-xl leading-none"
              >✕</button>
            </div>
            <p className="text-sm text-gray-500 mb-5">
              Set a new password for <span className="font-semibold text-gray-700">{pwModal.name}</span>.
            </p>

            {pwSuccess ? (
              <div className="flex flex-col items-center py-4 gap-3">
                <div className="w-12 h-12 rounded-full bg-emerald-100 flex items-center justify-center">
                  <svg className="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                  </svg>
                </div>
                <p className="text-sm font-semibold text-emerald-700">Password updated successfully!</p>
              </div>
            ) : (
              <>
                {pwError && (
                  <div className="mb-4 px-4 py-3 rounded-xl bg-red-50 border border-red-100 text-sm text-red-700">
                    {pwError}
                  </div>
                )}
                <div className="space-y-4">
                  <div>
                    <label className="block text-xs font-medium text-gray-700 mb-1">New Password *</label>
                    <input
                      type="password"
                      value={pwForm.password}
                      onChange={(e) => setPwForm({ ...pwForm, password: e.target.value })}
                      placeholder="Min. 8 characters"
                      className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-gray-700 mb-1">Confirm Password *</label>
                    <input
                      type="password"
                      value={pwForm.confirm}
                      onChange={(e) => setPwForm({ ...pwForm, confirm: e.target.value })}
                      placeholder="Re-enter new password"
                      className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                  </div>
                </div>
                <div className="flex gap-3 mt-6">
                  <button
                    onClick={handleResetPassword}
                    disabled={resetPasswordMutation.isPending}
                    className="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-semibold py-2.5 rounded-xl text-sm flex items-center justify-center gap-2"
                  >
                    {resetPasswordMutation.isPending && (
                      <span className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                    )}
                    Update Password
                  </button>
                  <button
                    onClick={() => setPwModal(null)}
                    disabled={resetPasswordMutation.isPending}
                    className="px-4 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-xl disabled:opacity-40"
                  >
                    Cancel
                  </button>
                </div>
              </>
            )}
          </div>
        </div>
      )}

      {modal !== undefined && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-md max-h-[90dvh] overflow-y-auto p-5 sm:p-6">
            <h2 className="text-lg font-bold text-gray-900 mb-5">
              {modal ? 'Edit Member' : 'Add Team Member'}
            </h2>

            {formError && (
              <div className="mb-4 px-4 py-3 rounded-xl bg-red-50 border border-red-100 text-sm text-red-700">
                {formError}
              </div>
            )}

            <div className="space-y-4">
              {(['name', 'email'] as const).map((k) => (
                <div key={k}>
                  <label className="block text-xs font-medium text-gray-700 mb-1 capitalize">{k} *</label>
                  <input
                    value={form[k]}
                    onChange={(e) => setForm({ ...form, [k]: e.target.value })}
                    type={k === 'email' ? 'email' : 'text'}
                    className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
              ))}
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">
                  Password {modal ? '(leave blank to keep)' : '*'}
                </label>
                <input
                  type="password"
                  value={form.password ?? ''}
                  onChange={(e) => setForm({ ...form, password: e.target.value })}
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Role *</label>
                <select
                  value={form.staff_role_id ?? ''}
                  onChange={(e) => {
                    const staffRoleId = e.target.value ? Number(e.target.value) : undefined;
                    const picked = staffRoles.find((role) => role.id === staffRoleId);
                    setForm({
                      ...form,
                      staff_role_id: staffRoleId,
                      role: picked?.access === 'manager' ? 'manager' : 'employee',
                      manager_id: picked?.access === 'manager' ? undefined : form.manager_id,
                    });
                  }}
                  className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                  <option value="">Select role</option>
                  {(isOwner() ? staffRoles : staffRoles.filter((role) => role.access !== 'manager')).map((role) => (
                    <option key={role.id} value={role.id}>{role.name}</option>
                  ))}
                </select>
              </div>
              {isOwner() ? (
                <>
                  <div>
                    <label className="block text-xs font-medium text-gray-700 mb-1">Branch *</label>
                    <select
                      value={form.branch_id ?? ''}
                      onChange={(e) => setForm({
                        ...form,
                        branch_id: e.target.value ? Number(e.target.value) : undefined,
                        manager_id: undefined,
                      })}
                      className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                      <option value="">Select branch</option>
                      {branchOptions.map((b) => (
                        <option key={b.id} value={b.id}>{b.name} ({b.code})</option>
                      ))}
                    </select>
                  </div>
                  {(form.role ?? 'employee') === 'employee' && (
                    <div>
                      <label className="block text-xs font-medium text-gray-700 mb-1">Reporting Manager *</label>
                      <select
                        value={form.manager_id ?? ''}
                        disabled={!form.branch_id || loadingManagers}
                        onChange={(e) => setForm({ ...form, manager_id: e.target.value ? Number(e.target.value) : undefined })}
                        className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:bg-gray-50"
                      >
                        <option value="">{form.branch_id ? 'Select manager' : 'Select a branch first'}</option>
                        {branchManagers.map((m) => (
                          <option key={m.id} value={m.id}>{m.name}</option>
                        ))}
                      </select>
                      {form.branch_id && !loadingManagers && branchManagers.length === 0 && (
                        <p className="text-xs text-amber-600 mt-1">No active manager is available in this branch. Add a manager first.</p>
                      )}
                    </div>
                  )}
                </>
              ) : (
                <>
                  <div>
                    <label className="block text-xs font-medium text-gray-700 mb-1">Branch</label>
                    <input disabled value={user?.branch?.name ?? ''} className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm bg-gray-50 text-gray-500" />
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-gray-700 mb-1">Reporting Manager</label>
                    <input disabled value={user?.name ?? ''} className="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm bg-gray-50 text-gray-500" />
                  </div>
                </>
              )}
            </div>

            <div className="flex gap-3 mt-6">
              <button
                onClick={handleSubmit}
                disabled={isPending}
                className="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 disabled:cursor-not-allowed text-white font-semibold py-2.5 rounded-xl text-sm flex items-center justify-center gap-2"
              >
                {isPending && (
                  <span className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                )}
                {modal ? 'Save Changes' : 'Add Member'}
              </button>
              <button
                onClick={() => { setModal(undefined); setFormError(null); }}
                className="px-4 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-xl"
              >
                Cancel
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
