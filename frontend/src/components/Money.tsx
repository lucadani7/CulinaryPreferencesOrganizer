import { useExchangeRates } from '@/api/hooks';
import { formatDate, formatMoney } from '@/api/labels';

/**
 * A euro amount, followed by its value in the user's local currency when the user's country
 * is outside the euro area, e.g. "€1.49 ≈ RON 7.59" (ECB reference rate of the latest day).
 */
export default function Money({ value, className = '' }: { value: number | string | null | undefined; className?: string }) {
  const fx = useExchangeRates();
  const n = value === null || value === undefined || value === '' ? null : Number(value);
  const { local_currency: local, local_rate: rate, date } = fx.data ?? {};

  return (
    <span className={`money ${className}`}>
      {formatMoney(n)}
      {n !== null && Number.isFinite(n) && local && rate ? (
        <small className="money__local" title={`ECB rate of ${formatDate(date)}: 1 EUR = ${rate} ${local}`}>
          ≈ {formatMoney(n * rate, local)}
        </small>
      ) : null}
    </span>
  );
}
