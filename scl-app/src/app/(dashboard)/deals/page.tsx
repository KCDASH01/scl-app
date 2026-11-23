'use client';

import { useState } from 'react';
import { usePathname } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { motion, AnimatePresence } from 'framer-motion';
import { dealsApi, type DealPayload } from '@/lib/api/deals';
import { leadsApi } from '@/lib/api/leads';
import { admissionsApi } from '@/lib/api/admissions';
import { pipelinesApi } from '@/lib/api/pipelines';
import { organizationApi } from '@/lib/api/organization';
import { useAuthStore } from '@/store/authStore';
import { useBranchStore } from '@/store/branchStore';
import { Modal } from '@/components/ui/Modal';
import { Badge } from '@/components/ui/Badge';
import { SkeletonTable } from '@/components/ui/Skeleton';
import FeeChangeHistory from '@/components/deals/FeeChangeHistory';
import FeeHistory from '@/components/deals/FeeHistory';
import { periodLabel } from '@/lib/feePeriods';
import MoneyReceipt from '@/components/deals/MoneyReceipt';
import { SuperadminBranchField } from '@/components/ui/SuperadminBranchField';
import type { Deal, DealPayment, PaginatedResponse } from '@/types';
import { PAYMENT_MODES, paymentModeLabel } from '@/lib/paymentModes';
import { courseDealValue, currencyLabel, currencySymbol, formatDealMoney, normalizeDealCurrency } from '@/lib/currency';
import { keepPreviousIfSameBranch } from '@/lib/queryBranch';

const LOST_REASONS = [
  'Price too high',
  'Went with competitor',
  'No budget',
  'No response',
  'Not a fit',
  'Other',
];

function fmtDate(d: string) {
  return new Date(d).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: '2-digit' });
}

function dealRemaining(deal: Deal): number | null {
  if (deal.value == null) return null;
  const received = Number(deal.total_received ?? 0);
  return Math.max(0, Number(deal.value) - received);
}

