// Small accessible charts for the analytics page: labelled CSS bars (the alumni tracker's .rate-bar) and KPI tiles.
import type { ReactNode } from 'react';
import type { Rate } from './types';
import { percent } from './types';

export interface Bar { label: string; value: number; text?: string }

/**
 * Horizontal bars, scaled to the largest value (or to $max, for rates and averages, where 0 is still an answer).
 * The numbers are always written out.
 */
export function Bars({ rows, max, empty = 'Nothing counted yet.' }: { rows: Bar[]; max?: number; empty?: string }) {
  if (rows.length === 0 || (max === undefined && rows.every((r) => r.value === 0))) return <p className="muted small">{empty}</p>;
  const top = max ?? Math.max(...rows.map((r) => r.value));
  return (
    <ul className="bars">
      {rows.map((r) => (
        <li key={r.label}>
          <span className="bars-label">{r.label}</span>
          <span className="rate-bar" aria-hidden="true">
            <span style={{ width: `${top > 0 ? Math.round((r.value / top) * 100) : 0}%` }} />
          </span>
          <span className="num bars-value">{r.text ?? r.value}</span>
        </li>
      ))}
    </ul>
  );
}

/** A number with its label; $hint explains how it is counted. */
export function Kpi({ label, value, hint }: { label: string; value: ReactNode; hint?: string }) {
  return (
    <div className="kpi">
      <span className="kpi-label">{label}</span>
      <strong className="num kpi-value">{value}</strong>
      {hint && <span className="small muted">{hint}</span>}
    </div>
  );
}

/** A rate shown as a bar beside its percentage, as in the alumni tracker. */
export function RateCell({ rate }: { rate: Rate }) {
  if (rate === null) return <span className="muted small">No data</span>;
  return (
    <span className="rate">
      <span className="rate-bar" aria-hidden="true"><span style={{ width: percent(rate) }} /></span>
      <span className="num">{percent(rate)}</span>
    </span>
  );
}
