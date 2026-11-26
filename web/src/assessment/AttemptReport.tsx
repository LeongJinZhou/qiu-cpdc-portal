// One completed self-assessment as an A4 sheet: the taker's own print view, and what CPDC reads (audited) when it
// opens a person's reflections. Text only: nothing the person wrote is rendered as HTML.
import { ArrowLeft, Printer } from 'lucide-react';
import { useEffect, type MouseEvent } from 'react';
import { apiGet, useLoad } from '../api';
import { dateLabel } from '../profile/types';
import { LoadError, SkeletonLines } from '../ui/parts';
import { traitName, type AttemptReport } from './types';
import './assessment.css';

/** Print styles show only the sheet while this is mounted. */
function usePrintPage() {
  useEffect(() => {
    document.body.classList.add('as-print-page');
    return () => document.body.classList.remove('as-print-page');
  }, []);
}

export function OwnReportPage({ id, go }: { id: number; go: (to: string) => void }) {
  const load = useLoad(`assessment-report:${id}`, () => apiGet<AttemptReport>(`/api/assessments/attempts/${id}`));
  const back = (event: MouseEvent) => {
    event.preventDefault();
    go('/assessments');
  };
  return (
    <div className="as-report-page">
      <div className="as-report-bar">
        <a className="back-link" href="/assessments" onClick={back}>
          <ArrowLeft size={16} aria-hidden="true" /> All self-assessments
        </a>
        <button type="button" className="btn btn-primary" onClick={() => window.print()} disabled={!load.data}>
          <Printer size={17} aria-hidden="true" /> Print or save as PDF
        </button>
      </div>
      {load.error && (
        <>
          <h1 className="visually-hidden">Self-assessment report</h1>
          <LoadError error={load.error} onRetry={load.reload} />
        </>
      )}
      {!load.data && !load.error && <SkeletonLines lines={8} />}
      {load.data && <ReportSheet report={load.data} />}
    </div>
  );
}

/** level 2 when the sheet sits inside another page that has its own h1. */
export function ReportSheet({ report, level = 1 }: { report: AttemptReport; level?: 1 | 2 }) {
  usePrintPage();
  const Title = level === 1 ? 'h1' : 'h2';
  const p = report.person;
  const facts: [string, string][] = [
    ['Name', p.display_name],
    ['Matric no', p.matric_no ?? 'Not on record'],
    ['Faculty', p.faculty ?? 'Not on record'],
    ['Programme', p.programme ?? 'Not given'],
    ['Semester', p.semester === null ? 'Not given' : String(p.semester)],
    ['Date', dateLabel(report.taken_at)],
  ];
  return (
    <article className="as-sheet" aria-label="Self-assessment report">
      <header className="as-sheet-top">
        <img src="/brand/qiu-mark-dark.png" alt="" className="as-sheet-mark" />
        <div>
          <p className="as-sheet-org">Quest International University · CPDC</p>
          <Title className="as-sheet-title">{report.title}</Title>
        </div>
      </header>
      <dl className="as-facts">
        {facts.map(([term, value]) => (
          <div key={term}>
            <dt>{term}</dt>
            <dd>{value}</dd>
          </div>
        ))}
      </dl>
      <table className="as-sheet-table">
        <thead>
          <tr>
            <th>{report.answer_style === 'likert' ? 'Characteristic' : 'Question'}</th>
            <th>Rating</th>
            <th className="num">Score</th>
          </tr>
        </thead>
        <tbody>
          {report.traits.map((t) => (
            <tr key={t.prompt}>
              <td>{report.answer_style === 'likert' ? traitName(t.prompt) : t.prompt}</td>
              <td>{t.answer}</td>
              <td className="num">
                {t.points} / {t.max_points}
              </td>
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr>
            <td colSpan={2}>
              Total{report.band ? `: ${report.band}` : ''}
            </td>
            <td className="num">
              {report.score} / {report.max_score} ({report.percent}%)
            </td>
          </tr>
        </tfoot>
      </table>
      {report.reflections.length > 0 && (
        <section className="as-sheet-reflect">
          <p className="as-sheet-h">Commitments</p>
          {report.reflections.map((r) => (
            <div key={r.prompt}>
              <p className="as-sheet-q">{r.prompt}</p>
              <p>{r.answer || 'Not answered.'}</p>
            </div>
          ))}
        </section>
      )}
    </article>
  );
}
