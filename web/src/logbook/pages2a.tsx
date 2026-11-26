// Pages of slice 2a by path, so App.tsx needs only two marked lines. Permission checks here are for looks; the API
// refuses on its own.
import type { ReactNode } from 'react';
import { can, type Me } from '../auth';
import { AssessmentAdminList, AssessmentEditorPage } from '../assessment/AssessmentBuilder';
import { AssessmentList, TakeAssessment } from '../assessment/AssessmentsPage';
import { MyProject } from '../showcase/MyProject';
import { ProjectPage, TalentFeed } from '../showcase/TalentFeed';
import { InternDetailPage, InternTracker } from './InternTracker';
import { LogbookPage } from './LogbookPage';
import { SupervisorReview } from './SupervisorReview';

/** The supervisor's review page: open to anyone holding a link. */
export function publicPage2a(path: string): ReactNode | null {
  const token = /^\/review\/([A-Za-z0-9_-]{43})$/.exec(path)?.[1];
  return token ? <SupervisorReview token={token} /> : null;
}

/** A signed-in page of slice 2a and the nav item it belongs to, or null for any other path. */
export function signedInPage2a(path: string, me: Me, go: (to: string) => void): { nav: string; element: ReactNode } | null {
  const [, base = '', rest] = /^\/([a-z-]+)(?:\/([0-9]+|new))?$/.exec(path) ?? [];
  const id = rest && rest !== 'new' ? Number(rest) : null;
  const page = (permission: string, element: ReactNode) => (can(me, permission) ? { nav: `/${base}`, element } : null);

  switch (base) {
    case 'logbook':
      return rest ? null : page('logbook.own', <LogbookPage me={me} />);
    case 'internships':
      return page('logbook.track', id ? <InternDetailPage key={id} id={id} go={go} /> : rest ? null : <InternTracker me={me} go={go} />);
    case 'my-project':
      return rest ? null : page('showcase.own', <MyProject me={me} />);
    case 'showcase':
      return page('showcase.browse', id ? <ProjectPage key={id} id={id} go={go} /> : rest ? null : <TalentFeed go={go} />);
    case 'assessments':
      return page('assessment.take', id ? <TakeAssessment key={id} id={id} me={me} go={go} /> : rest ? null : <AssessmentList go={go} />);
    case 'assessment-builder':
      return page(
        'assessment.manage',
        rest ? <AssessmentEditorPage key={rest} id={id} me={me} go={go} /> : <AssessmentAdminList go={go} />,
      );
    default:
      return null;
  }
}
