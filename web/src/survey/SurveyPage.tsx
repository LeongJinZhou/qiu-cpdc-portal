// Students: the yearly University and Programme Survey. One section per step, drafts saved on the server (on every
// step change and by hand), missing items marked before submit, and a receipt once it is sent.
import { ArrowLeft, ArrowRight, CheckCircle2, ClipboardList, Save } from 'lucide-react';
import { useRef, useState, type MouseEvent } from 'react';
import { apiGet, apiWrite, useLoad } from '../api';
import type { Me } from '../auth';
import { dayLabel as dateLabel } from '../workshops/types'; // the API sends ISO times with Z here
import { PageHead } from '../shell/Shell';
import { Empty, errorText, LoadError, Notice, SkeletonLines, Spinner } from '../ui/parts';
import { itemKeys, missingIn, submitProblem, toBody, type Answer, type Draft, type MyCycle, type ResponseView, type Section, type SurveyFormDef, type SurveyResponse } from './types';
import './survey.css';

export function SurveyPage({ me, go }: { me: Me; go: (to: string) => void }) {
  const list = useLoad('surveys', () => apiGet<{ form: SurveyFormDef; cycles: MyCycle[] }>('/api/surveys'));
  const [openId, setOpenId] = useState<number | null>(null);

  if (openId !== null && list.data) {
    return <SurveyRound key={openId} id={openId} form={list.data.form} me={me} go={go} onBack={() => { setOpenId(null); list.reload(); }} />;
  }
  return (
    <>
      <PageHead title="University and Programme Survey">
        Once a year QIU asks how your programme, teaching and services are going. It takes about 15 minutes; your draft is
        saved as you go.
      </PageHead>
      {list.error && <LoadError error={list.error} onRetry={list.reload} />}
      {!list.data && !list.error && <SkeletonLines lines={3} />}
      {list.data && list.data.cycles.length === 0 && (
        <div className="panel">
          <Empty icon={ClipboardList} title="No survey open right now">CPDC opens the survey once a year. It will show here.</Empty>
        </div>
      )}
      <div className="sv-list">
        {list.data?.cycles.map((c) => (
          <article key={c.id} className="panel panel-pad sv-round">
            <div className="stack" style={{ gap: 4 }}>
              <h2>{c.title}</h2>
              <span className="small muted">
                Academic year {c.academic_year}
                {c.closes_at && c.is_open && ` · open until ${dateLabel(c.closes_at)}`}
              </span>
            </div>
            <div className="sv-round-side">
              {c.my_status === 'submitted' && (
                <span className="badge badge-ok">
                  <CheckCircle2 size={13} aria-hidden="true" /> Sent {c.submitted_at ? dateLabel(c.submitted_at) : ''}
                </span>
              )}
              {c.my_status === 'draft' && <span className="badge badge-warn">Draft saved</span>}
              {!c.is_open && c.my_status !== 'submitted' && <span className="badge">Closed</span>}
              {(c.is_open || c.my_status === 'submitted') && (
                <button type="button" className={`btn btn-small${c.my_status === 'submitted' ? '' : ' btn-primary'}`} onClick={() => setOpenId(c.id)}>
                  {c.my_status === 'submitted' ? 'View receipt' : c.my_status === 'draft' ? 'Continue' : 'Start'}
                </button>
              )}
            </div>
          </article>
        ))}
      </div>
    </>
  );
}

function SurveyRound({ id, form, me, go, onBack }: { id: number; form: SurveyFormDef; me: Me; go: (to: string) => void; onBack: () => void }) {
  const load = useLoad(`survey:${id}`, () => apiGet<ResponseView>(`/api/surveys/${id}/response`));
  const back = (event: MouseEvent) => {
    event.preventDefault();
    onBack();
  };
  return (
    <div className="sv-page">
      <a className="back-link" href="/surveys" onClick={back}>
        <ArrowLeft size={16} aria-hidden="true" /> All surveys
      </a>
      {load.error && (
        <>
          <PageHead title="University and Programme Survey" />
          <LoadError error={load.error} onRetry={load.reload} />
        </>
      )}
      {!load.data && !load.error && <SkeletonLines lines={6} />}
      {load.data && load.data.response?.status === 'submitted' && <Receipt view={load.data} response={load.data.response} go={go} />}
      {load.data && load.data.response?.status !== 'submitted' && <Wizard view={load.data} form={form} csrf={me.csrf_token} go={go} />}
    </div>
  );
}

