'use client';

import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'next/navigation';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AccessDenied } from '@/components/ui/AccessDenied';
import { SuperadminBranchField } from '@/components/ui/SuperadminBranchField';
import { expenseCategoriesApi, hostelExpensesApi, hostelsApi, type ExpenseCategoryPayload, type HostelExpensePayload, type HostelPayload } from '@/lib/api/hostelExpenses';
import { formatDealMoney } from '@/lib/currency';
import { PAYMENT_MODES, paymentModeLabel } from '@/lib/paymentModes';
import { keepPreviousIfSameBranch } from '@/lib/queryBranch';
import { useAuthStore } from '@/store/authStore';
import { useBranchStore } from '@/store/branchStore';
import type { ExpenseCategory, Hostel, HostelExpense } from '@/types';

interface ApiError {
  response?: { data?: { message?: string; errors?: Record<string, string[]> } };
}

type Tab = 'expenses' | 'hostels' | 'types';

function apiMessage(err: unknown, fallback: string) {
  const data = (err as ApiError)?.response?.data;
  const first = data?.errors ? Object.values(data.errors).flat()[0] : undefined;
  return first || data?.message || fallback;
}

function fieldError(err: unknown, field: string) {
  return (err as ApiError)?.response?.data?.errors?.[field]?.[0];
}

function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

function formatDate(value?: string | null) {
  if (!value) return '—';
  return new Date(`${value}T00:00:00`).toLocaleDateString('en-IN', {
    day: 'numeric', month: 'short', year: 'numeric',
  });
}

const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500';

function CloseButton({ onClick }: { onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100"
      aria-label="Close"
    >
      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
      </svg>
    </button>
  );
}

const TAB_TITLES: Record<Tab, string> = {
  expenses: 'Expenses',
  hostels: 'Hostels',
  types: 'Expense types',
};

export default function HostelExpensesPage() {
  const { canManageTeam } = useAuthStore();
  const searchParams = useSearchParams();
  const rawTab = searchParams.get('tab');
  const tab: Tab = rawTab === 'hostels' || rawTab === 'types' ? rawTab : 'expenses';

  if (!canManageTeam()) {
    return <AccessDenied reason="Hostel expenses are only available to superadmins and managers." />;
  }

  return (
    <div className="space-y-5">
      <h1 className="text-xl font-bold text-gray-900">{TAB_TITLES[tab]}</h1>
      {tab === 'expenses' && <ExpensesTab />}
      {tab === 'hostels' && <HostelsTab />}
      {tab === 'types' && <TypesTab />}
    </div>
  );
}

