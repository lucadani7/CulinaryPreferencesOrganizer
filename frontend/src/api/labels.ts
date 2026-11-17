import type { Diet, ListStatus, Perishability, Sentiment } from './types';

export const LOCALE = 'en-GB';

export const SENTIMENTS: { value: Sentiment; label: string; emoji: string }[] = [
  { value: 'love', label: 'Love', emoji: '😍' },
  { value: 'like', label: 'Like', emoji: '🙂' },
  { value: 'neutral', label: 'Neutral', emoji: '😐' },
  { value: 'dislike', label: 'Dislike', emoji: '🙁' },
  { value: 'avoid', label: 'Avoid', emoji: '🚫' },
];

export const sentimentLabel = (s: Sentiment | null | undefined) =>
  SENTIMENTS.find((x) => x.value === s) ?? null;

export const DIETS: { value: Diet; label: string }[] = [
  { value: 'omnivore', label: 'Omnivore' },
  { value: 'vegetarian', label: 'Vegetarian' },
  { value: 'vegan', label: 'Vegan' },
  { value: 'pescatarian', label: 'Pescatarian' },
];

export const PERISHABILITY: { value: Perishability; label: string }[] = [
  { value: 'high', label: 'High' },
  { value: 'medium', label: 'Medium' },
  { value: 'low', label: 'Low' },
];

export const STATUS: Record<ListStatus, string> = {
  open: 'Open',
  completed: 'Completed',
  archived: 'Archived',
};

export const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** All prices are stored in this currency. */
export const BASE_CURRENCY = 'EUR';

export const formatMoney = (v: number | string | null | undefined, currency = BASE_CURRENCY) => {
  if (v === null || v === undefined || v === '') return '—';
  const n = Number(v);
  if (!Number.isFinite(n)) return '—';
  try {
    return new Intl.NumberFormat(LOCALE, { style: 'currency', currency }).format(n);
  } catch {
    // unknown currency code: show the plain number followed by the code
    return `${n.toFixed(2)} ${currency}`;
  }
};

/**
 * Date-only values ("2026-11-20", or Laravel's 'date' cast "2026-11-20T00:00:00.000000Z")
 * are read as local calendar dates, so they never shift to the previous day west of UTC.
 */
const parseDate = (v: string) => {
  const m = /^(\d{4})-(\d{2})-(\d{2})(?:T00:00:00(?:\.0+)?Z)?$/.exec(v);
  return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : new Date(v);
};

export const formatDate = (v: string | null | undefined) => {
  if (!v) return '—';
  const d = parseDate(v);
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleDateString(LOCALE, { day: '2-digit', month: 'short', year: 'numeric' });
};

export const seasonText = (from: number, to: number) =>
  from === 1 && to === 12 ? 'all year' : `${MONTHS[from - 1]} – ${MONTHS[to - 1]}`;