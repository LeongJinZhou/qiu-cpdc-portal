// The logbook page's placement and survey tabs: the student's own placement form, its change history, the survey.
import { ArrowRight, History } from 'lucide-react';
import { apiGet, useLoad } from '../api';
import type { Me } from '../auth';
import { dateLabel } from '../profile/types';
import { LoadError, SkeletonLines } from '../ui/parts';
import { PlacementForm } from './PlacementForm';
import { SatisfactionForm } from './SatisfactionForm';
import { FIELD_LABEL, REASON_LABEL, changeValue, type Change, type MyPlacement } from './types';
import './placement.css';

/** The student's own placement, survey and change history (GET /api/placement). */
export function usePlacement() {
  return useLoad('placement', () => apiGet<MyPlacement>('/api/placement'));
}

export function PlacementPanel({
  me,
  mine,
  onSaved,
  onCancel,
}: {
  me: Me;
  mine: MyPlacement;
  onSaved: (mine: MyPlacement) => void;
  onCancel?: () => void;
}) {
  const { placement } = mine;
  return (
    <div className="stack">
      <section className="panel panel-pad stack" aria-labelledby="pl-h">
        <div>
          <h2 id="pl-h">{placement?.placement_submitted_at ? 'Your placement' : 'Placement form'}</h2>
          <p className="small muted">
            {placement?.placement_submitted_at
              ? `Submitted ${dateLabel(placement.placement_submitted_at)}. To change it, edit the details and give a reason.`
              : 'Tell CPDC where you do your internship. Your supervisor approves your weekly logbook from an email link.'}
          </p>
        </div>
        <PlacementForm
          key={placement?.placement_submitted_at ?? 'new'}
          placement={placement}
          businessNatures={mine.business_natures}
          csrf={me.csrf_token}
          onSaved={onSaved}
          onCancel={onCancel}
        />
      </section>
      {mine.changes.length > 0 && <ChangeList changes={mine.changes} title="Changes you made" />}
    </div>
  );
}

export function SurveyPanel({ me, mine, onSaved }: { me: Me; mine: MyPlacement; onSaved: (mine: MyPlacement) => void }) {
  const company = mine.placement?.company ?? 'this organisation';
  return (
    <section className="panel panel-pad stack" aria-labelledby="sv-h">
      <div>
        <h2 id="sv-h">Internship satisfaction survey</h2>
        <p className="small muted">For CPDC and future interns. Answer at the end of your internship at {company}.</p>
      </div>
      <SatisfactionForm company={company} saved={mine.satisfaction} csrf={me.csrf_token} onSaved={onSaved} />
    </section>
  );
}

export function PlacementLoadState({ load }: { load: ReturnType<typeof usePlacement> }) {
  if (load.error) return <LoadError error={load.error} onRetry={load.reload} />;
  return <SkeletonLines lines={6} />;
}

/** A change history: when, why, and each field's old and new value. */
export function ChangeList({ changes, title }: { changes: Change[]; title: string }) {
  return (
    <section className="panel panel-pad stack" aria-label={title}>
      <h3 className="pl-history-title">
        <History size={17} aria-hidden="true" /> {title}
      </h3>
      <ol className="pl-history">
        {changes.map((c) => (
          <li key={c.id}>
            <div className="pl-history-head">
              <strong>{REASON_LABEL[c.change_reason]}</strong>
              <span className="small muted num">
                {dateLabel(c.created_at)}
                {c.changed_by ? ` · ${c.changed_by}` : ''}
              </span>
            </div>
            {c.remarks && <p className="small">{c.remarks}</p>}
            <dl className="pl-diff small">
              {c.fields.map((f) => (
                <div key={f.field}>
                  <dt>{FIELD_LABEL[f.field]}</dt>
                  <dd>
                    <del>{changeValue(f.field, f.old)}</del>
                    <ArrowRight size={13} aria-hidden="true" />
                    <span className="visually-hidden">changed to</span>
                    <ins>{changeValue(f.field, f.new)}</ins>
                  </dd>
                </div>
              ))}
            </dl>
          </li>
        ))}
      </ol>
    </section>
  );
}
