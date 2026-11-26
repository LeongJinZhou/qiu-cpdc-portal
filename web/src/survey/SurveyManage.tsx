// CPDC: survey rounds (open and close), response rate per faculty and programme, section and item means without
// N/A, comments without names, CSV export. Reading comments and exporting are audited on the server.
import { BarChart3, ChevronDown, Download, MessageSquareText, Plus, ShieldCheck } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { apiGet, apiWrite, useLoad } from '../api';
import type { Me } from '../auth';
import { PageHead } from '../shell/Shell';
import { Empty, errorText, LoadError, Notice, SkeletonLines, Spinner } from '../ui/parts';
import { meanText, type ManagedCycle, type RateRow, type Report } from './types';
import './survey.css';

const STATUS_BADGE: Record<ManagedCycle['status'], string> = { draft: 'badge', open: 'badge badge-ok', closed: 'badge badge-warn' };
const STATUS_LABEL: Record<ManagedCycle['status'], string> = { draft: 'Draft', open: 'Open', closed: 'Closed' };

export function SurveyManage({ me }: { me: Me }) {
  const list = useLoad('surveys-manage', () => apiGet<{ items: ManagedCycle[] }>('/api/surveys/manage'));
  const [chosen, setChosen] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);
  const [busy, setBusy] = useState<number | null>(null);
  const [note, setNote] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  const items = list.data?.items ?? [];
  const current = chosen ?? items[0]?.id ?? null;

  const setStatus = (c: ManagedCycle, status: 'open' | 'closed') => {
    setBusy(c.id);
    setNote(null);
    apiWrite<ManagedCycle>('PATCH', `/api/surveys/manage/${c.id}/status`, { status }, me.csrf_token)
      .then(
        () => {
          setNote({ tone: 'ok', text: status === 'open' ? `${c.title} is open. Students see it now.` : `${c.title} is closed.` });
          list.reload();
        },
        (error: unknown) => setNote({ tone: 'error', text: errorText(error) }),
      )
      .finally(() => setBusy(null));
  };

  return (
    <>
      <PageHead
        title="Survey results"
        actions={
          <button type="button" className="btn btn-primary" onClick={() => setCreating((v) => !v)} aria-expanded={creating}>
            <Plus size={16} aria-hidden="true" /> New survey round
          </button>
        }
      >
        The yearly University and Programme Survey. Open a round, follow the response rate, read the results.
      </PageHead>
      {creating && (
        <NewRound
          csrf={me.csrf_token}
          onDone={(id) => {
            setCreating(false);
            setChosen(id);
            list.reload();
          }}
        />
      )}
      {note && <Notice tone={note.tone}>{note.text}</Notice>}
      <section className="panel panel-pad">
        {list.error && <LoadError error={list.error} onRetry={list.reload} />}
        {!list.data && !list.error && <SkeletonLines lines={3} />}
        {list.data && items.length === 0 && (
          <Empty icon={BarChart3} title="No survey rounds yet">Create the first round, then open it for students.</Empty>
        )}
        {items.length > 0 && (
          <div className="table-wrap">
            <table className="table sv-rounds">
              <thead>
                <tr>
                  <th>Round</th>
                  <th>Status</th>
                  <th className="num">Sent</th>
                  <th className="num">Drafts</th>
                  <th className="num">Rate</th>
                  <th><span className="visually-hidden">Actions</span></th>
                </tr>
              </thead>
              <tbody>
                {items.map((c) => (
                  <tr key={c.id} aria-current={c.id === current ? 'true' : undefined} className={c.id === current ? 'sv-row-current' : undefined}>
                    <td>
                      <button type="button" className="sv-link" onClick={() => setChosen(c.id)}>
                        {c.title}
                      </button>
                      <span className="small muted"> {c.academic_year}</span>
                    </td>
                    <td><span className={STATUS_BADGE[c.status]}>{STATUS_LABEL[c.status]}</span></td>
                    <td className="num" data-label="Sent">{c.submitted_count} / {c.invited_count}</td>
                    <td className="num" data-label="Drafts">{c.draft_count}</td>
                    <td className="num" data-label="Rate">{c.response_rate === null ? '-' : `${c.response_rate}%`}</td>
                    <td>
                      <div className="row sv-actions">
                        {c.status !== 'open' && (
                          <button type="button" className="btn btn-small" disabled={busy !== null} onClick={() => setStatus(c, 'open')}>
                            {busy === c.id && <Spinner />} {c.status === 'draft' ? 'Open' : 'Reopen'}
                          </button>
                        )}
                        {c.status === 'open' && (
                          <button type="button" className="btn btn-small" disabled={busy !== null} onClick={() => setStatus(c, 'closed')}>
                            {busy === c.id && <Spinner />} Close
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
      {current !== null && <RoundReport key={current} id={current} />}
    </>
  );
}

function NewRound({ csrf, onDone }: { csrf: string; onDone: (id: number) => void }) {
  const year = new Date().getFullYear();
  const [title, setTitle] = useState(`University and Programme Survey ${year}/${year + 1}`);
  const [academicYear, setAcademicYear] = useState(`${year}/${year + 1}`);
  const [closes, setCloses] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    apiWrite<ManagedCycle>('POST', '/api/surveys/manage', { title, academic_year: academicYear, closes_at: closes || null }, csrf)
      .then((c) => onDone(c.id), (e: unknown) => setError(errorText(e)))
      .finally(() => setBusy(false));
  };
  return (
    <form className="panel panel-pad stack sv-new" onSubmit={submit} noValidate>
      <h2>New survey round</h2>
      <div className="sv-new-grid">
        <div className="field">
          <label htmlFor="svn-title">Title</label>
          <input id="svn-title" className="input" maxLength={160} value={title} onChange={(e) => setTitle(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="svn-year">Academic year</label>
          <input id="svn-year" className="input num" maxLength={9} value={academicYear} onChange={(e) => setAcademicYear(e.target.value)} placeholder="2026/2027" />
        </div>
        <div className="field">
          <label htmlFor="svn-close">Closes (Malaysia time, optional)</label>
          <input id="svn-close" className="input" type="datetime-local" value={closes} onChange={(e) => setCloses(e.target.value)} />
        </div>
      </div>
      {error && <Notice tone="error">{error}</Notice>}
      <div className="row">
        <button type="submit" className="btn btn-primary" disabled={busy || title.trim().length < 3}>
          {busy && <Spinner />} Create as draft
        </button>
        <span className="small muted">Students see it only once you open it.</span>
      </div>
    </form>
  );
}

function RoundReport({ id }: { id: number }) {
  const load = useLoad(`survey-report:${id}`, () => apiGet<Report>(`/api/surveys/manage/${id}`));
  const [openSection, setOpenSection] = useState<string | null>(null);
  const r = load.data;
  if (load.error) return <LoadError error={load.error} onRetry={load.reload} />;
  if (!r) return <SkeletonLines lines={6} />;
  return (
    <div className="stack sv-report">
      <section className="panel panel-pad stack" aria-labelledby="sv-rate-h">
        <div className="section-head">
          <h2 id="sv-rate-h">{r.cycle.title}</h2>
          <a className="btn btn-small" href={`/api/surveys/manage/${id}/export`} download>
            <Download size={15} aria-hidden="true" /> CSV
          </a>
        </div>
        <div className="sv-totals">
          <div>
            <strong className="num">{r.totals.rate === null ? '-' : `${r.totals.rate}%`}</strong>
            <span className="small muted">response rate</span>
          </div>
          <div>
            <strong className="num">{r.totals.submitted}</strong>
            <span className="small muted">sent</span>
          </div>
          <div>
            <strong className="num">{r.totals.invited}</strong>
            <span className="small muted">active students</span>
          </div>
        </div>
        <div className="sv-rates">
          <RateTable title="By faculty" rows={r.by_faculty} />
          <RateTable title="By programme" rows={r.by_programme} />
        </div>
      </section>

      <section className="panel panel-pad stack" aria-labelledby="sv-means-h">
        <h2 id="sv-means-h">Results</h2>
        <p className="small muted">Means on 1 to 5, from sent answers only. N/A is counted apart and left out of every mean.</p>
        {r.totals.submitted === 0 && <Empty icon={BarChart3} title="No answers sent yet" />}
        {r.totals.submitted > 0 &&
          r.form.sections.map((s) => {
            const sec = r.stats.sections[s.key];
            const open = openSection === s.key;
            return (
              <div key={s.key} className="sv-sec">
                <button type="button" className="sv-sec-head" aria-expanded={open} onClick={() => setOpenSection(open ? null : s.key)}>
                  <span>{s.title}</span>
                  <MeanBar mean={sec?.mean ?? null} />
                  <ChevronDown size={16} aria-hidden="true" className="sv-chevron" />
                </button>
                {open && (
                  <div className="sv-sec-body">
                    <table className="table">
                      <thead>
                        <tr>
                          <th>Item</th>
                          <th className="num">Mean</th>
                          <th className="num">Answers</th>
                          {s.na && <th className="num">N/A</th>}
                        </tr>
                      </thead>
                      <tbody>
                        {s.items.map((item) => {
                          const st = r.stats.items[item.key];
                          return (
                            <tr key={item.key}>
                              <td>{item.text}</td>
                              <td className="num">{meanText(st?.mean)}</td>
                              <td className="num">{st?.n ?? 0}</td>
                              {s.na && <td className="num">{st?.na ?? 0}</td>}
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                    <Comments id={id} sectionKey={s.key} label={`Comments on ${s.title.toLowerCase()}`} />
                    {s.extra && <Comments id={id} sectionKey={s.extra.key} label={s.extra.label} />}
                  </div>
                )}
              </div>
            );
          })}
      </section>
    </div>
  );
}

function RateTable({ title, rows }: { title: string; rows: RateRow[] }) {
  return (
    <div className="sv-rate">
      <h3>{title}</h3>
      {rows.length === 0 ? (
        <p className="small muted">Nothing yet.</p>
      ) : (
        <ul>
          {rows.map((row) => (
            <li key={row.name}>
              <span className="sv-rate-name">{row.name}</span>
              <span className="sv-rate-bar" aria-hidden="true">
                <span style={{ width: `${Math.min(100, row.rate ?? 0)}%` }} />
              </span>
              <span className="num small">
                {row.submitted}/{row.invited || '-'} {row.rate === null ? '' : `· ${row.rate}%`}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function MeanBar({ mean }: { mean: number | null }) {
  return (
    <span className="sv-mean">
      <span className="sv-mean-bar" aria-hidden="true">
        <span style={{ width: `${mean === null ? 0 : ((mean - 1) / 4) * 100}%` }} />
      </span>
      <strong className="num">{meanText(mean)}</strong>
    </span>
  );
}

function Comments({ id, sectionKey, label }: { id: number; sectionKey: string; label: string }) {
  const [state, setState] = useState<{ loading: boolean; items?: { faculty: string | null; programme: string | null; text: string }[]; error?: unknown }>({ loading: false });
  const read = () => {
    setState({ loading: true });
    apiGet<{ items: { faculty: string | null; programme: string | null; text: string }[] }>(`/api/surveys/manage/${id}/comments`, { section: sectionKey }).then(
      (r) => setState({ loading: false, items: r.items }),
      (error: unknown) => setState({ loading: false, error }),
    );
  };
  return (
    <div className="sv-comments">
      {!state.items && (
        <button type="button" className="btn btn-small btn-quiet" onClick={read} disabled={state.loading}>
          {state.loading ? <Spinner /> : <MessageSquareText size={15} aria-hidden="true" />} {label}
        </button>
      )}
      {state.error !== undefined && <Notice tone="error">{errorText(state.error)}</Notice>}
      {state.items && (
        <>
          <p className="small muted sv-audit">
            <ShieldCheck size={14} aria-hidden="true" /> {label}. No names; this reading is logged.
          </p>
          {state.items.length === 0 && <p className="small muted">No comments.</p>}
          <ul className="sv-comment-list">
            {state.items.map((c, i) => (
              <li key={i}>
                <p>{c.text}</p>
                <span className="small muted">{[c.faculty, c.programme].filter(Boolean).join(' · ') || 'Faculty not given'}</span>
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  );
}
