// CV builder: the career profile plus what the CV adds, saved on the server, printed or saved as PDF by the browser.
// Sections fold away; every field is checked as the person types and the marked fields block saving.
import { BadgeCheck, ChevronDown, Plus, Printer, ShieldCheck, Trash2, X } from 'lucide-react';
import { useEffect, useId, useState, type FormEvent, type ReactNode } from 'react';
import { apiGet, apiWrite, useLoad } from '../api';
import type { Me } from '../auth';
import { monthLabel } from '../profile/types';
import { PageHead } from '../shell/Shell';
import { LoadError, Notice, SkeletonLines, Spinner, errorText } from '../ui/parts';
import { CvPreview } from './CvPreview';
import {
  AWARD_LABEL,
  LIMITS,
  SECTION_IDEAS,
  blankItem,
  cvErrors,
  cvProblem,
  toCvBody,
  withDefaults,
  type AwardKind,
  type CvContent,
  type CvItem,
  type CvResponse,
} from './types';
import './cv.css';

export function CvPage({ me, go }: { me: Me; go: (to: string) => void }) {
  const load = useLoad('cv', () => apiGet<CvResponse>('/api/cv'));
  // Print styles show only the sheet while this page is open.
  useEffect(() => {
    document.body.classList.add('cv-page');
    return () => document.body.classList.remove('cv-page');
  }, []);

  if (load.error && !load.data) {
    return (
      <>
        <PageHead title="CV builder" />
        <LoadError error={load.error} onRetry={load.reload} />
      </>
    );
  }
  if (!load.data) return <SkeletonLines lines={6} />;
  return <CvEditor key={load.data.saved_at ?? 'new'} data={load.data} csrf={me.csrf_token} go={go} />;
}

/** A section of the editor that folds away; its heading is the toggle. */
function Fold({ title, note, open: startOpen = false, children }: { title: string; note?: string; open?: boolean; children: ReactNode }) {
  const [open, setOpen] = useState(startOpen);
  const id = useId();
  return (
    <section className="cv-fold" aria-labelledby={`${id}-h`}>
      <h2 id={`${id}-h`} className="cv-fold-h">
        <button type="button" aria-expanded={open} aria-controls={`${id}-b`} onClick={() => setOpen(!open)}>
          <span>{title}</span>
          {note && <span className="small muted cv-fold-note">{note}</span>}
          <ChevronDown size={17} aria-hidden="true" className="cv-fold-chevron" />
        </button>
      </h2>
      <div id={`${id}-b`} className="stack cv-fold-body" hidden={!open}>
        {children}
      </div>
    </section>
  );
}

/** A labelled input with its inline error. */
function Field({ id, label, error, hint, children }: { id: string; label: string; error?: string; hint?: string; children: ReactNode }) {
  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      {children}
      {error ? (
        <span className="error" id={`${id}-err`}>
          {error}
        </span>
      ) : (
        hint && <span className="hint">{hint}</span>
      )}
    </div>
  );
}

