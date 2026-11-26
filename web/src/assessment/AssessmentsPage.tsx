// Students and alumni: published self-assessments, taking one, and the result (worked out on the server).
import { ArrowLeft, ClipboardCheck, Printer, RotateCcw } from 'lucide-react';
import { useState, type FormEvent, type MouseEvent } from 'react';
import { apiGet, apiWrite, useLoad } from '../api';
import type { Me } from '../auth';
import { dateLabel } from '../profile/types';
import { PageHead } from '../shell/Shell';
import { Empty, errorText, LoadError, Notice, SkeletonLines, Spinner } from '../ui/parts';
import type { Available, Result, Taking } from './types';
import './assessment.css';

export function AssessmentList({ go }: { go: (to: string) => void }) {
  const list = useLoad('assessments', () => apiGet<{ items: Available[] }>('/api/assessments'));
  const open = (id: number) => (event: MouseEvent) => {
    if (event.metaKey || event.ctrlKey) return;
    event.preventDefault();
    go(`/assessments/${id}`);
  };
  return (
    <>
      <PageHead title="Self-assessments">Short checks from CPDC. Your latest result shows on your profile.</PageHead>
      {list.error && <LoadError error={list.error} onRetry={list.reload} />}
      {!list.data && !list.error && <SkeletonLines lines={4} />}
      {list.data && list.data.items.length === 0 && (
        <div className="panel">
          <Empty icon={ClipboardCheck} title="Nothing to take yet">CPDC publishes self-assessments here.</Empty>
        </div>
      )}
      <div className="as-list">
        {list.data?.items.map((a) => (
          <article key={a.id} className="panel panel-pad as-item">
            <div className="stack" style={{ gap: 6 }}>
              <h2>{a.title}</h2>
              {a.description && <p className="small muted">{a.description}</p>}
              <span className="small muted num">
                {a.question_count} {a.question_count === 1 ? 'question' : 'questions'}
              </span>
            </div>
            <div className="as-item-side">
              {a.latest && <ResultChip result={a.latest} />}
              {a.latest?.attempt_id && (
                <a className="btn btn-small btn-quiet" href={`/assessment-report/${a.latest.attempt_id}`} onClick={(e) => { e.preventDefault(); go(`/assessment-report/${a.latest?.attempt_id ?? ''}`); }}>
                  <Printer size={15} aria-hidden="true" /> Report
                </a>
              )}
              <a className={`btn btn-small${a.latest ? '' : ' btn-primary'}`} href={`/assessments/${a.id}`} onClick={open(a.id)}>
                {a.latest ? 'Take again' : 'Start'}
              </a>
            </div>
          </article>
        ))}
      </div>
    </>
  );
}

