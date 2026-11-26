import { describe, expect, it } from 'vitest';
import { allowanceLabel, batchLabel, changeValue } from './types';

describe('placement labels', () => {
  it('names a batch by month and year, empty without one', () => {
    expect(batchLabel(1, 2027)).toBe('Jan 2027');
    expect(batchLabel(12, 2026)).toBe('Dec 2026');
    expect(batchLabel(null, 2027)).toBe('');
  });

  it('shows the allowance in ringgit, 0 as unpaid', () => {
    expect(allowanceLabel('1200.00')).toBe('RM 1,200.00');
    expect(allowanceLabel(0)).toBe('Unpaid');
    expect(allowanceLabel(null)).toBe('Unpaid');
  });

  it('shows change log values for people', () => {
    expect(changeValue('has_hr_contact', 0)).toBe('No');
    expect(changeValue('batch_month', 3)).toBe('Mar');
    expect(changeValue('company_address_2', null)).toBe('-');
    expect(changeValue('company', 'Fake Co')).toBe('Fake Co');
  });
});
