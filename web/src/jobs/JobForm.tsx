// CPDC: post or edit a job or internship, with the skill levels it asks for (used by the skills gap view).
import { useState, type FormEvent } from 'react';
import { ApiFailure, apiGet, apiUpload, apiWrite, useLoad, type Paged } from '../api';
import { PdfPicker } from './PdfPicker';
import type { Me } from '../auth';
import type { SkillArea } from '../skills/types';
import { LoadError, Notice, SkeletonLines, Spinner, errorText } from '../ui/parts';
import {
  JOB_STATUS_LABEL,
  TYPE_LABEL,
  todayInMalaysia,
  type Employer,
  type JobForEdit,
  type JobStatus,
  type JobType,
  type PayFrequency,
} from './types';

interface Draft {
  employer_id: string;
  title: string;
  job_type: JobType;
  location: string;
  closing_date: string;
  description: string;
  status: JobStatus;
  skills: Record<number, number>; // area id => level 1-5; absent = not asked for
  extras: Extras; // slice A
}

// --- slice A: job details and brochure (begin) ---
/** Same list as JobDetails::STATES on the server, which refuses anything else. */
export const STATES = ['Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang', 'Perak', 'Perlis',
  'Pulau Pinang', 'Sabah', 'Sarawak', 'Selangor', 'Terengganu', 'Kuala Lumpur', 'Labuan', 'Putrajaya', 'Outside Malaysia'];
const EXTRA_TEXT = ['specializations', 'vacancy_count', 'duration_months', 'salary_min', 'salary_max', 'min_qualification',
  'work_experience', 'other_requirements', 'benefits', 'country', 'state', 'contact_email'] as const;
type Extras = Record<(typeof EXTRA_TEXT)[number], string> & { pay_frequency: PayFrequency | '' };
const NO_EXTRAS = { ...Object.fromEntries(EXTRA_TEXT.map((k) => [k, ''])), pay_frequency: '' } as Extras;

function extrasFrom(job: JobForEdit): Extras {
  const out: Extras = { ...NO_EXTRAS, pay_frequency: job.pay_frequency ?? '' };
  for (const k of EXTRA_TEXT) {
    const v = job[k];
    out[k] = v === null ? '' : Array.isArray(v) ? v.join(', ') : String(v);
  }
  return out;
}

/** The detail fields as job.save.json wants them: numbers or null, trimmed text. */
function extrasBody(x: Extras): Record<string, unknown> {
  const num = (v: string) => (v.trim() === '' ? null : Number(v));
  return {
    specializations: x.specializations.trim(),
    vacancy_count: num(x.vacancy_count),
    duration_months: num(x.duration_months),
    salary_min: num(x.salary_min),
    salary_max: num(x.salary_max),
    pay_frequency: x.pay_frequency === '' ? null : x.pay_frequency,
    min_qualification: x.min_qualification.trim(),
    work_experience: x.work_experience.trim(),
    other_requirements: x.other_requirements.trim(),
    benefits: x.benefits.trim(),
    country: x.country.trim(),
    state: x.state.trim(),
    contact_email: x.contact_email.trim(),
  };
}
// --- slice A (end) ---

const EMPTY: Draft = {
  employer_id: '',
  title: '',
  job_type: 'internship',
  location: '',
  closing_date: '',
  description: '',
  status: 'open',
  skills: {},
  extras: { ...NO_EXTRAS, country: 'Malaysia' },
};

function fromJob(job: JobForEdit): Draft {
  return {
    extras: extrasFrom(job),
    employer_id: String(job.employer_id),
    title: job.title,
    job_type: job.job_type,
    location: job.location,
    closing_date: job.closing_date,
    description: job.description,
    status: job.status,
    skills: Object.fromEntries(job.skills.map((s) => [s.area_id, s.level])),
  };
}