export function TakeAssessment({ id, me, go }: { id: number; me: Me; go: (to: string) => void }) {
  const load = useLoad(`assessment:${id}`, () => apiGet<Taking>(`/api/assessments/${id}`));
  const [answers, setAnswers] = useState<Record<number, number>>({});
  const [reflections, setReflections] = useState<string[]>([]);
  const [semester, setSemester] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [result, setResult] = useState<Result | null>(null);
  const back = (event: MouseEvent) => {
    event.preventDefault();
    go('/assessments');
  };

  const a = load.data;
  const submit = (event: FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    const body = {
      answers: Object.entries(answers).map(([q, o]) => ({ question_id: Number(q), option_id: o })),
      reflections: a ? a.reflection_prompts.map((_, i) => reflections[i] ?? '') : [],
      semester: semester === '' ? null : Number(semester),
    };
    apiWrite<Result>('POST', `/api/assessments/${id}/attempts`, body, me.csrf_token)
      .then((r) => {
        setResult(r);
        window.scrollTo(0, 0);
      }, setError)
      .finally(() => setBusy(false));
  };
  const restart = () => {
    setResult(null);
    setAnswers({});
    setReflections([]);
  };
  const answered = a ? a.questions.filter((q) => answers[q.id] !== undefined).length : 0;
  const written = a ? a.reflection_prompts.filter((_, i) => (reflections[i] ?? '').trim() !== '').length : 0;
  const ready = a !== undefined && answered === a.questions.length && written === a.reflection_prompts.length;

  return (
    <div className="as-page">
      <a className="back-link" href="/assessments" onClick={back}>
        <ArrowLeft size={16} aria-hidden="true" /> All self-assessments
      </a>
      {load.error && <LoadError error={load.error} onRetry={load.reload} />}
      {!a && !load.error && <SkeletonLines lines={6} />}
      {a && result && (
        <section className="panel panel-pad as-result" role="status" aria-label="Your result">
          <div className="as-ring" style={{ ['--p' as string]: result.percent }}>
            <strong className="num">{result.percent}%</strong>
          </div>
          <div className="stack" style={{ gap: 6 }}>
            <h1>{result.band ?? 'Done'}</h1>
            <p className="muted">
              {a.title}: {result.score} of {result.max_score} points. Saved to your profile.
            </p>
            <div className="row">
              {result.attempt_id && (
                <a className="btn btn-small btn-primary" href={`/assessment-report/${result.attempt_id}`} onClick={(e) => { e.preventDefault(); go(`/assessment-report/${result.attempt_id ?? ''}`); }}>
                  <Printer size={15} aria-hidden="true" /> Open printable report
                </a>
              )}
              <button type="button" className="btn btn-small" onClick={restart}>
                <RotateCcw size={15} aria-hidden="true" /> Take again
              </button>
              <a className="btn btn-small btn-quiet" href="/profile" onClick={(e) => { e.preventDefault(); go('/profile'); }}>
                Open my profile
              </a>
            </div>
          </div>
        </section>
      )}
      {a && !result && (
        <form className="stack" onSubmit={submit}>
          <PageHead title={a.title}>{a.description}</PageHead>
          {a.answer_style === 'likert' ? (
            <section className="panel panel-pad as-likert" aria-label="Rate yourself">
              <p className="small muted">Rate yourself on each one, from 1 (Very Poor) to 5 (Excellent).</p>
              {a.questions.map((q, i) => (
                <fieldset key={q.id} className="as-likert-row">
                  <legend>
                    <span className="muted num">{i + 1}.</span> {q.prompt}
                  </legend>
                  <div className="as-scale">
                    {q.options.map((o, k) => (
                      <label key={o.id} className="as-scale-step">
                        <input type="radio" name={`q-${q.id}`} checked={answers[q.id] === o.id} onChange={() => setAnswers({ ...answers, [q.id]: o.id })} />
                        <span className="num">{k + 1}</span>
                        <span className="as-scale-label">{o.label}</span>
                      </label>
                    ))}
                  </div>
                </fieldset>
              ))}
            </section>
          ) : (
            a.questions.map((q, i) => (
              <fieldset key={q.id} className="panel panel-pad as-question">
                <legend>
                  <span className="muted num">{i + 1}.</span> {q.prompt}
                </legend>
                {q.options.map((o) => (
                  <label key={o.id} className="as-option">
                    <input type="radio" name={`q-${q.id}`} checked={answers[q.id] === o.id} onChange={() => setAnswers({ ...answers, [q.id]: o.id })} />
                    <span>{o.label}</span>
                  </label>
                ))}
              </fieldset>
            ))
          )}
          {a.reflection_prompts.length > 0 && (
            <section className="panel panel-pad stack" aria-labelledby="as-reflect-h">
              <h2 id="as-reflect-h" className="as-side-title">Your commitments</h2>
              {a.reflection_prompts.map((prompt, i) => (
                <div key={prompt} className="field">
                  <label htmlFor={`as-r-${i}`}>{prompt}</label>
                  <textarea
                    id={`as-r-${i}`}
                    className="textarea"
                    rows={3}
                    maxLength={2000}
                    required
                    value={reflections[i] ?? ''}
                    onChange={(e) => setReflections(a.reflection_prompts.map((_, j) => (j === i ? e.target.value : reflections[j] ?? '')))}
                  />
                </div>
              ))}
              <div className="field as-semester">
                <label htmlFor="as-sem">Your semester (optional)</label>
                <select id="as-sem" className="select" value={semester} onChange={(e) => setSemester(e.target.value)}>
                  <option value="">Not given</option>
                  {Array.from({ length: 12 }, (_, i) => (
                    <option key={i + 1} value={i + 1}>Semester {i + 1}</option>
                  ))}
                </select>
              </div>
            </section>
          )}
          {error !== null && <Notice tone="error">{errorText(error)}</Notice>}
          <div className="as-submit">
            <span className="small muted num">
              {answered} of {a.questions.length} rated
              {a.reflection_prompts.length > 0 && `, ${written} of ${a.reflection_prompts.length} written`}
            </span>
            <button type="submit" className="btn btn-primary" disabled={busy || !ready}>
              {busy && <Spinner />} See my result
            </button>
          </div>
        </form>
      )}
    </div>
  );
}

function ResultChip({ result }: { result: Result }) {
  return (
    <span className="as-chip" title={result.taken_at ? `Taken ${dateLabel(result.taken_at)}` : undefined}>
      <strong className="num">{result.percent}%</strong>
      {result.band && <span>{result.band}</span>}
    </span>
  );
}

/** The person's latest results, for their profile page. Renders nothing until they have one. */
export function MyResults() {
  const load = useLoad('assessment-results', () => apiGet<{ items: Result[] }>('/api/assessments/results'));
  const items = load.data?.items ?? [];
  if (items.length === 0) return null;
  return (
    <section className="panel panel-pad stack" aria-labelledby="as-results">
      <h2 id="as-results" className="as-side-title">
        <ClipboardCheck size={17} aria-hidden="true" /> Self-assessments
      </h2>
      <ul className="as-results">
        {items.map((r) => (
          <li key={r.assessment_id}>
            <div>
              <strong>{r.title}</strong>
              <span className="small muted">{r.taken_at ? dateLabel(r.taken_at) : ''}</span>
            </div>
            <ResultChip result={r} />
          </li>
        ))}
      </ul>
    </section>
  );
}