function ExpensesTab() {
  const qc = useQueryClient();
  const selectedBranchId = useBranchStore((s) => s.selectedBranchId);
  const [search, setSearch] = useState('');
  const [hostelId, setHostelId] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [paymentMode, setPaymentMode] = useState('');
  const [page, setPage] = useState(1);
  const [modal, setModal] = useState<HostelExpense | null | undefined>(undefined);
  const [saveError, setSaveError] = useState<unknown>(null);
  const [exporting, setExporting] = useState(false);

  useEffect(() => { setPage(1); }, [search, hostelId, categoryId, dateFrom, dateTo, paymentMode, selectedBranchId]);

  const listParams = {
    search: search || undefined,
    hostel_id: hostelId ? Number(hostelId) : undefined,
    expense_category_id: categoryId ? Number(categoryId) : undefined,
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
    payment_mode: paymentMode || undefined,
    page,
    per_page: 20,
  };

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['hostel-expenses', selectedBranchId, listParams],
    queryFn: () => hostelExpensesApi.list(listParams),
    placeholderData: keepPreviousIfSameBranch(selectedBranchId),
  });

  const { data: hostels = [] } = useQuery({
    queryKey: ['hostels', 'options', selectedBranchId],
    queryFn: () => hostelsApi.options(),
    staleTime: 60_000,
  });

  const { data: categories = [] } = useQuery({
    queryKey: ['expense-categories', 'options', selectedBranchId, hostelId],
    queryFn: () => expenseCategoriesApi.options({ hostel_id: hostelId ? Number(hostelId) : undefined }),
    staleTime: 60_000,
  });

  const rows = data?.data ?? [];
  const meta = data?.meta;
  const summary = data?.summary;

  const saveMutation = useMutation({
    mutationFn: (payload: HostelExpensePayload) =>
      modal?.id ? hostelExpensesApi.update(modal.id, payload) : hostelExpensesApi.create(payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['hostel-expenses'] });
      qc.invalidateQueries({ queryKey: ['hostels'] });
      qc.invalidateQueries({ queryKey: ['expense-categories'] });
      setModal(undefined);
      setSaveError(null);
    },
    onError: (err) => setSaveError(err),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => hostelExpensesApi.delete(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['hostel-expenses'] }),
    onError: (err) => alert(apiMessage(err, 'Could not delete expense.')),
  });

  const hasFilters = Boolean(search || hostelId || categoryId || dateFrom || dateTo || paymentMode);

  const clearFilters = () => {
    setSearch('');
    setHostelId('');
    setCategoryId('');
    setDateFrom('');
    setDateTo('');
    setPaymentMode('');
    setPage(1);
  };

  const exportCsv = async () => {
    setExporting(true);
    try {
      await hostelExpensesApi.exportCsv({ ...listParams, page: undefined, per_page: undefined });
    } catch (err) {
      alert(apiMessage(err, 'Could not export CSV.'));
    } finally {
      setExporting(false);
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex items-end justify-between gap-3 flex-wrap">
        <div className="text-sm text-gray-500">
          {summary ? `${summary.count} expenses · ${formatDealMoney(summary.total_amount)}` : 'Expense history'}
        </div>
        <div className="flex flex-wrap gap-2">
          <button
            onClick={exportCsv}
            disabled={exporting}
            className="text-sm font-semibold px-4 py-2 rounded-xl border border-gray-200 text-gray-700 disabled:opacity-50"
          >
            {exporting ? 'Exporting…' : 'Export CSV'}
          </button>
          <button
            onClick={() => { setSaveError(null); setModal(null); }}
            className="text-sm font-semibold px-4 py-2 rounded-xl bg-indigo-600 text-white"
          >
            + Add Expense
          </button>
        </div>
      </div>

      <div className="flex flex-col lg:flex-row gap-3 lg:items-center">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 flex-1">
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search notes, hostel, type" className={inputCls} />
          <select value={hostelId} onChange={(e) => { setHostelId(e.target.value); setCategoryId(''); }} className={inputCls}>
            <option value="">All hostels</option>
            {hostels.map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}
          </select>
          <select value={categoryId} onChange={(e) => setCategoryId(e.target.value)} className={inputCls}>
            <option value="">All types</option>
            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
          <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className={inputCls} />
          <input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className={inputCls} />
          <select value={paymentMode} onChange={(e) => setPaymentMode(e.target.value)} className={inputCls}>
            <option value="">All payment modes</option>
            {PAYMENT_MODES.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
          </select>
        </div>
        <button
          type="button"
          onClick={clearFilters}
          disabled={!hasFilters}
          className="text-sm font-medium px-4 py-2.5 rounded-xl border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-40 whitespace-nowrap"
        >
          Clear filters
        </button>
      </div>

      <div className="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        {isError ? (
          <div className="p-8 text-center text-sm text-red-600">
            {apiMessage(error, 'Failed to load expenses.')}
            <button onClick={() => refetch()} className="block mx-auto mt-2 text-indigo-600">Retry</button>
          </div>
        ) : isLoading ? (
          <div className="flex items-center justify-center h-48">
            <div className="w-6 h-6 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" />
          </div>
        ) : rows.length === 0 ? (
          <div className="px-6 py-12 text-center text-gray-400 text-sm">No expenses yet. Add a hostel and expense type first, then log an expense.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-[860px] w-full divide-y divide-gray-100">
              <thead className="bg-gray-50">
                <tr>
                  {['Date', 'Hostel', 'Type', 'Amount', 'Payment', 'Notes', 'Added by', ''].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider whitespace-nowrap">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {rows.map((row) => (
                  <tr key={row.id} className="hover:bg-gray-50/60">
                    <td className="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">{formatDate(row.expense_date)}</td>
                    <td className="px-4 py-3 text-sm text-gray-700 whitespace-nowrap">{row.hostel ? `${row.hostel.name} (${row.hostel.code})` : '—'}</td>
                    <td className="px-4 py-3 text-sm text-gray-700">{row.category?.name ?? '—'}</td>
                    <td className="px-4 py-3 text-sm font-medium text-gray-900 whitespace-nowrap">{formatDealMoney(row.amount)}</td>
                    <td className="px-4 py-3 text-sm text-gray-600">{paymentModeLabel(row.payment_mode)}</td>
                    <td className="px-4 py-3 text-sm text-gray-500 max-w-xs truncate">{row.notes || '—'}</td>
                    <td className="px-4 py-3 text-sm text-gray-600">{row.created_by?.name ?? '—'}</td>
                    <td className="px-4 py-3 whitespace-nowrap">
                      <button onClick={() => { setSaveError(null); setModal(row); }} className="text-xs text-indigo-600 font-medium mr-3">Edit</button>
                      <button
                        onClick={() => { if (confirm('Delete this expense?')) deleteMutation.mutate(row.id); }}
                        className="text-xs text-red-500 font-medium"
                      >
                        Delete
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {meta && meta.last_page > 1 && (
          <div className="px-4 py-3 border-t border-gray-100 flex flex-wrap items-center justify-between gap-2">
            <span className="text-xs text-gray-400">Page {meta.current_page} of {meta.last_page}</span>
            <div className="flex gap-2">
              <button onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page === 1} className="text-xs px-3 py-1.5 border border-gray-200 rounded-lg disabled:opacity-40">Previous</button>
              <button onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))} disabled={page === meta.last_page} className="text-xs px-3 py-1.5 border border-gray-200 rounded-lg disabled:opacity-40">Next</button>
            </div>
          </div>
        )}
      </div>

      {modal !== undefined && (
        <ExpenseForm
          expense={modal}
          saving={saveMutation.isPending}
          error={saveError}
          onClose={() => setModal(undefined)}
          onSave={(payload) => saveMutation.mutate(payload)}
        />
      )}
    </div>
  );
}