export function JobForm({
  id,
  me,
  onCancel,
  onSaved,
  onNeedEmployer,
}: {
  id: number | null;
  me: Me;
  onCancel: () => void;
  onSaved: (title: string) => void;
  onNeedEmployer: () => void;
}) {
  const data = useLoad(`job-form:${id ?? 'new'}`, async () => {
    const [employers, skills, job] = await Promise.all([
      apiGet<Paged<Employer>>('/api/employers', { per_page: 200 }),
      apiGet<{ areas: SkillArea[] }>('/api/skills/areas'),
      id === null ? Promise.resolve(null) : apiGet<JobForEdit>(`/api/jobs/manage/${id}`),
    ]);
    return { employers: employers.items, areas: skills.areas, job };
  });

  if (data.error) return <section className="panel panel-pad"><LoadError error={data.error} onRetry={data.reload} /></section>;
  if (!data.data) return <section className="panel panel-pad"><SkeletonLines lines={8} /></section>;
  const { employers, areas, job } = data.data;
  const choosable = employers.filter((e) => e.active || e.id === job?.employer_id);
  if (choosable.length === 0) {
    return (
      <section className="panel panel-pad stack">
        <Notice tone="info">Add the employer to the directory first, then post its job.</Notice>
        <div className="row">
          <button type="button" className="btn btn-primary" onClick={onNeedEmployer}>Open the employer directory</button>
          <button type="button" className="btn btn-quiet" onClick={onCancel}>Back</button>
        </div>
      </section>
    );
  }
  return (
    <Editor
      key={id ?? 'new'}
      id={id}
      me={me}
      start={job ? fromJob(job) : EMPTY}
      brochureName={job?.has_brochure ? job.attachment_name ?? 'Brochure.pdf' : null}
      employers={choosable}
      areas={areas}
      onCancel={onCancel}
      onSaved={onSaved}
    />
  );
}

