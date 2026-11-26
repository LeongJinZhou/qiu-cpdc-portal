// CPDC analytics (docs/SPEC.md section 15): university-wide numbers with filters, the faculty table, alumni outcomes
// and the CSV downloads. Every number comes from GET /api/analytics/overview; downloads are audited on the server.
import { ChartColumn, Download, Search } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { apiGet, useLoad } from '../api';
import { can, type Me } from '../auth';
import { STATUS_LABEL } from '../profile/types';
import { PageHead } from '../shell/Shell';
import { Empty, LoadError, SkeletonLines } from '../ui/parts';
import '../admin/admin.css';
import { Bars, Kpi, RateCell } from './Bars';
import './analytics.css';
import { filterQuery, percent, ringgit, type Overview } from './types';

type Filters = { faculty: string; programme: string; role: string; cohort: string; from: string; to: string };
const NO_FILTERS: Filters = { faculty: '', programme: '', role: 'all', cohort: '', from: '', to: '' };
const STAGE_LABEL: Record<string, string> = {
  submitted: 'Submitted', reviewing: 'Reviewing', shortlisted: 'Shortlisted', interview: 'Interview',
  offered: 'Offered', hired: 'Hired', rejected: 'Rejected', withdrawn: 'Withdrawn',
};

export function AnalyticsPage({ me, go }: { me: Me; go: (to: string) => void }) {
  const [draft, setDraft] = useState<Filters>(NO_FILTERS);
  const [filters, setFilters] = useState<Filters>(NO_FILTERS);
  const load = useLoad(`analytics:${JSON.stringify(filters)}`, () => apiGet<Overview>('/api/analytics/overview', filters), 'analytics');
  const data = load.data;
  const set = (key: keyof Filters) => (value: string) => setDraft({ ...draft, [key]: value });
  const apply = (event: FormEvent) => {
    event.preventDefault();
    setFilters(draft);
  };

  return (
    <>
      <PageHead title="Analytics">
        Career readiness, internships, jobs and alumni outcomes across QIU, counted on the server from what students,
        alumni and CPDC record here.
      </PageHead>

      <form className="panel panel-pad toolbar" onSubmit={apply} style={{ marginBottom: 20 }} aria-label="Filters">
        <Select id="an-fac" label="Faculty" value={draft.faculty} onChange={set('faculty')} options={data?.options.faculties ?? []} />
        <Select id="an-prog" label="Programme" value={draft.programme} onChange={set('programme')} options={data?.options.programmes ?? []} />
        <div className="field">
          <label htmlFor="an-role">People</label>
          <select id="an-role" className="select" value={draft.role} onChange={(e) => set('role')(e.target.value)}>
            <option value="all">Everyone</option>
            <option value="student">Students</option>
            <option value="alumni">Alumni</option>
          </select>
        </div>
        <Select id="an-cohort" label="Graduation year" value={draft.cohort} onChange={set('cohort')} options={(data?.options.cohorts ?? []).map(String)} />
        <div className="field" style={{ flex: '0 1 160px' }}>
          <label htmlFor="an-from">From</label>
          <input id="an-from" type="date" className="input num" value={draft.from} onChange={(e) => set('from')(e.target.value)} />
        </div>
        <div className="field" style={{ flex: '0 1 160px' }}>
          <label htmlFor="an-to">To</label>
          <input id="an-to" type="date" className="input num" value={draft.to} min={draft.from || undefined} onChange={(e) => set('to')(e.target.value)} />
        </div>
        <button type="submit" className="btn btn-primary"><Search size={16} aria-hidden="true" /> Show</button>
      </form>

      {load.error && <LoadError error={load.error} onRetry={load.reload} />}
      {!data && !load.error && <SkeletonLines lines={8} />}
      {data && (
        <div aria-busy={load.loading}>
          <KpiStrip data={data} />
          <div className="an-grid">
            <Readiness data={data} />
            <Internships data={data} />
            <Jobs data={data} />
            <Growth data={data} />
            <FacultyTable data={data} />
            <Alumni data={data} go={go} />
            <Downloads me={me} data={data} filters={filters} />
          </div>
        </div>
      )}
    </>
  );
}

