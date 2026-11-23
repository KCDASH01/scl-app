export const WEEKDAYS = [
  { id: 'mon', short: 'Mon', name: 'Monday' },
  { id: 'tue', short: 'Tue', name: 'Tuesday' },
  { id: 'wed', short: 'Wed', name: 'Wednesday' },
  { id: 'thu', short: 'Thu', name: 'Thursday' },
  { id: 'fri', short: 'Fri', name: 'Friday' },
  { id: 'sat', short: 'Sat', name: 'Saturday' },
  { id: 'sun', short: 'Sun', name: 'Sunday' },
] as const;

export type WeekdayId = (typeof WEEKDAYS)[number]['id'];

export interface ScheduleParts {
  days: WeekdayId[];
  start: string;
  end: string;
}

const EMPTY: ScheduleParts = { days: [], start: '', end: '' };

export function emptyScheduleParts(): ScheduleParts {
  return { ...EMPTY, days: [] };
}

function formatTime12(hhmm: string): string {
  if (!hhmm) return '';
  const [hourStr, minuteStr] = hhmm.split(':');
  const hour = Number(hourStr);
  const minute = Number(minuteStr);
  if (!Number.isFinite(hour) || !Number.isFinite(minute)) return '';
  const suffix = hour >= 12 ? 'PM' : 'AM';
  const hour12 = hour % 12 || 12;
  return minute === 0 ? `${hour12} ${suffix}` : `${hour12}:${String(minute).padStart(2, '0')} ${suffix}`;
}

function parseTimeToHHmm(raw: string): string {
  const value = raw.trim().toUpperCase().replace(/\./g, '');
  const match = value.match(/^(\d{1,2})(?::(\d{2}))?\s*(AM|PM)?$/);
  if (!match) return '';
  let hour = Number(match[1]);
  const minute = Number(match[2] ?? '0');
  const mer = match[3];
  if (mer === 'PM' && hour < 12) hour += 12;
  if (mer === 'AM' && hour === 12) hour = 0;
  if (hour > 23 || minute > 59) return '';
  return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
}

function formatDays(dayIds: WeekdayId[]): string {
  if (!dayIds.length) return '';
  const indexes = dayIds
    .map((id) => WEEKDAYS.findIndex((d) => d.id === id))
    .filter((i) => i >= 0)
    .sort((a, b) => a - b);
  if (!indexes.length) return '';

  const consecutive = indexes.every((n, i) => i === 0 || n === indexes[i - 1] + 1);
  if (consecutive && indexes.length > 1) {
    return `${WEEKDAYS[indexes[0]].name}–${WEEKDAYS[indexes[indexes.length - 1]].name}`;
  }
  return indexes.map((i) => WEEKDAYS[i].name).join(', ');
}

export function composeSchedule(parts: ScheduleParts): string {
  const days = formatDays(parts.days);
  const start = formatTime12(parts.start);
  const end = formatTime12(parts.end);
  const time = start && end ? `${start}–${end}` : start || end;
  return [days, time].filter(Boolean).join(', ');
}

export function parseSchedule(value?: string | null): ScheduleParts {
  const parts = emptyScheduleParts();
  if (!value?.trim()) return parts;

  const daysPart = value.includes(',') ? value.slice(0, value.lastIndexOf(',')).trim() : value.trim();
  const timePart = value.includes(',') ? value.slice(value.lastIndexOf(',') + 1).trim() : '';

  const names = daysPart
    .replace(/–/g, '-')
    .split(/,|\band\b/i)
    .flatMap((chunk) => {
      const range = chunk.split('-').map((s) => s.trim()).filter(Boolean);
      if (range.length === 2) {
        const from = WEEKDAYS.findIndex((d) => d.name.toLowerCase() === range[0].toLowerCase());
        const to = WEEKDAYS.findIndex((d) => d.name.toLowerCase() === range[1].toLowerCase());
        if (from >= 0 && to >= from) return WEEKDAYS.slice(from, to + 1).map((d) => d.id);
      }
      const one = WEEKDAYS.find((d) => d.name.toLowerCase() === chunk.trim().toLowerCase());
      return one ? [one.id] : [];
    });
  parts.days = Array.from(new Set(names)) as WeekdayId[];

  const source = timePart || (!parts.days.length ? daysPart : '');
  const times = source.replace(/–/g, '-').split('-').map((s) => s.trim()).filter(Boolean);
  if (times[0]) parts.start = parseTimeToHHmm(times[0]);
  if (times[1]) parts.end = parseTimeToHHmm(times[1]);

  return parts;
}

export function scheduleTimeError(parts: ScheduleParts): string | null {
  if ((parts.start && !parts.end) || (!parts.start && parts.end)) {
    return 'Choose both a start time and an end time.';
  }
  if (parts.start && parts.end && parts.end <= parts.start) {
    return 'End time cannot be before the start time.';
  }
  return null;
}
