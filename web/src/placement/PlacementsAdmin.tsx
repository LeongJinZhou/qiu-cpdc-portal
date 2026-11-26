// CPDC: every placement with filters, one placement's change history, and the Industry Visit export.
import { Briefcase, History, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { apiGet, useLoad, type Paged } from '../api';
import { Pager } from '../admin/Pager';
import { dayLabel } from '../logbook/types';
import { Empty, LoadError, PersonName, SkeletonLines } from '../ui/parts';
import { ChangeList } from './PlacementPanels';
import { NO_FILTERS, PlacementFilters, toQuery, type Filters } from './PlacementFilters';
import { allowanceLabel, batchLabel, RATING_LABEL, type ChangeHistory, type PlacementRow } from './types';
import './placement.css';

export function PlacementsAdmin() {
  const [filters, setFilters] = useState<Filters>(NO_FILTERS);
  const [page, setPage] = useState(1);
  const [history, setHistory] = useState<number | null>(null);
  const query = { ...toQuery(filters), page, per_page: 25 };
  const list = useLoad(`placements:${JSON.stringify(query)}`, () => apiGet<Paged<PlacementRow>>('/api/placements', query), 'placements');
  const filtered = JSON.stringify(filters) !== JSON.stringify(NO_FILTERS);

  return (
    <div className="stack">
      <section className="panel panel-pad stack">
        <PlacementFilters
          value={filters}
          fields={['q', 'allowance']}
          onApply={(f) => {
            setPage(1);
            setFilters(f);
          }}
          exports={[{ label: 'Industry Visit CSV', path: '/api/placements/export/visits' }]}
        />
        {list.error && <LoadError error={list.error} onRetry={list.reload} />}
        {list.loading && !list.data && <SkeletonLines lines={5} />}
        {list.data && list.data.items.length === 0 && (
          <Empty icon={Briefcase} title={filtered ? 'No placements match' : 'No placements yet'}>
            {filtered ? 'Try other filters.' : 'Students appear once they send their placement form from the logbook.'}
          </Empty>
        )}
        {list.data && list.data.items.length > 0 && (
          <ul className="pl-rows" aria-busy={list.loading}>
            {list.data.items.map((r) => (
              <li key={r.internship_id} className="pl-row">
                <div className="pl-row-who">
                  <PersonName name={r.display_name} linkedinUrl={r.linkedin_url} photo={r.picture_url} />
                  <span className="small muted num">{[r.matric_no, r.faculty].filter(Boolean).join(' · ')}</span>
                </div>
                <div className="pl-row-where small">
                  <strong>{r.company}</strong>
                  <span className="muted">{[r.business_nature, r.company_state, r.company_country].filter(Boolean).join(' · ') || 'Placement form not sent yet'}</span>
                  <span className="muted num">
                    {dayLabel(r.start_date)} to {dayLabel(r.end_date)}
                  </span>
                </div>
                <dl className="pl-row-facts small">
                  <div>
                    <dt>Batch</dt>
                    <dd className="num">{batchLabel(r.batch_month, r.batch_year) || '-'}</dd>
                  </div>
                  <div>
                    <dt>Allowance</dt>
                    <dd className="num">{allowanceLabel(r.monthly_allowance_rm)}</dd>
                  </div>
                  <div>
                    <dt>Survey</dt>
                    <dd>{r.rating ? <span className="num">{r.rating}/5 {RATING_LABEL[r.rating]}</span> : <span className="muted">Not yet</span>}</dd>
                  </div>
                </dl>
                <div className="pl-row-action">
                  {!r.placement_submitted_at && <span className="badge badge-warn">Form not sent</span>}
                  {r.changes > 0 ? (
                    <button type="button" className="btn btn-small" onClick={() => setHistory(r.internship_id)} aria-label={`Change history of ${r.display_name}, ${r.changes} changes`}>
                      <History size={15} aria-hidden="true" /> <span className="num">{r.changes}</span> {r.changes === 1 ? 'change' : 'changes'}
                    </button>
                  ) : (
                    r.placement_submitted_at && <span className="small muted">No changes</span>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
        {list.data && <Pager page={page} perPage={list.data.per_page} total={list.data.total} onPage={setPage} />}
      </section>
      {history !== null && <HistoryPanel key={history} id={history} onClose={() => setHistory(null)} />}
    </div>
  );
}

function HistoryPanel({ id, onClose }: { id: number; onClose: () => void }) {
  const load = useLoad(`placement-history:${id}`, () => apiGet<ChangeHistory>(`/api/placements/${id}/changes`));
  // The history opens under the list: bring it into view and move focus to it.
  const heading = useRef<HTMLHeadingElement>(null);
  useEffect(() => {
    heading.current?.scrollIntoView({ block: 'start' });
    heading.current?.focus({ preventScroll: true });
  }, []);
  return (
    <div className="stack" aria-live="polite">
      <div className="section-head">
        <h2 ref={heading} tabIndex={-1} className="pl-history-title">{load.data ? `${load.data.display_name} at ${load.data.company}` : 'Change history'}</h2>
        <button type="button" className="btn btn-quiet btn-small" onClick={onClose}>
          <X size={15} aria-hidden="true" /> Close
        </button>
      </div>
      {load.error && <LoadError error={load.error} onRetry={load.reload} />}
      {!load.data && !load.error && <SkeletonLines lines={4} />}
      {load.data && <ChangeList changes={load.data.changes} title="Change history" />}
    </div>
  );
}
