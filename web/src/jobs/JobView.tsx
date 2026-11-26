// One job for a student or graduate: what it is, who offers it, the skills it asks for, and applying.
import { ArrowLeft, Building2, CheckCircle2, ExternalLink, Gauge, MapPin, Send } from 'lucide-react';
import { useState, type FormEvent, type MouseEvent } from 'react';
import { JobFacts } from './JobFacts';
import { apiGet, apiPostForm, apiWrite, useLoad } from '../api';
import type { Me } from '../auth';
import { LevelPips } from '../skills/LevelPips';
import { LoadError, Notice, SkeletonLines, Spinner, errorText } from '../ui/parts';
import { Closing } from './Closing';
import { PdfPicker } from './PdfPicker';
import { dateLabel } from '../profile/types';
import { APPLICATION_LABEL, TYPE_LABEL, applicationTone, type JobDetail } from './types';

export function JobView({
  id,
  me,
  onBack,
  onApplied,
  onCompare,
}: {
  id: number;
  me: Me;
  onBack: () => void;
  onApplied: () => void;
  onCompare?: () => void;
}) {
  const load = useLoad(`job:${id}`, () => apiGet<JobDetail>(`/api/jobs/${id}`));
  const [fresh, setFresh] = useState<JobDetail | null>(null);
  const job = fresh ?? load.data;

  if (load.error && !job) {
    return (
      <section className="panel panel-pad stack">
        <BackButton onBack={onBack} />
        <LoadError error={load.error} onRetry={load.reload} />
      </section>
    );
  }
  if (!job) return <section className="panel panel-pad"><SkeletonLines lines={6} /></section>;

  return (
    <article className="panel panel-pad job-view" aria-labelledby="job-h">
      <BackButton onBack={onBack} />
      <header className="job-view-head">
        <h2 id="job-h">{job.title}</h2>
        <div className="job-view-employer"><Building2 size={15} aria-hidden="true" /> {job.employer.name}</div>
        <div className="job-item-meta">
          <span className="badge">{TYPE_LABEL[job.job_type]}</span>
          <span><MapPin size={13} aria-hidden="true" /> {job.location}</span>
          <Closing date={job.closing_date} />
        </div>
        {!job.my_application && job.open && (
          <div>
            <a className="btn btn-primary" href="#apply-h" onClick={jumpToApply}>
              <Send size={16} aria-hidden="true" /> Apply
            </a>
          </div>
        )}
      </header>
      <JobFacts job={job} />

      {job.my_application ? (
        <ApplicationState job={job} csrf={me.csrf_token} onChanged={(next) => { setFresh(next); onApplied(); }} />
      ) : job.open ? (
        <ApplyForm job={job} csrf={me.csrf_token} onDone={(next) => { setFresh(next); onApplied(); }} />
      ) : (
        <Notice tone="warn">This job no longer takes applications.</Notice>
      )}

      <section className="job-section" aria-labelledby="job-about">
        <h3 id="job-about">About the role</h3>
        <p className="prose">{job.description}</p>
      </section>

      {job.skills.length > 0 && (
        <section className="job-section" aria-labelledby="job-skills">
          <div className="section-head">
            <h3 id="job-skills">Skills asked for</h3>
            {onCompare && (
              <button type="button" className="btn btn-small" onClick={onCompare}>
                <Gauge size={15} aria-hidden="true" /> Compare with my skills
              </button>
            )}
          </div>
          <ul className="need-list">
            {job.skills.map((s) => (
              <li key={s.area_id}>
                <span>{s.name}</span>
                <LevelPips level={s.level} tone="need" />
              </li>
            ))}
          </ul>
        </section>
      )}

      <section className="job-section" aria-labelledby="job-employer">
        <h3 id="job-employer">About {job.employer.name}</h3>
        <p className="small muted">{[job.employer.industry, job.employer.location].filter(Boolean).join(' · ') || 'No details yet.'}</p>
        {job.employer.about && <p className="prose">{job.employer.about}</p>}
        {job.employer.website && (
          <a href={job.employer.website} target="_blank" rel="noopener noreferrer" className="small">
            Employer website <ExternalLink size={13} aria-hidden="true" />
          </a>
        )}
      </section>
    </article>
  );
}

