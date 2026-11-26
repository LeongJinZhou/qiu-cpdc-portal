// The CV as GET /api/cv and PUT /api/cv carry it: profile data (read only here) plus what the CV adds.
import type { Job, School } from '../profile/types';

export interface CvItem {
  heading: string;
  sub: string;
  period: string;
  detail: string;
}

export interface CvSection {
  title: string;
  items: CvItem[];
}

export type AwardKind = 'deans_list' | 'award' | 'certificate';

export interface CvAward {
  kind: AwardKind;
  title: string;
  issuer: string;
  period: string;
  learning_id?: number;
}

export interface CvActivity {
  activity: string;
  role_and_details: string;
  period: string;
}

export interface CvReference {
  name: string;
  designation: string;
  company: string;
  phone: string;
  email: string;
}

export interface CvContent {
  contact: { email: string; phone: string; location: string; website: string };
  headline: string;
  summary: string;
  hidden_experience: number[];
  hidden_education: number[];
  show_skills: boolean;
  sections: CvSection[];
  academic: { show_cgpa: boolean; cgpa: string; intake_period: string };
  courses_taken: string[];
  awards: CvAward[];
  cocurricular: CvActivity[];
  references: CvReference[];
  /** PDPA: CPDC may share this CV with QIU partner employers. The server keeps when it was first given. */
  employer_sharing_consent: boolean;
}

export interface VerifiedLearning {
  id: number;
  kind: 'course' | 'certificate';
  name: string;
  provider: string;
  completed_on: string;
}

export const AWARD_LABEL: Record<AwardKind, string> = {
  deans_list: "Dean's List",
  award: 'Competition or award',
  certificate: 'Professional certificate',
};

export const LIMITS = { courses: 10, awards: 20, cocurricular: 15, references: 2 };

export interface CvProfile {
  display_name: string;
  email: string | null;
  personal_email: string | null;
  linkedin_url: string | null;
  github_url: string | null;
  faculty: string | null;
  programme: string | null;
  graduation_year: number | null;
  experience: (Job & { id: number })[];
  education: (School & { id: number })[];
  skills: string[];
}

export interface CvResponse {
  profile: CvProfile;
  cv: CvContent;
  saved_at: string | null;
  employer_sharing_consent_at: string | null;
  verified_learning: VerifiedLearning[];
}

/** Section ideas offered with one click; the person can name their own. */
export const SECTION_IDEAS = ['Projects', 'Certifications', 'Awards', 'Leadership and activities', 'Languages', 'Volunteering'];

export const blankItem: CvItem = { heading: '', sub: '', period: '', detail: '' };

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const PHONE = /^[0-9+() -]*$/;
const PHONE_TEXT = 'Use only digits, spaces, +, - and brackets.';

/**
 * Every problem the server would refuse, by the id of the field it belongs to, in plain words. Shown next to the
 * field as the person types; saving waits until there are none.
 */
export function cvErrors(cv: CvContent): Record<string, string> {
  const errors: Record<string, string> = {};
  if (cv.contact.website && !/^https:\/\/\S+$/.test(cv.contact.website)) errors['cv-site'] = 'Start with https://.';
  if (cv.contact.phone && !PHONE.test(cv.contact.phone)) errors['cv-phone'] = PHONE_TEXT;
  if (cv.contact.email && !EMAIL.test(cv.contact.email)) errors['cv-email'] = 'This email does not look right.';
  if (cv.academic.cgpa && !/^[0-4]\.[0-9]{2}$/.test(cv.academic.cgpa)) errors['cv-cgpa'] = 'Write it as 3.45 (0.00 to 4.99).';
  cv.sections.forEach((section, si) => {
    if (!section.title.trim()) errors[`cv-s-${si}`] = 'Give this section a title.';
    section.items.forEach((item, ii) => {
      if (!item.heading.trim()) errors[`cv-h-${si}-${ii}`] = 'Every entry needs a heading.';
    });
  });
  cv.awards.forEach((a, i) => {
    if (!a.title.trim()) errors[`cv-aw-${i}`] = 'Name the award or certificate.';
  });
  cv.cocurricular.forEach((c, i) => {
    if (!c.activity.trim()) errors[`cv-co-${i}`] = 'Name the activity.';
  });
  cv.references.forEach((r, i) => {
    if (!r.name.trim()) errors[`cv-rn-${i}`] = 'Give the name.';
    if (r.phone && !PHONE.test(r.phone)) errors[`cv-rp-${i}`] = PHONE_TEXT;
    if (r.email && !EMAIL.test(r.email)) errors[`cv-re-${i}`] = 'This email does not look right.';
  });
  return errors;
}

/** The first problem the server would refuse, in plain words, or null. */
export function cvProblem(cv: CvContent): string | null {
  const first = Object.values(cvErrors(cv))[0];
  return first ? `Please fix the marked field: ${first}` : null;
}

/** A saved CV from before slice B lacks the new parts: fill them in. */
export function withDefaults(cv: Partial<CvContent> & Pick<CvContent, 'contact'>): CvContent {
  return {
    headline: '',
    summary: '',
    hidden_experience: [],
    hidden_education: [],
    show_skills: true,
    sections: [],
    academic: { show_cgpa: false, cgpa: '', intake_period: '' },
    courses_taken: [],
    awards: [],
    cocurricular: [],
    references: [],
    employer_sharing_consent: false,
    ...cv,
  };
}

/** The body PUT /api/cv takes: awards without an empty learning link. */
export function toCvBody(cv: CvContent): CvContent {
  return {
    ...cv,
    awards: cv.awards.map(({ learning_id, ...rest }) => (learning_id ? { ...rest, learning_id } : rest)),
  };
}