function Receipt({ view, response, go }: { view: ResponseView; response: SurveyResponse; go: (to: string) => void }) {
  return (
    <section className="panel panel-pad sv-receipt" role="status" aria-labelledby="sv-receipt-h">
      <CheckCircle2 size={40} aria-hidden="true" className="sv-receipt-icon" />
      <div className="stack" style={{ gap: 6 }}>
        <h1 id="sv-receipt-h">Thank you. Your answers are in.</h1>
        <p className="muted">
          {view.cycle.title}, sent {response.submitted_at ? dateLabel(response.submitted_at) : ''}. Receipt number{' '}
          <strong className="num">SV-{view.cycle.id}-{response.id}</strong>.
        </p>
        <p className="small muted">CPDC reads the results by faculty and programme. Your name is never shown with your comments.</p>
        <div className="row">
          <button type="button" className="btn btn-small" onClick={() => go('/home')}>
            Back to home
          </button>
        </div>
      </div>
    </section>
  );
}

function Wizard({ view, form, csrf, go }: { view: ResponseView; form: SurveyFormDef; csrf: string; go: (to: string) => void }) {
  const saved = view.response;
  const [draft, setDraft] = useState<Draft>({
    programme: saved?.programme ?? view.defaults.programme ?? '',
    semester: saved?.semester ?? null,
    answers: saved?.answers ?? {},
    comments: saved?.comments ?? {},
  });
  const [step, setStep] = useState(0);
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState<'draft' | 'submit' | null>(null);
  const [savedAt, setSavedAt] = useState<string | null>(saved?.updated_at ?? null);
  const [problem, setProblem] = useState<string | null>(null);
  const [showMissing, setShowMissing] = useState(false);
  const [done, setDone] = useState<SurveyResponse | null>(null);
  const top = useRef<HTMLDivElement>(null);
  const steps = form.sections.length + 2; // about you, the sections, check and send
  const total = itemKeys(form).length;
  const answered = itemKeys(form).filter((k) => draft.answers[k] !== undefined).length;

  const change = (next: Draft) => {
    setDraft(next);
    setDirty(true);
    setProblem(null);
  };
  const setAnswer = (key: string, value: Answer) => change({ ...draft, answers: { ...draft.answers, [key]: value } });
  const setComment = (key: string, value: string) => change({ ...draft, comments: { ...draft.comments, [key]: value } });

  async function saveDraft(): Promise<boolean> {
    setSaving('draft');
    try {
      const r = await apiWrite<SurveyResponse>('PUT', `/api/surveys/${view.cycle.id}/response`, toBody(draft, false), csrf);
      setSavedAt(r.updated_at);
      setDirty(false);
      return true;
    } catch (error) {
      setProblem(errorText(error));
      return false;
    } finally {
      setSaving(null);
    }
  }
  const goTo = async (next: number) => {
    if (dirty && !(await saveDraft())) return;
    setStep(next);
    top.current?.scrollIntoView({ block: 'start' });
  };
  const submit = async () => {
    const stop = submitProblem(form, draft);
    if (stop) {
      setShowMissing(true);
      setProblem(stop.text);
      if (dirty) await saveDraft();
      setStep(stop.step);
      top.current?.scrollIntoView({ block: 'start' });
      return;
    }
    setSaving('submit');
    try {
      setDone(await apiWrite<SurveyResponse>('PUT', `/api/surveys/${view.cycle.id}/response`, toBody(draft, true), csrf));
    } catch (error) {
      setProblem(errorText(error));
    } finally {
      setSaving(null);
    }
  };

  if (done) return <Receipt view={view} response={done} go={go} />;
  const section = step >= 1 && step <= form.sections.length ? form.sections[step - 1] : null;
  return (
    <div className="stack" ref={top}>
      <PageHead title={view.cycle.title}>Academic year {view.cycle.academic_year}</PageHead>
      <div className="panel panel-pad sv-progress">
        <div className="sv-progress-text">
          <span>
            Step <span className="num">{step + 1}</span> of <span className="num">{steps}</span>
            {section && `: ${section.title}`}
          </span>
          <span className="small muted num">
            {answered} of {total} answered
          </span>
        </div>
        <div className="sv-bar" role="progressbar" aria-label="Items answered" aria-valuemin={0} aria-valuemax={total} aria-valuenow={answered}>
          <span style={{ width: `${(answered / total) * 100}%` }} />
        </div>
        <ol className="sv-steps" aria-label="Sections">
          {['About you', ...form.sections.map((s) => s.title), 'Check and send'].map((label, i) => {
            const s = i >= 1 && i <= form.sections.length ? form.sections[i - 1] : null;
            const left = s ? missingIn(s, draft.answers).length : 0;
            return (
              <li key={label}>
                <button
                  type="button"
                  className="sv-step"
                  aria-current={i === step ? 'step' : undefined}
                  data-done={s ? left === 0 : undefined}
                  data-missing={showMissing && s && left > 0 ? true : undefined}
                  onClick={() => void goTo(i)}
                  disabled={saving !== null}
                  title={label}
                >
                  <span className="num">{i + 1}</span>
                  <span className="visually-hidden">{label}{s && left > 0 ? `, ${left} not answered` : ''}</span>
                </button>
              </li>
            );
          })}
        </ol>
      </div>

      {step === 0 && (
        <section className="panel panel-pad stack" aria-labelledby="sv-about-h">
          <h2 id="sv-about-h">About you</h2>
          <div className="sv-about">
            <div className="field">
              <span className="label">Faculty</span>
              <p className="sv-fixed">{view.defaults.faculty ?? 'Not on record'}</p>
              <span className="hint">From your QIU account.</span>
            </div>
            <div className="field">
              <label htmlFor="sv-prog">Programme</label>
              <input id="sv-prog" className="input" maxLength={200} value={draft.programme} aria-invalid={showMissing && !draft.programme.trim() ? true : undefined} onChange={(e) => change({ ...draft, programme: e.target.value })} placeholder="Bachelor of ..." />
            </div>
            <div className="field">
              <label htmlFor="sv-sem">Semester</label>
              <select id="sv-sem" className="select" value={draft.semester ?? ''} aria-invalid={showMissing && draft.semester === null ? true : undefined} onChange={(e) => change({ ...draft, semester: e.target.value === '' ? null : Number(e.target.value) })}>
                <option value="">Pick one</option>
                {Array.from({ length: 12 }, (_, i) => (
                  <option key={i + 1} value={i + 1}>Semester {i + 1}</option>
                ))}
              </select>
            </div>
          </div>
        </section>
      )}

      {section && (
        <SectionStep section={section} form={form} draft={draft} showMissing={showMissing} onAnswer={setAnswer} onComment={setComment} />
      )}

      {step === steps - 1 && (
        <section className="panel panel-pad stack" aria-labelledby="sv-check-h">
          <h2 id="sv-check-h">Check and send</h2>
          <ul className="sv-check">
            {form.sections.map((s, i) => {
              const left = missingIn(s, draft.answers).length;
              return (
                <li key={s.key}>
                  <span>{s.title}</span>
                  {left === 0 ? (
                    <span className="badge badge-ok">Complete</span>
                  ) : (
                    <button type="button" className="btn btn-small btn-quiet" onClick={() => void goTo(i + 1)}>
                      <span className="num">{left}</span> to answer
                    </button>
                  )}
                </li>
              );
            })}
          </ul>
          <p className="small muted">Once sent, your answers cannot be changed.</p>
        </section>
      )}

      {problem && <Notice tone="error">{problem}</Notice>}
      <div className="sv-nav">
        <button type="button" className="btn" onClick={() => void goTo(step - 1)} disabled={step === 0 || saving !== null}>
          <ArrowLeft size={16} aria-hidden="true" /> Back
        </button>
        <button type="button" className="btn btn-quiet" onClick={() => void saveDraft()} disabled={saving !== null || !dirty}>
          {saving === 'draft' ? <Spinner /> : <Save size={16} aria-hidden="true" />} Save draft
        </button>
        <span className="small muted sv-saved" aria-live="polite">
          {dirty ? 'Unsaved changes' : savedAt ? 'Draft saved' : ''}
        </span>
        {step < steps - 1 ? (
          <button type="button" className="btn btn-primary" onClick={() => void goTo(step + 1)} disabled={saving !== null}>
            Next <ArrowRight size={16} aria-hidden="true" />
          </button>
        ) : (
          <button type="button" className="btn btn-primary" onClick={() => void submit()} disabled={saving !== null}>
            {saving === 'submit' && <Spinner />} Send my answers
          </button>
        )}
      </div>
    </div>
  );
}

