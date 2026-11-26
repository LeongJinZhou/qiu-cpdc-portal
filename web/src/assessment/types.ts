// Self-assessment data as the API sends it (api/src/Assessment).

export type AssessmentStatus = 'draft' | 'published' | 'archived';
/** 'likert': every question is rated 1-5 (Very Poor .. Excellent); the server writes those five answers. */
export type AnswerStyle = 'choice' | 'likert';

export interface Result {
  attempt_id?: number;
  assessment_id: number;
  title: string;
  score: number;
  max_score: number;
  percent: number;
  band: string | null;
  taken_at?: string;
}

export interface Available {
  id: number;
  title: string;
  description: string;
  question_count: number;
  latest: Result | null;
}

export interface TakeQuestion {
  id: number;
  prompt: string;
  options: { id: number; label: string }[];
}

export interface Taking {
  id: number;
  title: string;
  description: string;
  answer_style: AnswerStyle;
  reflection_prompts: string[];
  questions: TakeQuestion[];
}

/** One completed attempt as the print view shows it (GET /api/assessments/attempts/{id}). */
export interface AttemptReport {
  attempt_id: number;
  assessment_id: number;
  title: string;
  answer_style: AnswerStyle;
  taken_at: string;
  person: {
    user_id: number;
    display_name: string;
    matric_no: string | null;
    faculty: string | null;
    programme: string | null;
    semester: number | null;
  };
  traits: { prompt: string; answer: string; points: number; max_points: number }[];
  score: number;
  max_score: number;
  percent: number;
  band: string | null;
  reflections: { prompt: string; answer: string }[];
}

/** Trait averages for CPDC (GET /api/assessments/manage/{id}/traits); means are keyed by question id. */
export interface Traits {
  assessment: { id: number; title: string };
  questions: { id: number; prompt: string; max_points: number }[];
  overall: { people: number; means: Record<string, number> };
  groups: { faculty: string | null; semester: number | null; people: number; means: Record<string, number> }[];
}

export interface TakerRow {
  attempt_id: number;
  user_id: number;
  display_name: string;
  matric_no: string | null;
  linkedin_url: string | null;
  faculty: string | null;
  semester: number | null;
  percent: number;
  band: string | null;
  taken_at: string;
  has_reflections: boolean;
}

/** The trait name of a characteristics prompt ("Resilience: I keep going ..." -> "Resilience"). */
export function traitName(prompt: string): string {
  const head = prompt.split(':')[0]?.trim() ?? prompt;
  return head.length > 0 && head.length < 60 ? head : prompt;
}

export interface Band {
  min_percent: number;
  label: string;
}

export interface EditQuestion {
  id?: number;
  prompt: string;
  options: { id?: number; label: string; points: number }[];
}

export interface Managed {
  id: number;
  title: string;
  description: string;
  answer_style: AnswerStyle;
  reflection_prompts: string[];
  status: AssessmentStatus;
  bands: Band[];
  questions: EditQuestion[];
  locked: boolean;
  updated_at: string;
}

export interface ManagedRow {
  id: number;
  title: string;
  status: AssessmentStatus;
  updated_at: string;
  question_count: number;
  attempt_count: number;
  people_count: number;
  average_percent: number | null;
}

export const STATUS_LABEL: Record<AssessmentStatus, string> = { draft: 'Draft', published: 'Published', archived: 'Archived' };
export const STATUS_BADGE: Record<AssessmentStatus, string> = { draft: 'badge', published: 'badge badge-ok', archived: 'badge badge-warn' };

/** What the editor sends: ids and the locked flag are not part of the saved shape. */
export function toSaveBody(a: Pick<Managed, 'title' | 'description' | 'bands' | 'questions' | 'answer_style' | 'reflection_prompts'>) {
  return {
    title: a.title,
    description: a.description,
    answer_style: a.answer_style,
    reflection_prompts: a.reflection_prompts.map((p) => p.trim()).filter(Boolean),
    bands: a.bands.map((b) => ({ min_percent: b.min_percent, label: b.label })),
    // A rating question gets the five standard answers on the server; two placeholders satisfy the schema.
    questions: a.questions.map((q) => ({
      prompt: q.prompt,
      options:
        a.answer_style === 'likert'
          ? LIKERT.slice(0, 2).map((label, i) => ({ label, points: i + 1 }))
          : q.options.map((o) => ({ label: o.label, points: o.points })),
    })),
  };
}

export const LIKERT = ['Very Poor', 'Poor', 'Average', 'Good', 'Excellent'];

/** The first problem the server would refuse, in the editor's words; null when it can be saved. */
export function editorProblem(a: Pick<Managed, 'title' | 'bands' | 'questions' | 'answer_style' | 'reflection_prompts'>): string | null {
  const prompts = a.reflection_prompts.map((p) => p.trim()).filter(Boolean);
  if (new Set(prompts).size !== prompts.length) return 'Two reflection questions are the same.';
  if (a.title.trim().length < 3) return 'Give it a title.';
  if (!a.bands.some((b) => b.min_percent === 0)) return 'One result band must start at 0%.';
  if (new Set(a.bands.map((b) => b.min_percent)).size !== a.bands.length) return 'Two result bands start at the same score.';
  if (a.bands.some((b) => !b.label.trim())) return 'Every result band needs a name.';
  if (a.questions.length === 0) return 'Add at least one question.';
  for (const [i, q] of a.questions.entries()) {
    if (q.prompt.trim().length < 3) return `Question ${i + 1} needs its text.`;
    if (a.answer_style === 'likert') continue; // the five ratings come from the server
    if (q.options.length < 2) return `Question ${i + 1} needs at least two answers.`;
    if (q.options.some((o) => !o.label.trim())) return `Every answer of question ${i + 1} needs its text.`;
  }
  return null;
}
