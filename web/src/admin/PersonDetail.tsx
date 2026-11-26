// One person's full profile for CPDC (read-only), plus role change and "make alumni", and for students and alumni the
// 360 analytics view (slice C). Viewing is audited.
import { ArrowLeft, ExternalLink } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { apiGet, apiPatch, apiWrite, useLoad } from '../api';
import { Person360 } from '../analytics/Person360';
import { ROLE_LABEL, can, type Me, type Role } from '../auth';
import { STATUS_LABEL, dateLabel, monthLabel, type Profile } from '../profile/types';
import '../profile/profile.css';
import { Avatar, LoadError, Notice, SkeletonLines, Spinner, errorText } from '../ui/parts';
import './admin.css';

const ASSIGNABLE: Role[] = ['student', 'staff', 'admin'];

export function PersonDetail({ me, id, go }: { me: Me; id: number; go: (to: string) => void }) {
  const load = useLoad(`person:${id}`, () => apiGet<Profile>(`/api/people/${id}`));
  const p = load.data;
  return (
    <>
      <a className="back-link" href="/people" onClick={(e) => { e.preventDefault(); go('/people'); }}>
        <ArrowLeft size={16} aria-hidden="true" /> People
      </a>
      {load.error && <LoadError error={load.error} onRetry={load.reload} />}
      {!p && !load.error && <SkeletonLines lines={6} />}
      {p && (
        <>
          <div className="profile-head">
            <Avatar name={p.display_name} src={p.picture_url} size={64} />
            <div>
              <h1>{p.display_name}</h1>
              <div className="meta">
                <span>{ROLE_LABEL[p.role as Role] ?? p.role}</span>
                {p.headline && <span>{p.headline}</span>}
                {p.linkedin_url ? (
                  <a href={p.linkedin_url} target="_blank" rel="noopener noreferrer">LinkedIn profile <ExternalLink size={13} aria-hidden="true" /></a>
                ) : (
                  <span className="badge badge-warn">No LinkedIn link</span>
                )}
              </div>
            </div>
          </div>
          <div className="profile-grid">
            <div className="panel panel-pad">
              <section className="section">
                <h2>Now</h2>
                <dl className="facts">
                  <dt>Status</dt><dd>{STATUS_LABEL[p.employment_status]}</dd>
                  {(p.current_title || p.current_company) && (<><dt>Job</dt><dd>{[p.current_title, p.current_company].filter(Boolean).join(', ')}</dd></>)}
                  <dt>Confirmed</dt><dd>{dateLabel(p.job_confirmed_at)}</dd>
                </dl>
              </section>
              {p.about && (
                <section className="section">
                  <h2>About</h2>
                  <p style={{ whiteSpace: 'pre-line', maxWidth: '68ch' }}>{p.about}</p>
                </section>
              )}
              <section className="section">
                <h2>Experience</h2>
                {p.experience.length === 0 ? <p className="muted small">None added.</p> : (
                  <ol className="timeline">
                    {p.experience.map((j) => (
                      <li key={j.id}>
                        <strong>{j.title}, {j.company}</strong>
                        <span className="when">{monthLabel(j.start)} to {monthLabel(j.end, true)}{j.location ? ` · ${j.location}` : ''}</span>
                        {j.description && <p className="small">{j.description}</p>}
                      </li>
                    ))}
                  </ol>
                )}
              </section>
              <section className="section">
                <h2>Education</h2>
                {p.education.length === 0 ? <p className="muted small">None added.</p> : (
                  <ol className="timeline">
                    {p.education.map((s) => (
                      <li key={s.id}>
                        <strong>{s.school}</strong>
                        <span className="when">{[s.degree, s.start_year && s.end_year ? `${s.start_year} to ${s.end_year}` : s.end_year].filter(Boolean).join(' · ')}</span>
                      </li>
                    ))}
                  </ol>
                )}
              </section>
              {p.skills.length > 0 && (
                <section className="section">
                  <h2>Skills</h2>
                  <p>{p.skills.join(', ')}</p>
                </section>
              )}
            </div>
            <div className="side-stack">
              <section className="panel panel-pad stack">
                <h2>Details</h2>
                <dl className="facts">
                  <dt>QIU email</dt><dd>{p.email ?? '-'}</dd>
                  <dt>Personal email</dt><dd>{p.personal_email ?? '-'}</dd>
                  <dt>{p.matric_no ? 'Matric no' : 'Staff no'}</dt><dd className="num">{p.matric_no ?? p.employee_id ?? '-'}</dd>
                  <dt>Faculty</dt><dd>{p.faculty ?? p.department ?? '-'}</dd>
                  <dt>Programme</dt><dd>{p.programme ?? '-'}</dd>
                  <dt>Graduated</dt><dd className="num">{p.graduation_year ?? '-'}</dd>
                  <dt>Last sign-in</dt><dd>{dateLabel(p.last_sign_in_at)}</dd>
                  {p.github_url && (<><dt>GitHub</dt><dd><a href={p.github_url} target="_blank" rel="noopener noreferrer">{p.github_url.replace('https://', '')}</a></dd></>)}
                </dl>
              </section>
              {me.user.id !== p.id && p.role !== 'superadmin' && p.role !== 'pending' && (
                <RoleTools me={me} person={p} onChanged={load.reload} />
              )}
            </div>
          </div>
          {can(me, 'analytics.view') && (p.role === 'student' || p.role === 'alumni') && <Person360 id={p.id} />}
        </>
      )}
    </>
  );
}

