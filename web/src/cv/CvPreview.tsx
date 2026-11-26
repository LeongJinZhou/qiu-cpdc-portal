// The CV as it prints: one A4 sheet layout, text only (nothing from the person is rendered as HTML).
import { monthLabel } from '../profile/types';
import type { CvContent, CvProfile } from './types';

function yearRange(start: number | null, end: number | null): string {
  if (start && end) return `${start} to ${end}`;
  return String(end ?? start ?? '');
}

export function CvPreview({ profile, cv }: { profile: CvProfile; cv: CvContent }) {
  const jobs = profile.experience.filter((j) => !cv.hidden_experience.includes(j.id));
  const schools = profile.education.filter((s) => !cv.hidden_education.includes(s.id));
  const contact = [cv.contact.email, cv.contact.phone, cv.contact.location].filter(Boolean);
  const links = [cv.contact.website, profile.linkedin_url, profile.github_url].filter((l): l is string => Boolean(l));
  const studies = [
    cv.academic.intake_period.trim() ? `Intake ${cv.academic.intake_period.trim()}` : '',
    cv.academic.show_cgpa && cv.academic.cgpa ? `CGPA ${cv.academic.cgpa}` : '',
  ].filter(Boolean);
  const courses = cv.courses_taken.map((c) => c.trim()).filter(Boolean);
  const awards = cv.awards.filter((a) => a.title.trim());
  const activities = cv.cocurricular.filter((c) => c.activity.trim());
  const references = cv.references.filter((r) => r.name.trim());
  return (
    <article className="cv-sheet" aria-label="CV preview">
      <header className="cv-top">
        <h1>{profile.display_name}</h1>
        {cv.headline && <p className="cv-headline">{cv.headline}</p>}
        {contact.length > 0 && <p className="cv-contact">{contact.join('  ·  ')}</p>}
        {links.length > 0 && (
          <p className="cv-contact">
            {links.map((link, i) => (
              <span key={link}>
                {i > 0 && '  ·  '}
                <a href={link} target="_blank" rel="noopener noreferrer">{link.replace(/^https:\/\/(www\.)?/, '')}</a>
              </span>
            ))}
          </p>
        )}
      </header>

      {cv.summary && (
        <section className="cv-block">
          <h2>Profile</h2>
          <p className="cv-text">{cv.summary}</p>
        </section>
      )}

      {jobs.length > 0 && (
        <section className="cv-block">
          <h2>Experience</h2>
          {jobs.map((job) => (
            <div key={job.id} className="cv-entry">
              <div className="cv-entry-head">
                <strong>{job.title}</strong>
                <span className="cv-period">
                  {[monthLabel(job.start), job.start || job.end ? monthLabel(job.end, true) : ''].filter(Boolean).join(' to ')}
                </span>
              </div>
              <div className="cv-sub">{[job.company, job.location].filter(Boolean).join(', ')}</div>
              {job.description && <p className="cv-text">{job.description}</p>}
            </div>
          ))}
        </section>
      )}

      {(schools.length > 0 || studies.length > 0 || courses.length > 0) && (
        <section className="cv-block">
          <h2>Education</h2>
          {schools.map((s) => (
            <div key={s.id} className="cv-entry">
              <div className="cv-entry-head">
                <strong>{s.school}</strong>
                <span className="cv-period">{yearRange(s.start_year, s.end_year)}</span>
              </div>
              {(s.degree || s.field) && <div className="cv-sub">{[s.degree, s.field].filter(Boolean).join(', ')}</div>}
            </div>
          ))}
          {studies.length > 0 && <p className="cv-text">{studies.join('  ·  ')}</p>}
          {courses.length > 0 && <p className="cv-text">Relevant courses: {courses.join(', ')}</p>}
        </section>
      )}

      {awards.length > 0 && (
        <section className="cv-block">
          <h2>Awards and certificates</h2>
          {awards.map((a, i) => (
            <div key={i} className="cv-entry">
              <div className="cv-entry-head">
                <strong>{a.title}</strong>
                {a.period && <span className="cv-period">{a.period}</span>}
              </div>
              {a.issuer && <div className="cv-sub">{a.issuer}</div>}
            </div>
          ))}
        </section>
      )}

      {activities.length > 0 && (
        <section className="cv-block">
          <h2>Co-curricular activities</h2>
          {activities.map((c, i) => (
            <div key={i} className="cv-entry">
              <div className="cv-entry-head">
                <strong>{c.activity}</strong>
                {c.period && <span className="cv-period">{c.period}</span>}
              </div>
              {c.role_and_details && <p className="cv-text">{c.role_and_details}</p>}
            </div>
          ))}
        </section>
      )}

      {cv.sections.map((section, i) => (
        <section key={i} className="cv-block">
          <h2>{section.title}</h2>
          {section.items.map((item, j) => (
            <div key={j} className="cv-entry">
              <div className="cv-entry-head">
                <strong>{item.heading}</strong>
                {item.period && <span className="cv-period">{item.period}</span>}
              </div>
              {item.sub && <div className="cv-sub">{item.sub}</div>}
              {item.detail && <p className="cv-text">{item.detail}</p>}
            </div>
          ))}
        </section>
      ))}

      {cv.show_skills && profile.skills.length > 0 && (
        <section className="cv-block">
          <h2>Skills</h2>
          <p className="cv-text">{profile.skills.join(', ')}</p>
        </section>
      )}

      {references.length > 0 && (
        <section className="cv-block">
          <h2>References</h2>
          <div className="cv-refs">
            {references.map((r, i) => (
              <div key={i} className="cv-entry">
                <strong>{r.name}</strong>
                {(r.designation || r.company) && <div className="cv-sub">{[r.designation, r.company].filter(Boolean).join(', ')}</div>}
                {(r.phone || r.email) && <div className="cv-sub">{[r.phone, r.email].filter(Boolean).join('  ·  ')}</div>}
              </div>
            ))}
          </div>
        </section>
      )}
    </article>
  );
}
