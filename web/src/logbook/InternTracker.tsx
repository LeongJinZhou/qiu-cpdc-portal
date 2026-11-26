// CPDC: every intern's logbook progress, and one intern's weeks (read only).
import { ArrowLeft, BookOpen, Search, TriangleAlert } from 'lucide-react';
import { useState, type FormEvent, type MouseEvent } from 'react';
import { apiGet, useLoad, type Paged } from '../api';
import { Pager } from '../admin/Pager';
import { can, type Me } from '../auth';
import { PlacementsAdmin } from '../placement/PlacementsAdmin';
import { SatisfactionAdmin } from '../placement/SatisfactionAdmin';
import { PageHead } from '../shell/Shell';
import { Empty, LoadError, PersonName, SkeletonLines } from '../ui/parts';
import { dayLabel, type InternDetail, type InternRow } from './types';
import { WeekCard, WeekProgress } from './WeekCard';
import './logbook.css';

/** /internships: logbook progress, and for placement.manage (slice A) every placement and the satisfaction results. */
export function InternTracker({ me, go }: { me: Me; go: (to: string) => void }) {
  const [tab, setTab] = useState<'logbook' | 'placements' | 'satisfaction'>('logbook');
  if (!can(me, 'placement.manage')) return <LogbookProgress go={go} />;
  return (
    <>
      <PageHead title="Internships">Every intern's placement, logbook progress and satisfaction with their company.</PageHead>
      <div className="tabs" role="tablist" aria-label="Internships">
        <button type="button" role="tab" aria-selected={tab === 'logbook'} onClick={() => setTab('logbook')}>Logbook</button>
        <button type="button" role="tab" aria-selected={tab === 'placements'} onClick={() => setTab('placements')}>Placements</button>
        <button type="button" role="tab" aria-selected={tab === 'satisfaction'} onClick={() => setTab('satisfaction')}>Satisfaction</button>
      </div>
      {tab === 'logbook' && <LogbookProgress go={go} withHead={false} />}
      {tab === 'placements' && <PlacementsAdmin />}
      {tab === 'satisfaction' && <SatisfactionAdmin />}
    </>
  );
}