function Select({ id, label, value, onChange, options }: { id: string; label: string; value: string; onChange: (v: string) => void; options: string[] }) {
  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      <select id={id} className="select" value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">All</option>
        {options.map((o) => <option key={o} value={o}>{o}</option>)}
      </select>
    </div>
  );
}

function KpiStrip({ data }: { data: Overview }) {
  const k = data.kpis;
  return (
    <section className="kpis" aria-label="Key numbers">
      <Kpi label="Active students" value={k.students} />
      <Kpi label="Alumni" value={k.alumni} />
      <Kpi label="Students with LinkedIn" value={percent(k.student_linkedin_rate)} />
      <Kpi label="Alumni with LinkedIn" value={percent(k.alumni_linkedin_rate)} />
      <Kpi label="Onboarding done" value={percent(k.onboarding_rate)} hint="Students" />
      <Kpi label="Alumni employment rate" value={percent(k.alumni_employment_rate)} hint={`${k.alumni_unknown} with status unknown`} />
      <Kpi label="Internship placement" value={percent(k.internship_placement_rate)} hint="Students with a placement" />
      <Kpi label="Job offer from host" value={percent(k.job_offer_rate)} hint="Of satisfaction answers" />
    </section>
  );
}

function Facts({ items }: { items: [string, string | number][] }) {
  return (
    <dl className="an-facts">
      {items.map(([label, value]) => <div key={label}><dt>{label}</dt><dd className="num">{value}</dd></div>)}
    </dl>
  );
}

function Readiness({ data }: { data: Overview }) {
  const r = data.readiness;
  return (
    <section className="panel panel-pad an-section" aria-labelledby="an-ready">
      <h2 id="an-ready">Career readiness</h2>
      <Bars max={1} rows={[
        { label: 'LinkedIn PDF imported', value: r.pdf_import_rate ?? 0, text: percent(r.pdf_import_rate) },
        { label: 'CV built', value: r.cv_rate ?? 0, text: percent(r.cv_rate) },
        { label: 'Shares CV with employers', value: r.consent_rate ?? 0, text: percent(r.consent_rate) },
        { label: 'Skills rated', value: r.skills_rate ?? 0, text: percent(r.skills_rate) },
      ]} empty="No students counted." />
      <p className="small muted" style={{ marginTop: 10 }}>Out of <span className="num">{r.students}</span> students.</p>
    </section>
  );
}

function Internships({ data }: { data: Overview }) {
  const i = data.internship;
  return (
    <section className="panel panel-pad an-section" aria-labelledby="an-intern">
      <h2 id="an-intern">Internships</h2>
      <Facts items={[
        ['Placed', i.placed],
        ['Average allowance', ringgit(i.allowance_average)],
        ['Median allowance', ringgit(i.allowance_median)],
        ['Unpaid', i.unpaid],
        ['Logbook on track', percent(i.logbook_completion_rate)],
        ['Weeks waiting', i.weeks_waiting],
        ['Weeks returned', i.weeks_returned],
        ['Satisfaction', i.satisfaction_average === null ? '-' : `${i.satisfaction_average} / 5`],
        ['Recommend to juniors', percent(i.recommend_rate)],
      ]} />
      <h3>Nature of business</h3>
      <Bars rows={i.by_nature.map((r) => ({ label: r.label, value: r.count }))} empty="No placements yet." />
      <h3>Top host companies</h3>
      <Bars rows={i.top_companies.map((r) => ({ label: r.label, value: r.count }))} empty="No placements yet." />
      <p className="small muted" style={{ marginTop: 10 }}>
        Logbook on track = approved weeks out of the weeks due so far (<span className="num">{i.weeks_expected}</span>).
      </p>
    </section>
  );
}