function ExpenseForm({ expense, saving, error, onClose, onSave }: {
  expense: HostelExpense | null;
  saving: boolean;
  error: unknown;
  onClose: () => void;
  onSave: (payload: HostelExpensePayload) => void;
}) {
  const selected = useBranchStore((s) => s.selectedBranchId);
  const [form, setForm] = useState<HostelExpensePayload>({
    branch_id: expense?.branch_id,
    hostel_id: expense?.hostel_id ?? 0,
    expense_category_id: expense?.expense_category_id ?? 0,
    amount: expense?.amount ?? 0,
    expense_date: expense?.expense_date ?? todayIso(),
    payment_mode: expense?.payment_mode ?? '',
    notes: expense?.notes ?? '',
  });

  const { data: hostels = [] } = useQuery({
    queryKey: ['hostels', 'options', selected],
    queryFn: () => hostelsApi.options(),
  });

  const hostelOptions = useMemo(() => {
    if (expense?.hostel && !hostels.some((h) => h.id === expense.hostel!.id)) {
      return [{
        id: expense.hostel.id,
        branch_id: expense.hostel.branch_id ?? expense.branch_id,
        name: expense.hostel.name,
        code: expense.hostel.code,
        is_active: expense.hostel.is_active,
      } as Hostel, ...hostels];
    }
    return hostels;
  }, [expense, hostels]);

  const { data: categories = [] } = useQuery({
    queryKey: ['expense-categories', 'options', form.hostel_id],
    queryFn: () => expenseCategoriesApi.options({ hostel_id: form.hostel_id }),
    enabled: Boolean(form.hostel_id),
  });

  const categoryOptions = useMemo(() => {
    if (expense?.category && Number(form.hostel_id) === expense.hostel_id && !categories.some((c) => c.id === expense.category!.id)) {
      return [expense.category as ExpenseCategory, ...categories];
    }
    return categories;
  }, [expense, categories, form.hostel_id]);

  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-2xl shadow-xl w-full max-w-lg p-4 sm:p-6 max-h-[90dvh] overflow-y-auto">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-gray-900">{expense ? 'Edit Expense' : 'Add Expense'}</h2>
          <CloseButton onClick={onClose} />
        </div>
        {error ? <div className="mb-4 px-4 py-3 rounded-xl bg-red-50 text-sm text-red-700">{apiMessage(error, 'Could not save expense.')}</div> : null}
        <div className="space-y-3">
          <SuperadminBranchField
            value={form.branch_id}
            onChange={(id) => setForm((f) => ({ ...f, branch_id: id, hostel_id: 0, expense_category_id: 0 }))}
          />
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Hostel *</label>
            <select
              value={form.hostel_id || ''}
              onChange={(e) => setForm((f) => ({ ...f, hostel_id: Number(e.target.value) || 0, expense_category_id: 0 }))}
              className={inputCls}
            >
              <option value="">Select hostel</option>
              {hostelOptions.map((h) => (
                <option key={h.id} value={h.id}>{h.name} ({h.code}){h.is_active ? '' : ' — inactive'}</option>
              ))}
            </select>
            {fieldError(error, 'hostel_id') && <p className="mt-1 text-xs text-red-600">{fieldError(error, 'hostel_id')}</p>}
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Expense type *</label>
            <select
              value={form.expense_category_id || ''}
              disabled={!form.hostel_id}
              onChange={(e) => setForm((f) => ({ ...f, expense_category_id: Number(e.target.value) || 0 }))}
              className={inputCls}
            >
              <option value="">{form.hostel_id ? 'Select type' : 'Select a hostel first'}</option>
              {categoryOptions.map((c) => (
                <option key={c.id} value={c.id}>{c.name}{c.is_active === false ? ' — inactive' : ''}</option>
              ))}
            </select>
            {fieldError(error, 'expense_category_id') && <p className="mt-1 text-xs text-red-600">{fieldError(error, 'expense_category_id')}</p>}
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium text-gray-700 mb-1">Amount (₹) *</label>
              <input type="number" min={0.01} step="0.01" value={form.amount || ''} onChange={(e) => setForm((f) => ({ ...f, amount: Number(e.target.value) }))} className={inputCls} />
              {fieldError(error, 'amount') && <p className="mt-1 text-xs text-red-600">{fieldError(error, 'amount')}</p>}
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-700 mb-1">Date *</label>
              <input type="date" value={form.expense_date} onChange={(e) => setForm((f) => ({ ...f, expense_date: e.target.value }))} className={inputCls} />
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Payment mode</label>
            <select value={form.payment_mode ?? ''} onChange={(e) => setForm((f) => ({ ...f, payment_mode: e.target.value || null }))} className={inputCls}>
              <option value="">Not specified</option>
              {PAYMENT_MODES.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-700 mb-1">Notes</label>
            <textarea rows={2} value={form.notes ?? ''} onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))} className={inputCls} />
          </div>
        </div>
        <div className="flex gap-3 mt-6">
          <button
            onClick={() => onSave({ ...form, payment_mode: form.payment_mode || null, notes: form.notes || null })}
            disabled={saving || !form.hostel_id || !form.expense_category_id || !form.amount}
            className="flex-1 bg-indigo-600 text-white font-semibold py-2.5 rounded-xl text-sm disabled:opacity-60"
          >
            {saving ? 'Saving…' : expense ? 'Save Changes' : 'Add Expense'}
          </button>
          <button onClick={onClose} className="px-4 text-sm text-gray-500 border border-gray-200 rounded-xl">Cancel</button>
        </div>
      </div>
    </div>
  );
}

