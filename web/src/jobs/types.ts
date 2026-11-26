// Jobs, employers and applications as the slice 2b API returns them.

export type JobType = 'full_time' | 'part_time' | 'internship' | 'contract';
export type JobStatus = 'draft' | 'open' | 'closed';
export type ApplicationStatus =
  | 'submitted'
  | 'reviewing'
  | 'shortlisted'
  | 'interview'
  | 'offered'
  | 'hired'
  | 'rejected'
  | 'withdrawn';

export const TYPE_LABEL: Record<JobType, string> = {
  full_time: 'Full-time',
  part_time: 'Part-time',
  internship: 'Internship',
  contract: 'Contract',
};

export const JOB_STATUS_LABEL: Record<JobStatus, string> = { draft: 'Draft', open: 'Open', closed: 'Closed' };

export const APPLICATION_LABEL: Record<ApplicationStatus, string> = {
  submitted: 'Sent',
  reviewing: 'Being reviewed',
  shortlisted: 'Shortlisted',
  interview: 'Interview',
  offered: 'Offer made',
  hired: 'Hired',
  rejected: 'Not selected',
  withdrawn: 'Withdrawn',
};

/** Badge tone per application status. */
export function applicationTone(status: ApplicationStatus): string {
  if (status === 'hired' || status === 'offered') return 'badge badge-ok';
  if (status === 'shortlisted' || status === 'interview') return 'badge badge-warn';
  if (status === 'rejected') return 'badge badge-red';
  return 'badge';
}

export interface SkillNeed {
  area_id: number;
  name: string;
  level: number;
}

// --- slice A: job details (begin) ---
export type PayFrequency = 'monthly' | 'hourly' | 'lump_sum' | 'unpaid' | 'negotiable';

export const PAY_LABEL: Record<PayFrequency, string> = {
  monthly: 'per month',
  hourly: 'per hour',
  lump_sum: 'lump sum',
  unpaid: 'Unpaid',
  negotiable: 'Negotiable',
};

/** The optional detail CPDC adds to a job (api/src/Jobs/JobDetails.php). */
export interface JobExtras {
  specializations: string[];
  vacancy_count: number | null;
  duration_months: number | null;
  salary_min: number | null;
  salary_max: number | null;
  pay_frequency: PayFrequency | null;
  min_qualification: string | null;
  work_experience: string | null;
  other_requirements: string | null;
  benefits: string | null;
  country: string | null;
  state: string | null;
  contact_email: string | null;
  attachment_name: string | null;
  has_brochure: boolean;
}

/** "RM 1,200 - 1,500 per month", "Unpaid", "Negotiable", or null when nothing is said about pay. */
export function payLabel(job: Pick<JobExtras, 'salary_min' | 'salary_max' | 'pay_frequency'>): string | null {
  const { salary_min: min, salary_max: max, pay_frequency: pay } = job;
  if (pay === 'unpaid' || pay === 'negotiable') return PAY_LABEL[pay];
  if (min === null && max === null) return null;
  const rm = (n: number) => n.toLocaleString('en-MY', { maximumFractionDigits: 2 });
  const range = min !== null && max !== null && min !== max ? `${rm(min)} - ${rm(max)}` : rm((min ?? max) as number);
  return `RM ${range}${pay ? ` ${PAY_LABEL[pay]}` : ''}`;
}
// --- slice A (end) ---

export interface JobSummary {
  id: number;
  title: string;
  employer_id: number;
  employer_name: string;
  job_type: JobType;
  location: string;
  closing_date: string;
  my_status: ApplicationStatus | null;
  // slice A
  state: string | null;
  specializations: string[];
  salary_min: number | null;
  salary_max: number | null;
  pay_frequency: PayFrequency | null;
}

export interface MyApplication {
  id: number;
  job_id: number;
  title: string;
  employer_name: string;
  job_type: JobType;
  closing_date: string;
  status: ApplicationStatus;
  status_changed_at: string;
  created_at: string;
  note: string | null;
  cv_name: string | null;
}

export interface JobDetail extends Omit<JobSummary, 'my_status'>, JobExtras {
  open: boolean;
  description: string;
  employer: {
    id: number;
    name: string;
    industry: string | null;
    website: string | null;
    location: string | null;
    about: string | null;
  };
  skills: SkillNeed[];
  my_application: MyApplication | null;
}

export interface Employer {
  id: number;
  name: string;
  industry: string | null;
  website: string | null;
  location: string | null;
  about: string | null;
  active: boolean;
  jobs: number;
}

export interface ManagedJob {
  id: number;
  title: string;
  employer_id: number;
  employer_name: string;
  job_type: JobType;
  location: string;
  closing_date: string;
  status: JobStatus;
  applications: number;
  new_applications: number;
}

export interface JobForEdit extends JobExtras {
  id: number;
  employer_id: number;
  employer_name: string;
  title: string;
  job_type: JobType;
  location: string;
  closing_date: string;
  description: string;
  status: JobStatus;
  skills: SkillNeed[];
}

export interface ApplicantRow {
  id: number;
  status: ApplicationStatus;
  created_at: string;
  status_changed_at: string;
  has_cv: boolean;
  user_id: number;
  display_name: string;
  picture_url: string | null;
  role: string;
  programme: string | null;
  faculty: string | null;
  linkedin_url: string | null;
}

/** A calendar date "2026-12-31" as "31 Dec 2026". */
export function dayLabel(value: string): string {
  const [year, month, day] = value.split('-').map(Number);
  if (!year || !month || !day) return value;
  return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString('en-MY', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
  });
}

/** Today in Malaysia as "YYYY-MM-DD" (the server decides; this only helps the forms). */
export function todayInMalaysia(): string {
  return new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Kuala_Lumpur' });
}

/** Days from today (Malaysia) to a closing date; negative when past. */
export function daysLeft(closing: string): number {
  const [y, m, d] = closing.split('-').map(Number);
  const [ty, tm, td] = todayInMalaysia().split('-').map(Number);
  return Math.round((Date.UTC(y, m - 1, d) - Date.UTC(ty, tm - 1, td)) / 86400000);
}

export const PDF_MAX_BYTES = 5 * 1024 * 1024;

/** A person-facing reason the file cannot be sent, or null when it looks fine (the server checks again). */
export function pdfProblem(file: File): string | null {
  if (file.type && file.type !== 'application/pdf') return 'Choose a PDF file.';
  if (file.size > PDF_MAX_BYTES) return 'The PDF must be smaller than 5 MB.';
  if (file.size === 0) return 'This file is empty.';
  return null;
}
