// The filter bar shared by CPDC's placement list and satisfaction view: the same filters drive the CSV exports.
import { Download, Search } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { apiGet, useLoad, type Query } from '../api';
import { batchLabel, type PlacementFilterChoices } from './types';

export interface Filters {
  q: string;
  company: string;
  faculty: string;
  batch: string; // "2027-1" or ""
  business_nature: string;
  allowance_min: string;
  allowance_max: string;
}

export const NO_FILTERS: Filters = { q: '', company: '', faculty: '', batch: '', business_nature: '', allowance_min: '', allowance_max: '' };

/** The filters as the API query (batch split into year and month; empty values left out by apiGet). */
export function toQuery(f: Filters): Query {
  const [year, month] = f.batch.split('-');
  return {
    q: f.q.trim(),
    company: f.company.trim(),
    faculty: f.faculty,
    batch_year: year,
    batch_month: month,
    business_nature: f.business_nature,
    allowance_min: f.allowance_min,
    allowance_max: f.allowance_max,
  };
}

/** A same-origin export URL with the current filters (no personal data: only CPDC's filter choices). */
export function exportUrl(path: string, f: Filters): string {
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(toQuery(f))) if (v !== undefined && v !== '') params.set(k, String(v));
  const qs = params.toString();
  return qs ? `${path}?${qs}` : path;
}

export function PlacementFilters({
  value,
  onApply,
  fields,
  exports,
}: {
  value: Filters;
  onApply: (f: Filters) => void;
  fields: ('q' | 'company' | 'allowance')[];
  exports: { label: string; path: string }[];
}) {
  const [draft, setDraft] = useState(value);
  const choices = useLoad('placement-filters', () => apiGet<PlacementFilterChoices>('/api/placements/filters'));
  const set = (key: keyof Filters) => (v: string) => setDraft((d) => ({ ...d, [key]: v }));
  const submit = (event: FormEvent) => {
    event.preventDefault();
    onApply(draft);
  };

  return (
    <form className="pl-filters" onSubmit={submit}>
      <div className="toolbar">
        {fields.includes('q') && (
          <div className="field" style={{ flex: '2 1 220px' }}>
            <label htmlFor="pf-q">Search</label>
            <input id="pf-q" className="input" placeholder="Name, matric number or company" value={draft.q} onChange={(e) => set('q')(e.target.value)} />
          </div>
        )}
        {fields.includes('company') && (
          <div className="field" style={{ flex: '2 1 220px' }}>
            <label htmlFor="pf-company">Company</label>
            <input id="pf-company" className="input" placeholder="Any company" value={draft.company} onChange={(e) => set('company')(e.target.value)} />
          </div>
        )}
        <Choice id="pf-faculty" label="Faculty" value={draft.faculty} onChange={set('faculty')} options={(choices.data?.faculties ?? []).map((f) => [f, f])} />
        <Choice
          id="pf-batch"
          label="Batch"
          value={draft.batch}
          onChange={set('batch')}
          options={(choices.data?.batches ?? []).map((b) => [`${b.year}-${b.month}`, batchLabel(b.month, b.year)])}
        />
        <Choice
          id="pf-nature"
          label="Nature of business"
          value={draft.business_nature}
          onChange={set('business_nature')}
          options={(choices.data?.business_natures ?? []).map((n) => [n, n])}
        />
        {fields.includes('allowance') && (
          <div className="pl-range">
            <div className="field">
              <label htmlFor="pf-min">Allowance from (RM)</label>
              <input id="pf-min" className="input num" type="number" min={0} inputMode="numeric" value={draft.allowance_min} onChange={(e) => set('allowance_min')(e.target.value)} />
            </div>
            <div className="field">
              <label htmlFor="pf-max">to (RM)</label>
              <input id="pf-max" className="input num" type="number" min={0} inputMode="numeric" value={draft.allowance_max} onChange={(e) => set('allowance_max')(e.target.value)} />
            </div>
          </div>
        )}
        <button type="submit" className="btn btn-primary">
          <Search size={16} aria-hidden="true" /> Show
        </button>
      </div>
      <div className="row pl-exports">
        {exports.map((x) => (
          <a key={x.path} className="btn btn-small" href={exportUrl(x.path, value)} download>
            <Download size={15} aria-hidden="true" /> {x.label}
          </a>
        ))}
        <span className="small muted">Exports use the filters shown. Each export is in the audit log.</span>
      </div>
    </form>
  );
}

function Choice({
  id,
  label,
  value,
  onChange,
  options,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (v: string) => void;
  options: [string, string][];
}) {
  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      <select id={id} className="select" value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">All</option>
        {options.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
      </select>
    </div>
  );
}
