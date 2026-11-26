// CPDC: every self-assessment with its use, and the editor (questions, points per answer, result bands).
import { Archive, ArrowLeft, BarChart3, ClipboardList, Eye, Lock, Plus, Trash2 } from 'lucide-react';
import { useState, type FormEvent, type MouseEvent } from 'react';
import { apiGet, apiWrite, useLoad } from '../api';
import type { Me } from '../auth';
import { dateLabel } from '../profile/types';
import { PageHead } from '../shell/Shell';
import { Empty, errorText, LoadError, Notice, SkeletonLines, Spinner } from '../ui/parts';
import { editorProblem, STATUS_BADGE, STATUS_LABEL, toSaveBody, type Band, type EditQuestion, type Managed, type ManagedRow } from './types';
import './assessment.css';

export function AssessmentAdminList({ go }: { go: (to: string) => void }) {
  const list = useLoad('assessments-manage', () => apiGet<{ items: ManagedRow[] }>('/api/assessments/manage'));
  const follow = (to: string) => (event: MouseEvent) => {
    if (event.metaKey || event.ctrlKey) return;
    event.preventDefault();
    go(to);
  };
  return (
    <>
      <PageHead
        title="Assessments"
        actions={
          <a className="btn btn-primary" href="/assessment-builder/new" onClick={follow('/assessment-builder/new')}>
            <Plus size={16} aria-hidden="true" /> New assessment
          </a>
        }
      >
        Short self-assessments for students and alumni. The server scores every answer; results show on their profile.
      </PageHead>
      <section className="panel panel-pad">
        {list.error && <LoadError error={list.error} onRetry={list.reload} />}
        {!list.data && !list.error && <SkeletonLines lines={4} />}
        {list.data && list.data.items.length === 0 && (
          <Empty icon={ClipboardList} title="No assessments yet">Write the first one: a few questions with points per answer.</Empty>
        )}
        {list.data && list.data.items.length > 0 && (
          <div className="table-wrap">
            <table className="table">
              <thead>
                <tr>
                  <th>Title</th>
                  <th>Status</th>
                  <th className="num">Questions</th>
                  <th className="num">Taken</th>
                  <th className="num">Average</th>
                  <th>Changed</th>
                  <th><span className="visually-hidden">Open</span></th>
                </tr>
              </thead>
              <tbody>
                {list.data.items.map((a) => (
                  <tr key={a.id}>
                    <td><strong>{a.title}</strong></td>
                    <td><span className={STATUS_BADGE[a.status]}>{STATUS_LABEL[a.status]}</span></td>
                    <td className="num">{a.question_count}</td>
                    <td className="num" title={`${a.people_count} people`}>{a.attempt_count}</td>
                    <td className="num">{a.average_percent === null ? '-' : `${a.average_percent}%`}</td>
                    <td className="small muted num">{dateLabel(a.updated_at)}</td>
                    <td>
                      <a className="btn btn-small" href={`/assessment-builder/${a.id}`} onClick={follow(`/assessment-builder/${a.id}`)}>
                        Open
                      </a>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </>
  );
}

const BLANK: Managed = {
  id: 0,
  title: '',
  description: '',
  status: 'draft',
  answer_style: 'choice',
  reflection_prompts: [],
  bands: [
    { min_percent: 0, label: 'Getting started' },
    { min_percent: 50, label: 'On your way' },
    { min_percent: 80, label: 'Ready' },
  ],
  questions: [{ prompt: '', options: [{ label: '', points: 0 }, { label: '', points: 2 }] }],
  locked: false,
  updated_at: '',
};

export function AssessmentEditorPage({ id, me, go }: { id: number | null; me: Me; go: (to: string) => void }) {
  const load = useLoad(`assessment-manage:${id ?? 'new'}`, () =>
    id === null ? Promise.resolve(BLANK) : apiGet<Managed>(`/api/assessments/manage/${id}`),
  );
  const back = (event: MouseEvent) => {
    event.preventDefault();
    go('/assessment-builder');
  };
  return (
    <div className="as-page">
      <a className="back-link" href="/assessment-builder" onClick={back}>
        <ArrowLeft size={16} aria-hidden="true" /> All assessments
      </a>
      {load.error && <LoadError error={load.error} onRetry={load.reload} />}
      {!load.data && !load.error && <SkeletonLines lines={6} />}
      {load.data && <Editor key={load.data.id} initial={load.data} me={me} go={go} />}
    </div>
  );
}

function Editor({ initial, me, go }: { initial: Managed; me: Me; go: (to: string) => void }) {
  const [a, setA] = useState<Managed>(initial);
  const [busy, setBusy] = useState<string | null>(null);
  const [note, setNote] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  const isNew = a.id === 0;
  const problem = editorProblem(a);
  const readOnly = a.locked;

  const run = (label: string, call: () => Promise<Managed>, ok: string) => {
    setBusy(label);
    setNote(null);
    call()
      .then(
        (saved) => {
          setA(saved);
          setNote({ tone: 'ok', text: ok });
          if (isNew) window.history.replaceState({}, '', `/assessment-builder/${saved.id}`);
        },
        (error: unknown) => setNote({ tone: 'error', text: errorText(error) }),
      )
      .finally(() => setBusy(null));
  };
  const save = (event: FormEvent) => {
    event.preventDefault();
    const body = toSaveBody(a);
    run(
      'save',
      () =>
        isNew
          ? apiWrite<Managed>('POST', '/api/assessments/manage', body, me.csrf_token)
          : apiWrite<Managed>('PUT', `/api/assessments/manage/${a.id}`, body, me.csrf_token),
      'Saved.',
    );
  };
  const status = (next: Managed['status'], ok: string) =>
    run(next, () => apiWrite<Managed>('PATCH', `/api/assessments/manage/${a.id}/status`, { status: next }, me.csrf_token), ok);

  const setQuestion = (i: number, q: EditQuestion) => setA({ ...a, questions: a.questions.map((x, j) => (j === i ? q : x)) });
  const setBand = (i: number, b: Band) => setA({ ...a, bands: a.bands.map((x, j) => (j === i ? b : x)) });

  return (
    <form className="stack" onSubmit={save} noValidate>
      <PageHead
        title={isNew ? 'New assessment' : a.title || 'Assessment'}
        actions={
          !isNew && (
            <>
              <span className={STATUS_BADGE[a.status]}>{STATUS_LABEL[a.status]}</span>
              <a className="btn btn-small" href={`/assessment-insights/${a.id}`} onClick={(e) => { e.preventDefault(); go(`/assessment-insights/${a.id}`); }}>
                <BarChart3 size={15} aria-hidden="true" /> Results and reflections
              </a>
            </>
          )
        }
      />
      {readOnly && (
        <Notice tone="info">
          <Lock size={14} aria-hidden="true" /> People have taken this assessment, so its questions are fixed. Archive it and write a new one to
          change them.
        </Notice>
      )}
      <fieldset className="panel panel-pad stack as-fieldset" disabled={readOnly}>
        <div className="field">
          <label htmlFor="ab-title">Title</label>
          <input id="ab-title" className="input" maxLength={160} value={a.title} onChange={(e) => setA({ ...a, title: e.target.value })} />
        </div>
        <div className="field">
          <label htmlFor="ab-desc">What it is for</label>
          <textarea id="ab-desc" className="textarea" rows={2} maxLength={2000} value={a.description} onChange={(e) => setA({ ...a, description: e.target.value })} />
        </div>
        <div className="field">
          <label htmlFor="ab-style">Answers</label>
          <select id="ab-style" className="select" value={a.answer_style} onChange={(e) => setA({ ...a, answer_style: e.target.value === 'likert' ? 'likert' : 'choice' })}>
            <option value="choice">Own answers with points</option>
            <option value="likert">Rating 1-5 (Very Poor to Excellent)</option>
          </select>
        </div>
      </fieldset>

      <fieldset className="panel panel-pad stack as-fieldset" disabled={readOnly}>
        <legend className="as-legend">Written reflections</legend>
        <p className="small muted">Questions the person answers in their own words. Each one is required. CPDC can read them; every reading is logged.</p>
        {a.reflection_prompts.map((prompt, i) => (
          <div key={i} className="as-band as-prompt">
            <div className="field">
              <label htmlFor={`ab-r-${i}`}>Reflection {i + 1}</label>
              <input id={`ab-r-${i}`} className="input" maxLength={300} value={prompt} onChange={(e) => setA({ ...a, reflection_prompts: a.reflection_prompts.map((p, j) => (j === i ? e.target.value : p)) })} />
            </div>
            <button type="button" className="btn btn-quiet btn-small" aria-label={`Remove reflection ${i + 1}`} onClick={() => setA({ ...a, reflection_prompts: a.reflection_prompts.filter((_, j) => j !== i) })}>
              <Trash2 size={15} aria-hidden="true" />
            </button>
          </div>
        ))}
        {a.reflection_prompts.length < 5 && (
          <button type="button" className="btn btn-small" onClick={() => setA({ ...a, reflection_prompts: [...a.reflection_prompts, ''] })}>
            <Plus size={15} aria-hidden="true" /> Add reflection question
          </button>
        )}
      </fieldset>

      <fieldset className="panel panel-pad stack as-fieldset" disabled={readOnly}>
        <legend className="as-legend">Result bands</legend>
        <p className="small muted">Each band starts at a share of the best possible score. One must start at 0%.</p>
        {a.bands.map((b, i) => (
          <div key={i} className="as-band">
            <div className="field">
              <label htmlFor={`ab-bandl-${i}`}>Band name</label>
              <input id={`ab-bandl-${i}`} className="input" maxLength={80} value={b.label} onChange={(e) => setBand(i, { ...b, label: e.target.value })} />
            </div>
            <div className="field">
              <label htmlFor={`ab-band-${i}`}>From %</label>
              <input id={`ab-band-${i}`} className="input num" type="number" min={0} max={100} value={b.min_percent} onChange={(e) => setBand(i, { ...b, min_percent: Number(e.target.value) })} />
            </div>
            <button type="button" className="btn btn-quiet btn-small" aria-label={`Remove band ${b.label || i + 1}`} disabled={a.bands.length <= 1} onClick={() => setA({ ...a, bands: a.bands.filter((_, j) => j !== i) })}>
              <Trash2 size={15} aria-hidden="true" />
            </button>
          </div>
        ))}
        {a.bands.length < 6 && (
          <button type="button" className="btn btn-small" onClick={() => setA({ ...a, bands: [...a.bands, { min_percent: 100, label: '' }] })}>
            <Plus size={15} aria-hidden="true" /> Add band
          </button>
        )}
      </fieldset>

      {a.questions.map((q, i) => (
        <fieldset key={i} className="panel panel-pad stack as-fieldset" disabled={readOnly}>
          <div className="section-head">
            <legend className="as-legend">Question {i + 1}</legend>
            <button type="button" className="btn btn-quiet btn-small" disabled={a.questions.length <= 1} onClick={() => setA({ ...a, questions: a.questions.filter((_, j) => j !== i) })}>
              <Trash2 size={15} aria-hidden="true" /> Remove
            </button>
          </div>
          <div className="field">
            <label htmlFor={`ab-q-${i}`}>Question</label>
            <input id={`ab-q-${i}`} className="input" maxLength={500} value={q.prompt} onChange={(e) => setQuestion(i, { ...q, prompt: e.target.value })} />
          </div>
          {a.answer_style === 'likert' && <p className="small muted">Rated 1 (Very Poor) to 5 (Excellent).</p>}
          {a.answer_style === 'choice' && q.options.map((o, k) => (
            <div key={k} className="as-band">
              <div className="field">
                <label htmlFor={`ab-o-${i}-${k}`}>Answer {k + 1}</label>
                <input id={`ab-o-${i}-${k}`} className="input" maxLength={300} value={o.label} onChange={(e) => setQuestion(i, { ...q, options: q.options.map((x, m) => (m === k ? { ...o, label: e.target.value } : x)) })} />
              </div>
              <div className="field">
                <label htmlFor={`ab-p-${i}-${k}`}>Points</label>
                <input id={`ab-p-${i}-${k}`} className="input num" type="number" min={0} max={10} value={o.points} onChange={(e) => setQuestion(i, { ...q, options: q.options.map((x, m) => (m === k ? { ...o, points: Number(e.target.value) } : x)) })} />
              </div>
              <button type="button" className="btn btn-quiet btn-small" aria-label={`Remove answer ${k + 1}`} disabled={q.options.length <= 2} onClick={() => setQuestion(i, { ...q, options: q.options.filter((_, m) => m !== k) })}>
                <Trash2 size={15} aria-hidden="true" />
              </button>
            </div>
          ))}
          {a.answer_style === 'choice' && q.options.length < 6 && (
            <button type="button" className="btn btn-small" onClick={() => setQuestion(i, { ...q, options: [...q.options, { label: '', points: 0 }] })}>
              <Plus size={15} aria-hidden="true" /> Add answer
            </button>
          )}
        </fieldset>
      ))}

      {!readOnly && a.questions.length < 40 && (
        <button type="button" className="btn" onClick={() => setA({ ...a, questions: [...a.questions, { prompt: '', options: [{ label: '', points: 0 }, { label: '', points: 2 }] }] })}>
          <Plus size={16} aria-hidden="true" /> Add question
        </button>
      )}

      {note && <Notice tone={note.tone}>{note.text}</Notice>}
      {!readOnly && problem && <p className="small muted">{problem}</p>}
      <div className="as-submit">
        {!readOnly && (
          <button type="submit" className="btn btn-primary" disabled={busy !== null || problem !== null}>
            {busy === 'save' && <Spinner />} {isNew ? 'Create draft' : 'Save'}
          </button>
        )}
        {!isNew && a.status !== 'published' && (
          <button type="button" className="btn" disabled={busy !== null} onClick={() => status('published', 'Published. Students and alumni can take it now.')}>
            {busy === 'published' ? <Spinner /> : <Eye size={16} aria-hidden="true" />} Publish
          </button>
        )}
        {!isNew && a.status === 'published' && (
          <button type="button" className="btn" disabled={busy !== null} onClick={() => status(a.locked ? 'archived' : 'draft', a.locked ? 'Archived. Results stay on profiles.' : 'Back to draft.')}>
            {busy ? <Spinner /> : <Archive size={16} aria-hidden="true" />} {a.locked ? 'Archive' : 'Unpublish'}
          </button>
        )}
        <button type="button" className="btn btn-quiet" onClick={() => go('/assessment-builder')}>
          Done
        </button>
      </div>
    </form>
  );
}
