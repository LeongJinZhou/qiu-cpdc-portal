// Internship logbook data as the API sends it (api/src/Logbook), and small date helpers.

export type EntryStatus = 'draft' | 'submitted' | 'approved' | 'returned';

export interface Internship {
  id: number;
  company: string;
  supervisor_name: string;
  supervisor_email: string;
  start_date: string;
  end_date: string;
  /** Set once the full placement form is sent (slice A); null for an internship from the short form. */
  placement_submitted_at: string | null;
}

export interface Entry {
  id: number;
  week_start: string;
  hours: number;
  activities: string;
  learnings: string;
  status: EntryStatus;
  supervisor_comment: string | null;
  submitted_at: string | null;
  decided_at: string | null;
  link_expires_at: string | null;
  link_active: boolean;
}

export interface Logbook {
  internship: Internship | null;
  entries: Entry[];
  today: string;
  /** Weeks of the internship that have started (worked out on the server). */
  weeks_expected: number;
}

export interface InternRow {
  internship_id: number;
  student_id: number;
  display_name: string;
  matric_no: string | null;
  department: string | null;
  picture_url: string | null;
  linkedin_url: string | null;
  company: string;
  supervisor_name: string;
  supervisor_email: string;
  /** Free-mail or QIU address: CPDC checks it is really the supervisor's. */
  supervisor_email_personal: boolean;
  start_date: string;
  end_date: string;
  approved: number;
  submitted: number;
  returned: number;
  draft: number;
  approved_hours: number;
  last_submitted_at: string | null;
  weeks_expected: number;
}

export interface InternDetail extends Logbook {
  student: {
    id: number;
    display_name: string;
    matric_no: string | null;
    department: string | null;
    picture_url: string | null;
    linkedin_url: string | null;
  };
}

export interface ReviewView {
  week_start: string;
  hours: number;
  activities: string;
  learnings: string;
  status: EntryStatus;
  supervisor_comment: string | null;
  decided_at: string | null;
  student_name: string;
  company: string;
  supervisor_name: string;
  expires_at: string;
}

export const STATUS_LABEL: Record<EntryStatus, string> = {
  draft: 'Draft',
  submitted: 'With supervisor',
  approved: 'Approved',
  returned: 'Returned',
};

export const STATUS_BADGE: Record<EntryStatus, string> = {
  draft: 'badge',
  submitted: 'badge badge-warn',
  approved: 'badge badge-ok',
  returned: 'badge badge-red',
};

/** "4 Jan 2027" from a YYYY-MM-DD date (no time zone shift: dates are calendar dates). */
export function dayLabel(date: string): string {
  const [y, m, d] = date.split('-').map(Number);
  if (!y || !m || !d) return date;
  return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
}

/** "Week of 4 Jan 2027". */
export function weekLabel(monday: string): string {
  return `Week of ${dayLabel(monday)}`;
}

/** The Monday of the week holding `date` (YYYY-MM-DD). */
export function mondayOf(date: string): string {
  const [y, m, d] = date.split('-').map(Number);
  const day = new Date(Date.UTC(y ?? 1970, (m ?? 1) - 1, d ?? 1));
  day.setUTCDate(day.getUTCDate() - ((day.getUTCDay() + 6) % 7));
  return day.toISOString().slice(0, 10);
}

/**
 * Mondays a student may still log: from the internship's first week up to the current week (and its end),
 * newest first, leaving out weeks that already have an entry (except `keep`, the week being edited).
 */
export function openWeeks(internship: Internship, today: string, taken: string[], keep?: string): string[] {
  const last = mondayOf(today < internship.end_date ? today : internship.end_date);
  const weeks: string[] = [];
  for (let week = mondayOf(internship.start_date); week <= last; ) {
    if (week === keep || !taken.includes(week)) weeks.unshift(week);
    const [y, m, d] = week.split('-').map(Number);
    week = new Date(Date.UTC(y ?? 1970, (m ?? 1) - 1, (d ?? 1) + 7)).toISOString().slice(0, 10);
  }
  return weeks;
}
