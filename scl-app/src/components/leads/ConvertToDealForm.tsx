'use client';

import FeePeriodEditor from '@/components/ui/FeePeriodEditor';
import { feeTotal, type FeePrice } from '@/lib/feePeriods';
import { useState } from 'react';
import type { ConvertPayload } from '@/lib/api/leads';
import type { Lead, Pipeline } from '@/types';
import { PAYMENT_MODES } from '@/lib/paymentModes';
import { courseDealValue, currencySymbol, type DealCurrency } from '@/lib/currency';

const inputCls =
  'w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-shadow bg-white';

function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

interface Props {
  leadName: string;
  course?: { price?: number | null; period_prices?: FeePrice[] | null; duration_unit?: string | null; duration_value?: number | null } | null;
  pipelines: Pipeline[] | undefined;
  onSave: (payload: ConvertPayload) => void;
  onClose: () => void;
  saving?: boolean;
}

export function ConvertToDealForm({ leadName, course, pipelines, onSave, onClose, saving }: Props) {
  const [feeRows, setFeeRows] = useState<FeePrice[]>(course?.period_prices ?? []);
  const [selectedPipelineId, setSelectedPipelineId] = useState<number>(0);
  const [form, setForm] = useState<ConvertPayload>({
    pipeline_id: 0,
    stage_id: 0,
    title: `Fee — ${leadName}`,
    value: courseDealValue(course),
    currency: 'INR',
  });
  const [paymentAmount, setPaymentAmount] = useState<number | ''>('');
  const [paymentDate, setPaymentDate] = useState(todayIso);
  const [paymentMode, setPaymentMode] = useState<NonNullable<ConvertPayload['payment']>['payment_mode']>('upi');

  const pipelineId = selectedPipelineId || pipelines?.[0]?.id || 0;
  const stages = pipelines?.find((p) => p.id === pipelineId)?.stages ?? [];

  const handleSubmit = () => {
    const payload: ConvertPayload = {
      ...form,
      ...(feeRows.length ? { fee_periods: feeRows, value: feeTotal(feeRows) } : {}),
      pipeline_id: pipelineId,
      stage_id: form.stage_id,
    };

    if (paymentAmount && Number(paymentAmount) > 0) {
      payload.payment = {
        amount: Number(paymentAmount),
        payment_date: paymentDate,
        payment_mode: paymentMode,
      };
    }

    onSave(payload);
  };

  const dealValue = feeRows.length ? feeTotal(feeRows) : Number(form.value ?? 0);
  const received = paymentAmount ? Number(paymentAmount) : 0;
  const willAutoWin = dealValue > 0 && received >= dealValue;
  const symbol = currencySymbol(form.currency);

  return (
    <div className="space-y-4">
      <div className="bg-indigo-50 rounded-xl p-3 text-sm text-indigo-700">
        Converting <strong>{leadName}</strong> to fee collection.
        {willAutoWin && (
          <p className="mt-1 text-emerald-700 font-medium">
            Full payment recorded — this will be marked as Won.
          </p>
        )}
      </div>

      <div>
        <label className="block text-xs font-semibold text-gray-600 mb-1">Title</label>
        <input
          value={form.title ?? ''}
          onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))}
          className={inputCls}
        />
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1">Pipeline</label>
          <select
            value={pipelineId}
            onChange={(e) => {
              const id = Number(e.target.value);
              setSelectedPipelineId(id);
              setForm((f) => ({ ...f, pipeline_id: id, stage_id: 0 }));
            }}
            className={inputCls}
          >
            {pipelines?.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1">Stage *</label>
          <select
            value={form.stage_id}
            onChange={(e) => setForm((f) => ({ ...f, stage_id: Number(e.target.value) }))}
            className={inputCls}
          >
            <option value={0}>Select stage…</option>
            {stages.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
              </option>
            ))}
          </select>
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1">Type</label>
          <select
            value={form.currency ?? 'INR'}
            onChange={(e) =>
              setForm((f) => ({ ...f, currency: e.target.value as DealCurrency }))
            }
            className={inputCls}
          >
            <option value="INR">INR (₹)</option>
            <option value="USD">Dollar ($)</option>
          </select>
        </div>
        <div>
          <label className="block text-xs font-semibold text-gray-600 mb-1">Fee Value ({symbol})</label>
          <input
            type="number"
            min={0}
            value={feeRows.length ? feeTotal(feeRows) : form.value ?? ''}
            readOnly={feeRows.length > 0}
            onChange={(e) =>
              setForm((f) => ({ ...f, value: e.target.value ? Number(e.target.value) : undefined }))
            }
            placeholder={courseDealValue(course) != null ? String(courseDealValue(course)) : '0'}
            className={inputCls}
          />
          {courseDealValue(course) != null && (
            <p className="mt-1 text-[11px] text-gray-400">Filled from course price. You can change it.</p>
          )}
        </div>
      </div>

      {feeRows.length > 0 && <FeePeriodEditor rows={feeRows} onChange={setFeeRows} currency={form.currency} unit={course?.duration_unit} duration={course?.duration_value} />}
      <div className="border border-gray-100 rounded-xl p-3 space-y-3 bg-gray-50/50">
        <p className="text-xs font-semibold text-gray-600">Initial Payment (optional)</p>
        <div>
          <label className="block text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">
            Amount Received ({symbol})
          </label>
          <input
            type="number"
            min={0}
            step="0.01"
            value={paymentAmount}
            onChange={(e) => setPaymentAmount(e.target.value ? Number(e.target.value) : '')}
            placeholder="0"
            className={inputCls}
          />
        </div>
        {paymentAmount !== '' && Number(paymentAmount) > 0 && (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">
                Payment Date
              </label>
              <input
                type="date"
                value={paymentDate}
                onChange={(e) => setPaymentDate(e.target.value)}
                className={inputCls}
              />
            </div>
            <div>
              <label className="block text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">
                Payment Mode
              </label>
              <select
                value={paymentMode}
                onChange={(e) =>
                  setPaymentMode(e.target.value as NonNullable<ConvertPayload['payment']>['payment_mode'])
                }
                className={inputCls}
              >
                {PAYMENT_MODES.map((m) => (
                  <option key={m.value} value={m.value}>{m.label}</option>
                ))}
              </select>
            </div>
          </div>
        )}
      </div>

      <div className="flex gap-3 pt-1">
        <button
          onClick={handleSubmit}
          disabled={saving || !form.stage_id}
          className="flex-1 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60 text-white font-semibold py-2.5 rounded-xl text-sm flex items-center justify-center gap-2 transition-colors"
        >
          {saving && (
            <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
          )}
          Convert to Course Fee Collection
        </button>
        <button
          onClick={onClose}
          className="px-5 py-2.5 text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded-xl transition-colors"
        >
          Cancel
        </button>
      </div>
    </div>
  );
}

/** @deprecated use leadName prop directly */
export function ConvertToDealFormFromLead({
  lead,
  ...props
}: Omit<Props, 'leadName'> & { lead: Lead }) {
  return <ConvertToDealForm {...props} leadName={lead.full_name} course={lead.course} />;
}