function resolveWriteBranchId(selectedBranchId: number | 'all', formBranchId?: number) {
  if (selectedBranchId !== 'all' && Number(selectedBranchId) > 0) return Number(selectedBranchId);
  const userBranch = useAuthStore.getState().user?.branch_id;
  if (formBranchId && formBranchId > 0) return formBranchId;
  if (userBranch && userBranch > 0) return userBranch;
  return undefined;
}

function HostelsTab() {
  const qc = useQueryClient();
  const selectedBranchId = useBranchStore((s) => s.selectedBranchId);
  const lockedBranchId = selectedBranchId !== 'all' ? Number(selectedBranchId) : undefined;
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [modal, setModal] = useState<Hostel | null | undefined>(undefined);
  const [form, setForm] = useState<HostelPayload>({ branch_id: 0, name: '', code: '', address: '', capacity: null, is_active: true });
  const [saveError, setSaveError] = useState<unknown>(null);

  useEffect(() => { setPage(1); }, [search, status, selectedBranchId]);

  const { data, isLoading } = useQuery({
    queryKey: ['hostels', selectedBranchId, { search, status, page }],
    queryFn: () => hostelsApi.list({
      search: search || undefined,
      is_active: status === '' ? undefined : status === 'active',
      page,
      per_page: 20,
    }),
    placeholderData: keepPreviousIfSameBranch(selectedBranchId),
  });

  const saveMutation = useMutation({
    mutationFn: (payload: HostelPayload) =>
      modal?.id ? hostelsApi.update(modal.id, payload) : hostelsApi.create(payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['hostels'] });
      setModal(undefined);
      setSaveError(null);
    },
    onError: (err) => setSaveError(err),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => hostelsApi.delete(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['hostels'] }),
    onError: (err) => alert(apiMessage(err, 'Could not delete hostel.')),
  });

  const openCreate = () => {
    setForm({ branch_id: lockedBranchId ?? 0, name: '', code: '', address: '', capacity: null, is_active: true });
    setSaveError(null);
    setModal(null);
  };

  const rows = data?.data ?? [];
  const meta = data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search hostels..." className={`${inputCls} sm:w-64`} />
        <div className="flex gap-2 w-full sm:w-auto">
          <select value={status} onChange={(e) => setStatus(e.target.value)} className={inputCls}>
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
          <button onClick={openCreate} className="text-sm font-semibold px-4 py-2 rounded-xl bg-indigo-600 text-white whitespace-nowrap">+ Add Hostel</button>
        </div>
      </div>
      <div className="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        {isLoading ? (
          <div className="flex items-center justify-center h-40"><div className="w-6 h-6 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" /></div>
        ) : rows.length === 0 ? (
          <div className="px-6 py-12 text-center text-gray-400 text-sm">No hostels yet. <button onClick={openCreate} className="text-indigo-600">Add Hostel</button></div>
        ) : (
          <div className="overflow-x-auto">
          <table className="min-w-[700px] w-full divide-y divide-gray-100">
            <thead className="bg-gray-50">
              <tr>{['Name', 'Code', 'Branch', 'Capacity', 'Status', 'Expenses', ''].map((h) => (
                <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">{h}</th>
              ))}</tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {rows.map((row) => (
                <tr key={row.id}>
                  <td className="px-4 py-3 text-sm font-medium text-gray-900">{row.name}</td>
                  <td className="px-4 py-3 text-sm text-gray-600">{row.code}</td>
                  <td className="px-4 py-3 text-sm text-gray-600">{row.branch?.name ?? '—'}</td>
                  <td className="px-4 py-3 text-sm text-gray-600">{row.capacity ?? '—'}</td>
                  <td className="px-4 py-3"><span className={`text-xs px-2 py-0.5 rounded-full font-medium ${row.is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>{row.is_active ? 'Active' : 'Inactive'}</span></td>
                  <td className="px-4 py-3 text-sm text-gray-600">{row.expenses_count ?? 0}</td>
                  <td className="px-4 py-3 whitespace-nowrap">
                    <button onClick={() => { setForm({ branch_id: row.branch_id, name: row.name, code: row.code, address: row.address ?? '', capacity: row.capacity ?? null, is_active: row.is_active }); setSaveError(null); setModal(row); }} className="text-xs text-indigo-600 font-medium mr-3">Edit</button>
                    <button onClick={() => { if (confirm('Delete this hostel?')) deleteMutation.mutate(row.id); }} className="text-xs text-red-500 font-medium">Delete</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
        )}
        {meta && meta.last_page > 1 && (
          <div className="px-4 py-3 border-t border-gray-100 flex justify-end gap-2">
            <button onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page === 1} className="text-xs px-3 py-1.5 border rounded-lg disabled:opacity-40">Previous</button>
            <button onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))} disabled={page === meta.last_page} className="text-xs px-3 py-1.5 border rounded-lg disabled:opacity-40">Next</button>
          </div>
        )}
      </div>

      {modal !== undefined && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-lg p-4 sm:p-6 max-h-[90dvh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-semibold">{modal ? 'Edit Hostel' : 'Add Hostel'}</h2>
              <CloseButton onClick={() => setModal(undefined)} />
            </div>
            {saveError ? <div className="mb-4 px-4 py-3 rounded-xl bg-red-50 text-sm text-red-700">{apiMessage(saveError, 'Could not save hostel.')}</div> : null}
            <div className="space-y-3">
              <SuperadminBranchField value={form.branch_id || undefined} onChange={(id) => setForm((f) => ({ ...f, branch_id: id ?? 0 }))} />
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Name *</label>
                <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={inputCls} />
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Code *</label>
                <input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} className={inputCls} />
                {fieldError(saveError, 'code') && <p className="mt-1 text-xs text-red-600">{fieldError(saveError, 'code')}</p>}
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Address</label>
                <input value={form.address ?? ''} onChange={(e) => setForm({ ...form, address: e.target.value })} className={inputCls} />
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Capacity</label>
                  <input type="number" min={1} value={form.capacity ?? ''} onChange={(e) => setForm({ ...form, capacity: e.target.value ? Number(e.target.value) : null })} className={inputCls} />
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-700 mb-1">Status</label>
                  <select value={form.is_active ? 'active' : 'inactive'} onChange={(e) => setForm({ ...form, is_active: e.target.value === 'active' })} className={inputCls}>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </div>
              </div>
            </div>
            <div className="flex gap-3 mt-6">
              <button
                onClick={() => saveMutation.mutate({ ...form, branch_id: resolveWriteBranchId(selectedBranchId, form.branch_id), address: form.address || null })}
                disabled={saveMutation.isPending}
                className="flex-1 bg-indigo-600 text-white font-semibold py-2.5 rounded-xl text-sm disabled:opacity-60"
              >
                {saveMutation.isPending ? 'Saving…' : modal ? 'Save Changes' : 'Create Hostel'}
              </button>
              <button onClick={() => setModal(undefined)} className="px-4 text-sm text-gray-500 border border-gray-200 rounded-xl">Cancel</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function TypesTab() {
  const qc = useQueryClient();
  const selectedBranchId = useBranchStore((s) => s.selectedBranchId);
  const lockedBranchId = selectedBranchId !== 'all' ? Number(selectedBranchId) : undefined;
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [modal, setModal] = useState<ExpenseCategory | null | undefined>(undefined);
  const [form, setForm] = useState<ExpenseCategoryPayload>({ branch_id: 0, name: '', is_active: true });
  const [saveError, setSaveError] = useState<unknown>(null);

  useEffect(() => { setPage(1); }, [search, status, selectedBranchId]);

  const { data, isLoading } = useQuery({
    queryKey: ['expense-categories', selectedBranchId, { search, status, page }],
    queryFn: () => expenseCategoriesApi.list({
      search: search || undefined,
      is_active: status === '' ? undefined : status === 'active',
      page,
      per_page: 20,
    }),
    placeholderData: keepPreviousIfSameBranch(selectedBranchId),
  });

  const saveMutation = useMutation({
    mutationFn: (payload: ExpenseCategoryPayload) =>
      modal?.id ? expenseCategoriesApi.update(modal.id, payload) : expenseCategoriesApi.create(payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['expense-categories'] });
      setModal(undefined);
      setSaveError(null);
    },
    onError: (err) => setSaveError(err),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => expenseCategoriesApi.delete(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['expense-categories'] }),
    onError: (err) => alert(apiMessage(err, 'Could not delete expense type.')),
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search types..." className={`${inputCls} sm:w-64`} />
        <div className="flex gap-2 w-full sm:w-auto">
          <select value={status} onChange={(e) => setStatus(e.target.value)} className={inputCls}>
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
          <button
            onClick={() => { setForm({ branch_id: lockedBranchId ?? 0, name: '', is_active: true }); setSaveError(null); setModal(null); }}
            className="text-sm font-semibold px-4 py-2 rounded-xl bg-indigo-600 text-white whitespace-nowrap"
          >
            + Add type
          </button>
        </div>
      </div>
      <div className="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        {isLoading ? (
          <div className="flex items-center justify-center h-40"><div className="w-6 h-6 border-4 border-indigo-600 border-t-transparent rounded-full animate-spin" /></div>
        ) : rows.length === 0 ? (
          <div className="px-6 py-12 text-center text-gray-400 text-sm">No expense types yet.</div>
        ) : (
          <div className="overflow-x-auto">
          <table className="min-w-[560px] w-full divide-y divide-gray-100">
            <thead className="bg-gray-50">
              <tr>{['Name', 'Branch', 'Status', 'Expenses', ''].map((h) => (
                <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase whitespace-nowrap">{h}</th>
              ))}</tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {rows.map((row) => (
                <tr key={row.id}>
                  <td className="px-4 py-3 text-sm font-medium text-gray-900">{row.name}</td>
                  <td className="px-4 py-3 text-sm text-gray-600">{row.branch?.name ?? '—'}</td>
                  <td className="px-4 py-3"><span className={`text-xs px-2 py-0.5 rounded-full font-medium ${row.is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>{row.is_active ? 'Active' : 'Inactive'}</span></td>
                  <td className="px-4 py-3 text-sm text-gray-600">{row.expenses_count ?? 0}</td>
                  <td className="px-4 py-3 whitespace-nowrap">
                    <button onClick={() => { setForm({ branch_id: row.branch_id, name: row.name, is_active: row.is_active }); setSaveError(null); setModal(row); }} className="text-xs text-indigo-600 font-medium mr-3">Edit</button>
                    <button onClick={() => { if (confirm('Delete this expense type?')) deleteMutation.mutate(row.id); }} className="text-xs text-red-500 font-medium">Delete</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
        )}
      </div>

      {modal !== undefined && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-md p-4 sm:p-6 max-h-[90dvh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-semibold">{modal ? 'Edit expense type' : 'Add expense type'}</h2>
              <CloseButton onClick={() => setModal(undefined)} />
            </div>
            {saveError ? <div className="mb-4 px-4 py-3 rounded-xl bg-red-50 text-sm text-red-700">{apiMessage(saveError, 'Could not save type.')}</div> : null}
            <div className="space-y-3">
              <SuperadminBranchField value={form.branch_id || undefined} onChange={(id) => setForm((f) => ({ ...f, branch_id: id ?? 0 }))} />
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Name *</label>
                <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={inputCls} />
                {fieldError(saveError, 'name') && <p className="mt-1 text-xs text-red-600">{fieldError(saveError, 'name')}</p>}
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Status</label>
                <select value={form.is_active ? 'active' : 'inactive'} onChange={(e) => setForm({ ...form, is_active: e.target.value === 'active' })} className={inputCls}>
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
            </div>
            <div className="flex gap-3 mt-6">
              <button
                onClick={() => saveMutation.mutate({ ...form, branch_id: resolveWriteBranchId(selectedBranchId, form.branch_id) })}
                disabled={saveMutation.isPending}
                className="flex-1 bg-indigo-600 text-white font-semibold py-2.5 rounded-xl text-sm disabled:opacity-60"
              >
                {saveMutation.isPending ? 'Saving…' : modal ? 'Save Changes' : 'Create type'}
              </button>
              <button onClick={() => setModal(undefined)} className="px-4 text-sm text-gray-500 border border-gray-200 rounded-xl">Cancel</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
