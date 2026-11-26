// The student's internship logbook: internship details, a week form, and every week with its status.
import { BookOpen, Building2, Pencil, Plus, RotateCw, Send } from 'lucide-react';
import { useState } from 'react';
import { apiGet, apiWrite, useLoad } from '../api';
import type { Me } from '../auth';
import { dateLabel } from '../profile/types';
import { PageHead } from '../shell/Shell';
import { Empty, errorText, LoadError, Notice, SkeletonLines, Spinner } from '../ui/parts';
import { PlacementLoadState, PlacementPanel, SurveyPanel, usePlacement } from '../placement/PlacementPanels';
import type { MyPlacement } from '../placement/types';
import { dayLabel, type Entry, type Logbook } from './types';
import { WeekCard, WeekProgress } from './WeekCard';
import { WeekForm } from './WeekForm';
import './logbook.css';

type Editing = { kind: 'none' } | { kind: 'new' } | { kind: 'edit'; entry: Entry };

export function LogbookPage({ me }: { me: Me }) {
  const load = useLoad('logbook', () => apiGet<Logbook>('/api/logbook'));
  const [fresh, setFresh] = useState<Logbook | null>(null);
  // slice A: placement form and survey tabs
  const placement = usePlacement();
  const [placementFresh, setPlacementFresh] = useState<MyPlacement | null>(null);
  const [tab, setTab] = useState<'weeks' | 'placement' | 'survey'>('weeks');
  const mine = placementFresh ?? placement.data;
  const [editing, setEditing] = useState<Editing>({ kind: 'none' });
  const [message, setMessage] = useState<{ tone: 'ok' | 'error'; text: string; id?: number } | null>(null);
  const [busy, setBusy] = useState<number | null>(null);
  const logbook = fresh ?? load.data;

  if (load.error && !logbook) {
    return (
      <>
        <PageHead title="Internship logbook" />
        <LoadError error={load.error} onRetry={load.reload} />
      </>
    );
  }
  if (!logbook) return <SkeletonLines lines={6} />;

  const { internship } = logbook;
  const replace = (entry: Entry) => {
    const others = logbook.entries.filter((e) => e.id !== entry.id);
    setFresh({ ...logbook, entries: [...others, entry].sort((a, b) => b.week_start.localeCompare(a.week_start)) });
  };
  const send = (entry: Entry, again: boolean) => {
    setBusy(entry.id);
    setMessage(null);
    apiWrite<Entry>('POST', `/api/logbook/entries/${entry.id}/${again ? 'resend' : 'submit'}`, undefined, me.csrf_token)
      .then(
        (sent) => {
          replace(sent);
          setMessage({ tone: 'ok', text: `Sent to ${internship?.supervisor_name ?? 'your supervisor'}.`, id: sent.id });
        },
        (error: unknown) => setMessage({ tone: 'error', text: errorText(error) }),
      )
      .finally(() => setBusy(null));
  };

  // ---- slice A: the placement form and survey share this page ----
  const placementSaved = (saved: MyPlacement) => {
    setPlacementFresh(saved);
    setFresh(null);
    load.reload();
  };
  if (!internship) {
    return (
      <>
        <PageHead title="Internship logbook">
          Log each week of your internship. Start with your placement details; your industry supervisor approves each
          week from an email link.
        </PageHead>
        {mine ? <PlacementPanel me={me} mine={mine} onSaved={placementSaved} /> : <PlacementLoadState load={placement} />}
      </>
    );
  }
  const tabs = (
    <div className="tabs" role="tablist" aria-label="Internship">
      <button type="button" role="tab" aria-selected={tab === 'weeks'} onClick={() => setTab('weeks')}>Logbook</button>
      <button type="button" role="tab" aria-selected={tab === 'placement'} onClick={() => setTab('placement')}>Placement</button>
      <button type="button" role="tab" aria-selected={tab === 'survey'} onClick={() => setTab('survey')}>Survey</button>
    </div>
  );
  if (tab !== 'weeks') {
    return (
      <>
        <PageHead title="Internship logbook">Your placement details and, at the end, the satisfaction survey.</PageHead>
        {tabs}
        {!mine && <PlacementLoadState load={placement} />}
        {mine && tab === 'placement' && <PlacementPanel me={me} mine={mine} onSaved={placementSaved} onCancel={() => setTab('weeks')} />}
        {mine && tab === 'survey' && <SurveyPanel me={me} mine={mine} onSaved={setPlacementFresh} />}
      </>
    );
  }
  // ---- slice A: end ----

  return (
    <>
      <PageHead
        title="Internship logbook"
        actions={
          editing.kind === 'none' && (
            <button type="button" className="btn btn-primary" onClick={() => setEditing({ kind: 'new' })}>
              <Plus size={16} aria-hidden="true" /> Log a week
            </button>
          )
        }
      >
        Your supervisor approves each week from an email link. CPDC sees your progress.
      </PageHead>
      {tabs}
      {!internship.placement_submitted_at && (
        <Notice tone="info">
          <div className="row">
            <span>CPDC now asks for your full placement details (address, batch, allowance, HR contact).</span>
            <button type="button" className="btn btn-small" onClick={() => setTab('placement')}>Complete placement form</button>
          </div>
        </Notice>
      )}

      <div className="lb-grid">
        <div className="stack">
          {editing.kind !== 'none' && (
            <section className="panel panel-pad stack" aria-labelledby="lb-form">
              <h2 id="lb-form">{editing.kind === 'edit' ? 'Change this week' : 'Log a week'}</h2>
              <WeekForm
                internship={internship}
                today={logbook.today}
                entries={logbook.entries}
                editing={editing.kind === 'edit' ? editing.entry : null}
                csrf={me.csrf_token}
                onCancel={() => setEditing({ kind: 'none' })}
                onDone={(entry, sent, sendError) => {
                  replace(entry);
                  setEditing({ kind: 'none' });
                  setMessage(
                    sendError !== undefined
                      ? { tone: 'error', text: `Saved as a draft, not sent: ${errorText(sendError)}` }
                      : { tone: 'ok', text: sent ? `Sent to ${internship.supervisor_name}.` : 'Draft saved.', id: entry.id },
                  );
                }}
              />
            </section>
          )}
          {message && <Notice tone={message.tone}>{message.text}</Notice>}
          {logbook.entries.length === 0 && editing.kind === 'none' && (
            <div className="panel">
              <Empty icon={BookOpen} title="No weeks yet">
                Log your first week once it has started. You can save a draft and send it later.
              </Empty>
            </div>
          )}
          {logbook.entries.map((entry) => (
            <WeekCard
              key={entry.id}
              entry={entry}
              highlight={message?.tone === 'ok' && message.id === entry.id}
              actions={
                entry.status === 'approved' ? undefined : (
                  <WeekActions entry={entry} busy={busy === entry.id} onEdit={() => setEditing({ kind: 'edit', entry })} onSend={send} />
                )
              }
            />
          ))}
        </div>

        <aside className="side-stack">
          <section className="panel panel-pad stack" aria-labelledby="lb-progress">
            <h2 id="lb-progress" className="lb-side-title">Progress</h2>
            <WeekProgress entries={logbook.entries} expected={logbook.weeks_expected} />
          </section>
          <section className="panel panel-pad stack" aria-labelledby="lb-where">
            <div className="section-head">
              <h2 id="lb-where" className="lb-side-title">
                <Building2 size={17} aria-hidden="true" /> {internship.company}
              </h2>
              <button type="button" className="btn btn-quiet btn-small" onClick={() => setTab('placement')}>
                <Pencil size={14} aria-hidden="true" /> Change
              </button>
            </div>
            <dl className="lb-facts small">
              <dt>Supervisor</dt>
              <dd>
                {internship.supervisor_name}
                <div className="muted">{internship.supervisor_email}</div>
              </dd>
              <dt>Dates</dt>
              <dd className="num">
                {dayLabel(internship.start_date)} to {dayLabel(internship.end_date)}
              </dd>
            </dl>
          </section>
        </aside>
      </div>
    </>
  );
}

