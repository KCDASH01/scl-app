'use client';

import { useState, useRef, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useAuthStore } from '@/store/authStore';
import { useBranchStore } from '@/store/branchStore';
import { authApi } from '@/lib/api/auth';
import { branchesApi } from '@/lib/api/branches';
import { organizationApi, type ReceiptSettings } from '@/lib/api/organization';

const inputCls = 'w-full border border-gray-300 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500';

// ── Settings Page ─────────────────────────────────────────────────────────────
export default function SettingsPage() {
  const { user, setUser, isOwner, isAdmin } = useAuthStore();
  const canManage = isOwner() || isAdmin();
  const selectedBranchId = useBranchStore((s) => s.selectedBranchId);
  const qc = useQueryClient();

  // Fetch fresh user+org data on mount so organization.timezone is never stale
  const { data: freshMe } = useQuery({
    queryKey: ['me'],
    queryFn: authApi.me,
    staleTime: 0,          // always re-fetch when settings page mounts
    refetchOnMount: true,
  });
  useEffect(() => {
    if (freshMe) setUser(freshMe);
  }, [freshMe]); // eslint-disable-line react-hooks/exhaustive-deps

  // Profile
  const [profileForm, setProfileForm]   = useState({ name: user?.name ?? '', phone: user?.phone ?? '' });
  const [pwForm, setPwForm]             = useState({ current_password: '', password: '', password_confirmation: '' });
  const [profileMsg, setProfileMsg]     = useState('');
  const [pwMsg, setPwMsg]               = useState('');
  const [profileError, setProfileError] = useState('');
  const [pwError, setPwError]           = useState('');
  const [profileLoading, setProfileLoading] = useState(false);
  const [pwLoading, setPwLoading]           = useState(false);

  // Receipt settings — one set of details per branch
  const logoInputRef = useRef<HTMLInputElement>(null);
  const [branchId, setBranchId] = useState<number | null>(isOwner() ? null : (user?.branch_id ?? null));
  const { data: branches = [], isLoading: loadingBranches } = useQuery({
    queryKey: ['branches', 'active'],
    queryFn: () => branchesApi.list({ is_active: true }),
    enabled: canManage && isOwner(),
  });

  useEffect(() => {
    if (!isOwner() || !branches.length || branchId != null) return;
    const preferred = selectedBranchId !== 'all' ? Number(selectedBranchId) : branches[0].id;
    setBranchId(branches.some((branch) => branch.id === preferred) ? preferred : branches[0].id);
  }, [branches, branchId, selectedBranchId, isOwner]);

  const { data: savedReceipt, isLoading: receiptLoading, isError: receiptLoadError, refetch: refetchReceipt } = useQuery({
    queryKey: ['receipt-settings', branchId],
    queryFn: () => organizationApi.getReceiptSettings(Number(branchId)),
    enabled: canManage && branchId != null,
  });
  const [receipt, setReceipt] = useState<ReceiptSettings>({});
  const [receiptMsg, setReceiptMsg]     = useState('');
  const [receiptError, setReceiptError] = useState('');
  const receiptReady = branchId != null && savedReceipt?.branch_id === branchId;

  useEffect(() => {
    if (!savedReceipt || savedReceipt.branch_id !== branchId) return;
    setReceipt(savedReceipt);
  }, [savedReceipt, branchId]);

  const branchName = isOwner()
    ? (branches.find((branch) => branch.id === branchId)?.name ?? 'this branch')
    : (user?.branch?.name ?? 'your branch');

  const receiptMutation = useMutation({
    mutationFn: () => organizationApi.updateReceiptSettings({ ...receipt, branch_id: Number(branchId) }),
    onSuccess: (saved) => {
      qc.setQueryData(['receipt-settings', saved.branch_id], saved);
      setReceipt(saved);
      setReceiptMsg(`Saved for ${branchName}.`);
      setTimeout(() => setReceiptMsg(''), 3000);
    },
    onError: () => setReceiptError('Failed to save. Please try again.'),
  });

  const handleLogoUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    if (file.size > 1024 * 1024) { alert('Logo must be under 1 MB.'); return; }
    const reader = new FileReader();
    reader.onload = (ev) => setReceipt((r) => ({ ...r, logo: ev.target?.result as string }));
    reader.readAsDataURL(file);
  };

  const setR = (k: keyof ReceiptSettings, v: string) => setReceipt((r) => ({ ...r, [k]: v }));

  // ── Handlers ─────────────────────────────────────────────────────────────────
  const handleProfileSave = async (e: React.FormEvent) => {
    e.preventDefault();
    setProfileMsg(''); setProfileError('');
    setProfileLoading(true);
    try {
      const updated = await authApi.updateMe(profileForm);
      setUser(updated);
      setProfileMsg('Profile updated successfully.');
    } catch {
      setProfileError('Failed to update profile.');
    } finally {
      setProfileLoading(false);
    }
  };

  const handlePasswordChange = async (e: React.FormEvent) => {
    e.preventDefault();
    setPwMsg(''); setPwError('');
    setPwLoading(true);
    try {
      await authApi.changePassword(pwForm);
      setPwMsg('Password changed successfully.');
      setPwForm({ current_password: '', password: '', password_confirmation: '' });
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setPwError(msg ?? 'Failed to change password.');
    } finally {
      setPwLoading(false);
    }
  };

  // ── Render ────────────────────────────────────────────────────────────────────
  return (
    <div className="max-w-3xl space-y-4 sm:space-y-6">
      <h1 className="text-xl font-bold text-gray-900">Settings</h1>

      {/* ── Profile ─────────────────────────────────────────────────────────── */}
      <div className="bg-white rounded-2xl border border-gray-200 p-4 sm:p-6">
        <h2 className="font-semibold text-gray-900 mb-5">Profile</h2>
        <form onSubmit={handleProfileSave} className="space-y-4">
          {profileMsg  && <div className="px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">{profileMsg}</div>}
          {profileError && <div className="px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">{profileError}</div>}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">Full Name</label>
            <input value={profileForm.name} onChange={(e) => setProfileForm({ ...profileForm, name: e.target.value })} className={inputCls} />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">Phone</label>
            <input value={profileForm.phone} onChange={(e) => setProfileForm({ ...profileForm, phone: e.target.value })} className={inputCls} />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">Email</label>
            <input value={user?.email ?? ''} disabled className="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-gray-50 text-gray-400 cursor-not-allowed" />
          </div>
          <button type="submit" disabled={profileLoading} className="bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-semibold px-5 py-2.5 rounded-xl text-sm">
            {profileLoading ? 'Saving…' : 'Save Profile'}
          </button>
        </form>
      </div>

      {/* ── Change Password ──────────────────────────────────────────────────── */}
      <div className="bg-white rounded-2xl border border-gray-200 p-4 sm:p-6">
          <h2 className="font-semibold text-gray-900 mb-5">Change Password</h2>
          <form onSubmit={handlePasswordChange} className="space-y-4">
            {pwMsg  && <div className="px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">{pwMsg}</div>}
            {pwError && <div className="px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">{pwError}</div>}
            {(['current_password', 'password', 'password_confirmation'] as const).map((k) => (
              <div key={k}>
                <label className="block text-sm font-medium text-gray-700 mb-1.5">
                  {k === 'current_password' ? 'Current Password' : k === 'password' ? 'New Password' : 'Confirm New Password'}
                </label>
                <input type="password" value={pwForm[k]} onChange={(e) => setPwForm({ ...pwForm, [k]: e.target.value })} className={inputCls} />
              </div>
            ))}
            <button type="submit" disabled={pwLoading} className="bg-gray-900 hover:bg-gray-800 disabled:opacity-50 text-white font-semibold px-5 py-2.5 rounded-xl text-sm">
              {pwLoading ? 'Updating…' : 'Change Password'}
            </button>
          </form>
        </div>

      {/* ── Receipt Settings (managers only) ────────────────────────────────── */}
      {canManage && (
        <div className="bg-white rounded-2xl border border-gray-200 p-4 sm:p-6">
          <div className="mb-5">
            <h2 className="font-semibold text-gray-900">Receipt Settings</h2>
            <p className="text-xs text-gray-400 mt-0.5">Printed on payment receipts for {branchName} only. Each branch keeps its own details.</p>
          </div>

          {isOwner() && (
            <div className="mb-5">
              <label htmlFor="receipt-branch" className="block text-sm font-medium text-gray-700 mb-1.5">Branch</label>
              <select
                id="receipt-branch"
                value={branchId ?? ''}
                onChange={(e) => {
                  setBranchId(e.target.value ? Number(e.target.value) : null);
                  setReceiptMsg('');
                  setReceiptError('');
                  if (logoInputRef.current) logoInputRef.current.value = '';
                }}
                disabled={loadingBranches || branches.length === 0}
                className={inputCls}
              >
                {branches.length === 0 ? <option value="">No branches yet</option> : null}
                {branches.map((branch) => (
                  <option key={branch.id} value={branch.id}>{branch.name}</option>
                ))}
              </select>
            </div>
          )}

          {receiptMsg   && <div className="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">{receiptMsg}</div>}
          {receiptError && <div className="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">{receiptError}</div>}
          {receiptLoadError && (
            <div className="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">
              Could not load receipt details.{' '}
              <button type="button" onClick={() => refetchReceipt()} className="font-semibold underline">Retry</button>
            </div>
          )}
          {!isOwner() && !user?.branch_id && (
            <p className="text-sm text-gray-500">Your account is not assigned to a branch.</p>
          )}
          {branchId != null && !receiptReady && receiptLoading && (
            <div className="py-8 text-center text-sm text-gray-400">Loading receipt details…</div>
          )}

          {receiptReady && <div className="space-y-5">
            {/* Logo upload */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">Company Logo</label>
              <div className="flex flex-wrap items-center gap-4">
                {receipt.logo ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={receipt.logo} alt="Logo" className="w-16 h-16 shrink-0 object-contain border border-gray-200 rounded-xl bg-gray-50 p-1" />
                ) : (
                  <div className="w-16 h-16 shrink-0 border-2 border-dashed border-gray-200 rounded-xl flex items-center justify-center text-gray-300">
                    <svg className="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                  </div>
                )}
                <div>
                  <button
                    type="button"
                    onClick={() => logoInputRef.current?.click()}
                    className="px-3 py-2 text-sm font-medium border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors text-gray-700"
                  >
                    {receipt.logo ? 'Change Logo' : 'Upload Logo'}
                  </button>
                  {receipt.logo && (
                    <button
                      type="button"
                      onClick={() => setR('logo', '')}
                      className="ml-2 px-3 py-2 text-sm text-red-500 hover:text-red-700 transition-colors"
                    >
                      Remove
                    </button>
                  )}
                  <p className="text-xs text-gray-400 mt-1">PNG or JPG, max 1 MB</p>
                </div>
                <input ref={logoInputRef} type="file" accept="image/png,image/jpeg,image/gif,image/webp" className="hidden" onChange={handleLogoUpload} />
              </div>
            </div>

            {/* Company name + tagline */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1.5">Company Name</label>
                <input value={receipt.company_name ?? ''} onChange={(e) => setR('company_name', e.target.value)} placeholder="Acme Pvt Ltd" className={inputCls} />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1.5">Tagline <span className="font-normal text-gray-400">(optional)</span></label>
                <input value={receipt.tagline ?? ''} onChange={(e) => setR('tagline', e.target.value)} placeholder="e.g. Trusted since 2010" className={inputCls} />
              </div>
            </div>

            {/* Address */}
            <div className="space-y-3">
              <label className="block text-sm font-medium text-gray-700">Address</label>
              <input value={receipt.address_line1 ?? ''} onChange={(e) => setR('address_line1', e.target.value)} placeholder="Address Line 1" className={inputCls} />
              <input value={receipt.address_line2 ?? ''} onChange={(e) => setR('address_line2', e.target.value)} placeholder="Address Line 2 (optional)" className={inputCls} />
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <input value={receipt.city ?? ''} onChange={(e) => setR('city', e.target.value)} placeholder="City" className={inputCls} />
                <input value={receipt.state ?? ''} onChange={(e) => setR('state', e.target.value)} placeholder="State" className={inputCls} />
                <input value={receipt.pincode ?? ''} onChange={(e) => setR('pincode', e.target.value)} placeholder="Pincode" className={inputCls} />
              </div>
            </div>

            {/* Contact */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1.5">Phone</label>
                <input value={receipt.phone ?? ''} onChange={(e) => setR('phone', e.target.value)} placeholder="+91 98765 43210" className={inputCls} />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1.5">Email</label>
                <input value={receipt.email ?? ''} onChange={(e) => setR('email', e.target.value)} placeholder="info@company.com" className={inputCls} />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1.5">Website <span className="font-normal text-gray-400">(optional)</span></label>
                <input value={receipt.website ?? ''} onChange={(e) => setR('website', e.target.value)} placeholder="www.company.com" className={inputCls} />
              </div>
            </div>

            {/* Tax */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1.5">GSTIN <span className="font-normal text-gray-400">(optional)</span></label>
                <input value={receipt.gstin ?? ''} onChange={(e) => setR('gstin', e.target.value)} placeholder="22AAAAA0000A1Z5" className={inputCls} />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1.5">PAN <span className="font-normal text-gray-400">(optional)</span></label>
                <input value={receipt.pan ?? ''} onChange={(e) => setR('pan', e.target.value)} placeholder="AAAAA0000A" className={inputCls} />
              </div>
            </div>

            {/* Footer note */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1.5">Receipt Footer Note <span className="font-normal text-gray-400">(optional)</span></label>
              <textarea
                rows={2}
                value={receipt.footer_note ?? ''}
                onChange={(e) => setR('footer_note', e.target.value)}
                placeholder="e.g. Thank you for your business! Payments are non-refundable."
                className={`${inputCls} resize-none`}
              />
            </div>

            <button
              type="button"
              onClick={() => { setReceiptMsg(''); setReceiptError(''); receiptMutation.mutate(); }}
              disabled={receiptMutation.isPending || !receiptReady}
              className="bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-semibold px-5 py-2.5 rounded-xl text-sm"
            >
              {receiptMutation.isPending ? 'Saving…' : `Save for ${branchName}`}
            </button>
          </div>}
        </div>
      )}

      {/* ── Organization info ────────────────────────────────────────────────── */}
      <div className="bg-white rounded-2xl border border-gray-200 p-4 sm:p-6">
        <h2 className="font-semibold text-gray-900 mb-4">Organization</h2>
        <dl className="space-y-3 text-sm">
          {[
            ['Name', user?.organization?.name],
            ['Email', user?.organization?.email],
            ['Timezone', user?.organization?.timezone],
            ['Country', user?.organization?.country],
          ].map(([label, value]) => (
            <div key={label} className="flex justify-between gap-4">
              <dt className="text-gray-500 shrink-0">{label}</dt>
              <dd className="text-gray-900 font-medium text-right break-words min-w-0">{value ?? '—'}</dd>
            </div>
          ))}
        </dl>
      </div>
    </div>
  );
}
