// The yearly University and Programme Survey as the API sends it (api/src/Survey). The item list lives on the
// server (SurveyForm); the screens only render it.

export type Answer = number | 'na';
export type Scale = 'agree' | 'quality';

export interface Section {
  key: string;
  title: string;
  intro: string;
  scale: Scale;
  na: boolean;
  items: { key: string; text: string }[];
  extra?: { key: string; label: string };
}

export interface SurveyFormDef {
  sections: Section[];
  scales: Record<Scale, string[]>;
  na: string;
  comment_max: number;
}

export interface Cycle {
  id: number;
  title: string;
  academic_year: string;
  status: 'draft' | 'open' | 'closed';
  opens_at: string | null;
  closes_at: string | null;
  is_open: boolean;
}

export interface MyCycle extends Cycle {
  my_status: 'none' | 'draft' | 'submitted';
  submitted_at: string | null;
}

export interface SurveyResponse {
  id: number;
  status: 'draft' | 'submitted';
  faculty: string | null;
  programme: string | null;
  semester: number | null;
  answers: Record<string, Answer>;
  comments: Record<string, string>;
  submitted_at: string | null;
  updated_at: string;
}

export interface ResponseView {
  cycle: Cycle;
  response: SurveyResponse | null;
  defaults: { faculty: string | null; programme: string | null };
}

export interface ManagedCycle extends Cycle {
  submitted_count: number;
  draft_count: number;
  invited_count: number;
  response_rate: number | null;
}

export interface RateRow {
  name: string;
  invited: number;
  submitted: number;
  drafts: number;
  rate: number | null;
}

export interface Report {
  cycle: Cycle;
  form: SurveyFormDef;
  totals: { invited: number; submitted: number; rate: number | null };
  by_faculty: RateRow[];
  by_programme: RateRow[];
  stats: {
    items: Record<string, { n: number; na: number; mean: number | null }>;
    sections: Record<string, { n: number; mean: number | null }>;
  };
}

export interface Draft {
  programme: string;
  semester: number | null;
  answers: Record<string, Answer>;
  comments: Record<string, string>;
}

/** Item keys of the whole form, in order. */
export function itemKeys(form: SurveyFormDef): string[] {
  return form.sections.flatMap((s) => s.items.map((i) => i.key));
}

/** Unanswered item keys of one section (N/A counts as answered). */
export function missingIn(section: Section, answers: Record<string, Answer>): string[] {
  return section.items.filter((i) => answers[i.key] === undefined).map((i) => i.key);
}

/** What stops a submit, in the order the person meets it; null when it can be sent. */
export function submitProblem(form: SurveyFormDef, draft: Draft): { step: number; text: string } | null {
  if (!draft.programme.trim()) return { step: 0, text: 'Enter your programme.' };
  if (draft.semester === null) return { step: 0, text: 'Pick your semester.' };
  for (const [i, section] of form.sections.entries()) {
    const missing = missingIn(section, draft.answers).length;
    if (missing > 0) {
      const what = missing === 1 ? '1 item' : `${missing} items`;
      return { step: i + 1, text: `${section.title}: ${what} not answered yet.${section.na ? ' Pick N/A for any you have not used.' : ''}` };
    }
  }
  return null;
}

/** The body PUT /api/surveys/{id}/response takes. */
export function toBody(draft: Draft, submit: boolean) {
  const comments: Record<string, string> = {};
  for (const [key, text] of Object.entries(draft.comments)) if (text.trim()) comments[key] = text;
  return { programme: draft.programme, semester: draft.semester, answers: draft.answers, comments, submit };
}

/** A mean as text with two decimals, or a dash. */
export function meanText(mean: number | null | undefined): string {
  return mean === null || mean === undefined ? '-' : mean.toFixed(2);
}
