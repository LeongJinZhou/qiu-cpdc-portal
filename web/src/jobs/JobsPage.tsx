// Students and alumni: open jobs and internships with filters, one job's detail and applying, and my applications.
import { BriefcaseBusiness, MapPin, Search } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { apiGet, useLoad, type Paged } from '../api';
import { can, type Me } from '../auth';
import { PageHead } from '../shell/Shell';
import { Empty, LoadError, SkeletonLines } from '../ui/parts';
import { Closing } from './Closing';
import { JobView } from './JobView';
import { ListFooter } from './ListFooter';
import { MyApplications } from './MyApplications';
import { APPLICATION_LABEL, TYPE_LABEL, applicationTone, payLabel, type JobSummary } from './types';
import './jobs.css';

type Filters = { q: string; type: string; state: string; specialization: string };
const NO_FILTERS: Filters = { q: '', type: '', state: '', specialization: '' };

export function JobsPage({ me, go }: { me: Me; go: (to: string) => void }) {
  const [tab, setTab] = useState<'open' | 'mine'>('open');
  const [draft, setDraft] = useState<Filters>(NO_FILTERS);
  const [filters, setFilters] = useState<Filters>(NO_FILTERS);
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<number | null>(null);
  const query = { ...filters, page, per_page: 20 };
  const list = useLoad(`jobs:${JSON.stringify(query)}`, () => apiGet<Paged<JobSummary>>('/api/jobs', query), 'jobs');
  const filtered = Object.values(filters).some((v) => v !== '');
  const choices = useLoad('job-filters', () => apiGet<{ states: string[]; specializations: string[] }>('/api/jobs/filters'));

  const apply = (event: FormEvent) => {
    event.preventDefault();
    setPage(1);
    setFilters(draft);
  };
  // On a phone the detail replaces the list: start it at the top.
  const select = (id: number | null) => {
    setSelected(id);
    if (window.matchMedia('(max-width: 900px)').matches) window.scrollTo(0, 0);
  };
  const open = (id: number) => {
    select(id);
    setTab('open');
  };

  return (
    <>
      <PageHead title="Jobs and internships">
        Openings CPDC has posted for QIU students and graduates. Apply with a short note and, if you like, your CV.
      </PageHead>
      <div className="tabs" role="tablist" aria-label="Jobs">
        <button type="button" role="tab" aria-selected={tab === 'open'} onClick={() => setTab('open')}>Open jobs</button>
        <button type="button" role="tab" aria-selected={tab === 'mine'} onClick={() => setTab('mine')}>My applications</button>
      </div>

      {tab === 'mine' && <MyApplications me={me} onOpen={open} />}

      {tab === 'open' && (
        <div className="split" data-detail={selected !== null}>
          <section className="panel panel-pad split-list" aria-label="Open jobs">
            <form className="toolbar" onSubmit={apply} style={{ marginBottom: 14 }}>
              <div className="field" style={{ flex: '2 1 200px' }}>
                <label htmlFor="jb-q">Search</label>
                <input id="jb-q" className="input" placeholder="Job title, employer, place or keyword" value={draft.q} onChange={(e) => setDraft({ ...draft, q: e.target.value })} />
              </div>
              <div className="field">
                <label htmlFor="jb-type">Job type</label>
                <select id="jb-type" className="select" value={draft.type} onChange={(e) => setDraft({ ...draft, type: e.target.value })}>
                  <option value="">All</option>
                  {Object.entries(TYPE_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
              </div>
              {/* slice A: state and specialization from the open jobs */}
              <div className="field">
                <label htmlFor="jb-state">Location (state)</label>
                <select id="jb-state" className="select" value={draft.state} onChange={(e) => setDraft({ ...draft, state: e.target.value })}>
                  <option value="">Anywhere</option>
                  {choices.data?.states.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
              </div>
              <div className="field">
                <label htmlFor="jb-spec">Specialization</label>
                <select id="jb-spec" className="select" value={draft.specialization} onChange={(e) => setDraft({ ...draft, specialization: e.target.value })}>
                  <option value="">All</option>
                  {choices.data?.specializations.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
              </div>
              <button type="submit" className="btn btn-primary"><Search size={16} aria-hidden="true" /> Show</button>
            </form>
            {list.error && <LoadError error={list.error} onRetry={list.reload} />}
            {list.loading && !list.data && <SkeletonLines lines={5} />}
            {list.data && list.data.items.length === 0 && (
              <Empty icon={BriefcaseBusiness} title={filtered ? 'No jobs match' : 'No open jobs right now'}>
                {filtered ? 'Try other filters.' : 'CPDC posts new openings here. Check back soon.'}
              </Empty>
            )}
            <ul className="job-list" aria-busy={list.loading}>
              {list.data?.items.map((job) => (
                <li key={job.id}>
                  <button type="button" className="job-item" aria-current={selected === job.id ? 'true' : undefined} onClick={() => select(job.id)}>
                    <span className="job-item-title">{job.title}</span>
                    <span className="job-item-employer">{job.employer_name}</span>
                    <span className="job-item-meta">
                      <span className="badge">{TYPE_LABEL[job.job_type]}</span>
                      <span><MapPin size={13} aria-hidden="true" /> {[job.location, job.state].filter(Boolean).join(', ')}</span>
                      {payLabel(job) && <span className="num">{payLabel(job)}</span>}
                      <Closing date={job.closing_date} />
                      {job.my_status && <span className={applicationTone(job.my_status)}>{APPLICATION_LABEL[job.my_status]}</span>}
                    </span>
                  </button>
                </li>
              ))}
            </ul>
            {list.data && list.data.total > 0 && <ListFooter page={page} perPage={list.data.per_page} total={list.data.total} noun="job" onPage={setPage} />}
          </section>
          <div className="split-detail">
            {selected === null ? (
              <section className="panel panel-pad">
                <Empty icon={BriefcaseBusiness} title="Choose a job">Its details and the application form appear here.</Empty>
              </section>
            ) : (
              <JobView
                key={selected}
                id={selected}
                me={me}
                onBack={() => select(null)}
                onApplied={list.reload}
                onCompare={can(me, 'skills.own') ? () => go(`/skills?job=${selected}`) : undefined}
              />
            )}
          </div>
        </div>
      )}
    </>
  );
}
