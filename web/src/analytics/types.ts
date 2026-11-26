// Shapes of GET /api/analytics/overview and GET /api/people/:id/analytics (docs/SPEC.md section 15).
import type { EmploymentStatus } from '../profile/types';

export type Rate = number | null;
export interface Count { label: string; count: number }
export interface Average { label: string; average: number | null }

export interface Overview {
  kpis: {
    students: number; alumni: number; student_linkedin_rate: Rate; alumni_linkedin_rate: Rate; onboarding_rate: Rate;
    alumni_employment_rate: Rate; alumni_known: number; alumni_unknown: number; internship_placement_rate: Rate;
    job_offer_rate: Rate;
  };
  readiness: { students: number; pdf_import_rate: Rate; cv_rate: Rate; consent_rate: Rate; skills_rate: Rate };
  internship: {
    placed: number; by_nature: Count[]; top_companies: Count[]; allowance_average: number | null;
    allowance_median: number | null; unpaid: number; logbook_completion_rate: Rate; weeks_expected: number;
    weeks_waiting: number; weeks_returned: number; satisfaction_answers: number; satisfaction_average: number | null;
    recommend_rate: Rate; job_offer_rate: Rate;
  };
  jobs: { open_jobs: number; applications: number; funnel: Count[]; top_employers: { label: string; applications: number; hired: number }[] };
  growth: {
    skills: (Average & { people: number })[]; learning_verified: number; learning_pending: number; learning_rejected: number;
    assessment_completion_rate: Rate; traits: (Average & { answers: number })[];
    survey: { cycle_id: number; title: string; responses: number; response_rate: Rate; sections: Average[] } | null;
    interview_sessions: number; interview_average: number | null; showcase_published: number; workshop_rsvps: number;
    workshop_attended: number; workshop_attendance_rate: Rate;
  };
  faculties: {
    faculty: string; students: number; linkedin_rate: Rate; cv_rate: Rate; interns: number; job_offer_rate: Rate;
    alumni: number; alumni_employment_rate: Rate;
  }[];
  alumni: {
    status: { label: EmploymentStatus; count: number }[];
    breakdown: { faculty: string; graduation_year: number | null; counts: Record<EmploymentStatus, number> }[];
    confirmed_recently: number; confirmation_overdue: number; never_confirmed: number; confirm_days: number;
    without_linkedin: number; without_linkedin_list: { id: number; display_name: string; faculty: string | null; graduation_year: number | null }[];
    network_visible: number; mentors_active: number; mentor_requests: Count[];
  };
  options: { faculties: string[]; programmes: string[]; cohorts: number[] };
}

export interface PersonView {
  checklist: { key: string; label: string; done: boolean }[];
  cv: { updated_at: string | null; employer_sharing_consent_at: string | null };
  internship: {
    company: string; business_nature: string | null; company_address_1: string | null; company_address_2: string | null;
    company_address_3: string | null; company_postcode: string | null; company_state: string | null;
    company_country: string | null; start_date: string; end_date: string | null; batch_month: number | null;
    batch_year: number | null; monthly_allowance_rm: string; has_hr_contact: number; hr_name: string | null;
    hr_phone: string | null; hr_email: string | null; supervisor_name: string; supervisor_email: string;
    supervisor_phone: string | null; placement_submitted_at: string | null;
    logbook: { started: number; approved: number; waiting: number; returned: number; hours_approved: number };
    satisfaction: { recommend_juniors: boolean; overall_rating: number; offered_job: boolean; updated_at: string } | null;
  } | null;
  applications: { id: number; title: string; employer: string; status: string; created_at: string }[];
  skills: { area: string; latest: number; previous: number | null }[];
  assessments: {
    id: number; title: string; score: number; max_score: number; band_label: string | null; semester: number | null;
    created_at: string; reflections: { prompt: string; answer: string }[];
  }[];
  learning: { id: number; kind: string; name: string; provider: string | null; completed_on: string | null; status: string }[];
  career_path: { done: number; total: number };
  interview: { sessions: number; answered: number; average: number | null };
  showcase: { id: number; title: string; project_year: number | null; published: boolean }[];
  workshops: { going: number; attended: number; recent: { id: number; title: string; starts_at: string; status: string; checked_in_at: string | null }[] };
  survey: { cycle: string; status: string } | null;
  alumni: {
    confirmations: { employment_status: EmploymentStatus; title: string | null; company: string | null; confirmed_at: string }[];
    confirmation_overdue: boolean; confirm_days: number; network_visible: boolean; mentor_active: boolean; mentor_matches: number;
  } | null;
}

/** "67%", or a dash when nothing was counted. */
export function percent(rate: Rate): string {
  return rate === null ? '-' : `${Math.round(rate * 100)}%`;
}

/** "RM 1,200" (two decimals only when needed). */
export function ringgit(value: number | string | null): string {
  if (value === null) return '-';
  const n = Number(value);
  return `RM ${n.toLocaleString('en-MY', { maximumFractionDigits: 2 })}`;
}

/** The filters as a query string without empty values. */
export function filterQuery(filters: Record<string, string>, extra: Record<string, string> = {}): string {
  return new URLSearchParams(Object.entries({ ...filters, ...extra }).filter(([, v]) => v !== '')).toString();
}
