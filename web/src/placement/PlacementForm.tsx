// The student's placement (industry visit) form, in four groups. Once submitted, a change needs a reason; the server
// keeps the old and new values for CPDC.
import { Building2, CalendarRange, Contact, UserRoundCheck } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { ApiFailure, apiWrite } from '../api';
import { errorText, Notice, Spinner } from '../ui/parts';
import { MALAYSIAN_STATES, REASON_LABEL, monthName, type ChangeReason, type MyPlacement, type Placement } from './types';

interface Draft {
  company: string;
  business_nature: string;
  company_address_1: string;
  company_address_2: string;
  company_address_3: string;
  company_postcode: string;
  company_state: string;
  company_country: string;
  start_date: string;
  end_date: string;
  batch_month: string;
  batch_year: string;
  monthly_allowance_rm: string;
  roles_responsibilities: string;
  no_hr: boolean;
  hr_name: string;
  hr_phone: string;
  hr_email: string;
  supervisor_name: string;
  supervisor_email: string;
  supervisor_phone: string;
  change_reason: ChangeReason | '';
  remarks: string;
}

function draftFrom(p: Placement | null): Draft {
  const text = (value: string | number | null | undefined) => (value === null || value === undefined ? '' : String(value));
  return {
    company: text(p?.company),
    business_nature: text(p?.business_nature),
    company_address_1: text(p?.company_address_1),
    company_address_2: text(p?.company_address_2),
    company_address_3: text(p?.company_address_3),
    company_postcode: text(p?.company_postcode),
    company_state: text(p?.company_state),
    company_country: p?.company_country ?? 'Malaysia',
    start_date: text(p?.start_date),
    end_date: text(p?.end_date),
    batch_month: text(p?.batch_month),
    batch_year: text(p?.batch_year),
    monthly_allowance_rm: p?.placement_submitted_at ? text(Number(p.monthly_allowance_rm ?? 0)) : '',
    roles_responsibilities: text(p?.roles_responsibilities),
    no_hr: p?.placement_submitted_at ? !p.has_hr_contact : false,
    hr_name: text(p?.hr_name),
    hr_phone: text(p?.hr_phone),
    hr_email: text(p?.hr_email),
    supervisor_name: text(p?.supervisor_name),
    supervisor_email: text(p?.supervisor_email),
    supervisor_phone: text(p?.supervisor_phone),
    change_reason: '',
    remarks: '',
  };
}