function WeekActions({
  entry,
  busy,
  onEdit,
  onSend,
}: {
  entry: Entry;
  busy: boolean;
  onEdit: () => void;
  onSend: (entry: Entry, again: boolean) => void;
}) {
  if (entry.status === 'draft' || entry.status === 'returned') {
    return (
      <>
        <button type="button" className="btn btn-small" onClick={() => onSend(entry, false)} disabled={busy}>
          {busy ? <Spinner /> : <Send size={14} aria-hidden="true" />} {entry.status === 'returned' ? 'Send again' : 'Send to supervisor'}
        </button>
        <button type="button" className="btn btn-small btn-quiet" onClick={onEdit}>
          <Pencil size={14} aria-hidden="true" /> Change
        </button>
      </>
    );
  }
  if (entry.status === 'submitted') {
    return (
      <>
        <span className="small muted">
          {entry.link_active
            ? `Sent ${dateLabel(entry.submitted_at)}. The link works until ${dateLabel(entry.link_expires_at)}.`
            : 'The link has expired or the supervisor changed. Send a new one.'}
        </span>
        <button type="button" className={`btn btn-small${entry.link_active ? ' btn-quiet' : ''}`} onClick={() => onSend(entry, true)} disabled={busy}>
          {busy ? <Spinner /> : <RotateCw size={14} aria-hidden="true" />} Send link again
        </button>
      </>
    );
  }
  return null; // approved: nothing left to do
}
