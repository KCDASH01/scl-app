'use client';

import { feeTotal, periodLabel, type FeePrice } from '@/lib/feePeriods';
import { formatDealMoney } from '@/lib/currency';

export default function FeePeriodEditor({ rows, onChange, unit, duration, editablePeriods = false, hostel = false, currency = 'INR' }: {
  rows: FeePrice[];
  onChange: (rows: FeePrice[]) => void;
  unit?: string | null;
  duration?: number | null;
  editablePeriods?: boolean;
  hostel?: boolean;
  currency?: string;
}) {
  const next = Math.max(0, ...rows.map((row) => row.period || 0)) + 1;
  const invalid = rows.some((row, i) => !Number.isInteger(row.period) || row.period < 1 || row.period > Number(duration)
    || rows.some((other, j) => j !== i && other.period === row.period));
  const change = (i: number, patch: Partial<FeePrice>) => onChange(rows.map((row, j) => j === i ? { ...row, ...patch } : row));
  const cls = 'w-full min-w-0 border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white';
  return <div className="space-y-3">
    <div className="overflow-x-auto">
      <table className="w-full text-sm text-left">
        <thead><tr className="text-xs text-gray-500"><th className="p-2">Period</th><th className="p-2">Course fee</th>
          {hostel && <><th className="p-2">Hostel stay</th><th className="p-2">Hostel fee</th></>}
          {editablePeriods && <th className="p-2">Action</th>}
        </tr></thead>
        <tbody>{rows.map((row, i) => <tr key={i} className="border-t border-gray-100">
          <td className="p-2">{editablePeriods ? <div className="flex items-center gap-2">
            <span>{periodLabel(unit)}</span><input aria-label={`Period number ${i + 1}`} type="number" min={1} max={duration ?? undefined} step={1}
              className={`${cls} max-w-[90px]`} value={row.period || ''} onChange={(e) => change(i, { period: Number(e.target.value) })} />
          </div> : periodLabel(unit, row.period)}</td>
          <td className="p-2"><input aria-label={`${periodLabel(unit, row.period)} course fee`} type="number" min={0} step="0.01" className={cls}
            value={row.amount ?? ''} onChange={(e) => change(i, { amount: e.target.value === '' ? null : Number(e.target.value) })} /></td>
          {hostel && <><td className="p-2"><select aria-label={`${periodLabel(unit, row.period)} hostel stay`} className={cls}
            value={row.hostel_stay ? 'yes' : 'no'} onChange={(e) => change(i, { hostel_stay: e.target.value === 'yes', hostel_amount: e.target.value === 'yes' ? row.hostel_amount ?? null : null })}>
            <option value="no">Not Stay</option><option value="yes">Stay</option>
          </select></td><td className="p-2">{row.hostel_stay ? <input aria-label={`${periodLabel(unit, row.period)} hostel fee`} type="number" min={0} step="0.01" className={cls}
            value={row.hostel_amount ?? ''} onChange={(e) => change(i, { hostel_amount: e.target.value === '' ? null : Number(e.target.value) })} /> : <span className="text-gray-400">Not applicable</span>}</td></>}
          {editablePeriods && <td className="p-2"><button type="button" onClick={() => onChange(rows.filter((_, j) => i !== j))} className="text-red-600">Delete</button></td>}
        </tr>)}</tbody>
      </table>
    </div>
    {invalid && <p role="alert" className="text-xs text-red-600">Period numbers must be unique whole numbers from 1 to {duration || 'the duration'}.</p>}
    {editablePeriods && <button type="button" disabled={!unit || !duration || invalid || next > duration}
      onClick={() => onChange([...rows, { period: next, amount: null }])}
      className="px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700 font-semibold text-sm disabled:opacity-40">
      + Add Price{unit && next <= Number(duration) ? ` (${periodLabel(unit, next)})` : ''}
    </button>}
    <p className="text-sm font-semibold text-gray-700">Total course fee: {formatDealMoney(feeTotal(rows), currency, { digits: 2 })}</p>
    {hostel && <p className="text-sm font-semibold text-gray-700">Total hostel fee: {formatDealMoney(rows.reduce((sum, row) => sum + (row.hostel_stay ? Number(row.hostel_amount ?? 0) : 0), 0), currency, { digits: 2 })}</p>}
  </div>;
}