function RoleTools({ me, person, onChanged }: { me: Me; person: Profile; onChanged: () => void }) {
  const [role, setRole] = useState(person.role as Role);
  const [year, setYear] = useState(String(person.graduation_year ?? new Date().getFullYear()));
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: 'ok' | 'warn' | 'error'; text: string } | null>(null);
  const superadmin = me.user.role === 'superadmin';
  const choices = ASSIGNABLE.filter((r) => r !== 'admin' || superadmin);
  const canChangeRole = person.role !== 'alumni' && (superadmin || person.role !== 'admin');

  async function changeRole(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setMessage(null);
    try {
      await apiPatch(`/api/people/${person.id}/role`, { role }, me.csrf_token);
      setMessage({ tone: 'ok', text: `Role changed to ${ROLE_LABEL[role]}.` });
      onChanged();
    } catch (error) {
      setMessage({ tone: 'error', text: errorText(error) });
    } finally {
      setBusy(false);
    }
  }

  async function makeAlumni() {
    const graduation = Number(year);
    if (!Number.isInteger(graduation) || graduation < 1990) {
      setMessage({ tone: 'error', text: 'Enter the graduation year.' });
      return;
    }
    setBusy(true);
    setMessage(null);
    try {
      const done = await apiWrite<{ linkedin_linked: boolean }>('POST', `/api/people/${person.id}/alumni`, { graduation_year: graduation }, me.csrf_token);
      setMessage(done.linkedin_linked
        ? { tone: 'ok', text: 'Now an alumnus. They sign in with LinkedIn from now on.' }
        : { tone: 'warn', text: 'Now an alumnus, but they have no LinkedIn linked: they cannot sign in once their QIU account closes.' });
      onChanged();
    } catch (error) {
      setMessage({ tone: 'error', text: errorText(error) });
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="panel panel-pad stack">
      <h2>Access</h2>
      {message && <Notice tone={message.tone}>{message.text}</Notice>}
      {canChangeRole && (
        <form className="row" onSubmit={changeRole} style={{ alignItems: 'end' }}>
          <div className="field" style={{ flex: 1 }}>
            <label htmlFor="role">Role</label>
            <select id="role" className="select" value={role} onChange={(e) => setRole(e.target.value as Role)}>
              {choices.map((r) => <option key={r} value={r}>{ROLE_LABEL[r]}</option>)}
            </select>
          </div>
          <button type="submit" className="btn" disabled={busy || role === person.role}>Change</button>
        </form>
      )}
      {person.role === 'student' && (
        <div className="stack" style={{ gap: 10, borderTop: '1px solid var(--line)', paddingTop: 14 }}>
          <div className="field">
            <label htmlFor="grad">Graduated in</label>
            <input id="grad" className="input num" inputMode="numeric" value={year} onChange={(e) => setYear(e.target.value.replace(/\D/g, '').slice(0, 4))} />
          </div>
          <button type="button" className="btn" onClick={makeAlumni} disabled={busy}>
            {busy && <Spinner />}Make alumni
          </button>
        </div>
      )}
    </section>
  );
}
