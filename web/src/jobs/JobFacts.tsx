// Slice A: the job's details (pay, vacancies, duration, requirements, benefits), its brochure and the employer's email.
import { FileDown, Mail } from 'lucide-react';
import { payLabel, type JobDetail } from './types';

export function JobFacts({ job }: { job: JobDetail }) {
  const place = [job.location, job.state, job.country].filter(Boolean).join(', ');
  const facts: [string, string | null][] = [
    ['Pay', payLabel(job)],
    ['Vacancies', job.vacancy_count === null ? null : String(job.vacancy_count)],
    ['Duration', job.duration_months === null ? null : `${job.duration_months} month${job.duration_months === 1 ? '' : 's'}`],
    ['Place', place],
    ['Minimum qualification', job.min_qualification],
    ['Work experience', job.work_experience],
    ['Suits', job.specializations.length > 0 ? job.specializations.join(', ') : null],
  ];
  const shown = facts.filter((f): f is [string, string] => f[1] !== null && f[1] !== '');

  return (
    <>
      {shown.length > 0 && (
        <dl className="job-facts">
          {shown.map(([label, value]) => (
            <div key={label}>
              <dt>{label}</dt>
              <dd className={label === 'Pay' || label === 'Vacancies' ? 'num' : undefined}>{value}</dd>
            </div>
          ))}
        </dl>
      )}
      {job.other_requirements && (
        <section className="job-section" aria-labelledby="job-req">
          <h3 id="job-req">Other requirements</h3>
          <p className="prose">{job.other_requirements}</p>
        </section>
      )}
      {job.benefits && (
        <section className="job-section" aria-labelledby="job-benefits">
          <h3 id="job-benefits">Benefits</h3>
          <p className="prose">{job.benefits}</p>
        </section>
      )}
      {(job.has_brochure || job.contact_email) && (
        <div className="row">
          {job.has_brochure && (
            <a className="btn btn-small" href={`/api/jobs/${job.id}/brochure`} download>
              <FileDown size={15} aria-hidden="true" /> {job.attachment_name ?? 'Brochure (PDF)'}
            </a>
          )}
          {job.contact_email && (
            <a className="btn btn-small btn-quiet" href={`mailto:${job.contact_email}?subject=${encodeURIComponent(job.title)}`}>
              <Mail size={15} aria-hidden="true" /> Contact employer
            </a>
          )}
        </div>
      )}
    </>
  );
}
