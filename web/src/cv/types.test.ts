import { describe, expect, it } from 'vitest';
import { cvErrors, cvProblem, toCvBody, withDefaults } from './types';

const base = withDefaults({ contact: { email: '', phone: '', location: '', website: '' } });

describe('CV rules', () => {
  it('fills the parts an older saved CV does not have', () => {
    expect(base.awards).toEqual([]);
    expect(base.academic).toEqual({ show_cgpa: false, cgpa: '', intake_period: '' });
    expect(base.employer_sharing_consent).toBe(false);
  });

  it('marks each wrong field by its id', () => {
    const errors = cvErrors({
      ...base,
      contact: { ...base.contact, website: 'http://x', phone: 'call me' },
      academic: { show_cgpa: true, cgpa: '3.5', intake_period: '' },
      awards: [{ kind: 'award', title: ' ', issuer: '', period: '' }],
      references: [{ name: '', designation: '', company: '', phone: '', email: 'nope' }],
    });
    expect(Object.keys(errors).sort()).toEqual(['cv-aw-0', 'cv-cgpa', 'cv-phone', 'cv-re-0', 'cv-rn-0', 'cv-site']);
    expect(cvProblem(base)).toBeNull();
  });

  it('accepts a CGPA with two decimals', () => {
    expect(cvErrors({ ...base, academic: { show_cgpa: true, cgpa: '3.75', intake_period: '' } })).toEqual({});
  });

  it('sends awards without an empty learning link', () => {
    const body = toCvBody({ ...base, awards: [{ kind: 'certificate', title: 'A', issuer: '', period: '', learning_id: 4 }, { kind: 'award', title: 'B', issuer: '', period: '' }] });
    expect(body.awards[0].learning_id).toBe(4);
    expect('learning_id' in body.awards[1]).toBe(false);
  });
});