function CvEditor({ data, csrf, go }: { data: CvResponse; csrf: string; go: (to: string) => void }) {
  const [cv, setCv] = useState<CvContent>(withDefaults(data.cv));
  const [savedAt, setSavedAt] = useState(data.saved_at);
  const [consentAt, setConsentAt] = useState(data.employer_sharing_consent_at);
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  const profile = data.profile;
  const errors = cvErrors(cv);
  const invalid = (id: string) => (errors[id] ? { 'aria-invalid': true as const, 'aria-describedby': `${id}-err` } : {});

  const change = (next: CvContent) => {
    setCv(next);
    setDirty(true);
    setMessage(null);
  };
  const setContact = (name: keyof CvContent['contact'], value: string) => change({ ...cv, contact: { ...cv.contact, [name]: value } });
  const toggle = (list: 'hidden_experience' | 'hidden_education', id: number, shown: boolean) =>
    change({ ...cv, [list]: shown ? cv[list].filter((x) => x !== id) : [...cv[list], id] });
  const setSection = (index: number, patch: Partial<CvContent['sections'][number]>) =>
    change({ ...cv, sections: cv.sections.map((s, i) => (i === index ? { ...s, ...patch } : s)) });
  const setItem = (si: number, ii: number, patch: Partial<CvItem>) =>
    setSection(si, { items: cv.sections[si].items.map((item, j) => (j === ii ? { ...item, ...patch } : item)) });
  const addSection = (title: string) => change({ ...cv, sections: [...cv.sections, { title, items: [{ ...blankItem }] }] });
  const setAt = <K extends 'awards' | 'cocurricular' | 'references' | 'courses_taken'>(key: K, index: number, value: CvContent[K][number]) =>
    change({ ...cv, [key]: cv[key].map((x, i) => (i === index ? value : x)) });
  const removeAt = (key: 'awards' | 'cocurricular' | 'references' | 'courses_taken', index: number) =>
    change({ ...cv, [key]: cv[key].filter((_, i) => i !== index) });

  async function save(event: FormEvent) {
    event.preventDefault();
    const problem = cvProblem(cv);
    if (problem) {
      setMessage({ tone: 'error', text: problem });
      return;
    }
    setSaving(true);
    try {
      const saved = await apiWrite<CvResponse>('PUT', '/api/cv', toCvBody(cv), csrf);
      setCv(withDefaults(saved.cv));
      setSavedAt(saved.saved_at);
      setConsentAt(saved.employer_sharing_consent_at);
      setDirty(false);
      setMessage({ tone: 'ok', text: 'Your CV is saved.' });
    } catch (error) {
      setMessage({ tone: 'error', text: errorText(error) });
    } finally {
      setSaving(false);
    }
  }

  const unused = SECTION_IDEAS.filter((idea) => !cv.sections.some((s) => s.title.toLowerCase() === idea.toLowerCase()));
  const notAdded = data.verified_learning.filter((l) => !cv.awards.some((a) => a.learning_id === l.id));
  const errorCount = Object.keys(errors).length;
  return (
    <>
      <PageHead
        title="CV builder"
        actions={
          <button type="button" className="btn" onClick={() => window.print()}>
            <Printer size={17} aria-hidden="true" /> Print or save as PDF
          </button>
        }
      >
        Your jobs, education and skills come from your career profile. Add the rest here; it is saved to your account.
      </PageHead>
      <div className="cv-layout">
        <form className="panel panel-pad cv-editor stack" onSubmit={save} noValidate>
          <Fold title="Top of the CV" open>
            <Field id="cv-headline" label="Headline">
              <input id="cv-headline" className="input" maxLength={300} value={cv.headline} onChange={(e) => change({ ...cv, headline: e.target.value })} placeholder="Final-year Data Science student" />
            </Field>
            <div className="cv-grid">
              <Field id="cv-email" label="Email" error={errors['cv-email']}>
                <input id="cv-email" type="email" className="input" maxLength={254} value={cv.contact.email} {...invalid('cv-email')} onChange={(e) => setContact('email', e.target.value)} />
              </Field>
              <Field id="cv-phone" label="Phone" error={errors['cv-phone']}>
                <input id="cv-phone" type="tel" className="input" maxLength={40} value={cv.contact.phone} {...invalid('cv-phone')} onChange={(e) => setContact('phone', e.target.value)} placeholder="+60 12-345 6789" />
              </Field>
              <Field id="cv-location" label="Location">
                <input id="cv-location" className="input" maxLength={200} value={cv.contact.location} onChange={(e) => setContact('location', e.target.value)} placeholder="Ipoh, Perak" />
              </Field>
              <Field id="cv-site" label="Website or portfolio" error={errors['cv-site']}>
                <input id="cv-site" type="url" className="input" maxLength={300} value={cv.contact.website} {...invalid('cv-site')} onChange={(e) => setContact('website', e.target.value)} placeholder="https://..." />
              </Field>
            </div>
            <Field id="cv-summary" label="Profile summary" hint="Three or four lines: what you do, what you are good at, what you look for.">
              <textarea id="cv-summary" className="textarea" rows={4} maxLength={3000} value={cv.summary} onChange={(e) => change({ ...cv, summary: e.target.value })} />
            </Field>
          </Fold>

          <Fold title="Studies at QIU" note={cv.courses_taken.length ? `${cv.courses_taken.length} courses` : undefined}>
            <div className="cv-grid">
              <Field id="cv-cgpa" label="CGPA" error={errors['cv-cgpa']}>
                <input id="cv-cgpa" className="input num" inputMode="decimal" maxLength={4} value={cv.academic.cgpa} {...invalid('cv-cgpa')} onChange={(e) => change({ ...cv, academic: { ...cv.academic, cgpa: e.target.value.trim() } })} placeholder="3.45" />
              </Field>
              <Field id="cv-intake" label="Intake">
                <input id="cv-intake" className="input" maxLength={60} value={cv.academic.intake_period} onChange={(e) => change({ ...cv, academic: { ...cv.academic, intake_period: e.target.value } })} placeholder="September 2024" />
              </Field>
            </div>
            <label className="check">
              <input type="checkbox" checked={cv.academic.show_cgpa} disabled={!cv.academic.cgpa} onChange={(e) => change({ ...cv, academic: { ...cv.academic, show_cgpa: e.target.checked } })} />
              <span>Show my CGPA on the CV</span>
            </label>
            <div className="stack cv-list">
              <span className="label small">Relevant courses taken ({cv.courses_taken.length} of {LIMITS.courses})</span>
              {cv.courses_taken.map((course, i) => (
                <div key={i} className="cv-row">
                  <label htmlFor={`cv-course-${i}`} className="visually-hidden">Course {i + 1}</label>
                  <input id={`cv-course-${i}`} className="input" maxLength={120} value={course} onChange={(e) => setAt('courses_taken', i, e.target.value)} placeholder="Data Structures and Algorithms" />
                  <button type="button" className="btn btn-quiet btn-small" aria-label={`Remove course ${i + 1}`} onClick={() => removeAt('courses_taken', i)}>
                    <X size={15} aria-hidden="true" />
                  </button>
                </div>
              ))}
              {cv.courses_taken.length < LIMITS.courses && (
                <button type="button" className="btn btn-small cv-add" onClick={() => change({ ...cv, courses_taken: [...cv.courses_taken, ''] })}>
                  <Plus size={15} aria-hidden="true" /> Add course
                </button>
              )}
            </div>
          </Fold>

          <Fold title="From your career profile" note={`${profile.experience.length} jobs, ${profile.education.length} schools`}>
            <div className="cv-head">
              <span className="small muted">Untick what this CV should leave out.</span>
              <a href="/profile" className="small" onClick={(e) => { e.preventDefault(); go('/profile'); }}>Edit profile</a>
            </div>
            {profile.experience.length === 0 && profile.education.length === 0 && (
              <Notice>Your profile has no jobs or education yet. Add them on My profile, or import your LinkedIn PDF there.</Notice>
            )}
            {profile.experience.map((job) => (
              <label key={job.id} className="check">
                <input type="checkbox" checked={!cv.hidden_experience.includes(job.id)} onChange={(e) => toggle('hidden_experience', job.id, e.target.checked)} />
                <span>
                  {job.title}, {job.company}
                  <span className="small muted"> {[monthLabel(job.start), job.start ? monthLabel(job.end, true) : ''].filter(Boolean).join(' to ')}</span>
                </span>
              </label>
            ))}
            {profile.education.map((s) => (
              <label key={s.id} className="check">
                <input type="checkbox" checked={!cv.hidden_education.includes(s.id)} onChange={(e) => toggle('hidden_education', s.id, e.target.checked)} />
                <span>{[s.school, s.degree].filter(Boolean).join(', ')}</span>
              </label>
            ))}
            <label className="check">
              <input type="checkbox" checked={cv.show_skills} onChange={(e) => change({ ...cv, show_skills: e.target.checked })} />
              <span>Skills ({profile.skills.length})</span>
            </label>
          </Fold>

          <Fold title="Awards and certificates" note={cv.awards.length ? String(cv.awards.length) : undefined}>
            {notAdded.length > 0 && (
              <div className="stack cv-verified">
                <span className="small muted">From your verified learning records:</span>
                <div className="row">
                  {notAdded.map((l) => (
                    <button key={l.id} type="button" className="btn btn-small" disabled={cv.awards.length >= LIMITS.awards} onClick={() => change({ ...cv, awards: [...cv.awards, { kind: 'certificate', title: l.name, issuer: l.provider, period: l.completed_on.slice(0, 4), learning_id: l.id }] })}>
                      <BadgeCheck size={15} aria-hidden="true" /> {l.name}
                    </button>
                  ))}
                </div>
              </div>
            )}
            {cv.awards.map((award, i) => (
              <div key={i} className="cv-section">
                <div className="cv-grid">
                  <Field id={`cv-awk-${i}`} label="Kind">
                    <select id={`cv-awk-${i}`} className="select" value={award.kind} onChange={(e) => setAt('awards', i, { ...award, kind: e.target.value as AwardKind })}>
                      {(Object.keys(AWARD_LABEL) as AwardKind[]).map((k) => (
                        <option key={k} value={k}>{AWARD_LABEL[k]}</option>
                      ))}
                    </select>
                  </Field>
                  <Field id={`cv-awp-${i}`} label="When">
                    <input id={`cv-awp-${i}`} className="input" maxLength={60} value={award.period} onChange={(e) => setAt('awards', i, { ...award, period: e.target.value })} placeholder="Semester 2, 2025" />
                  </Field>
                </div>
                <Field id={`cv-aw-${i}`} label="Title" error={errors[`cv-aw-${i}`]}>
                  <input id={`cv-aw-${i}`} className="input" maxLength={200} value={award.title} {...invalid(`cv-aw-${i}`)} onChange={(e) => setAt('awards', i, { ...award, title: e.target.value })} />
                </Field>
                <Field id={`cv-awi-${i}`} label="Given by">
                  <input id={`cv-awi-${i}`} className="input" maxLength={200} value={award.issuer} onChange={(e) => setAt('awards', i, { ...award, issuer: e.target.value })} />
                </Field>
                <div className="row cv-entry-foot">
                  {award.learning_id && (
                    <span className="badge badge-ok">
                      <BadgeCheck size={13} aria-hidden="true" /> Verified by CPDC
                    </span>
                  )}
                  <button type="button" className="btn btn-quiet btn-small" onClick={() => removeAt('awards', i)}>
                    <X size={15} aria-hidden="true" /> Remove
                  </button>
                </div>
              </div>
            ))}
            {cv.awards.length < LIMITS.awards && (
              <div className="row">
                {(['deans_list', 'award', 'certificate'] as AwardKind[]).map((kind) => (
                  <button key={kind} type="button" className="btn btn-small" onClick={() => change({ ...cv, awards: [...cv.awards, { kind, title: kind === 'deans_list' ? "Dean's List" : '', issuer: kind === 'deans_list' ? 'Quest International University' : '', period: '' }] })}>
                    <Plus size={15} aria-hidden="true" /> {AWARD_LABEL[kind]}
                  </button>
                ))}
              </div>
            )}
          </Fold>

          <Fold title="Co-curricular activities" note={cv.cocurricular.length ? String(cv.cocurricular.length) : undefined}>
            {cv.cocurricular.map((c, i) => (
              <div key={i} className="cv-section">
                <div className="cv-grid">
                  <Field id={`cv-co-${i}`} label="Activity or club" error={errors[`cv-co-${i}`]}>
                    <input id={`cv-co-${i}`} className="input" maxLength={200} value={c.activity} {...invalid(`cv-co-${i}`)} onChange={(e) => setAt('cocurricular', i, { ...c, activity: e.target.value })} />
                  </Field>
                  <Field id={`cv-cop-${i}`} label="When">
                    <input id={`cv-cop-${i}`} className="input" maxLength={60} value={c.period} onChange={(e) => setAt('cocurricular', i, { ...c, period: e.target.value })} placeholder="2024 to 2025" />
                  </Field>
                </div>
                <Field id={`cv-cor-${i}`} label="Your role and what you did">
                  <textarea id={`cv-cor-${i}`} className="textarea" rows={2} maxLength={1000} value={c.role_and_details} onChange={(e) => setAt('cocurricular', i, { ...c, role_and_details: e.target.value })} />
                </Field>
                <button type="button" className="btn btn-quiet btn-small cv-remove" onClick={() => removeAt('cocurricular', i)}>
                  <X size={15} aria-hidden="true" /> Remove
                </button>
              </div>
            ))}
            {cv.cocurricular.length < LIMITS.cocurricular && (
              <button type="button" className="btn btn-small cv-add" onClick={() => change({ ...cv, cocurricular: [...cv.cocurricular, { activity: '', role_and_details: '', period: '' }] })}>
                <Plus size={15} aria-hidden="true" /> Add activity
              </button>
            )}
          </Fold>

          <Fold title="References" note={`${cv.references.length} of ${LIMITS.references}`}>
            <p className="small muted">Ask each person first. Their details print on your CV.</p>
            {cv.references.map((r, i) => (
              <div key={i} className="cv-section">
                <div className="cv-grid">
                  <Field id={`cv-rn-${i}`} label="Name" error={errors[`cv-rn-${i}`]}>
                    <input id={`cv-rn-${i}`} className="input" maxLength={120} value={r.name} {...invalid(`cv-rn-${i}`)} onChange={(e) => setAt('references', i, { ...r, name: e.target.value })} />
                  </Field>
                  <Field id={`cv-rd-${i}`} label="Designation">
                    <input id={`cv-rd-${i}`} className="input" maxLength={120} value={r.designation} onChange={(e) => setAt('references', i, { ...r, designation: e.target.value })} placeholder="Senior Lecturer" />
                  </Field>
                  <Field id={`cv-rc-${i}`} label="Company or organisation">
                    <input id={`cv-rc-${i}`} className="input" maxLength={200} value={r.company} onChange={(e) => setAt('references', i, { ...r, company: e.target.value })} />
                  </Field>
                  <Field id={`cv-rp-${i}`} label="Phone" error={errors[`cv-rp-${i}`]}>
                    <input id={`cv-rp-${i}`} type="tel" className="input" maxLength={40} value={r.phone} {...invalid(`cv-rp-${i}`)} onChange={(e) => setAt('references', i, { ...r, phone: e.target.value })} />
                  </Field>
                </div>
                <Field id={`cv-re-${i}`} label="Email" error={errors[`cv-re-${i}`]}>
                  <input id={`cv-re-${i}`} type="email" className="input" maxLength={254} value={r.email} {...invalid(`cv-re-${i}`)} onChange={(e) => setAt('references', i, { ...r, email: e.target.value })} />
                </Field>
                <button type="button" className="btn btn-quiet btn-small cv-remove" onClick={() => removeAt('references', i)}>
                  <X size={15} aria-hidden="true" /> Remove
                </button>
              </div>
            ))}
            {cv.references.length < LIMITS.references && (
              <button type="button" className="btn btn-small cv-add" onClick={() => change({ ...cv, references: [...cv.references, { name: '', designation: '', company: '', phone: '', email: '' }] })}>
                <Plus size={15} aria-hidden="true" /> Add reference
              </button>
            )}
          </Fold>

          <Fold title="Extra sections" note={cv.sections.length ? String(cv.sections.length) : undefined}>
            {cv.sections.map((section, si) => (
              <div key={si} className="cv-section">
                <div className="cv-head">
                  <div className="cv-grow">
                    <Field id={`cv-s-${si}`} label="Section title" error={errors[`cv-s-${si}`]}>
                      <input id={`cv-s-${si}`} className="input" maxLength={80} value={section.title} {...invalid(`cv-s-${si}`)} onChange={(e) => setSection(si, { title: e.target.value })} />
                    </Field>
                  </div>
                  <button type="button" className="btn btn-quiet btn-small" onClick={() => change({ ...cv, sections: cv.sections.filter((_, i) => i !== si) })} aria-label={`Remove section ${section.title}`}>
                    <Trash2 size={16} aria-hidden="true" />
                  </button>
                </div>
                {section.items.map((item, ii) => (
                  <div key={ii} className="cv-item">
                    <div className="cv-grid">
                      <Field id={`cv-h-${si}-${ii}`} label="Heading" error={errors[`cv-h-${si}-${ii}`]}>
                        <input id={`cv-h-${si}-${ii}`} className="input" maxLength={200} value={item.heading} {...invalid(`cv-h-${si}-${ii}`)} onChange={(e) => setItem(si, ii, { heading: e.target.value })} />
                      </Field>
                      <Field id={`cv-p-${si}-${ii}`} label="When">
                        <input id={`cv-p-${si}-${ii}`} className="input" maxLength={60} value={item.period} onChange={(e) => setItem(si, ii, { period: e.target.value })} placeholder="2026" />
                      </Field>
                    </div>
                    <Field id={`cv-u-${si}-${ii}`} label="Organisation or role">
                      <input id={`cv-u-${si}-${ii}`} className="input" maxLength={200} value={item.sub} onChange={(e) => setItem(si, ii, { sub: e.target.value })} />
                    </Field>
                    <Field id={`cv-d-${si}-${ii}`} label="Details">
                      <textarea id={`cv-d-${si}-${ii}`} className="textarea" rows={2} maxLength={1500} value={item.detail} onChange={(e) => setItem(si, ii, { detail: e.target.value })} />
                    </Field>
                    <button type="button" className="btn btn-quiet btn-small cv-remove" onClick={() => setSection(si, { items: section.items.filter((_, j) => j !== ii) })}>
                      <X size={15} aria-hidden="true" /> Remove entry
                    </button>
                  </div>
                ))}
                {section.items.length < 20 && (
                  <button type="button" className="btn btn-small cv-add" onClick={() => setSection(si, { items: [...section.items, { ...blankItem }] })}>
                    <Plus size={15} aria-hidden="true" /> Add entry
                  </button>
                )}
              </div>
            ))}
            {cv.sections.length < 10 && (
              <div className="row cv-ideas">
                {unused.map((idea) => (
                  <button key={idea} type="button" className="btn btn-small" onClick={() => addSection(idea)}>
                    <Plus size={15} aria-hidden="true" /> {idea}
                  </button>
                ))}
                <button type="button" className="btn btn-quiet btn-small" onClick={() => addSection('')}>
                  <Plus size={15} aria-hidden="true" /> Other section
                </button>
              </div>
            )}
          </Fold>

          <section className="cv-consent" aria-labelledby="cv-consent-h">
            <h2 id="cv-consent-h" className="cv-consent-h">
              <ShieldCheck size={17} aria-hidden="true" /> Sharing with employers
            </h2>
            <label className="check">
              <input type="checkbox" checked={cv.employer_sharing_consent} onChange={(e) => change({ ...cv, employer_sharing_consent: e.target.checked })} />
              <span>
                I agree that CPDC may share this CV with QIU partner employers for jobs and internships (Personal Data
                Protection Act 2010). I can withdraw this any time by unticking it.
              </span>
            </label>
            {consentAt && cv.employer_sharing_consent && !dirty && (
              <span className="small muted">Agreed on {new Date(consentAt).toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Asia/Kuala_Lumpur' })}.</span>
            )}
          </section>

          {message && <Notice tone={message.tone}>{message.text}</Notice>}
          <div className="row cv-save">
            <button type="submit" className="btn btn-primary" disabled={saving || !dirty || errorCount > 0}>
              {saving && <Spinner size={14} />}Save CV
            </button>
            <span className="small muted">
              {errorCount > 0 ? `${errorCount} ${errorCount === 1 ? 'field needs' : 'fields need'} a fix` : dirty ? 'Unsaved changes' : savedAt ? 'All changes saved' : 'Not saved yet'}
            </span>
          </div>
        </form>
        <div className="cv-paper">
          <CvPreview profile={profile} cv={cv} />
        </div>
      </div>
    </>
  );
}
