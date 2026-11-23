import { formatDealMoney } from '@/lib/currency';
import { periodLabel } from '@/lib/feePeriods';

export interface FeeAuditEntry {
  id: number; actor_name?: string | null; payment_id?: number; action: string; reason?: string; created_at: string;
  before: unknown; after: unknown;
}

function record(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? value as Record<string, unknown> : {};
}

function describe(value: unknown, entry: FeeAuditEntry, currency: string): string[] {
  if (entry.action === 'payment_corrected' && Array.isArray(value)) {
    const payment = record(value.find((item) => record(item).id === entry.payment_id));
    const lines = [
      `Amount: ${formatDealMoney(Number(payment.amount), currency, { digits: 2 })}`,
      `Date: ${String(payment.payment_date ?? '').slice(0, 10)}`,
      `Mode: ${String(payment.payment_mode ?? '').replace(/_/g, ' ')}`,
    ];
    if (payment.notes) lines.push(`Notes: ${payment.notes}`);
    if (Array.isArray(payment.allocations)) payment.allocations.forEach((item) => {
      const allocation = record(item);
      lines.push(`Period ${allocation.period}: ${formatDealMoney(Number(allocation.amount), currency, { digits: 2 })}`);
    });
    return lines;
  }
  const schedule = record(record(value).schedule ?? value);
  return Array.isArray(schedule.fee_periods) ? schedule.fee_periods.map((item) => {
    const row = record(item);
    return `${periodLabel(String(schedule.fee_unit), Number(row.period))}: ${formatDealMoney(Number(row.amount), currency, { digits: 2 })}`;
  }) : ['Whole-course fee'];
}

export default function FeeChangeHistory({ entries, currency }: { entries?: FeeAuditEntry[]; currency: string }) {
  if (!entries?.length) return null;
  return <details className="border rounded-xl p-3 text-xs">
    <summary className="cursor-pointer font-semibold text-gray-700">Change History ({entries.length})</summary>
    <div className="mt-3 space-y-3">{entries.map((entry) => <div key={entry.id} className="border-t pt-3 space-y-2">
      <p className="font-semibold">{entry.action === 'payment_corrected' ? `Payment #${entry.payment_id} corrected` : entry.action === 'first_period_converted' ? 'Existing fee assigned to period 1' : entry.action === 'course_periods_added' ? 'Course periods extended' : 'Fee schedule updated'}</p>
      <p className="text-gray-500">{new Date(entry.created_at).toLocaleString('en-IN')}{entry.actor_name ? ` - ${entry.actor_name}` : ''}{entry.reason ? ` - ${entry.reason}` : ''}</p>
      <div className="grid grid-cols-2 gap-3">{(['before', 'after'] as const).map((key) => <div key={key} className="rounded-lg bg-gray-50 p-2">
        <p className="font-semibold capitalize mb-1">{key}</p>
        {describe(entry[key], entry, currency).map((line, i) => <p key={i}>{line}</p>)}
      </div>)}</div>
    </div>)}</div>
  </details>;
}
