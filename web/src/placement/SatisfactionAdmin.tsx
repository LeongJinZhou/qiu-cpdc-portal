// CPDC: internship satisfaction per company (average rating, recommend rate, job-offer conversion) and its export.
import { Star } from 'lucide-react';
import { useState } from 'react';
import { apiGet, useLoad } from '../api';
import { Empty, LoadError, SkeletonLines } from '../ui/parts';
import { NO_FILTERS, PlacementFilters, toQuery, type Filters } from './PlacementFilters';
import type { Measures, SatisfactionReport } from './types';
import './placement.css';

const pct = (value: number | null) => (value === null ? '-' : `${value}%`);
const avg = (value: number | null) => (value === null ? '-' : value.toFixed(2));

export function SatisfactionAdmin() {
  const [filters, setFilters] = useState<Filters>(NO_FILTERS);
  const query = toQuery(filters);
  const load = useLoad(`satisfaction:${JSON.stringify(query)}`, () => apiGet<SatisfactionReport>('/api/placements/satisfaction', query), 'satisfaction');
  const report = load.data;

  return (
    <section className="panel panel-pad stack">
      <PlacementFilters
        value={filters}
        fields={['company']}
        onApply={setFilters}
        exports={[{ label: 'Industry Satisfaction CSV', path: '/api/placements/export/satisfaction' }]}
      />
      {load.error && <LoadError error={load.error} onRetry={load.reload} />}
      {!report && !load.error && <SkeletonLines lines={5} />}
      {report && <Totals total={report.total} />}
      {report && report.companies.length === 0 && (
        <Empty icon={Star} title="No survey answers here">Interns answer the survey from their logbook at the end of the internship.</Empty>
      )}
      {report && report.companies.length > 0 && (
        <div className="table-wrap" aria-busy={load.loading}>
          <table className="table pl-table">
            <thead>
              <tr>
                <th>Company</th>
                <th className="num">Answers</th>
                <th className="num">Average rating</th>
                <th className="num">Would recommend</th>
                <th className="num">Offered a job</th>
              </tr>
            </thead>
            <tbody>
              {report.companies.map((c) => (
                <tr key={c.company}>
                  <td>{c.company}</td>
                  <td className="num">{c.responses}</td>
                  <td className="num">{avg(c.avg_rating)}</td>
                  <td className="num">{pct(c.recommend_pct)}</td>
                  <td className="num">{pct(c.offer_pct)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

function Totals({ total }: { total: Measures }) {
  return (
    <dl className="pl-totals">
      <div>
        <dt>Answers</dt>
        <dd className="num">{total.responses}</dd>
      </div>
      <div>
        <dt>Average rating (1-5)</dt>
        <dd className="num">{avg(total.avg_rating)}</dd>
      </div>
      <div>
        <dt>Would recommend to juniors</dt>
        <dd className="num">{pct(total.recommend_pct)}</dd>
      </div>
      <div>
        <dt>Internship to job offer</dt>
        <dd className="num">{pct(total.offer_pct)}</dd>
      </div>
    </dl>
  );
}