export function PlacementForm({
  placement,
  businessNatures,
  csrf,
  onSaved,
  onCancel,
}: {
  placement: Placement | null;
  businessNatures: string[];
  csrf: string;
  onSaved: (mine: MyPlacement) => void;
  onCancel?: () => void;
}) {
  const [draft, setDraft] = useState<Draft>(() => draftFrom(placement));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<{ field: string | null; text: string } | null>(null);
  const submitted = placement?.placement_submitted_at != null;
  const set = <K extends keyof Draft>(key: K) => (value: Draft[K]) => setDraft((d) => ({ ...d, [key]: value }));
  const emailChanged = placement !== null && draft.supervisor_email.trim().toLowerCase() !== placement.supervisor_email;

  // The batch is usually the month the placement starts: fill it in once, the student can change it.
  const setStart = (value: string) =>
    setDraft((d) => {
      const [y, m] = value.split('-');
      const fill = d.batch_month === '' && d.batch_year === '' && y && m;
      return { ...d, start_date: value, ...(fill ? { batch_month: String(Number(m)), batch_year: y } : {}) };
    });

  async function save(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    const body: Record<string, unknown> = {
      company: draft.company.trim(),
      business_nature: draft.business_nature,
      company_address_1: draft.company_address_1.trim(),
      company_address_2: draft.company_address_2.trim(),
      company_address_3: draft.company_address_3.trim(),
      company_postcode: draft.company_postcode.trim(),
      company_state: draft.company_state.trim(),
      company_country: draft.company_country.trim(),
      start_date: draft.start_date,
      end_date: draft.end_date,
      batch_month: Number(draft.batch_month),
      batch_year: Number(draft.batch_year),
      monthly_allowance_rm: draft.monthly_allowance_rm === '' ? 0 : Number(draft.monthly_allowance_rm),
      roles_responsibilities: draft.roles_responsibilities.trim(),
      has_hr_contact: !draft.no_hr,
      supervisor_name: draft.supervisor_name.trim(),
      supervisor_email: draft.supervisor_email.trim(),
      supervisor_phone: draft.supervisor_phone.trim(),
    };
    if (!draft.no_hr) Object.assign(body, { hr_name: draft.hr_name.trim(), hr_phone: draft.hr_phone.trim(), hr_email: draft.hr_email.trim() });
    if (submitted) Object.assign(body, { change_reason: draft.change_reason || undefined, remarks: draft.remarks.trim() });
    try {
      onSaved(await apiWrite<MyPlacement>('PUT', '/api/placement', body, csrf));
    } catch (failure) {
      setError({ field: failure instanceof ApiFailure ? failure.field : null, text: errorText(failure) });
    } finally {
      setBusy(false);
    }
  }

  const bad = (field: string) => (error?.field === field ? true : undefined);
  const field = (id: keyof Draft, label: string, props: Partial<FieldProps> = {}) => (
    <Field id={`pl-${id}`} label={label} value={String(draft[id])} onChange={set(id) as (v: string) => void} invalid={bad(id)} {...props} />
  );

  return (
    <form className="stack pl-form" onSubmit={save} noValidate>
      <Group icon={<Building2 size={17} aria-hidden="true" />} title="Company and address">
        <div className="grid-2">
          {field('company', 'Company', { autoComplete: 'organization' })}
          <div className="field">
            <label htmlFor="pl-nature">Nature of business</label>
            <select id="pl-nature" className="select" required value={draft.business_nature} aria-invalid={bad('business_nature')} onChange={(e) => set('business_nature')(e.target.value)}>
              <option value="" disabled>Choose one</option>
              {businessNatures.map((n) => <option key={n} value={n}>{n}</option>)}
            </select>
          </div>
        </div>
        {field('company_address_1', 'Address line 1', { autoComplete: 'address-line1' })}
        <div className="grid-2">
          {field('company_address_2', 'Address line 2 (optional)', { required: false, autoComplete: 'address-line2' })}
          {field('company_address_3', 'Address line 3 (optional)', { required: false, autoComplete: 'address-line3' })}
        </div>
        <div className="pl-grid-3">
          {field('company_postcode', 'Postcode', { autoComplete: 'postal-code', inputMode: 'numeric' })}
          {field('company_state', 'State', { list: 'pl-states', autoComplete: 'address-level1' })}
          {field('company_country', 'Country', { autoComplete: 'country-name' })}
        </div>
        <datalist id="pl-states">{MALAYSIAN_STATES.map((s) => <option key={s} value={s} />)}</datalist>
      </Group>

      <Group icon={<CalendarRange size={17} aria-hidden="true" />} title="Dates, batch and allowance">
        <div className="pl-grid-4">
          <Field id="pl-start_date" label="Starts" type="date" value={draft.start_date} onChange={setStart} invalid={bad('start_date')} />
          {field('end_date', 'Ends', { type: 'date' })}
          <div className="field">
            <label htmlFor="pl-batch-month">Batch month</label>
            <select id="pl-batch-month" className="select" required value={draft.batch_month} aria-invalid={bad('batch_month')} onChange={(e) => set('batch_month')(e.target.value)}>
              <option value="" disabled>Month</option>
              {Array.from({ length: 12 }, (_, i) => <option key={i + 1} value={i + 1}>{monthName(i + 1)}</option>)}
            </select>
          </div>
          {field('batch_year', 'Batch year', { type: 'number', inputMode: 'numeric', min: 2000, max: 2100 })}
        </div>
        {field('monthly_allowance_rm', 'Monthly allowance (RM)', {
          type: 'number', inputMode: 'decimal', min: 0, step: '0.01', required: false, placeholder: '0',
          hint: 'Leave empty or 0 if the internship is unpaid.',
        })}
        <div className="field">
          <label htmlFor="pl-roles">Roles and responsibilities</label>
          <textarea id="pl-roles" className="textarea" rows={4} maxLength={5000} required value={draft.roles_responsibilities} aria-invalid={bad('roles_responsibilities')} onChange={(e) => set('roles_responsibilities')(e.target.value)} placeholder="What you do there, in a few lines." />
        </div>
      </Group>

      <Group icon={<Contact size={17} aria-hidden="true" />} title="HR contact">
        <label className="check">
          <input type="checkbox" checked={draft.no_hr} onChange={(e) => set('no_hr')(e.target.checked)} />
          <span>No HR personnel at this company</span>
        </label>
        {!draft.no_hr && (
          <div className="pl-grid-3">
            {field('hr_name', "HR person's name")}
            {field('hr_phone', 'HR phone', { type: 'tel', autoComplete: 'off' })}
            {field('hr_email', 'HR email', { type: 'email' })}
          </div>
        )}
      </Group>

      <Group icon={<UserRoundCheck size={17} aria-hidden="true" />} title="Industry supervisor">
        <div className="pl-grid-3">
          {field('supervisor_name', "Supervisor's name")}
          {field('supervisor_email', "Supervisor's work email", { type: 'email', hint: 'Each logbook week goes here as a link. No account needed.' })}
          {field('supervisor_phone', "Supervisor's phone", { type: 'tel' })}
        </div>
        {emailChanged && (
          <Notice tone="warn">Links already sent to the old address stop working. Send those weeks again after saving.</Notice>
        )}
      </Group>

      {submitted && (
        <Group title="Why are you changing it?">
          <div className="grid-2">
            <div className="field">
              <label htmlFor="pl-reason">Reason</label>
              <select id="pl-reason" className="select" required value={draft.change_reason} aria-invalid={bad('change_reason')} onChange={(e) => set('change_reason')(e.target.value as ChangeReason)}>
                <option value="" disabled>Choose a reason</option>
                {Object.entries(REASON_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </div>
            {field('remarks', 'Remarks (optional)', { required: false, maxLength: 1000 })}
          </div>
          <p className="hint">CPDC sees what changed, the reason and the date.</p>
        </Group>
      )}

      {error && <Notice tone="error">{error.text}</Notice>}
      <div className="row">
        <button type="submit" className="btn btn-primary" disabled={busy}>
          {busy && <Spinner />} {submitted ? 'Save changes' : 'Submit placement'}
        </button>
        {onCancel && (
          <button type="button" className="btn btn-quiet" onClick={onCancel}>
            Cancel
          </button>
        )}
      </div>
    </form>
  );
}

function Group({ icon, title, children }: { icon?: ReactNode; title: string; children: ReactNode }) {
  return (
    <fieldset className="pl-group">
      <legend>
        {icon}
        {title}
      </legend>
      {children}
    </fieldset>
  );
}

interface FieldProps {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  invalid?: true;
  type?: string;
  hint?: string;
  required?: boolean;
  autoComplete?: string;
  inputMode?: 'numeric' | 'decimal' | 'text';
  list?: string;
  min?: number;
  max?: number;
  step?: string;
  maxLength?: number;
  placeholder?: string;
}

function Field({ id, label, value, onChange, invalid, type = 'text', hint, required = true, autoComplete, ...rest }: FieldProps) {
  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      <input
        id={id}
        className="input"
        type={type}
        value={value}
        required={required}
        aria-invalid={invalid}
        autoComplete={autoComplete ?? 'off'}
        aria-describedby={hint ? `${id}-hint` : undefined}
        onChange={(e) => onChange(e.target.value)}
        {...rest}
      />
      {hint && <span id={`${id}-hint`} className="hint">{hint}</span>}
    </div>
  );
}
