import type { FeePeriodSummary } from '@/lib/feePeriods';
import { periodLabel } from '@/lib/feePeriods';
import { formatDealMoney } from '@/lib/currency';

export default function FeeHistory({ periods, unit, currency }: { periods?: FeePeriodSummary[]; unit?: string | null; currency: string }) {
  if (!periods?.length) return <p className="text-xs text-gray-500">Legacy whole-course fee</p>;
  const current = periods.find((row) => row.state === 'current');
  return <details className="border border-indigo-100 bg-indigo-50/40 rounded-xl p-3">
    <summary className="cursor-pointer text-sm font-semibold text-indigo-800">Fee History — {current ? `Current: ${periodLabel(unit, current.period)}` : 'All periods paid'}</summary>
    <div className="overflow-x-auto mt-3"><table className="w-full text-xs text-left">
      <thead><tr>{['Period', 'Fee', 'Received', 'Remaining', 'State'].map((label) => <th key={label} className="p-2">{label}</th>)}</tr></thead>
      <tbody>{periods.map((row) => <tr key={row.period} className="border-t border-indigo-100">
        <td className="p-2 whitespace-nowrap">{periodLabel(unit, row.period)}</td>
        <td className="p-2">{formatDealMoney(row.amount, currency, { digits: 2 })}</td>
        <td className="p-2">{formatDealMoney(row.received, currency, { digits: 2 })}</td>
        <td className="p-2">{formatDealMoney(row.remaining, currency, { digits: 2 })}</td>
        <td className="p-2 capitalize">{row.state.replace(/_/g, ' ')}</td>
      </tr>)}</tbody>
    </table></div>
  </details>;
}
