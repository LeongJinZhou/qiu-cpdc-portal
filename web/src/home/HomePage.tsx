// Home: the signed-in person's overview from one call (GET /api/home): what to do next, upcoming workshops, job
// applications and shortcuts. Each part shows only when the person has its module.
import { ArrowRight, BookOpen, BriefcaseBusiness, CalendarDays, CheckCircle2, Circle, FileText, Route, type LucideIcon } from 'lucide-react';
import type { MouseEvent } from 'react';
import { apiGet, useLoad } from '../api';
import { can, type Me } from '../auth';
import { APPLICATION_LABEL, applicationTone, type ApplicationStatus } from '../jobs/types';
import { PageHead } from '../shell/Shell';
import { Empty, LoadError, SkeletonLines } from '../ui/parts';
import { dateParts, dayLabel, timeLabel } from '../workshops/types';
import './home.css';

interface HomeData {
  workshops:
    | {
        id: number;
        title: string;
        starts_at: string;
        ends_at: string;
        mode: 'in_person' | 'online';
        venue: string | null;
        spots_left: number;
        started: boolean;
        my_rsvp: { status: 'going' | 'waitlist'; waitlist_position: number | null } | null;
      }[]
    | null;
  tasks: { key: string; label: string; done: boolean; detail: string; link: string }[];
  applications: {
    active: number;
    latest: { id: number; job_id: number; title: string; employer_name: string; status: ApplicationStatus }[];
  } | null;
}

const SHORTCUTS: { path: string; label: string; icon: LucideIcon; permission: string }[] = [
  { path: '/jobs', label: 'Browse jobs', icon: BriefcaseBusiness, permission: 'jobs.browse' },
  { path: '/cv', label: 'Update my CV', icon: FileText, permission: 'cv.own' },
  { path: '/logbook', label: 'Logbook', icon: BookOpen, permission: 'logbook.own' },
  { path: '/career-path', label: 'Career path', icon: Route, permission: 'careerpath.view' },
];

export function HomePage({ me, go }: { me: Me; go: (to: string) => void }) {
  const load = useLoad('home', () => apiGet<HomeData>('/api/home'));
  const follow = (to: string) => (event: MouseEvent) => {
    if (event.metaKey || event.ctrlKey || event.shiftKey) return;
    event.preventDefault();
    go(to);
  };
  const first = me.user.display_name.split(/\s+/)[0] ?? me.user.display_name;
  const d = load.data;
  const open = d ? d.tasks.filter((t) => !t.done) : [];
  const shortcuts = SHORTCUTS.filter((s) => can(me, s.permission));

  return (
    <>
      <PageHead title={`Welcome back, ${first}`}>
        {d && open.length > 0 ? `${open.length} ${open.length === 1 ? 'thing' : 'things'} to do next.` : 'Your career portal at a glance.'}
      </PageHead>
      {load.error && <LoadError error={load.error} onRetry={load.reload} />}
      {!d && !load.error && <SkeletonLines lines={6} />}
      {d && (
        <div className="home-grid">
          {d.tasks.length > 0 && (
            <section className="panel panel-pad home-tasks" aria-labelledby="home-tasks-h">
              <div className="home-head">
                <h2 id="home-tasks-h">To do</h2>
                <span className="small muted num">
                  {d.tasks.length - open.length} of {d.tasks.length} done
                </span>
              </div>
              <ul className="home-list">
                {[...open, ...d.tasks.filter((t) => t.done)].map((t) => (
                  <li key={t.key} data-done={t.done}>
                    {t.done ? <CheckCircle2 size={18} aria-hidden="true" className="home-done" /> : <Circle size={18} aria-hidden="true" className="home-open" />}
                    <a href={t.link} onClick={follow(t.link)} className="home-task">
                      <span>{t.label}</span>
                      {t.detail && <span className="small muted">{t.detail}</span>}
                    </a>
                    <span className="visually-hidden">{t.done ? 'Done' : 'To do'}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}

          {d.workshops !== null && (
            <section className="panel panel-pad" aria-labelledby="home-ws-h">
              <div className="home-head">
                <h2 id="home-ws-h">Upcoming workshops</h2>
                <a href="/workshops" onClick={follow('/workshops')} className="small home-more">
                  All workshops <ArrowRight size={14} aria-hidden="true" />
                </a>
              </div>
              {d.workshops.length === 0 && <Empty icon={CalendarDays} title="Nothing planned yet">CPDC posts talks and workshops here.</Empty>}
              <ul className="home-list">
                {d.workshops.map((w) => {
                  const parts = dateParts(w.starts_at);
                  return (
                    <li key={w.id}>
                      <span className="home-date" aria-hidden="true">
                        <strong className="num">{parts.day}</strong>
                        <span>{parts.month}</span>
                      </span>
                      <a href="/workshops" onClick={follow('/workshops')} className="home-task">
                        <span>{w.title}</span>
                        <span className="small muted">
                          {dayLabel(w.starts_at)}, {timeLabel(w.starts_at)} · {w.mode === 'online' ? 'Online' : w.venue}
                        </span>
                      </a>
                      <RsvpBadge w={w} />
                    </li>
                  );
                })}
              </ul>
            </section>
          )}

          {d.applications !== null && (
            <section className="panel panel-pad" aria-labelledby="home-apps-h">
              <div className="home-head">
                <h2 id="home-apps-h">My applications</h2>
                <a href="/jobs" onClick={follow('/jobs')} className="small home-more">
                  Jobs <ArrowRight size={14} aria-hidden="true" />
                </a>
              </div>
              {d.applications.latest.length > 0 && (
                <p className="home-count">
                  <strong className="num">{d.applications.active}</strong>
                  <span className="muted"> active</span>
                </p>
              )}
              {d.applications.latest.length === 0 && (
                <Empty icon={BriefcaseBusiness} title="No applications yet">Open jobs and internships are on the Jobs page.</Empty>
              )}
              <ul className="home-list">
                {d.applications.latest.map((a) => (
                  <li key={a.id}>
                    <span className="home-task">
                      <span>{a.title}</span>
                      <span className="small muted">{a.employer_name}</span>
                    </span>
                    <span className={applicationTone(a.status)}>{APPLICATION_LABEL[a.status]}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}

          {shortcuts.length > 0 && (
            <nav className="panel panel-pad home-shortcuts" aria-label="Shortcuts">
              {shortcuts.map(({ path, label, icon: Icon }) => (
                <a key={path} href={path} onClick={follow(path)} className="btn">
                  <Icon size={16} aria-hidden="true" /> {label}
                </a>
              ))}
            </nav>
          )}
        </div>
      )}
    </>
  );
}

function RsvpBadge({ w }: { w: NonNullable<HomeData['workshops']>[number] }) {
  if (w.my_rsvp?.status === 'going') return <span className="badge badge-ok">Going</span>;
  if (w.my_rsvp?.status === 'waitlist') {
    return <span className="badge badge-warn">Waitlist{w.my_rsvp.waitlist_position ? ` #${w.my_rsvp.waitlist_position}` : ''}</span>;
  }
  return <span className="badge">{w.spots_left > 0 ? `${w.spots_left} seats` : 'Full'}</span>;
}