function SectionStep({
  section,
  form,
  draft,
  showMissing,
  onAnswer,
  onComment,
}: {
  section: Section;
  form: SurveyFormDef;
  draft: Draft;
  showMissing: boolean;
  onAnswer: (key: string, value: Answer) => void;
  onComment: (key: string, value: string) => void;
}) {
  const labels = form.scales[section.scale];
  return (
    <section className="panel panel-pad stack" aria-labelledby={`sv-${section.key}-h`}>
      <div>
        <h2 id={`sv-${section.key}-h`}>{section.title}</h2>
        <p className="small muted">{section.intro}</p>
      </div>
      <div className="sv-items">
        {section.items.map((item, i) => {
          const value = draft.answers[item.key];
          const missing = showMissing && value === undefined;
          return (
            <fieldset key={item.key} className="sv-item" data-missing={missing || undefined}>
              <legend>
                <span className="muted num">{i + 1}.</span> {item.text}
                {missing && <span className="sv-missing-tag">Not answered</span>}
              </legend>
              <div className={`sv-scale${section.na ? ' sv-scale-na' : ''}`}>
                {labels.map((label, k) => (
                  <label key={label} className="sv-choice">
                    <input type="radio" name={item.key} checked={value === k + 1} onChange={() => onAnswer(item.key, k + 1)} />
                    <span className="num">{k + 1}</span>
                    <span className="sv-choice-label">{label}</span>
                  </label>
                ))}
                {section.na && (
                  <label className="sv-choice sv-choice-na">
                    <input type="radio" name={item.key} checked={value === 'na'} onChange={() => onAnswer(item.key, 'na')} />
                    <span>N/A</span>
                    <span className="sv-choice-label">Not used</span>
                  </label>
                )}
              </div>
            </fieldset>
          );
        })}
      </div>
      {section.extra && (
        <div className="field">
          <label htmlFor={`sv-x-${section.extra.key}`}>{section.extra.label}</label>
          <textarea id={`sv-x-${section.extra.key}`} className="textarea" rows={2} maxLength={form.comment_max} value={draft.comments[section.extra.key] ?? ''} onChange={(e) => onComment(section.extra?.key ?? '', e.target.value)} />
        </div>
      )}
      <div className="field">
        <label htmlFor={`sv-c-${section.key}`}>Comments on {section.title.toLowerCase()} (optional)</label>
        <textarea id={`sv-c-${section.key}`} className="textarea" rows={3} maxLength={form.comment_max} value={draft.comments[section.key] ?? ''} onChange={(e) => onComment(section.key, e.target.value)} />
      </div>
    </section>
  );
}
