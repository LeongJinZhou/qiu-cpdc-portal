// One person's 360 view for CPDC (docs/SPEC.md section 15), under their profile on /people/:id. The server records
// every opening in the audit log. Private mentoring messages are never shown.
import { Check, Circle } from 'lucide-react';
import { apiGet, useLoad } from '../api';
import { STATUS_LABEL, dateLabel } from '../profile/types';
import { LoadError, SkeletonLines } from '../ui/parts';
import './analytics.css';
import { ringgit, type PersonView } from './types';

const APP_LABEL: Record<string, string> = {
  submitted: 'Submitted', reviewing: 'Reviewing', shortlisted: 'Shortlisted', interview: 'Interview',
  offered: 'Offered', hired: 'Hired', rejected: 'Rejected', withdrawn: 'Withdrawn',
};
const RATING = ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent'];

export function Person360({ id }: { id: number }) {
  const load = useLoad(`person-360:${id}`, () => apiGet<PersonView>(`/api/people/${id}/analytics`));
  if (load.error) return <LoadError error={load.error} onRetry={load.reload} />;
  const v = load.data;
  if (!v) return <SkeletonLines lines={6} />;
  return (
    <div className="stack" style={{ gap: 18, marginTop: 18 }}>
      <section className="panel panel-pad an-section" aria-labelledby="p360-ready">
        <h2 id="p360-ready">Career readiness</h2>
        <ul className="checklist">
          {v.checklist.map((c) => (
            <li key={c.key}>
              {c.done ? <Check size={16} className="done" aria-hidden="true" /> : <Circle size={16} className="todo" aria-hidden="true" />}
              <span>{c.label}</span>
              <span className="visually-hidden">{c.done ? 'done' : 'not yet'}</span>
            </li>
          ))}
        </ul>
        <p className="small muted" style={{ marginTop: 12 }}>
          CV updated {dateLabel(v.cv.updated_at)}. Shares CV with partner employers: {v.cv.employer_sharing_consent_at ? `yes, since ${dateLabel(v.cv.employer_sharing_consent_at)}` : 'no'}.
        </p>
      </section>

      {v.alumni && <AlumniPanel alumni={v.alumni} />}
      <InternshipPanel internship={v.internship} />

      <div className="an-grid" style={{ marginBottom: 0 }}>
        <section className="panel panel-pad an-section" aria-labelledby="p360-jobs">
          <h2 id="p360-jobs">Job applications</h2>
          {v.applications.length === 0 ? <p className="muted small">None.</p> : (
            <div className="table-wrap">
              <table className="table">
                <thead><tr><th>Job</th><th>Applied</th><th>Status</th></tr></thead>
                <tbody>
                  {v.applications.map((a) => (
                    <tr key={a.id}>
                      <td>{a.title}<div className="small muted">{a.employer}</div></td>
                      <td className="num small">{dateLabel(a.created_at)}</td>
                      <td><span className={`badge ${a.status === 'hired' || a.status === 'offered' ? 'badge-ok' : ''}`}>{APP_LABEL[a.status] ?? a.status}</span></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <section className="panel panel-pad an-section" aria-labelledby="p360-skills">
          <h2 id="p360-skills">Skills and learning</h2>
          {v.skills.length === 0 ? <p className="muted small">No skill ratings yet.</p> : (
            <dl className="an-facts">
              {v.skills.map((s) => (
                <div key={s.area}>
                  <dt>{s.area}</dt>
                  <dd className="num">{s.latest} / 5{s.previous !== null && s.previous !== s.latest && <span className="small muted"> (was {s.previous})</span>}</dd>
                </div>
              ))}
            </dl>
          )}
          <h3>Learning records</h3>
          {v.learning.length === 0 ? <p className="muted small">None.</p> : (
            <ul style={{ margin: 0, paddingLeft: 18 }}>
              {v.learning.map((l) => (
                <li key={l.id} className="small">
                  {l.name}{l.provider ? `, ${l.provider}` : ''}{' '}
                  <span className={`badge ${l.status === 'verified' ? 'badge-ok' : l.status === 'rejected' ? 'badge-red' : 'badge-warn'}`}>
                    {l.status === 'verified' ? 'Verified' : l.status === 'rejected' ? 'Rejected' : 'Waiting'}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>

      <section className="panel panel-pad an-section" aria-labelledby="p360-assess">
        <h2 id="p360-assess">Self-assessments and reflections</h2>
        {v.assessments.length === 0 ? <p className="muted small">None taken.</p> : v.assessments.map((a) => (
          <div key={a.id} style={{ marginBottom: 14 }}>
            <strong>{a.title}</strong>{' '}
            <span className="num">{a.score} / {a.max_score}</span>
            {a.band_label && <span className="badge" style={{ marginLeft: 8 }}>{a.band_label}</span>}
            <span className="small muted"> · {dateLabel(a.created_at)}{a.semester ? ` · semester ${a.semester}` : ''}</span>
            {a.reflections.map((r) => (
              <div key={r.prompt} className="reflection">
                <span className="small muted">{r.prompt}</span>
                <p>{r.answer}</p>
              </div>
            ))}
          </div>
        ))}
      </section>

      <section className="panel panel-pad an-section" aria-labelledby="p360-activity">
        <h2 id="p360-activity">Activity</h2>
        <dl className="an-facts">
          <div><dt>Workshops going</dt><dd className="num">{v.workshops.going}</dd></div>
          <div><dt>Workshops attended</dt><dd className="num">{v.workshops.attended}</dd></div>
          <div><dt>Career path steps</dt><dd className="num">{v.career_path.total === 0 ? 'No path' : `${v.career_path.done} / ${v.career_path.total}`}</dd></div>
          <div><dt>Interview sessions</dt><dd className="num">{v.interview.sessions}</dd></div>
          <div><dt>Interview score</dt><dd className="num">{v.interview.average === null ? '-' : `${v.interview.average} / 5`}</dd></div>
          <div><dt>Yearly survey</dt><dd>{v.survey ? `${v.survey.status === 'submitted' ? 'Sent' : v.survey.status === 'draft' ? 'Draft' : 'Not started'}` : 'No round yet'}</dd></div>
        </dl>
        {v.showcase.length > 0 && (
          <>
            <h3>FYP showcase</h3>
            <ul style={{ margin: 0, paddingLeft: 18 }}>
              {v.showcase.map((s) => (
                <li key={s.id} className="small">
                  {s.published ? <a href={`/showcase/${s.id}`}>{s.title}</a> : s.title}
                  {s.project_year && <span className="muted num"> · {s.project_year}</span>}
                  {!s.published && <span className="badge" style={{ marginLeft: 6 }}>Not published</span>}
                </li>
              ))}
            </ul>
          </>
        )}
      </section>
    </div>
  );
}

function InternshipPanel({ internship: i }: { internship: PersonView['internship'] }) {
  return (
    <section className="panel panel-pad an-section" aria-labelledby="p360-intern">
      <h2 id="p360-intern">Internship and industry visit</h2>
      {!i ? <p className="muted small">No internship recorded.</p> : (
        <>
          <dl className="an-facts">
            <div><dt>Company</dt><dd>{i.company}</dd></div>
            <div><dt>Nature of business</dt><dd>{i.business_nature ?? '-'}</dd></div>
            <div><dt>Address</dt><dd>{[i.company_address_1, i.company_address_2, i.company_address_3, i.company_postcode, i.company_state, i.company_country].filter(Boolean).join(', ') || '-'}</dd></div>
            <div><dt>Dates</dt><dd className="num">{i.start_date} to {i.end_date ?? 'open'}</dd></div>
            <div><dt>Batch</dt><dd className="num">{i.batch_month && i.batch_year ? `${String(i.batch_month).padStart(2, '0')}/${i.batch_year}` : '-'}</dd></div>
            <div><dt>Monthly allowance</dt><dd className="num">{Number(i.monthly_allowance_rm) === 0 ? 'Unpaid' : ringgit(i.monthly_allowance_rm)}</dd></div>
            <div><dt>HR</dt><dd>{i.has_hr_contact ? [i.hr_name, i.hr_phone, i.hr_email].filter(Boolean).join(' · ') || '-' : 'No HR personnel'}</dd></div>
            <div><dt>Industry supervisor</dt><dd>{[i.supervisor_name, i.supervisor_phone, i.supervisor_email].filter(Boolean).join(' · ')}</dd></div>
            <div><dt>Logbook weeks approved</dt><dd className="num">{i.logbook.approved} / {i.logbook.started}</dd></div>
            <div><dt>Hours approved</dt><dd className="num">{i.logbook.hours_approved}</dd></div>
            <div><dt>Waiting · returned</dt><dd className="num">{i.logbook.waiting} · {i.logbook.returned}</dd></div>
            <div><dt>Placement form</dt><dd>{i.placement_submitted_at ? `Sent ${dateLabel(i.placement_submitted_at)}` : 'Not sent'}</dd></div>
          </dl>
          <h3>Industry satisfaction</h3>
          {!i.satisfaction ? <p className="muted small">Not answered yet.</p> : (
            <p className="small">
              Rated <strong>{RATING[i.satisfaction.overall_rating]}</strong> (<span className="num">{i.satisfaction.overall_rating}</span>/5).
              {' '}{i.satisfaction.recommend_juniors ? 'Recommends' : 'Does not recommend'} it to juniors.
              {' '}{i.satisfaction.offered_job ? 'Was offered a job.' : 'Was not offered a job.'}
            </p>
          )}
        </>
      )}
    </section>
  );
}

function AlumniPanel({ alumni: a }: { alumni: NonNullable<PersonView['alumni']> }) {
  return (
    <section className="panel panel-pad an-section" aria-labelledby="p360-tracer">
      <h2 id="p360-tracer">Employment history</h2>
      {a.confirmation_overdue && <p className="small"><span className="badge badge-warn">Confirmation overdue</span> Not confirmed in the last {a.confirm_days} days.</p>}
      {a.confirmations.length === 0 ? <p className="muted small">Never confirmed.</p> : (
        <ol className="timeline">
          {a.confirmations.map((c) => (
            <li key={c.confirmed_at}>
              <strong>{STATUS_LABEL[c.employment_status]}{c.title || c.company ? `: ${[c.title, c.company].filter(Boolean).join(', ')}` : ''}</strong>
              <span className="when">{dateLabel(c.confirmed_at)}</span>
            </li>
          ))}
        </ol>
      )}
      <dl className="an-facts" style={{ marginTop: 12 }}>
        <div><dt>In the alumni network</dt><dd>{a.network_visible ? 'Yes' : 'No'}</dd></div>
        <div><dt>Mentor</dt><dd>{a.mentor_active ? 'Active' : 'No'}</dd></div>
        <div><dt>Mentoring matches</dt><dd className="num">{a.mentor_matches}</dd></div>
      </dl>
    </section>
  );
}
