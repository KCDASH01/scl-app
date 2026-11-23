'use client';

import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useAuthStore } from '@/store/authStore';
import { useBranchStore } from '@/store/branchStore';
import { branchesApi } from '@/lib/api/branches';
import { AccessDenied } from '@/components/ui/AccessDenied';
import {
  LEAD_EDIT_KEY,
  SIDEBAR_MANAGER_ADMIN_ITEMS,
  SIDEBAR_WORKSPACE_ITEMS,
  sidebarApi,
  type SidebarRoleMap,
  type SidebarRole,
  type SidebarScopePayload,
} from '@/lib/api/sidebar';


function Toggle({
  checked,
  onChange,
  label,
}: {
  checked: boolean;
  onChange: (next: boolean) => void;
  label: string;
}) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      onClick={() => onChange(!checked)}
      className={[
        'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
        checked ? 'bg-indigo-600' : 'bg-gray-300',
      ].join(' ')}
    >
      <span
        className={[
          'inline-block h-5 w-5 rounded-full bg-white shadow transition-transform',
          checked ? 'translate-x-5' : 'translate-x-0.5',
        ].join(' ')}
      />
    </button>
  );
}

function RoleColumn({
  title,
  hint,
  items,
  map,
  onToggle,
  leadEdit = false,
}: {
  title: string;
  hint: string;
  items: { href: string; label: string }[];
  map: SidebarRoleMap;
  onToggle: (href: string, next: boolean) => void;
  leadEdit?: boolean;
}) {
  return (
    <div className="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
      <div className="px-5 py-4 border-b border-gray-100">
        <h2 className="text-base font-semibold text-gray-900">{title}</h2>
        <p className="mt-0.5 text-xs text-gray-500">{hint}</p>
      </div>
      <ul className="divide-y divide-gray-100">
        {items.map((item) => {
          const visible = map[item.href] !== false;
          return (
            <li key={item.href} className="px-5 py-3.5 space-y-3">
              <div className="flex items-center justify-between gap-4">
                <div>
                  <p className="text-sm font-medium text-gray-900">{item.label}</p>
                  <p className="text-xs text-gray-400">{visible ? 'Visible' : 'Hidden'}</p>
                </div>
                <Toggle
                  checked={visible}
                  onChange={(next) => onToggle(item.href, next)}
                  label={`${title} ${item.label}`}
                />
              </div>
              {leadEdit && item.href === '/leads' && (
                <div className="flex items-center justify-between gap-4 pl-3 border-l-2 border-indigo-100">
                  <div>
                    <p className="text-sm font-medium text-gray-900">Edit lead</p>
                    <p className="text-xs text-gray-400">{map[LEAD_EDIT_KEY] === true ? 'Allowed' : 'Disabled'}</p>
                  </div>
                  <Toggle
                    checked={map[LEAD_EDIT_KEY] === true}
                    onChange={(next) => onToggle(LEAD_EDIT_KEY, next)}
                    label={`${title} edit lead`}
                  />
                </div>
              )}
            </li>
          );
        })}
      </ul>
    </div>
  );
}

