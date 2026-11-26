// The app: who is signed in, which page, and the gate that keeps onboarding first (docs/SPEC.md rule 4).
import { useCallback, useEffect, useState } from 'react';
import { AlumniTracker } from './admin/AlumniTracker';
import { AuditLog } from './admin/AuditLog';
import { People } from './admin/People';
import { PersonDetail } from './admin/PersonDetail';
import { Registrations } from './admin/Registrations';
import { can, fetchMe, signOut, type Me } from './auth';
import { takeQueryFlag } from './auth/linkedinResult';
import { Login } from './auth/Login';
import { Onboarding } from './auth/Onboarding';
import { Privacy } from './auth/Privacy';
import { RegisterGraduate } from './auth/RegisterGraduate';
import { ProfilePage } from './profile/ProfilePage';
import { NAV, Shell } from './shell/Shell';
import { LoadError, SkeletonLines } from './ui/parts';
// ---- slice 2a (logbook, showcase, assessments): imports ----
import { TITLES_2A } from './logbook/nav2a';
import { publicPage2a, signedInPage2a } from './logbook/pages2a';
// ---- slice 2a: end ----
// --- slice 2b (start) ---
import { JobsPage } from './jobs/JobsPage';
import { ManageJobs } from './jobs/ManageJobs';
import { LearningPage } from './learning/LearningPage';
import { LearningReview } from './learning/LearningReview';
import { SkillsPage } from './skills/SkillsPage';
// --- slice 2b (end) ---
// slice 3a (workshops, CV, career path)
import { CareerPathPage } from './careerpath/CareerPathPage';
import { CvPage } from './cv/CvPage';
import { WorkshopsPage } from './workshops/WorkshopsPage';
// --- slice 3b (begin) ---
import { InterviewPage } from './interview/InterviewPage';
import { QuestionBank } from './interview/QuestionBank';
import { MentoringActivity } from './network/MentoringActivity';
import { NetworkPage } from './network/NetworkPage';
// --- slice 3b (end) ---
// --- slice B (begin) ---
import { HomePage } from './home/HomePage';
import { SurveyManage } from './survey/SurveyManage';
import { SurveyPage } from './survey/SurveyPage';
// --- slice B (end) ---

type State = { kind: 'loading' } | { kind: 'error'; error: unknown } | { kind: 'out' } | { kind: 'in'; me: Me };

const TITLES: Record<string, string> = {
  '/profile': 'My profile',
  '/alumni': 'Alumni tracker',
  '/people': 'People',
  '/registrations': 'Graduate sign-ups',
  '/audit': 'Audit log',
  ...TITLES_2A, // slice 2a
  // --- slice 2b (start) ---
  '/jobs': 'Jobs',
  '/skills': 'Skills',
  '/learning': 'Learning',
  '/employers': 'Employers and jobs',
  '/learning-checks': 'Learning checks',
  // --- slice 2b (end) ---
  // slice 3a (workshops, CV, career path)
  '/workshops': 'Workshops',
  '/cv': 'CV builder',
  '/career-path': 'Career path',
  // --- slice 3b (begin) ---
  '/network': 'Alumni network',
  '/interview': 'Interview practice',
  '/mentoring': 'Mentoring activity',
  '/questions': 'Interview questions',
  // --- slice 3b (end) ---
  // --- slice B (begin) ---
  '/home': 'Home',
  '/surveys': 'University and Programme Survey',
  '/assessment-report': 'Self-assessment report',
  '/assessment-insights': 'Assessment results',
  // --- slice B (end) ---
};

