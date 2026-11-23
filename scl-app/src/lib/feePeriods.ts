import type { CourseDurationUnit } from '@/types';

export interface FeePrice {
  period: number;
  amount: number | null;
  hostel_stay?: boolean;
  hostel_amount?: number | null;
}

export interface FeePeriodSummary {
  period: number;
  amount: number;
  received: number;
  remaining: number;
  state: 'paid' | 'current' | 'upcoming' | 'not_applicable';
}

export interface FeeSchedule {
  unit: CourseDurationUnit;
  duration: number;
  rows: FeePrice[];
}

export interface PaymentAllocation { period: number; amount: number; remaining: number }
export interface ReceiptSnapshot {
  unit: CourseDurationUnit;
  fee_kind: string;
  total_fee: number;
  total_received: number;
  due_amount: number;
  allocations: PaymentAllocation[];
}

export function periodLabel(unit?: string | null, period?: number): string {
  const name = ({ days: 'Day', weeks: 'Week', months: 'Month', years: 'Year' } as Record<string, string>)[unit ?? ''] ?? 'Period';
  return period == null ? name : `${name} ${period}`;
}

export function feeTotal(rows: FeePrice[]): number {
  return rows.reduce((sum, row) => sum + Math.round(Number(row.amount ?? 0) * 100), 0) / 100;
}