function Jobs({ data }: { data: Overview }) {
  const j = data.jobs;
  return (
    <section className="panel panel-pad an-section" aria-labelledby="an-jobs">
      <h2 id="an-jobs">Jobs and applications</h2>
      <Facts items={[['Open jobs', j.open_jobs], ['Applications', j.applications]]} />
      <h3>Where applications stand</h3>
      <Bars rows={j.funnel.map((s) => ({ label: STAGE_LABEL[s.label] ?? s.label, value: s.count }))} empty="No applications yet." />
      <h3>Top employers</h3>
      <Bars rows={j.top_employers.map((e) => ({ label: e.label, value: e.applications, text: `${e.applications} (${e.hired} hired)` }))} empty="No applications yet." />
    </section>
  );
}

function Growth({ data }: { data: Overview }) {
  const g = data.growth;
  return (
    <section className="panel panel-pad an-section" aria-labelledby="an-growth">
      <h2 id="an-growth">Skills, learning and activity</h2>
      <Facts items={[
        ['Certificates verified', g.learning_verified],
        ['Waiting for a check', g.learning_pending],
        ['Self-assessment done', percent(g.assessment_completion_rate)],
        ['Interview sessions', g.interview_sessions],
        ['Interview score', g.interview_average === null ? '-' : `${g.interview_average} / 5`],
        ['FYP projects published', g.showcase_published],
        ['Workshop attendance', percent(g.workshop_attendance_rate)],
        ['Yearly survey response', percent(g.survey?.response_rate ?? null)],
      ]} />
      <h3>Skills (average of 1-5)</h3>
      <Bars max={5} rows={g.skills.map((s) => ({ label: s.label, value: s.average ?? 0, text: String(s.average) }))} empty="No skill ratings yet." />
      <h3>Self-assessment traits (average of 1-5)</h3>
      <Bars max={5} rows={g.traits.map((t) => ({ label: t.label, value: t.average ?? 0, text: String(t.average) }))} empty="No rated self-assessments yet." />
      {g.survey && (
        <>
          <h3>{g.survey.title} (average of 1-5, without N/A)</h3>
          <Bars max={5} rows={g.survey.sections.map((s) => ({ label: s.label, value: s.average ?? 0, text: s.average === null ? '-' : String(s.average) }))} empty="No answers yet." />
        </>
      )}
      <p className="small muted" style={{ marginTop: 10 }}>
        Workshops: <span className="num">{g.workshop_attended}</span> checked in of <span className="num">{g.workshop_rsvps}</span> going, past workshops only.
      </p>
    </section>
  );
}