function DealForm({ deal, onClose, onSave, saving, feeKind = 'course' }: {
  deal?: Deal | null;
  onClose: () => void;
  onSave: (d: DealPayload) => void;
  saving?: boolean;
  feeKind?: 'course' | 'hostel';
}) {
  const selected = useBranchStore((s) => s.selectedBranchId);
  const { data: pipelines } = useQuery({
    queryKey: ['pipelines', selected],
    queryFn: pipelinesApi.list,
  });
  const isCreate = !deal?.id;
  const [form, setForm] = useState<DealPayload>({
    title:               deal?.title ?? '',
    branch_id:           deal?.branch_id,
    lead_id:             deal?.lead_id ?? deal?.lead?.id,
    pipeline_id:         deal?.pipeline_id ?? 0,
    stage_id:            deal?.stage_id ?? 0,
    value:               deal?.value,
    currency:            deal?.currency ?? 'INR',
    status:              deal?.status ?? 'open',
    probability:         deal?.probability,
    expected_close_date: deal?.expected_close_date ?? '',
    description:         deal?.description ?? '',
    lost_reason:         deal?.lost_reason ?? '',
    original_value:      deal?.original_value,
    counter_offer_value: deal?.counter_offer_value,
    negotiation_notes:   deal?.negotiation_notes ?? '',
  });

  const set = (k: keyof DealPayload, v: unknown) => setForm((f) => ({ ...f, [k]: v }));
  const branchScope = selected !== 'all' ? selected : form.branch_id;
  const scopedPipelines = pipelines ?? [];
  const pipelineId = form.pipeline_id || scopedPipelines[0]?.id || 0;

  const { data: leadOptions = [], isLoading: leadsLoading } = useQuery({
    queryKey: ['leads', 'options', branchScope],
    queryFn: () => leadsApi.options({ branch_id: branchScope ? Number(branchScope) : undefined }),
    enabled: feeKind === 'course' && isCreate && Boolean(branchScope),
    staleTime: 30_000,
  });
  const { data: hostelPage, isLoading: hostelLoading } = useQuery({
    queryKey: ['admissions', 'hostel-options', branchScope],
    queryFn: () => admissionsApi.list({ hostel_stay: 1, per_page: 200 }),
    enabled: feeKind === 'hostel' && isCreate && Boolean(branchScope),
    staleTime: 30_000,
  });
  const hostelStudents = (hostelPage?.data ?? []).filter(
    (admission) => !branchScope || admission.branch_id === Number(branchScope),
  );
  const stages     = scopedPipelines.find((p) => p.id === pipelineId)?.stages ?? [];
  const feeLabel = feeKind === 'hostel' ? 'Hostel Fee' : 'Course Fee';
  const usesPeriodPrices = isCreate && Boolean(feeKind === 'course' ? leadOptions.find((lead) => lead.id === form.lead_id)?.course?.period_prices?.length : hostelStudents.find((student) => student.lead_id === form.lead_id)?.fee_schedule);

  const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-shadow';
  const symbol = currencySymbol(form.currency);

  return (
    <div className="space-y-4">
      <SuperadminBranchField
        value={form.branch_id}
        onChange={(id) => setForm((f) => ({ ...f, branch_id: id, lead_id: undefined, pipeline_id: 0, stage_id: 0, title: '' }))}
      />
      {isCreate && feeKind === 'hostel' ? (
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Student *</label>
          <select
            value={form.lead_id ?? ''}
            disabled={!branchScope}
            onChange={(e) => {
              const id = e.target.value ? Number(e.target.value) : undefined;
              const selectedStudent = hostelStudents.find((admission) => admission.lead_id === id);
              const name = selectedStudent?.lead?.full_name;
              setForm((f) => ({
                ...f,
                lead_id: id,
                title: name ? `Hostel Fee — ${name}` : f.title,
                value: selectedStudent?.hostel_amount != null ? Number(selectedStudent.hostel_amount) : undefined,
              }));
            }}
            className={inputCls}
          >
            <option value="">{branchScope ? 'Select hostel student' : 'Select a branch first'}</option>
            {hostelLoading && <option value="" disabled>Loading students…</option>}
            {hostelStudents.map((admission) => (
              <option key={admission.id} value={admission.lead_id}>
                {admission.lead?.full_name || admission.enrollment_id}
                {` · ${admission.enrollment_id}`}
                {admission.hostel_amount != null ? ` · ₹${Number(admission.hostel_amount).toLocaleString('en-IN')}` : ''}
              </option>
            ))}
          </select>
          <p className="mt-1 text-[11px] text-gray-400">Only students with Hostel stay checked on their admission.</p>
        </div>
      ) : isCreate ? (
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Lead *</label>
          <select
            value={form.lead_id ?? ''}
            disabled={!branchScope}
            onChange={(e) => {
              const id = e.target.value ? Number(e.target.value) : undefined;
              const selectedLead = leadOptions.find((lead) => lead.id === id);
              setForm((f) => ({
                ...f,
                lead_id: id,
                title: selectedLead ? `Fee — ${selectedLead.full_name}` : f.title,
                value: selectedLead ? courseDealValue(selectedLead.course) : undefined,
              }));
            }}
            className={inputCls}
          >
            <option value="">{branchScope ? 'Select lead' : 'Select a branch first'}</option>
            {leadsLoading && <option value="" disabled>Loading leads…</option>}
            {leadOptions.map((lead) => (
              <option key={lead.id} value={lead.id}>
                {lead.full_name}{lead.course ? ` · ${lead.course.name}` : ''}{lead.phone ? ` · ${lead.phone}` : ''}
              </option>
            ))}
          </select>
        </div>
      ) : deal?.lead ? (
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Lead</label>
          <p className="text-sm text-gray-800 py-2">{deal.lead.full_name}</p>
        </div>
      ) : null}
      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1.5">Title *</label>
        <input
          value={form.title}
          onChange={(e) => set('title', e.target.value)}
          placeholder={feeKind === 'hostel' ? 'e.g. Hostel Fee — Student name' : 'e.g. Fee — Student name'}
          className={inputCls}
        />
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Pipeline</label>
          <select
            value={pipelineId || ''}
            disabled={!branchScope}
            onChange={(e) => {
              const id = Number(e.target.value);
              set('pipeline_id', id);
              set('stage_id', 0);
            }}
            className={inputCls}
          >
            <option value="">{branchScope ? 'Select pipeline' : 'Select a branch first'}</option>
            {scopedPipelines.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </div>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Stage *</label>
          <select value={form.stage_id} onChange={(e) => set('stage_id', Number(e.target.value))} className={inputCls}>
            <option value={0}>Select stage…</option>
            {stages.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Type</label>
          <select
            value={form.currency ?? 'INR'}
            onChange={(e) => set('currency', e.target.value)}
            className={inputCls}
          >
            <option value="INR">INR (₹)</option>
            <option value="USD">Dollar ($)</option>
          </select>
        </div>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Value ({symbol})</label>
          <input
            type="number"
            min={0}
            value={form.value ?? ''}
            readOnly={usesPeriodPrices}
            onChange={(e) => set('value', e.target.value ? Number(e.target.value) : undefined)}
            placeholder="0"
            className={inputCls}
          />
          {isCreate && form.lead_id ? (
            <p className="mt-1 text-[11px] text-gray-400">
              {usesPeriodPrices ? 'Total from the agreed period prices. Manage individual prices in Student Admission.' : feeKind === 'hostel'
                ? 'Filled from the hostel amount on the admission. You can change it.'
                : 'Filled from the lead’s course price. You can change it.'}
            </p>
          ) : null}
        </div>
      </div>

      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1.5">Probability (%)</label>
        <input
          type="number"
          min={0}
          max={100}
          value={form.probability ?? ''}
          onChange={(e) => set('probability', e.target.value ? Number(e.target.value) : undefined)}
          placeholder="50"
          className={inputCls}
        />
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Status</label>
          <select value={form.status} disabled={usesPeriodPrices} onChange={(e) => set('status', e.target.value)} className={inputCls}>
            {['open', 'won', 'lost'].map((s) => (
              <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Expected Close Date</label>
          <input
            type="date"
            value={form.expected_close_date ?? ''}
            onChange={(e) => set('expected_close_date', e.target.value)}
            className={inputCls}
          />
        </div>
      </div>

      {form.status === 'lost' && (
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Lost Reason</label>
          <select
            value={form.lost_reason ?? ''}
            onChange={(e) => set('lost_reason', e.target.value)}
            className={inputCls}
          >
            <option value="">No reason specified</option>
            {LOST_REASONS.map((r) => <option key={r} value={r}>{r}</option>)}
          </select>
        </div>
      )}

      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1.5">Description</label>
        <textarea
          rows={2}
          value={form.description ?? ''}
          onChange={(e) => set('description', e.target.value)}
          placeholder="Optional notes…"
          className={`${inputCls} resize-none`}
        />
      </div>

      {/* ── Negotiation Section ──────────────────────────────────────────── */}
      <div className="border border-indigo-100 rounded-2xl overflow-hidden">
        <div className="bg-indigo-50/60 px-4 py-2.5 flex items-center gap-2">
          <svg className="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
          </svg>
          <span className="text-xs font-bold text-indigo-700 uppercase tracking-wide">Negotiation</span>
        </div>

        <div className="p-4 space-y-4">
          {/* Counter-offer tracker */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-semibold text-gray-600 mb-1.5">
                Original Value ({symbol})
                <span className="ml-1 font-normal text-gray-400">— your quote</span>
              </label>
              <input
                type="number"
                min={0}
                value={form.original_value ?? ''}
                onChange={(e) => set('original_value', e.target.value ? Number(e.target.value) : undefined)}
                placeholder="e.g. 60000"
                className={inputCls}
              />
            </div>
            <div>
              <label className="block text-xs font-semibold text-gray-600 mb-1.5">
                Counter Offer ({symbol})
                <span className="ml-1 font-normal text-gray-400">— client asks</span>
              </label>
              <input
                type="number"
                min={0}
                value={form.counter_offer_value ?? ''}
                onChange={(e) => set('counter_offer_value', e.target.value ? Number(e.target.value) : undefined)}
                placeholder="e.g. 45000"
                className={inputCls}
              />
            </div>
          </div>

          {/* Live difference indicator */}
          {form.original_value != null && form.counter_offer_value != null && (
            (() => {
              const diff   = Number(form.counter_offer_value) - Number(form.original_value);
              const pct    = ((diff / Number(form.original_value)) * 100).toFixed(1);
              const isDown = diff < 0;
              return (
                <div className={`flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold ${
                  isDown ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-700'
                }`}>
                  <svg className="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                      d={isDown ? 'M13 17H8m0 0l4-4m-4 4l4 4M21 12a9 9 0 11-18 0 9 9 0 0118 0z'
                               : 'M13 7h8m0 0l-4 4m4-4l-4-4M5 12a9 9 0 1018 0 9 9 0 01-18 0z'} />
                  </svg>
                  {isDown ? 'Client asking' : 'Client offering'}
                  {' '}{formatDealMoney(Math.abs(diff), form.currency)} {isDown ? 'less' : 'more'}
                  {' '}({isDown ? '' : '+'}{pct}%) —{' '}
                  gap between {formatDealMoney(form.original_value, form.currency)} and {formatDealMoney(form.counter_offer_value, form.currency)}
                </div>
              );
            })()
          )}

          {/* Negotiation notes */}
          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1.5">Negotiation Notes</label>
            <textarea
              rows={3}
              value={form.negotiation_notes ?? ''}
              onChange={(e) => set('negotiation_notes', e.target.value)}
              placeholder="e.g. Client wants 20% discount + 6-month payment plan. Legal reviewing contract. Decision by Friday."
              className={`${inputCls} resize-none`}
            />
          </div>
        </div>
      </div>

      <div className="flex gap-3 pt-2">
        <button
          onClick={() => onSave({ ...form, fee_kind: feeKind, pipeline_id: pipelineId, stage_id: form.stage_id, branch_id: form.branch_id })}
          disabled={saving || !form.title.trim() || !form.stage_id || (isCreate && !form.lead_id) || (selected === 'all' && !form.branch_id)}
          className="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-semibold
                     py-2.5 rounded-xl text-sm flex items-center justify-center gap-2 transition-colors"
        >
          {saving && (
            <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
          )}
          {deal ? 'Save Changes' : `Create ${feeLabel}`}
        </button>
        <button
          onClick={onClose}
          className="px-5 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-xl transition-colors"
        >
          Cancel
        </button>
      </div>
    </div>
  );
}

const STATUS_PILLS = [
  { value: '',     label: 'All' },
  { value: 'open', label: 'Open' },
  { value: 'won',  label: 'Won' },
  { value: 'lost', label: 'Lost' },
];

// ── Add Payment Modal ─────────────────────────────────────────────────────────
function AddPaymentModal({ deal, onClose, onSave, saving }: {
  deal: Deal;
  onClose: () => void;
  onSave: (payload: { amount: number; payment_date: string; payment_mode: string; txn_or_utr_number?: string; notes?: string }) => void;
  saving?: boolean;
}) {
  const [amount, setAmount] = useState('');
  const [date, setDate]     = useState(new Date().toISOString().slice(0, 10));
  const [mode, setMode]     = useState('bank_transfer');
  const [utr, setUtr]       = useState('');
  const [notes, setNotes]   = useState('');

  const inputCls = 'w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 transition-shadow';

  const fullRemaining = dealRemaining(deal);
  const exceedsBalance = Boolean(deal.fee_periods?.length && fullRemaining != null && Number(amount) > fullRemaining);
  const handleSave = () => {
    if (exceedsBalance) return;
    onSave({
      amount: Number(amount),
      payment_date: date,
      payment_mode: mode,
      ...(utr.trim()   ? { txn_or_utr_number: utr.trim() }   : {}),
      ...(notes.trim() ? { notes: notes.trim() }              : {}),
    });
  };

  return (
    <div className="space-y-4">
      <p className="text-sm text-gray-500">
        Add a new payment for <span className="font-semibold text-gray-800">{deal.title}</span>.
      </p>

      {deal.total_received != null && deal.total_received > 0 && (
        <div className="bg-emerald-50 border border-emerald-100 rounded-xl px-4 py-2 text-sm text-emerald-700 flex items-center gap-2">
          <svg className="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          Total received so far: <strong>{formatDealMoney(deal.total_received, deal.currency)}</strong>
          {deal.payments_count != null && deal.payments_count > 0 && (
            <span className="text-emerald-500">({deal.payments_count} payment{deal.payments_count !== 1 ? 's' : ''})</span>
          )}
        </div>
      )}

      {deal.fee_periods?.length ? <div className="text-xs text-indigo-700 bg-indigo-50 rounded-xl p-3">
        Outstanding across all periods: {formatDealMoney(fullRemaining, deal.currency, { digits: 2 })}. Payments fill the earliest unpaid period and continue to the next.
        <FeeHistory periods={deal.fee_periods} unit={deal.fee_unit} currency={deal.currency} />
      </div> : null}
      {exceedsBalance && <p role="alert" className="text-xs text-red-600">Payment exceeds the remaining total fee balance.</p>}
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Amount ({currencySymbol(deal.currency)}) *</label>
          <input
            type="number"
            min={0.01}
            step="0.01"
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            placeholder="e.g. 50000"
            className={inputCls}
            autoFocus
          />
        </div>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1.5">Payment Date *</label>
          <input
            type="date"
            value={date}
            onChange={(e) => setDate(e.target.value)}
            className={inputCls}
          />
        </div>
      </div>

      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1.5">Payment Mode *</label>
        <select value={mode} onChange={(e) => setMode(e.target.value)} className={inputCls}>
          {PAYMENT_MODES.map((m) => (
            <option key={m.value} value={m.value}>{m.label}</option>
          ))}
        </select>
      </div>

      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1.5">
          UTR / Transaction #
          <span className="ml-1 font-normal text-gray-400">— optional</span>
        </label>
        <input
          value={utr}
          onChange={(e) => setUtr(e.target.value)}
          placeholder="e.g. UTR123456789 or TXN-001"
          className={inputCls}
        />
      </div>

      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1.5">
          Notes
          <span className="ml-1 font-normal text-gray-400">— optional</span>
        </label>
        <textarea
          rows={2}
          value={notes}
          onChange={(e) => setNotes(e.target.value)}
          placeholder="e.g. First instalment, partial payment…"
          className={`${inputCls} resize-none`}
        />
      </div>

      <div className="flex gap-3 pt-1">
        <button
          onClick={handleSave}
          disabled={exceedsBalance || saving || !amount || Number(amount) <= 0 || !date || !mode}
          className="flex-1 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60 text-white font-semibold
                     py-2.5 rounded-xl text-sm flex items-center justify-center gap-2 transition-colors"
        >
          {saving && (
            <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
          )}
          Record Payment
        </button>
        <button
          onClick={onClose}
          className="px-5 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-xl transition-colors"
        >
          Cancel
        </button>
      </div>
    </div>
  );
}

// ── Transactions History Modal ────────────────────────────────────────────────
function TransactionsModal({ deal, onAddPayment, onViewReceipt }: {
  deal: Deal;
  onAddPayment: (updated: Partial<Deal>) => void;
  onViewReceipt: (payment: DealPayment, totalReceived: number) => void;
}) {
  const qc = useQueryClient();
  const canEditTransactions = useAuthStore((state) => state.isAdmin());
  const [selectedPeriod, setSelectedPeriod] = useState<number | 'all'>(deal.current_period?.state === 'current' ? deal.current_period.period : 'all');
  const [editingId, setEditingId] = useState<number | null>(null);
  const [editAmount, setEditAmount] = useState('');
  const [editDate, setEditDate] = useState('');
  const [editMode, setEditMode] = useState('bank_transfer');
  const [editUtr, setEditUtr] = useState('');
  const [editNotes, setEditNotes] = useState('');
  const [editError, setEditError] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['deal-payments', deal.id],
    queryFn: () => dealsApi.listPayments(deal.id),
    staleTime: 30_000,
  });

  const allPayments = data?.data ?? [];
  const payments = selectedPeriod === 'all' ? allPayments : allPayments.filter((payment) => payment.allocations?.some((allocation) => allocation.period === selectedPeriod));
  const total    = data?.total ?? 0;

  const startEdit = (p: DealPayment) => {
    if (!canEditTransactions) return;
    setEditingId(p.id);
    setEditAmount(String(p.amount));
    setEditDate(p.payment_date.slice(0, 10));
    setEditMode(p.payment_mode);
    setEditUtr(p.txn_or_utr_number ?? '');
    setEditNotes(p.notes ?? '');
    setEditError('');
  };

  const cancelEdit = () => {
    setEditingId(null);
    setEditAmount('');
    setEditDate('');
    setEditMode('bank_transfer');
    setEditUtr('');
    setEditNotes('');
    setEditError('');
  };

  const updatePaymentMutation = useMutation({
    mutationFn: ({
      paymentId, amount, payment_date, payment_mode, txn_or_utr_number, notes,
    }: {
      paymentId: number;
      amount: number;
      payment_date: string;
      payment_mode: string;
      txn_or_utr_number: string | null;
      notes: string | null;
    }) =>
      dealsApi.updatePayment(deal.id, paymentId, {
        amount,
        payment_date,
        payment_mode,
        txn_or_utr_number,
        notes,
      }),
    onSuccess: (result) => {
      qc.invalidateQueries({ queryKey: ['deal-payments', deal.id] });
      qc.invalidateQueries({ queryKey: ['deals'] });
      qc.invalidateQueries({ queryKey: ['sales-targets'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      qc.invalidateQueries({ queryKey: ['reports'] });
      qc.invalidateQueries({ queryKey: ['pipelines'] });
      cancelEdit();
      if (result.deal_marked_won || result.deal_reopened) {
        alert(result.message);
      }
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setEditError(msg || 'Failed to update payment.');
    },
  });

  const saveEdit = () => {
    const amount = Number(editAmount);
    if (!editDate || Number.isNaN(amount) || amount <= 0) {
      setEditError('Enter a valid amount and date.');
      return;
    }
    if (!editMode) {
      setEditError('Select a payment mode.');
      return;
    }
    if (editingId == null) return;
    updatePaymentMutation.mutate({
      paymentId: editingId,
      amount,
      payment_date: editDate,
      payment_mode: editMode,
      txn_or_utr_number: editUtr.trim() || null,
      notes: editNotes.trim() || null,
    });
  };

  const inputCls = 'border border-gray-200 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white';

  return (
    <div className="space-y-4">
      <FeeHistory periods={data?.fee_periods ?? deal.fee_periods} unit={data?.fee_unit ?? deal.fee_unit} currency={deal.currency} />
      {(data?.fee_periods ?? deal.fee_periods)?.length ? <label className="flex items-center gap-3 text-xs font-semibold text-gray-600">Transactions for
        <select value={selectedPeriod} onChange={(e) => { setSelectedPeriod(e.target.value === 'all' ? 'all' : Number(e.target.value)); cancelEdit(); }} className="border rounded-lg px-3 py-2 bg-white">
          <option value="all">All periods</option>
          {(data?.fee_periods ?? deal.fee_periods)?.map((row) => <option key={row.period} value={row.period}>{periodLabel(data?.fee_unit ?? deal.fee_unit, row.period)}</option>)}
        </select>
      </label> : null}
      {/* Header row */}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-gray-500">
          <span className="font-semibold text-gray-800">{deal.title}</span>
          {' '}· {payments.length} payment{payments.length !== 1 ? 's' : ''}
        </p>
        {total > 0 && (
          <span className="text-sm font-bold text-emerald-700">
            All payments: {formatDealMoney(total, deal.currency)}
          </span>
        )}
      </div>

      {isLoading ? (
        <div className="space-y-2">
          {[1,2,3].map((i) => (
            <div key={i} className="h-10 skeleton rounded-xl" />
          ))}
        </div>
      ) : payments.length === 0 ? (
        <div className="text-center py-8">
          <div className="w-12 h-12 bg-gray-50 rounded-2xl flex items-center justify-center mx-auto mb-3">
            <svg className="w-6 h-6 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <p className="text-sm text-gray-500">No payments recorded yet.</p>
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-[640px] w-full text-sm">
            <thead>
              <tr className="border-b border-gray-100">
                <th className="pb-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                <th className="pb-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Amount</th>
                <th className="pb-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider pl-4">Mode</th>
                <th className="pb-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider pl-4 whitespace-nowrap">UTR / Txn#</th>
                <th className="pb-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider pl-4">Notes</th>
                <th className="pb-2 pl-4" />
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {payments.map((p: DealPayment) => {
                const isEditing = canEditTransactions && editingId === p.id;
                return (
                  <tr key={p.id} className="hover:bg-gray-50/60 group">
                    <td className="py-2.5 text-gray-700 font-medium whitespace-nowrap">
                      {isEditing ? (
                        <input
                          type="date"
                          value={editDate}
                          onChange={(e) => { setEditDate(e.target.value); setEditError(''); }}
                          className={inputCls}
                        />
                      ) : (
                        fmtDate(p.payment_date)
                      )}
                    </td>
                    <td className="py-2.5 text-right font-semibold text-emerald-700 whitespace-nowrap">
                      {isEditing ? (
                        <input
                          type="number"
                          min={0.01}
                          step="0.01"
                          value={editAmount}
                          onChange={(e) => { setEditAmount(e.target.value); setEditError(''); }}
                          className={`${inputCls} w-28 text-right`}
                        />
                      ) : (
                        <>{formatDealMoney(p.amount, deal.currency)}
                          {p.allocations?.map((allocation) => <span key={allocation.period} className="block text-[10px] text-gray-500">{periodLabel(deal.fee_unit, allocation.period)}: {formatDealMoney(allocation.amount, deal.currency)}</span>)}</>
                      )}
                    </td>
                    <td className="py-2.5 pl-4 capitalize text-gray-600 whitespace-nowrap">
                      {isEditing ? (
                        <select
                          value={editMode}
                          onChange={(e) => { setEditMode(e.target.value); setEditError(''); }}
                          className={inputCls}
                        >
                          {PAYMENT_MODES.map((m) => (
                            <option key={m.value} value={m.value}>{m.label}</option>
                          ))}
                        </select>
                      ) : (
                        paymentModeLabel(p.payment_mode)
                      )}
                    </td>
                    <td className="py-2.5 pl-4 text-gray-400 font-mono text-xs">
                      {isEditing ? (
                        <input
                          value={editUtr}
                          onChange={(e) => { setEditUtr(e.target.value); setEditError(''); }}
                          placeholder="UTR / Txn#"
                          className={`${inputCls} w-36 font-mono`}
                        />
                      ) : (
                        p.txn_or_utr_number ?? '—'
                      )}
                    </td>
                    <td className="py-2.5 pl-4 text-gray-400 max-w-[160px]">
                      {isEditing ? (
                        <input
                          value={editNotes}
                          onChange={(e) => { setEditNotes(e.target.value); setEditError(''); }}
                          placeholder="Notes"
                          className={`${inputCls} w-40`}
                        />
                      ) : (
                        <span className="truncate block max-w-[160px]">{p.notes ?? '—'}</span>
                      )}
                    </td>
                    <td className="py-2.5 pl-4 whitespace-nowrap">
                      <div className="flex items-center justify-end gap-2">
                        {isEditing ? (
                          <>
                            <button
                              onClick={saveEdit}
                              disabled={updatePaymentMutation.isPending}
                              className="text-xs font-semibold px-2.5 py-1 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-60 transition-colors"
                            >
                              {updatePaymentMutation.isPending ? 'Saving…' : 'Save'}
                            </button>
                            <button
                              onClick={cancelEdit}
                              disabled={updatePaymentMutation.isPending}
                              className="text-xs font-medium px-2.5 py-1 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 transition-colors"
                            >
                              Cancel
                            </button>
                          </>
                        ) : (
                          <>
                            {canEditTransactions && <button
                              onClick={() => startEdit(p)}
                              className="flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-indigo-600 transition-colors"
                              title="Edit payment"
                            >
                              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                  d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                              </svg>
                              Edit
                            </button>}
                            <button
                              onClick={() => onViewReceipt(p, total)}
                              className="flex items-center gap-1 text-xs font-medium text-indigo-500 hover:text-indigo-700 transition-colors"
                              title="View receipt"
                            >
                              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                              </svg>
                              Receipt
                            </button>
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
            {payments.length > 1 && (
              <tfoot>
                <tr className="border-t-2 border-gray-200">
                  <td className="pt-2.5 text-xs font-semibold text-gray-500 uppercase">Total</td>
                  <td className="pt-2.5 text-right font-bold text-emerald-700">
                    {formatDealMoney(payments.reduce((sum, p) => sum + Number(p.amount), 0), deal.currency)}
                  </td>
                  <td colSpan={4} />
                </tr>
              </tfoot>
            )}
          </table>
        </div>
      )}

      <FeeChangeHistory entries={data?.history} currency={deal.currency} />
      {editError && (
        <p className="text-xs text-red-600">{editError}</p>
      )}

      {/* Add payment CTA — open deals only */}
      {(deal.fee_periods?.length ? Number(data?.fee_total ?? deal.value) > total : deal.status === 'open') && (
        <div className="pt-2 border-t border-gray-100">
          <button
            onClick={() => onAddPayment({ total_received: total, fee_periods: data?.fee_periods ?? deal.fee_periods, value: Number(data?.fee_total ?? deal.value) })}
            className="flex items-center gap-2 text-sm font-semibold text-emerald-600 hover:text-emerald-700 transition-colors"
          >
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            Add Another Payment
          </button>
        </div>
      )}
    </div>
  );
}

export default function DealsPage() {
  const pathname = usePathname();
  const feeKind: 'course' | 'hostel' = pathname.startsWith('/hostel-fees') ? 'hostel' : 'course';
  const copy = feeKind === 'hostel'
    ? {
        title: 'Hostel Fee Collection',
        add: 'Add Hostel Fee',
        first: 'Create First Hostel Fee',
        search: 'Search hostel fees…',
        empty: 'No hostel students yet',
        emptyHint: 'Students appear here after Hostel stay is checked and saved on their admission.',
        modalNew: 'New Hostel Fee',
        modalEdit: 'Edit Hostel Fee',
      }
    : {
        title: 'Course Fee Collection',
        add: 'Add Course Fee',
        first: 'Create First Course Fee',
        search: 'Search course fees…',
        empty: 'No course fees yet',
        emptyHint: 'Start tracking course fees after a lead is converted',
        modalNew: 'New Course Fee',
        modalEdit: 'Edit Course Fee',
      };
  const qc = useQueryClient();
  const { user, isOwner, isAdmin } = useAuthStore();
  const selectedBranchId = useBranchStore((s) => s.selectedBranchId);

  const isManager = isOwner() || isAdmin();
  const [viewMine, setViewMine]         = useState(!isManager);
  const [page, setPage]                 = useState(1);
  const [search, setSearch]             = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [modal, setModal]               = useState<Deal | null | undefined>(undefined);
  const [addPaymentModal, setAddPaymentModal] = useState<Deal | null>(null);
  const [txnsModal, setTxnsModal]             = useState<Deal | null>(null);
  const [receiptModal, setReceiptModal]       = useState<{ payment: DealPayment; deal: Deal } | null>(null);
  const [saveError, setSaveError]       = useState('');

  const receiptBranchId = receiptModal?.deal.branch_id;
  const { data: receiptSettings } = useQuery({
    queryKey: ['receipt-settings', receiptBranchId],
    queryFn: () => organizationApi.getReceiptSettings(receiptBranchId as number),
    enabled: receiptBranchId != null,
    staleTime: 5 * 60_000,
  });

  const assignedToFilter = viewMine ? user?.id : undefined;

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['deals', feeKind, selectedBranchId, { status: statusFilter, page, assigned_to: assignedToFilter, search }],
    queryFn: () => dealsApi.list({
      fee_kind: feeKind,
      status: statusFilter || undefined,
      assigned_to: assignedToFilter,
      search: search.trim() || undefined,
      page,
    }),
    placeholderData: keepPreviousIfSameBranch(selectedBranchId),
  });

  const createMutation = useMutation({
    mutationFn: (p: DealPayload) => dealsApi.create(p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['deals'] });
      qc.invalidateQueries({ queryKey: ['leads'] });
      qc.invalidateQueries({ queryKey: ['pipelines'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      setSaveError('');
      setModal(undefined);
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setSaveError(msg || 'Failed to create deal. Please try again.');
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, p }: { id: number; p: Partial<DealPayload> }) => dealsApi.update(id, p),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['deals'] });
      qc.invalidateQueries({ queryKey: ['leads'] });
      qc.invalidateQueries({ queryKey: ['pipelines'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      qc.invalidateQueries({ queryKey: ['reports'] });
      setSaveError('');
      setModal(undefined);
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      setSaveError(msg || 'Failed to save deal. Please try again.');
    },
  });

  // Optimistic delete
  const deleteMutation = useMutation({
    mutationFn: (id: number) => dealsApi.delete(id),
    onMutate: async (id) => {
      await qc.cancelQueries({ queryKey: ['deals'] });
      const snapshot = qc.getQueriesData<PaginatedResponse<Deal>>({ queryKey: ['deals'] });
      qc.setQueriesData<PaginatedResponse<Deal>>({ queryKey: ['deals'] }, (old) => {
        if (!old) return old;
        return { ...old, data: old.data.filter((d) => d.id !== id) };
      });
      return { snapshot };
    },
    onError: (_err, _id, ctx) => {
      if (ctx?.snapshot) {
        ctx.snapshot.forEach(([key, data]) => qc.setQueryData(key, data));
      }
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['deals'] }),
  });

  const addPaymentMutation = useMutation({
    mutationFn: ({ dealId, payload }: {
      dealId: number;
      payload: Parameters<typeof dealsApi.addPayment>[1];
    }) => dealsApi.addPayment(dealId, payload),
    onSuccess: (result, { dealId }) => {
      qc.invalidateQueries({ queryKey: ['deals'] });
      qc.invalidateQueries({ queryKey: ['deal-payments', dealId] });
      qc.invalidateQueries({ queryKey: ['sales-targets'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      setAddPaymentModal(null);
      if (result.deal_marked_won) {
        alert(result.message);
      }
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      alert(msg || 'Failed to record payment. Please try again.');
    },
  });

  const deals = data?.data ?? [];
  const meta  = data?.meta;
  const isSaving = createMutation.isPending || updateMutation.isPending;

  const inrTotal = deals
    .filter((d) => normalizeDealCurrency(d.currency) === 'INR')
    .reduce((sum, d) => sum + (Number(d.value) || 0), 0);
  const usdTotal = deals
    .filter((d) => normalizeDealCurrency(d.currency) === 'USD')
    .reduce((sum, d) => sum + (Number(d.value) || 0), 0);
  const totalValueParts = [
    inrTotal > 0 ? formatDealMoney(inrTotal, 'INR') : null,
    usdTotal > 0 ? formatDealMoney(usdTotal, 'USD') : null,
  ].filter(Boolean);

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex items-center justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-xl font-bold text-gray-900">{copy.title}</h1>
          {meta && (
            <p className="text-xs text-gray-400 mt-0.5">
              {meta.total} records{totalValueParts.length > 0 && ` · ${totalValueParts.join(' · ')} total value`}
              {viewMine ? ' · assigned to you' : ''}
            </p>
          )}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {isManager && (
            <div className="flex items-center gap-1 bg-gray-100 rounded-xl p-1">
              <button
                onClick={() => { setViewMine(true); setPage(1); }}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all ${
                  viewMine ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'
                }`}
              >
                Mine
              </button>
              <button
                onClick={() => { setViewMine(false); setPage(1); }}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-all ${
                  !viewMine ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'
                }`}
              >
                All
              </button>
            </div>
          )}
          {isManager && feeKind === 'course' && (
            <button
              onClick={() => { setSaveError(''); setModal(null); }}
              className="flex items-center gap-1.5 text-white text-sm font-semibold
                         px-4 py-2 rounded-xl transition-colors shadow-sm
                         bg-indigo-600 hover:bg-indigo-700 shadow-indigo-500/20"
            >
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
              </svg>
              {copy.add}
            </button>
          )}
        </div>
      </div>

      {/* Status filters + search */}
      <div className="flex items-center gap-3 flex-wrap">
        <div className="relative w-full sm:w-auto">
          <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
          </svg>
          <input
            type="search"
            placeholder={copy.search}
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
            className="border border-gray-200 rounded-xl pl-9 pr-4 py-2.5 text-sm w-full sm:w-64 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-shadow bg-white"
          />
        </div>
        <div className="flex gap-1 bg-gray-100 rounded-xl p-1 max-w-full overflow-x-auto no-scrollbar">
          {STATUS_PILLS.map((s) => (
            <button
              key={s.value}
              onClick={() => { setStatusFilter(s.value); setPage(1); }}
              className={`shrink-0 whitespace-nowrap px-3 py-1.5 rounded-lg text-xs font-semibold transition-all duration-150 ${
                statusFilter === s.value
                  ? 'bg-white text-gray-900 shadow-sm'
                  : 'text-gray-500 hover:text-gray-700'
              }`}
            >
              {s.label}
            </button>
          ))}
        </div>

        {isFetching && !isLoading && (
          <span className="text-xs text-gray-400 flex items-center gap-1.5">
            <svg className="w-3 h-3 animate-spin text-indigo-400" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            Updating…
          </span>
        )}
      </div>

      {/* Table */}
      <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        {isLoading ? (
          <SkeletonTable rows={6} cols={8} />
        ) : deals.length === 0 ? (
          <div className="px-6 py-16 text-center">
            <div className="w-14 h-14 bg-emerald-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
              <svg className="w-7 h-7 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                  d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            {search || statusFilter ? (
              <>
                <p className="text-gray-900 font-semibold text-sm mb-1">No records match your filters</p>
                <p className="text-xs text-gray-400 mb-4">Try a different search or status filter</p>
                <button
                  onClick={() => { setSearch(''); setStatusFilter(''); setPage(1); }}
                  className="text-sm text-indigo-600 hover:underline"
                >
                  Clear filters
                </button>
              </>
            ) : (
              <>
                <p className="text-gray-900 font-semibold text-sm mb-1">{copy.empty}</p>
                <p className="text-xs text-gray-400 mb-4">{copy.emptyHint}</p>
                {feeKind === 'course' && (
                <button
                  onClick={() => setModal(null)}
                  className="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2 rounded-xl transition-colors"
                >
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                  </svg>
                  {copy.first}
                </button>
                )}
              </>
            )}
          </div>
        ) : (
          <div className="overflow-x-auto">
          <table className="min-w-[960px] w-full divide-y divide-gray-100">
            <thead className="bg-gray-50/80">
              <tr>
                {['Title', 'Value', 'Stage', 'Status', 'Close Date', 'Received', 'Remaining', ''].map((h) => (
                  <th key={h} className="px-5 py-3 text-left text-[11px] font-semibold text-gray-500 uppercase tracking-wider whitespace-nowrap">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              <AnimatePresence initial={false}>
                {deals.map((deal, i) => {
                  const pastDue = deal.expected_close_date && deal.status === 'open' && new Date(deal.expected_close_date) < new Date();
                  return (
                    <motion.tr
                      key={deal.id}
                      initial={{ opacity: 0, y: 6 }}
                      animate={{ opacity: 1, y: 0 }}
                      exit={{ opacity: 0, x: -20 }}
                      transition={{ delay: i * 0.03, type: 'spring', damping: 30, stiffness: 400 }}
                      className="group hover:bg-gray-50/60 transition-colors"
                    >
                      <td className="px-5 py-3.5">
                        <p className="text-sm font-semibold text-gray-900">{deal.title}</p>
                        {deal.lead && <p className="text-xs text-gray-400 mt-0.5">{deal.lead.full_name}</p>}
                        <p className="text-xs text-indigo-600 mt-1">{deal.current_period ? periodLabel(deal.fee_unit, deal.current_period.period) : 'Legacy whole-course fee'}</p>
                      </td>
                      <td className="px-5 py-3.5">
                        <span className="text-sm font-semibold text-gray-800">
                          {deal.current_period ? formatDealMoney(deal.current_period.amount, deal.currency) : deal.value ? formatDealMoney(deal.value, deal.currency) : '—'}
                        </span>
                        <p className="text-[11px] text-gray-400 mt-0.5">{deal.current_period ? `Total: ${formatDealMoney(deal.value, deal.currency)}` : currencyLabel(deal.currency)}</p>
                        {deal.probability != null && (
                          <p className="text-xs text-gray-400 mt-0.5">{deal.probability}% prob.</p>
                        )}
                      </td>
                      <td className="px-5 py-3.5">
                        <span className="text-sm text-gray-600">{deal.stage?.name ?? '—'}</span>
                      </td>
                      <td className="px-5 py-3.5">
                        <Badge value={deal.status} />
                      </td>
                      <td className="px-5 py-3.5">
                        <span className={`text-sm ${
                          pastDue ? 'text-red-500 font-medium'
                            : deal.closed_at ? 'text-gray-700 font-medium'
                            : 'text-gray-400'
                        }`}>
                          {deal.status !== 'open' && deal.closed_at
                            ? fmtDate(deal.closed_at)
                            : deal.expected_close_date
                              ? fmtDate(deal.expected_close_date)
                              : '—'}
                        </span>
                      </td>
                      {/* ── Received column ── */}
                      <td className="px-5 py-3.5">
                        <div className="space-y-0.5">
                          {deal.total_received != null && deal.total_received > 0 ? (
                            <span className="text-sm font-semibold text-emerald-700">
                              {formatDealMoney(deal.current_period?.received ?? deal.total_received, deal.currency)}
                            </span>
                          ) : (
                            <span className="text-xs text-gray-400">
                              {deal.status === 'open' ? 'Not entered' : '—'}
                            </span>
                          )}
                          {(deal.payments_count ?? 0) > 0 || deal.status === 'open' ? (
                            <button
                              onClick={() => setTxnsModal(deal)}
                              className="block text-xs text-indigo-500 hover:text-indigo-700 hover:underline transition-colors"
                            >
                              View Transactions ({deal.payments_count ?? 0})
                            </button>
                          ) : null}
                        </div>
                      </td>
                      {/* ── Remaining column ── */}
                      <td className="px-5 py-3.5">
                        {(() => {
                          const remaining = deal.current_period?.remaining ?? dealRemaining(deal);
                          if (remaining == null) {
                            return <span className="text-xs text-gray-400">—</span>;
                          }
                          return (
                            <div><span className={`text-sm font-semibold ${
                              remaining === 0 ? 'text-emerald-600' : 'text-amber-700'
                            }`}>
                              {formatDealMoney(remaining, deal.currency)}
                            </span>{deal.current_period && <p className="text-[11px] text-gray-400 mt-0.5">Overall due: {formatDealMoney(dealRemaining(deal) ?? 0, deal.currency)}</p>}</div>
                          );
                        })()}
                      </td>
                      <td className="px-5 py-3.5">
                        <div className="flex items-center justify-end gap-1">
                          {/* Add Payment — open deals only */}
                          {deal.status === 'open' && (
                            <button
                              onClick={() => setAddPaymentModal(deal)}
                              className="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-emerald-600 hover:bg-emerald-50 transition-colors"
                              title="Add payment"
                            >
                              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                  d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                              </svg>
                            </button>
                          )}
                          {/* Edit — all users can update deal status/stage */}
                          <button
                            onClick={() => { setSaveError(''); setModal(deal); }}
                            className="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors"
                            title={deal.fee_periods?.length ? "Manage period prices in Admission" : "Edit"}
                          >
                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                          </button>
                          {/* Delete — managers only */}
                          {isManager && (
                            <button
                              onClick={() => { if (confirm('Delete this deal?')) deleteMutation.mutate(deal.id); }}
                              className="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-red-50 transition-colors"
                              title="Delete"
                            >
                              <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                  d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                              </svg>
                            </button>
                          )}
                        </div>
                      </td>
                    </motion.tr>
                  );
                })}
              </AnimatePresence>
            </tbody>
          </table>
          </div>
        )}

        {/* Pagination */}
        {meta && meta.last_page > 1 && (
          <div className="px-5 py-4 border-t border-gray-100 flex flex-wrap items-center justify-between gap-2 text-sm text-gray-500">
            <span className="text-xs">
              Showing {(meta.current_page - 1) * (meta.per_page ?? 15) + 1}–
              {Math.min(meta.current_page * (meta.per_page ?? 15), meta.total)} of {meta.total}
            </span>
            <div className="flex gap-2">
              <button
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page === 1}
                className="px-3 py-1.5 border border-gray-200 rounded-xl text-xs disabled:opacity-40 hover:bg-gray-50 transition-colors"
              >
                ← Prev
              </button>
              <button
                onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                disabled={page === meta.last_page}
                className="px-3 py-1.5 border border-gray-200 rounded-xl text-xs disabled:opacity-40 hover:bg-gray-50 transition-colors"
              >
                Next →
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Edit/Create Deal Modal */}
      <Modal
        open={modal !== undefined}
        onClose={() => { setModal(undefined); setSaveError(''); }}
        title={modal?.id ? copy.modalEdit : copy.modalNew}
        maxWidth="max-w-lg"
      >
        {modal !== undefined && (
          <>
            {saveError && (
              <div className="mb-4 px-4 py-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-600">
                {saveError}
              </div>
            )}
            <DealForm
              deal={modal}
              feeKind={feeKind}
              onClose={() => { setModal(undefined); setSaveError(''); }}
              onSave={(p) => modal?.id ? updateMutation.mutate({ id: modal.id, p }) : createMutation.mutate(p)}
              saving={isSaving}
            />
          </>
        )}
      </Modal>

      {/* Add Payment Modal */}
      <Modal
        open={addPaymentModal !== null}
        onClose={() => setAddPaymentModal(null)}
        title="Add Payment"
        maxWidth="max-w-lg"
      >
        {addPaymentModal && (
          <AddPaymentModal
            deal={addPaymentModal}
            onClose={() => setAddPaymentModal(null)}
            onSave={(payload) => addPaymentMutation.mutate({ dealId: addPaymentModal.id, payload })}
            saving={addPaymentMutation.isPending}
          />
        )}
      </Modal>

      {/* Transaction History Modal */}
      <Modal
        open={txnsModal !== null}
        onClose={() => setTxnsModal(null)}
        title="Payment Transactions"
        maxWidth="max-w-2xl"
      >
        {txnsModal && (
          <TransactionsModal
            deal={txnsModal}
            onAddPayment={(updated) => {
              setTxnsModal(null);
              setAddPaymentModal({ ...txnsModal, ...updated });
            }}
            onViewReceipt={(payment, totalReceived) => {
              setReceiptModal({ payment, deal: { ...txnsModal, total_received: totalReceived } });
              setTxnsModal(null);
            }}
          />
        )}
      </Modal>

      {/* Money Receipt Modal */}
      <Modal
        open={receiptModal !== null}
        onClose={() => setReceiptModal(null)}
        title="Money Receipt"
        maxWidth="max-w-3xl"
      >
        {receiptModal && receiptSettings && receiptSettings.branch_id === receiptModal.deal.branch_id ? (
          <MoneyReceipt
            payment={receiptModal.payment}
            deal={receiptModal.deal}
            settings={receiptSettings}
            onClose={() => setReceiptModal(null)}
          />
        ) : receiptModal ? (
          <div className="py-10 text-center text-sm text-gray-400">Loading receipt…</div>
        ) : null}
      </Modal>
    </div>
  );
}
