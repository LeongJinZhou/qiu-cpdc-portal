// Placement (industry visit), change history and satisfaction survey as api/src/Placement returns them.

export type ChangeReason = 'company_change' | 'date_change' | 'supervisor_hr_update' | 'allowance_role_update' | 'correction';

export interface PlacementFields {
  company: string;
  business_nature: string;
  company_address_1: string;
  company_address_2: string | null;
  company_address_3: string | null;
  company_postcode: string;
  company_state: string;
  company_country: string;
  start_date: string;
  end_date: string;
  batch_month: number;
  batch_year: number;
  /** "800.00": RM per month, 0 = unpaid. */
  monthly_allowance_rm: string;
  roles_responsibilities: string;
  has_hr_contact: boolean;
  hr_name: string | null;
  hr_phone: string | null;
  hr_email: string | null;
  supervisor_name: string;
  supervisor_email: string;
  supervisor_phone: string;
}

/** Saved by the student; fields may be empty on an internship started with the short logbook form. */
export interface Placement extends Partial<PlacementFields> {
  id: number;
  company: string;
  supervisor_name: string;
  supervisor_email: string;
  start_date: string;
  end_date: string;
  has_hr_contact: boolean;
  placement_submitted_at: string | null;
}

export interface Satisfaction {
  recommend_juniors: boolean;
  overall_rating: number;
  offered_job: boolean;
  feedback: string | null;
  submitted_at: string;
  updated_at: string;
}

export interface Change {
  id: number;
  change_reason: ChangeReason;
  remarks: string | null;
  created_at: string;
  changed_by?: string | null;
  fields: { field: keyof PlacementFields; old: string | number | null; new: string | number | null }[];
}

export interface MyPlacement {
  placement: Placement | null;
  satisfaction: Satisfaction | null;
  changes: Change[];
  business_natures: string[];
}

export interface PlacementRow {
  internship_id: number;
  student_id: number;
  display_name: string;
  matric_no: string | null;
  picture_url: string | null;
  faculty: string | null;
  linkedin_url: string | null;
  company: string;
  business_nature: string | null;
  company_state: string | null;
  company_country: string | null;
  start_date: string;
  end_date: string;
  batch_month: number | null;
  batch_year: number | null;
  monthly_allowance_rm: number;
  has_hr_contact: boolean;
  supervisor_name: string;
  placement_submitted_at: string | null;
  changes: number;
  rating: number | null;
}

export interface PlacementFilterChoices {
  faculties: string[];
  batches: { year: number; month: number }[];
  business_natures: string[];
}

export interface Measures {
  responses: number;
  avg_rating: number | null;
  recommend_pct: number | null;
  offer_pct: number | null;
}

export interface SatisfactionReport {
  companies: (Measures & { company: string })[];
  total: Measures;
}

export interface ChangeHistory {
  internship_id: number;
  display_name: string;
  company: string;
  changes: Change[];
}

export const REASON_LABEL: Record<ChangeReason, string> = {
  company_change: 'Changed company',
  date_change: 'Changed dates',
  supervisor_hr_update: 'Supervisor or HR update',
  allowance_role_update: 'Allowance or role update',
  correction: 'Correcting a mistake',
};

export const RATING_LABEL: Record<number, string> = { 1: 'Poor', 2: 'Fair', 3: 'Good', 4: 'Very good', 5: 'Excellent' };

export const FIELD_LABEL: Record<keyof PlacementFields, string> = {
  company: 'Company',
  business_nature: 'Nature of business',
  company_address_1: 'Address line 1',
  company_address_2: 'Address line 2',
  company_address_3: 'Address line 3',
  company_postcode: 'Postcode',
  company_state: 'State',
  company_country: 'Country',
  start_date: 'Start date',
  end_date: 'End date',
  batch_month: 'Batch month',
  batch_year: 'Batch year',
  monthly_allowance_rm: 'Monthly allowance (RM)',
  roles_responsibilities: 'Roles and responsibilities',
  has_hr_contact: 'Has an HR contact',
  hr_name: 'HR name',
  hr_phone: 'HR phone',
  hr_email: 'HR email',
  supervisor_name: 'Supervisor',
  supervisor_email: 'Supervisor email',
  supervisor_phone: 'Supervisor phone',
};

export const MALAYSIAN_STATES = [
  'Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang', 'Perak', 'Perlis', 'Pulau Pinang', 'Sabah',
  'Sarawak', 'Selangor', 'Terengganu', 'Kuala Lumpur', 'Labuan', 'Putrajaya',
];

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** "Jan 2027", or an empty string when no batch is set. */
export function batchLabel(month: number | null | undefined, year: number | null | undefined): string {
  return month && year ? `${MONTHS[month - 1] ?? ''} ${year}` : '';
}

export function monthName(month: number): string {
  return MONTHS[month - 1] ?? '';
}

/** "RM 1,200.00", or "Unpaid" for 0. */
export function allowanceLabel(rm: number | string | null | undefined): string {
  const value = Number(rm ?? 0);
  if (!value) return 'Unpaid';
  return `RM ${value.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/** A change log value as text for people: yes/no for the HR flag, a dash for empty. */
export function changeValue(field: keyof PlacementFields, value: string | number | null): string {
  if (value === null || value === '') return '-';
  if (field === 'has_hr_contact') return Number(value) === 1 ? 'Yes' : 'No';
  if (field === 'batch_month') return monthName(Number(value));
  if (field === 'monthly_allowance_rm') return allowanceLabel(value);
  return String(value);
}