export default function ManageSidebarPage() {
  const qc = useQueryClient();
  const isOwner = useAuthStore((s) => s.isOwner);
  const selectedBranchId = useBranchStore((s) => s.selectedBranchId);
  const [branchId, setBranchId] = useState<number | ''>('');
  const [role, setRole] = useState<SidebarRole>('manager');
  const [userId, setUserId] = useState<number | null>(null);
  const [map, setMap] = useState<SidebarRoleMap>({});
  const [dirty, setDirty] = useState(false);
  const [notice, setNotice] = useState('');
  const [confirmApply, setConfirmApply] = useState(false);
  const [loadedScope, setLoadedScope] = useState('');
  const scopeKey = `${branchId}:${role}:${userId ?? 'all'}`;

  const { data: branches = [], isLoading: loadingBranches } = useQuery({
    queryKey: ['branches', 'active'],
    queryFn: () => branchesApi.list({ is_active: true }),
    enabled: isOwner(),
  });

  useEffect(() => {
    if (!branches.length || branchId !== '') return;
    const preferred = selectedBranchId !== 'all' ? Number(selectedBranchId) : branches[0].id;
    setBranchId(branches.some((branch) => branch.id === preferred) ? preferred : branches[0].id);
  }, [branches, branchId, selectedBranchId]);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['sidebar-settings', branchId, role, userId],
    queryFn: () => sidebarApi.getScope(Number(branchId), role, userId),
    enabled: isOwner() && branchId !== '',
  });

  useEffect(() => {
    if (!data || data.branch_id !== branchId || data.role !== role || data.user_id !== userId) return;
    if (loadedScope !== scopeKey || !dirty) {
      setMap(data.map);
      setLoadedScope(scopeKey);
    }
  }, [data, branchId, role, userId, scopeKey, loadedScope, dirty]);

  const save = useMutation({
    mutationFn: (payload: SidebarScopePayload) => sidebarApi.saveScope(payload),
    onSuccess: (saved, payload) => {
      qc.setQueryData(['sidebar-settings', saved.branch_id, saved.role, saved.user_id], saved);
      setMap(saved.map);
      setDirty(false);
      setConfirmApply(false);
      setNotice(payload.reset ? 'Reset to role defaults.' : payload.clear_customizations
        ? 'Saved for everyone in this branch and role. Individual customizations cleared.'
        : saved.user_id ? 'Individual sidebar settings saved.'
        : 'Role defaults saved. Individual customizations preserved.');
      qc.invalidateQueries({ queryKey: ['sidebar-settings'] });
      qc.invalidateQueries({ queryKey: ['me'] });
    },
  });

  const branchName = branches.find((branch) => branch.id === branchId)?.name ?? 'this branch';
  const member = data?.users.find((user) => user.id === userId);
  const ready = branchId !== '' && !!data && data.branch_id === branchId && data.role === role
    && data.user_id === userId && loadedScope === scopeKey && !isError;
  const busy = save.isPending;
  const customizedCount = data?.users.filter((user) => user.has_customization).length ?? 0;
  const roleLabel = role === 'manager' ? 'Manager' : 'Employee';
  const items = role === 'manager'
    ? [...SIDEBAR_WORKSPACE_ITEMS, ...SIDEBAR_MANAGER_ADMIN_ITEMS] : SIDEBAR_WORKSPACE_ITEMS;

  if (!isOwner()) return <AccessDenied reason="Sidebar management is only available to superadmins." />;

  const changeSelection = (change: () => void) => {
    if (dirty && !window.confirm('Discard your unsaved sidebar changes?')) return;
    change();
    setDirty(false);
    setNotice('');
    setConfirmApply(false);
    save.reset();
  };
  const submit = (action?: 'reset' | 'clear') => save.mutate({
    branch_id: Number(branchId), role, user_id: userId,
    ...(action === 'reset' ? { reset: true } : { map }),
    ...(action === 'clear' ? { clear_customizations: true } : {}),
  });
  const selectCls = 'w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-60';

  return (
    <div className="max-w-5xl mx-auto space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Manage Sidebar</h1>
          <p className="mt-1 text-sm text-gray-500">
            Choose a branch and role, then manage all users or customize one person. Dashboard and Settings stay visible.
          </p>
        </div>
        <button type="button" onClick={() => submit()} disabled={!ready || busy}
          className="shrink-0 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold disabled:opacity-60">
          {busy ? 'Saving...' : 'Save changes'}
        </button>
      </div>

      {notice && <p role="status" className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-xl px-4 py-2.5">{notice}</p>}
      {save.isError && <p role="alert" className="text-sm text-red-700 bg-red-50 rounded-xl px-4 py-2.5">Could not save sidebar settings. Your changes have been kept; try again.</p>}

      <div className="bg-white rounded-2xl border border-gray-200 p-5 grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label htmlFor="sidebar-branch" className="block mb-2 text-sm font-medium text-gray-700">Branch</label>
          <select id="sidebar-branch" value={branchId} disabled={busy || loadingBranches || !branches.length}
            onChange={(e) => changeSelection(() => { setBranchId(Number(e.target.value)); setUserId(null); })} className={selectCls}>
            {!branches.length && <option value="">No branches yet</option>}
            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
          </select>
        </div>
        <div>
          <label htmlFor="sidebar-role" className="block mb-2 text-sm font-medium text-gray-700">Role</label>
          <select id="sidebar-role" value={role} disabled={busy}
            onChange={(e) => changeSelection(() => { setRole(e.target.value as SidebarRole); setUserId(null); })} className={selectCls}>
            <option value="manager">Manager / Admin</option>
            <option value="employee">Employee</option>
          </select>
        </div>
        <div>
          <label htmlFor="sidebar-user" className="block mb-2 text-sm font-medium text-gray-700">User</label>
          <select id="sidebar-user" value={userId ?? 'all'} disabled={busy || branchId === '' || isLoading}
            onChange={(e) => changeSelection(() => setUserId(e.target.value === 'all' ? null : Number(e.target.value)))} className={selectCls}>
            <option value="all">All Users (role defaults)</option>
            {data?.users.map((user) => <option key={user.id} value={user.id}>
              {user.name} ({user.email}){user.has_customization ? ' - Customized' : ''}{!user.is_active ? ' - Inactive' : ''}
            </option>)}
          </select>
        </div>
      </div>

      {isLoading && branchId !== '' && <p className="py-12 text-center text-sm text-gray-500">Loading sidebar settings...</p>}
      {isError && <div className="p-8 bg-white rounded-2xl border text-center">
        <p className="text-sm text-gray-600 mb-3">Failed to load sidebar settings.</p>
        <button type="button" onClick={() => refetch()} className="text-sm font-semibold text-indigo-600">Retry</button>
      </div>}

      {ready && <>
        <div className="bg-indigo-50 border border-indigo-100 rounded-xl p-4 space-y-3">
          <p className="text-sm text-indigo-900">
            {userId ? `${member?.name}: ${data.has_customization ? 'Custom sidebar' : 'Inheriting role defaults'} in ${branchName}.`
              : `Defaults for all ${role === 'manager' ? 'managers and admins' : 'employees'} in ${branchName}. ${customizedCount} user(s) have individual customizations.`}
          </p>
          <p className="text-xs text-indigo-700">
            {userId ? 'Saving customizes this user only. Reset restores the current role defaults.'
              : 'Saving updates users who inherit these defaults. Customized users keep their individual settings.'}
          </p>
          {member && !member.panel_access && <p className="text-sm text-amber-800">This user has a staff role with no panel access. Sidebar settings cannot grant access to that role.</p>}
          {userId ? <button type="button" disabled={busy || !data.has_customization} onClick={() => submit('reset')}
            className="text-sm font-semibold text-indigo-700 disabled:opacity-50">Reset to Role Defaults</button>
            : <button type="button" disabled={busy} onClick={() => setConfirmApply(true)}
              className="text-sm font-semibold text-indigo-700 disabled:opacity-50">Apply to Everyone and Clear Customizations</button>}
          {confirmApply && !userId && <div role="alert" className="bg-white border border-amber-200 rounded-xl p-4 space-y-3">
            <p className="text-sm text-gray-700">Apply the settings below to every {roleLabel.toLowerCase()} in {branchName}? This clears {customizedCount} individual customization(s) for this role only.</p>
            <div className="flex gap-4">
              <button type="button" disabled={busy} onClick={() => submit('clear')} className="text-sm font-semibold text-red-700">Confirm apply to everyone</button>
              <button type="button" disabled={busy} onClick={() => setConfirmApply(false)} className="text-sm text-gray-600">Cancel</button>
            </div>
          </div>}
        </div>
        <fieldset disabled={busy} className="min-w-0">
          <RoleColumn title={userId ? `${member?.name ?? 'User'} - ${roleLabel}` : `${roleLabel} defaults`}
            hint={userId ? `Applies only to this user in ${branchName}.` : `Applies to users inheriting ${roleLabel.toLowerCase()} defaults in ${branchName}.`}
            items={items} map={map} leadEdit={role === 'employee'}
            onToggle={(href, next) => { setMap((prev) => ({ ...prev, [href]: next })); setDirty(true); setNotice(''); }} />
        </fieldset>
      </>}
    </div>
  );
}