/** The header's Apply button: brings the application form into view and puts the cursor in the note. */
function jumpToApply(event: MouseEvent) {
  event.preventDefault();
  const note = document.getElementById('apply-note');
  note?.scrollIntoView({ block: 'center' });
  note?.focus({ preventScroll: true });
}

function BackButton({ onBack }: { onBack: () => void }) {
  return (
    <button type="button" className="btn btn-quiet btn-small split-back" onClick={onBack}>
      <ArrowLeft size={16} aria-hidden="true" /> All jobs
    </button>
  );
}

function ApplyForm({ job, csrf, onDone }: { job: JobDetail; csrf: string; onDone: (job: JobDetail) => void }) {
  const [note, setNote] = useState('');
  const [cv, setCv] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      onDone(await apiPostForm<JobDetail>(`/api/jobs/${job.id}/apply`, { note: note.trim(), cv }, csrf));
    } catch (failure) {
      setError(errorText(failure));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="apply" onSubmit={submit} aria-labelledby="apply-h">
      <h3 id="apply-h">Apply</h3>
      <p className="small muted">CPDC sees your note, your portal profile and your CV if you add one.</p>
      <div className="field">
        <label htmlFor="apply-note">Note to CPDC</label>
        <textarea
          id="apply-note"
          className="textarea"
          maxLength={2000}
          placeholder="Why this role, and when you can start."
          value={note}
          onChange={(e) => setNote(e.target.value)}
        />
        <span className="hint num">{note.length} / 2000</span>
      </div>
      <PdfPicker label="CV (optional)" hint="PDF, up to 5 MB." file={cv} onChange={setCv} />
      {error && <Notice tone="error">{error}</Notice>}
      <div className="row">
        <button type="submit" className="btn btn-primary" disabled={busy}>
          {busy ? <Spinner /> : <Send size={16} aria-hidden="true" />} Send application
        </button>
      </div>
    </form>
  );
}

function ApplicationState({ job, csrf, onChanged }: { job: JobDetail; csrf: string; onChanged: (job: JobDetail) => void }) {
  const application = job.my_application;
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);
  if (!application) return null;
  const canWithdraw = !['hired', 'rejected', 'withdrawn'].includes(application.status);

  async function withdraw() {
    setBusy(true);
    setError(null);
    try {
      await apiWrite('POST', `/api/applications/${application!.id}/withdraw`, undefined, csrf);
      onChanged(await apiGet<JobDetail>(`/api/jobs/${job.id}`));
    } catch (failure) {
      setError(errorText(failure));
    } finally {
      setBusy(false);
      setConfirming(false);
    }
  }

  return (
    <section className="applied" aria-labelledby="applied-h" aria-live="polite">
      <CheckCircle2 size={22} aria-hidden="true" />
      <div className="stack" style={{ gap: 8 }}>
        <h3 id="applied-h">
          Your application: <span className={applicationTone(application.status)}>{APPLICATION_LABEL[application.status]}</span>
        </h3>
        <p className="small muted">
          Sent {dateLabel(application.created_at)}
          {application.cv_name ? ` with ${application.cv_name}` : ', no CV attached'}. CPDC updates the status here.
        </p>
        {error && <Notice tone="error">{error}</Notice>}
        {canWithdraw && !confirming && (
          <div><button type="button" className="btn btn-small" onClick={() => setConfirming(true)}>Withdraw application</button></div>
        )}
        {confirming && (
          <div className="row">
            <span className="small">Withdraw for good? You cannot apply for this job again.</span>
            <button type="button" className="btn btn-small" disabled={busy} onClick={() => void withdraw()}>
              {busy && <Spinner size={14} />}Yes, withdraw
            </button>
            <button type="button" className="btn btn-quiet btn-small" onClick={() => setConfirming(false)}>Keep it</button>
          </div>
        )}
      </div>
    </section>
  );
}