function FacultyTable({ data }: { data: Overview }) {
  return (
    <section className="panel panel-pad an-section an-wide" aria-labelledby="an-fac-h">
      <h2 id="an-fac-h">By faculty</h2>
      {data.faculties.length === 0 ? <Empty icon={ChartColumn} title="Nobody counted">Try other filters.</Empty> : (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Faculty</th>
                <th className="num">Students</th>
                <th>LinkedIn</th>
                <th>CV built</th>
                <th className="num">Interns placed</th>
                <th>Job offer</th>
                <th className="num">Alumni</th>
                <th>Alumni employed</th>
              </tr>
            </thead>
            <tbody>
              {data.faculties.map((f) => (
                <tr key={f.faculty}>
                  <td>{f.faculty}</td>
                  <td className="num">{f.students}</td>
                  <td><RateCell rate={f.linkedin_rate} /></td>
                  <td><RateCell rate={f.cv_rate} /></td>
                  <td className="num">{f.interns}</td>
                  <td><RateCell rate={f.job_offer_rate} /></td>
                  <td className="num">{f.alumni}</td>
                  <td><RateCell rate={f.alumni_employment_rate} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

function Alumni({ data, go }: { data: Overview; go: (to: string) => void }) {
  const a = data.alumni;
  const requests = Object.fromEntries(a.mentor_requests.map((r) => [r.label, r.count]));
  return (
    <section className="panel panel-pad an-section an-wide" aria-labelledby="an-alumni">
      <h2 id="an-alumni">Alumni outcomes</h2>
      <div className="an-grid" style={{ marginBottom: 0 }}>
        <div>
          <h3 style={{ marginTop: 0 }}>Status</h3>
          <Bars rows={a.status.map((s) => ({ label: STATUS_LABEL[s.label], value: s.count }))} empty="No alumni counted." />
        </div>
        <div>
          <Facts items={[
            [`Confirmed in the last ${a.confirm_days} days`, a.confirmed_recently],
            ['Confirmation overdue', a.confirmation_overdue],
            ['Never confirmed', a.never_confirmed],
            ['In the alumni network', a.network_visible],
            ['Active mentors', a.mentors_active],
            ['Mentor requests waiting', requests.waiting ?? 0],
            ['Accepted', requests.accepted ?? 0],
            ['Declined', requests.declined ?? 0],
          ]} />
        </div>
      </div>
      {a.breakdown.length > 0 && (
        <>
          <h3>By faculty and graduation year</h3>
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th>Faculty</th>
                  <th className="num">Year</th>
                  {a.status.map((s) => <th key={s.label} className="num">{STATUS_LABEL[s.label]}</th>)}
                </tr>
              </thead>
              <tbody>
                {a.breakdown.map((b) => (
                  <tr key={`${b.faculty}-${b.graduation_year}`}>
                    <td>{b.faculty}</td>
                    <td className="num">{b.graduation_year ?? '-'}</td>
                    {a.status.map((s) => <td key={s.label} className="num">{b.counts[s.label]}</td>)}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
      <h3>Alumni without LinkedIn (<span className="num">{a.without_linkedin}</span>)</h3>
      <p className="small muted">They cannot sign in once their QIU account closes.</p>
      {a.without_linkedin_list.length === 0 ? <p className="small muted">None.</p> : (
        <ul className="home-list" style={{ listStyle: 'none', padding: 0, margin: 0 }}>
          {a.without_linkedin_list.map((p) => (
            <li key={p.id} style={{ padding: '6px 0' }}>
              <a href={`/people/${p.id}`} onClick={(e) => { e.preventDefault(); go(`/people/${p.id}`); }}>{p.display_name}</a>
              <span className="small muted"> · {[p.faculty, p.graduation_year].filter(Boolean).join(' · ') || 'No faculty'}</span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function Downloads({ me, data, filters }: { me: Me; data: Overview; filters: Filters }) {
  const placement = filterQuery({ faculty: filters.faculty });
  const link = (href: string, label: string) => (
    <a className="btn" href={href} download><Download size={16} aria-hidden="true" /> {label}</a>
  );
  return (
    <section className="panel panel-pad an-section an-wide" aria-labelledby="an-dl">
      <h2 id="an-dl">Downloads</h2>
      <p className="small muted" style={{ marginBottom: 12 }}>
        CSV in Malaysia time, with the filters above where the report has them. Every download is recorded in the audit log.
      </p>
      <div className="an-downloads">
        {can(me, 'analytics.export') && link(`/api/analytics/export?${filterQuery(filters, { report: 'readiness' })}`, 'Student readiness')}
        {can(me, 'analytics.export') && link(`/api/analytics/export?${filterQuery(filters, { report: 'outcomes' })}`, 'Alumni outcomes')}
        {can(me, 'placement.manage') && link(`/api/placements/export/visits?${placement}`, 'Internship and industry visit')}
        {can(me, 'placement.manage') && link(`/api/placements/export/satisfaction?${placement}`, 'Industry satisfaction')}
        {can(me, 'survey.manage') && data.growth.survey && link(`/api/surveys/manage/${data.growth.survey.cycle_id}/export`, 'Yearly survey')}
      </div>
    </section>
  );
}