function Editor({
  id,
  me,
  start,
  brochureName,
  employers,
  areas,
  onCancel,
  onSaved,
}: {
  id: number | null;
  me: Me;
  start: Draft;
  brochureName: string | null;
  employers: Employer[];
  areas: SkillArea[];
  onCancel: () => void;
  onSaved: (title: string) => void;
}) {
  const [draft, setDraft] = useState<Draft>(start);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<{ field: string | null; text: string } | null>(null);
  const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((d) => ({ ...d, [key]: value }));
  const setExtra = <K extends keyof Extras>(key: K, value: Extras[K]) =>
    setDraft((d) => ({ ...d, extras: { ...d.extras, [key]: value } }));
  const [brochure, setBrochure] = useState<File | null>(null);
  const [dropBrochure, setDropBrochure] = useState(false);
  const setLevel = (area: number, level: number) =>
    setDraft((d) => {
      const skills = { ...d.skills };
      if (level === 0) delete skills[area];
      else skills[area] = level;
      return { ...d, skills };
    });

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    const body = {
      employer_id: Number(draft.employer_id),
      title: draft.title.trim(),
      job_type: draft.job_type,
      location: draft.location.trim(),
      closing_date: draft.closing_date,
      description: draft.description.trim(),
      status: draft.status,
      skills: Object.entries(draft.skills).map(([area, level]) => ({ area_id: Number(area), level })),
      ...extrasBody(draft.extras),
    };
    try {
      const created = await apiWrite<{ id: number } | undefined>(
        id === null ? 'POST' : 'PUT',
        id === null ? '/api/jobs/manage' : `/api/jobs/manage/${id}`,
        body,
        me.csrf_token,
      );
      const jobId = id ?? created?.id;
      // The brochure goes after the job is saved: the job needs an id first.
      if (jobId && brochure) await apiUpload(`/api/jobs/manage/${jobId}/brochure`, 'file', brochure, me.csrf_token);
      else if (jobId && dropBrochure) await apiWrite('DELETE', `/api/jobs/manage/${jobId}/brochure`, undefined, me.csrf_token);
      onSaved(body.title);
    } catch (failure) {
      setError({ field: failure instanceof ApiFailure ? failure.field : null, text: errorText(failure) });
    } finally {
      setBusy(false);
    }
  }

  const invalid = (field: string) => (error?.field?.startsWith(field) ? true : undefined);
  return (
    <form className="panel panel-pad stack" onSubmit={submit} aria-labelledby="jf-h">
      <h2 id="jf-h">{id === null ? 'Post a job' : 'Edit job'}</h2>
      <div className="grid-2">
        <div className="field">
          <label htmlFor="jf-employer">Employer</label>
          <select id="jf-employer" className="select" required value={draft.employer_id} aria-invalid={invalid('employer_id')} onChange={(e) => set('employer_id', e.target.value)}>
            <option value="" disabled>Choose an employer</option>
            {employers.map((e) => <option key={e.id} value={e.id}>{e.name}</option>)}
          </select>
        </div>
        <div className="field">
          <label htmlFor="jf-title">Job title</label>
          <input id="jf-title" className="input" required maxLength={200} value={draft.title} aria-invalid={invalid('title')} onChange={(e) => set('title', e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="jf-type">Type</label>
          <select id="jf-type" className="select" value={draft.job_type} onChange={(e) => set('job_type', e.target.value as JobType)}>
            {Object.entries(TYPE_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </div>
        <div className="field">
          <label htmlFor="jf-location">City or Remote</label>
          <input id="jf-location" className="input" required maxLength={200} placeholder="Ipoh, Kuala Lumpur or Remote" value={draft.location} aria-invalid={invalid('location')} onChange={(e) => set('location', e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="jf-closing">Closing date</label>
          <input id="jf-closing" className="input" type="date" required min={id === null ? todayInMalaysia() : undefined} value={draft.closing_date} aria-invalid={invalid('closing_date')} onChange={(e) => set('closing_date', e.target.value)} />
          <span className="hint">Applications are taken through this day (Malaysia time).</span>
        </div>
        <div className="field">
          <label htmlFor="jf-status">Status</label>
          <select id="jf-status" className="select" value={draft.status} onChange={(e) => set('status', e.target.value as JobStatus)}>
            {Object.entries(JOB_STATUS_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
          <span className="hint">Only open jobs are shown to students and graduates.</span>
        </div>
      </div>
      <div className="field">
        <label htmlFor="jf-desc">Description</label>
        <textarea id="jf-desc" className="textarea" required maxLength={10000} rows={7} placeholder="What the role does, who it suits, how to prepare." value={draft.description} aria-invalid={invalid('description')} onChange={(e) => set('description', e.target.value)} />
      </div>
      {/* slice A: job details and brochure */}
      <fieldset className="levels jf-details">
        <legend>Details (optional)</legend>
        <div className="jf-grid">
          <Text id="jf-spec" label="Specializations" value={draft.extras.specializations} onChange={(v) => setExtra('specializations', v)} hint="Fields of study it suits, separated by commas." invalid={invalid('specializations')} />
          <Text id="jf-vac" label="Vacancies" type="number" value={draft.extras.vacancy_count} onChange={(v) => setExtra('vacancy_count', v)} invalid={invalid('vacancy_count')} />
          <Text id="jf-dur" label="Duration (months)" type="number" value={draft.extras.duration_months} onChange={(v) => setExtra('duration_months', v)} invalid={invalid('duration_months')} />
          <Text id="jf-smin" label="Salary from (RM)" type="number" value={draft.extras.salary_min} onChange={(v) => setExtra('salary_min', v)} invalid={invalid('salary_min')} />
          <Text id="jf-smax" label="Salary to (RM)" type="number" value={draft.extras.salary_max} onChange={(v) => setExtra('salary_max', v)} invalid={invalid('salary_max')} />
          <div className="field">
            <label htmlFor="jf-pay">Paid</label>
            <select id="jf-pay" className="select" value={draft.extras.pay_frequency} aria-invalid={invalid('pay_frequency')} onChange={(e) => setExtra('pay_frequency', e.target.value as PayFrequency | '')}>
              <option value="">Not stated</option>
              <option value="monthly">Monthly</option>
              <option value="hourly">Hourly</option>
              <option value="lump_sum">Lump sum</option>
              <option value="unpaid">Unpaid</option>
              <option value="negotiable">Negotiable</option>
            </select>
          </div>
          <Text id="jf-qual" label="Minimum qualification" value={draft.extras.min_qualification} onChange={(v) => setExtra('min_qualification', v)} />
          <Text id="jf-exp" label="Work experience" value={draft.extras.work_experience} onChange={(v) => setExtra('work_experience', v)} />
          <div className="field">
            <label htmlFor="jf-state">State</label>
            <select id="jf-state" className="select" value={draft.extras.state} aria-invalid={invalid('state')} onChange={(e) => setExtra('state', e.target.value)}>
              <option value="">Not stated</option>
              {/* A state saved before this list existed still shows; saving then asks for one from the list. */}
              {draft.extras.state !== '' && !STATES.includes(draft.extras.state) && <option value={draft.extras.state}>{draft.extras.state}</option>}
              {STATES.map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
            <span className="hint">Students filter by state.</span>
          </div>
          <Text id="jf-country" label="Country" value={draft.extras.country} onChange={(v) => setExtra('country', v)} />
          <Text id="jf-contact" label="Employer contact email" type="email" value={draft.extras.contact_email} onChange={(v) => setExtra('contact_email', v)} hint="Shown as a Contact employer link." invalid={invalid('contact_email')} />
        </div>
        <div className="grid-2">
          <div className="field">
            <label htmlFor="jf-req">Other requirements</label>
            <textarea id="jf-req" className="textarea" rows={3} maxLength={5000} value={draft.extras.other_requirements} onChange={(e) => setExtra('other_requirements', e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="jf-ben">Benefits</label>
            <textarea id="jf-ben" className="textarea" rows={3} maxLength={5000} value={draft.extras.benefits} onChange={(e) => setExtra('benefits', e.target.value)} />
          </div>
        </div>
        {brochureName && !brochure && !dropBrochure ? (
          <div className="row">
            <span className="small">Brochure: {brochureName}</span>
            <button type="button" className="btn btn-quiet btn-small" onClick={() => setDropBrochure(true)}>Remove brochure</button>
          </div>
        ) : (
          <PdfPicker label="Brochure (optional)" hint="PDF, up to 5 MB. Students can download it from the job." file={brochure} onChange={setBrochure} />
        )}
        {dropBrochure && !brochure && <span className="small muted">The brochure is removed when you save.</span>}
      </fieldset>
      <fieldset className="levels" aria-describedby="jf-skills-h">
        <legend>Skills asked for</legend>
        <p className="hint" id="jf-skills-h">Optional. Students compare their own rating against these levels.</p>
        {areas.map((area) => (
          <div className="level-row" key={area.id}>
            <label htmlFor={`jf-skill-${area.id}`}>{area.name}</label>
            <select id={`jf-skill-${area.id}`} className="select" value={draft.skills[area.id] ?? 0} onChange={(e) => setLevel(area.id, Number(e.target.value))}>
              <option value={0}>Not needed</option>
              {[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>Level {n}</option>)}
            </select>
          </div>
        ))}
      </fieldset>
      {error && <Notice tone="error">{error.text}</Notice>}
      <div className="row">
        <button type="submit" className="btn btn-primary" disabled={busy}>{busy && <Spinner />}{id === null ? 'Post job' : 'Save changes'}</button>
        <button type="button" className="btn btn-quiet" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  );
}

/** One optional input of the details group (slice A). */
function Text({
  id,
  label,
  value,
  onChange,
  type = 'text',
  hint,
  invalid,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  type?: 'text' | 'number' | 'email';
  hint?: string;
  invalid?: true;
}) {
  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      <input
        id={id}
        className={`input${type === 'number' ? ' num' : ''}`}
        type={type}
        min={type === 'number' ? 0 : undefined}
        step={type === 'number' ? 'any' : undefined}
        value={value}
        aria-invalid={invalid}
        aria-describedby={hint ? `${id}-h` : undefined}
        onChange={(e) => onChange(e.target.value)}
      />
      {hint && <span className="hint" id={`${id}-h`}>{hint}</span>}
    </div>
  );
}