function LogbookProgress({ go, withHead = true }: { go: (to: string) => void; withHead?: boolean }) {
  const [draft, setDraft] = useState({ q: '', attention: '' });
  const [filters, setFilters] = useState(draft);
  const [page, setPage] = useState(1);
  const query = { ...filters, page, per_page: 25 };
  const list = useLoad(`interns:${JSON.stringify(query)}`, () => apiGet<Paged<InternRow>>('/api/logbook/students', query), 'interns');

  const apply = (event: FormEvent) => {
    event.preventDefault();
    setPage(1);
    setFilters(draft);
  };
  const open = (id: number) => (event: MouseEvent) => {
    if (event.metaKey || event.ctrlKey) return;
    event.preventDefault();
    go(`/internships/${id}`);
  };

  return (
    <>
      {withHead && (
        <PageHead title="Internships">Every intern's logbook: weeks approved by the industry supervisor, waiting, and returned.</PageHead>
      )}
      <section className="panel panel-pad">
        <form className="toolbar" onSubmit={apply} style={{ marginBottom: 16 }}>
          <div className="field" style={{ flex: '2 1 240px' }}>
            <label htmlFor="it-q">Search</label>
            <input id="it-q" className="input" placeholder="Name, matric number or company" value={draft.q} onChange={(e) => setDraft({ ...draft, q: e.target.value })} />
          </div>
          <div className="field">
            <label htmlFor="it-att">Show</label>
            <select id="it-att" className="select" value={draft.attention} onChange={(e) => setDraft({ ...draft, attention: e.target.value })}>
              <option value="">All interns</option>
              <option value="1">With a returned week</option>
            </select>
          </div>
          <button type="submit" className="btn btn-primary">
            <Search size={16} aria-hidden="true" /> Show
          </button>
        </form>
        {list.error && <LoadError error={list.error} onRetry={list.reload} />}
        {list.loading && !list.data && <SkeletonLines lines={5} />}
        {list.data && list.data.items.length === 0 && (
          <Empty icon={BookOpen} title="No interns here">Students appear once they add their internship to the logbook.</Empty>
        )}
        {list.data && list.data.items.length > 0 && (
          <div className="table-wrap" aria-busy={list.loading}>
            <table className="table">
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Company and supervisor</th>
                  <th>Approved</th>
                  <th className="num">Waiting</th>
                  <th className="num">Returned</th>
                  <th className="num">Hours</th>
                  <th><span className="visually-hidden">Open</span></th>
                </tr>
              </thead>
              <tbody>
                {list.data.items.map((r) => (
                  <tr key={r.internship_id}>
                    <td>
                      <PersonName name={r.display_name} linkedinUrl={r.linkedin_url} photo={r.picture_url} />
                      <div className="small muted num" style={{ marginLeft: 42 }}>{r.matric_no ?? r.department ?? ''}</div>
                    </td>
                    <td className="small">
                      {r.company}
                      <div className="muted">{r.supervisor_name}</div>
                      <div className="muted">{r.supervisor_email}</div>
                      {r.supervisor_email_personal && (
                        <span className="badge badge-warn" title="Not a company address: check this is really the supervisor">
                          <TriangleAlert size={12} aria-hidden="true" /> Personal email: check
                        </span>
                      )}
                    </td>
                    <td>
                      <div className="lb-mini">
                        <span className="num small">
                          {r.approved}/{r.weeks_expected}
                        </span>
                        <div className="lb-bar" aria-hidden="true">
                          <span style={{ width: `${r.weeks_expected ? Math.min(100, (r.approved / r.weeks_expected) * 100) : 0}%` }} />
                        </div>
                      </div>
                    </td>
                    <td className="num">{r.submitted}</td>
                    <td className="num">{r.returned > 0 ? <span className="badge badge-red">{r.returned}</span> : 0}</td>
                    <td className="num">{r.approved_hours}</td>
                    <td>
                      <a className="btn btn-small" href={`/internships/${r.student_id}`} onClick={open(r.student_id)}>
                        View
                      </a>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {list.data && <Pager page={page} perPage={list.data.per_page} total={list.data.total} onPage={setPage} />}
      </section>
    </>
  );
}

export function InternDetailPage({ id, go }: { id: number; go: (to: string) => void }) {
  const load = useLoad(`intern:${id}`, () => apiGet<InternDetail>(`/api/logbook/students/${id}`));
  const back = (event: MouseEvent) => {
    event.preventDefault();
    go('/internships');
  };
  const d = load.data;
  return (
    <>
      <a className="back-link" href="/internships" onClick={back}>
        <ArrowLeft size={16} aria-hidden="true" /> All internships
      </a>
      {load.error && <LoadError error={load.error} onRetry={load.reload} />}
      {!d && !load.error && <SkeletonLines lines={6} />}
      {d && d.internship && (
        <>
          <PageHead title={d.student.display_name}>
            {d.internship.company} · supervisor {d.internship.supervisor_name} · {dayLabel(d.internship.start_date)} to{' '}
            {dayLabel(d.internship.end_date)}
          </PageHead>
          <div className="lb-grid">
            <div className="stack">
              {d.entries.length === 0 && (
                <div className="panel">
                  <Empty icon={BookOpen} title="No weeks logged yet" />
                </div>
              )}
              {d.entries.map((e) => (
                <WeekCard key={e.id} entry={e} />
              ))}
            </div>
            <aside className="side-stack">
              <section className="panel panel-pad stack" aria-labelledby="id-progress">
                <h2 id="id-progress" className="lb-side-title">Progress</h2>
                <WeekProgress entries={d.entries} expected={d.weeks_expected} />
                <PersonName name={d.student.display_name} linkedinUrl={d.student.linkedin_url} photo={d.student.picture_url} />
              </section>
            </aside>
          </div>
        </>
      )}
    </>
  );
}