export function App() {
  const [state, setState] = useState<State>({ kind: 'loading' });
  const [path, setPath] = useState(window.location.pathname);
  // Flags the server adds to a redirect, read once on load and removed from the address bar.
  const [flags] = useState(() => ({
    error: takeQueryFlag('error'),
    linkedin: takeQueryFlag('linkedin'),
    github: takeQueryFlag('github'),
  }));

  const go = useCallback((to: string) => {
    window.history.pushState({}, '', to);
    setPath(to.split('?')[0]);
    window.scrollTo(0, 0);
  }, []);

  const refresh = useCallback(
    () =>
      fetchMe().then(
        (me) => setState(me ? { kind: 'in', me } : { kind: 'out' }),
        (error: unknown) => setState({ kind: 'error', error }),
      ),
    [],
  );

  useEffect(() => {
    void refresh();
  }, [refresh]);
  useEffect(() => {
    const onPop = () => setPath(window.location.pathname);
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, []);

  const base = `/${path.split('/')[1] ?? ''}`;
  useEffect(() => {
    document.title = `${TITLES[base] ?? 'Career Portal'} | QIU`;
  }, [base]);

  // Pages anyone can open, signed in or not.
  if (path === '/privacy') return <Privacy />;
  if (path === '/register-graduate') return <RegisterGraduate />;
  // ---- slice 2a: the supervisor's review link (no sign-in) ----
  const public2a = publicPage2a(path);
  if (public2a) return public2a;
  // ---- slice 2a: end ----

  if (state.kind === 'loading') {
    return <div style={{ maxWidth: 420, margin: '20vh auto', padding: 20 }}><SkeletonLines lines={3} /></div>;
  }
  if (state.kind === 'error') {
    return <div style={{ maxWidth: 520, margin: '20vh auto', padding: 20 }}><LoadError error={state.error} onRetry={() => void refresh()} /></div>;
  }
  if (state.kind === 'out') {
    if (path !== '/login') window.history.replaceState({}, '', '/login');
    return <Login error={flags.error} />;
  }

  const { me } = state;
  if (me.onboarding.required) {
    if (path !== '/onboarding') window.history.replaceState({}, '', '/onboarding');
    return <Onboarding me={me} linkResult={flags.linkedin} onDone={() => void refresh().then(() => go('/profile'))} />;
  }

  // The first page this person may open; unknown or forbidden paths land there.
  const allowed = NAV.filter((item) => can(me, item.permission)).map((item) => item.path);
  const home = allowed[0] ?? '/profile';
  // ---- slice 2a (logbook, showcase, assessments): signed-in pages ----
  const page2a = signedInPage2a(path, me, go);
  if (page2a) {
    const signOut2a = () =>
      void signOut(me.csrf_token).finally(() => {
        setState({ kind: 'out' });
        window.history.replaceState({}, '', '/login');
        setPath('/login');
      });
    return <Shell me={me} path={page2a.nav} go={go} onSignOut={signOut2a}>{page2a.element}</Shell>;
  }
  // ---- slice 2a: end ----
  const personId = /^\/people\/(\d+)$/.exec(path)?.[1];
  // A menu path, exactly (two-part paths such as /surveys/manage are menu items of their own).
  const page = personId && can(me, 'people.view') ? '/people/:id' : allowed.includes(path) ? path : null;
  if (page === null && path !== home) {
    window.history.replaceState({}, '', home);
    queueMicrotask(() => setPath(home));
  }

  const handleSignOut = () => {
    signOut(me.csrf_token).finally(() => {
      setState({ kind: 'out' });
      window.history.replaceState({}, '', '/login');
      setPath('/login');
    });
  };

  return (
    <Shell me={me} path={page === '/people/:id' ? '/people' : path} go={go} onSignOut={handleSignOut}>
      {(page ?? home) === '/profile' && (
        <ProfilePage me={me} linkResult={flags.linkedin} githubResult={flags.github} onMeChanged={() => void refresh()} />
      )}
      {page === '/alumni' && <AlumniTracker me={me} />}
      {page === '/people' && <People go={go} />}
      {page === '/people/:id' && personId && <PersonDetail me={me} id={Number(personId)} go={go} />}
      {page === '/registrations' && <Registrations me={me} />}
      {page === '/audit' && <AuditLog />}
      {/* --- slice 2b (start) --- */}
      {page === '/jobs' && <JobsPage me={me} go={go} />}
      {page === '/skills' && <SkillsPage me={me} />}
      {page === '/learning' && <LearningPage me={me} />}
      {page === '/employers' && <ManageJobs me={me} />}
      {page === '/learning-checks' && <LearningReview me={me} />}
      {/* --- slice 2b (end) --- */}
      {/* slice 3a (workshops, CV, career path) */}
      {page === '/workshops' && <WorkshopsPage me={me} />}
      {page === '/cv' && <CvPage me={me} go={go} />}
      {page === '/career-path' && <CareerPathPage me={me} go={go} />}
      {/* --- slice 3b (begin) --- */}
      {page === '/network' && <NetworkPage me={me} />}
      {page === '/interview' && <InterviewPage me={me} />}
      {page === '/mentoring' && <MentoringActivity />}
      {page === '/questions' && <QuestionBank me={me} />}
      {/* --- slice 3b (end) --- */}
      {/* --- slice B (begin) --- */}
      {page === '/home' && <HomePage me={me} go={go} />}
      {page === '/surveys' && <SurveyPage me={me} go={go} />}
      {page === '/surveys/manage' && <SurveyManage me={me} />}
      {/* --- slice B (end) --- */}
    </Shell>
  );
}
