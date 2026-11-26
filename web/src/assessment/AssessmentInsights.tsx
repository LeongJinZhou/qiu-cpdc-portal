// CPDC: trait averages of one self-assessment per faculty and semester, who took it, and one person's written
// reflections. Opening reflections is written to the audit log by the server; the screen says so.
import { ArrowLeft, BarChart3, Eye, Printer, ShieldCheck, Users, X } from 'lucide-react';
import { useState, type MouseEvent } from 'react';
import { apiGet, useLoad } from '../api';
import { dateLabel } from '../profile/types';
import { PageHead } from '../shell/Shell';
import { Empty, LoadError, PersonName, SkeletonLines, Spinner, errorText, Notice } from '../ui/parts';
import { ReportSheet } from './AttemptReport';
import { traitName, type AttemptReport, type TakerRow, type Traits } from './types';
import './assessment.css';

export function AssessmentInsightsPage({ id, go }: { id: number; go: (to: string) => void }) {
  const traits = useLoad(`assessment-traits:${id}`, () => apiGet<Traits>(`/api/assessments/manage/${id}/traits`));
  const [faculty, setFaculty] = useState('');
  const people = useLoad(`assessment-people:${id}:${faculty}`, () =>
    apiGet<{ items: TakerRow[] }>(`/api/assessments/manage/${id}/people`, { faculty }),
  `assessment-people:${id}`);
  const [open, setOpen] = useState<{ row: TakerRow; report?: AttemptReport; error?: unknown } | null>(null);

  const back = (event: MouseEvent) => {
    event.preventDefault();
    go(`/assessment-builder/${id}`);
  };
  const read = (row: TakerRow) => {
    setOpen({ row });
    apiGet<AttemptReport>(`/api/assessments/manage/attempts/${row.attempt_id}`).then(
      (report) => setOpen({ row, report }),
      (error: unknown) => setOpen({ row, error }),
    );
  };

  const t = traits.data;
  const faculties = t ? [...new Set(t.groups.map((g) => g.faculty).filter((f): f is string => f !== null))] : [];
  return (
    <div className="as-insights">
      <a className="back-link" href={`/assessment-builder/${id}`} onClick={back}>
        <ArrowLeft size={16} aria-hidden="true" /> Back to the assessment
      </a>
      <PageHead title={t ? t.assessment.title : 'Results'}>
        Average rating per trait, by faculty and semester. Each person counts once, with their latest attempt.
      </PageHead>
      {traits.error && <LoadError error={traits.error} onRetry={traits.reload} />}
      {!t && !traits.error && <SkeletonLines lines={5} />}
      {t && t.overall.people === 0 && (
        <div className="panel">
          <Empty icon={BarChart3} title="Nobody has taken it yet">Averages appear after the first attempt.</Empty>
        </div>
      )}
      {t && t.overall.people > 0 && (
        <section className="panel panel-pad stack" aria-labelledby="as-traits-h">
          <h2 id="as-traits-h">Trait averages</h2>
          <div className="table-wrap">
            <table className="table as-traits">
              <thead>
                <tr>
                  <th>Faculty</th>
                  <th className="num">Sem.</th>
                  <th className="num">People</th>
                  {t.questions.map((q) => (
                    <th key={q.id} className="num" title={q.prompt}>
                      {traitName(q.prompt)}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                <tr className="as-traits-all">
                  <td>All</td>
                  <td className="num">-</td>
                  <td className="num">{t.overall.people}</td>
                  {t.questions.map((q) => (
                    <td key={q.id} className="num">
                      <Mean value={t.overall.means[q.id]} max={q.max_points} />
                    </td>
                  ))}
                </tr>
                {t.groups.map((g) => (
                  <tr key={`${g.faculty ?? ''}|${g.semester ?? ''}`}>
                    <td>{g.faculty ?? 'Not on record'}</td>
                    <td className="num">{g.semester ?? '-'}</td>
                    <td className="num">{g.people}</td>
                    {t.questions.map((q) => (
                      <td key={q.id} className="num">
                        <Mean value={g.means[q.id]} max={q.max_points} />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      <section className="panel panel-pad stack" aria-labelledby="as-people-h">
        <div className="section-head">
          <h2 id="as-people-h">People</h2>
          {faculties.length > 1 && (
            <div className="field as-filter">
              <label htmlFor="as-fac" className="visually-hidden">Faculty</label>
              <select id="as-fac" className="select" value={faculty} onChange={(e) => setFaculty(e.target.value)}>
                <option value="">Every faculty</option>
                {faculties.map((f) => (
                  <option key={f} value={f}>{f}</option>
                ))}
              </select>
            </div>
          )}
        </div>
        {people.error && <LoadError error={people.error} onRetry={people.reload} />}
        {!people.data && !people.error && <SkeletonLines lines={3} />}
        {people.data && people.data.items.length === 0 && <Empty icon={Users} title="No one yet" />}
        {people.data && people.data.items.length > 0 && (
          <ul className="as-people">
            {people.data.items.map((row) => (
              <li key={row.attempt_id}>
                <PersonName name={row.display_name} linkedinUrl={row.linkedin_url} size={30} />
                <span className="small muted">
                  {[row.faculty, row.semester ? `Semester ${row.semester}` : null, dateLabel(row.taken_at)].filter(Boolean).join(' · ')}
                </span>
                <span className="as-chip">
                  <strong className="num">{row.percent}%</strong>
                  {row.band && <span>{row.band}</span>}
                </span>
                <button type="button" className="btn btn-small" onClick={() => read(row)} disabled={open?.row.attempt_id === row.attempt_id && !open.report && !open.error}>
                  <Eye size={15} aria-hidden="true" /> {row.has_reflections ? 'Read reflections' : 'Open result'}
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>

      {open && (
        <section className="panel panel-pad stack as-reading" aria-labelledby="as-reading-h">
          <div className="section-head">
            <h2 id="as-reading-h">{open.row.display_name}</h2>
            <div className="row">
              <button type="button" className="btn btn-small" onClick={() => window.print()} disabled={!open.report}>
                <Printer size={15} aria-hidden="true" /> Print
              </button>
              <button type="button" className="btn btn-quiet btn-small" onClick={() => setOpen(null)} aria-label="Close">
                <X size={16} aria-hidden="true" />
              </button>
            </div>
          </div>
          <p className="small muted as-audit-note">
            <ShieldCheck size={14} aria-hidden="true" /> This reading is recorded in the audit log.
          </p>
          {!open.report && !open.error && (
            <p className="small muted">
              <Spinner /> Loading
            </p>
          )}
          {open.error !== undefined && <Notice tone="error">{errorText(open.error)}</Notice>}
          {open.report && <ReportSheet report={open.report} level={2} />}
        </section>
      )}
    </div>
  );
}

function Mean({ value, max }: { value: number | undefined; max: number }) {
  if (value === undefined) return <span className="muted">-</span>;
  const low = value / Math.max(1, max) < 0.6;
  return <span className={low ? 'as-mean-low' : undefined}>{value.toFixed(2)}</span>;
}
