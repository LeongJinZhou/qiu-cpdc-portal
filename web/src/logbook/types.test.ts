import { describe, expect, it } from 'vitest';
import { dayLabel, mondayOf, openWeeks, type Internship } from './types';

const internship: Internship = {
  id: 1,
  company: 'Fake Co',
  supervisor_name: 'Fake Supervisor',
  supervisor_email: 'boss@company.example',
  start_date: '2027-01-06',
  end_date: '2027-02-05',
  placement_submitted_at: null,
};

describe('logbook weeks', () => {
  it('finds the Monday of any day', () => {
    expect(mondayOf('2027-01-04')).toBe('2027-01-04');
    expect(mondayOf('2027-01-10')).toBe('2027-01-04');
    expect(mondayOf('2027-01-01')).toBe('2026-12-28');
  });

  it('offers started weeks newest first, without logged ones', () => {
    expect(openWeeks(internship, '2027-01-15', [])).toEqual(['2027-01-11', '2027-01-04']);
    expect(openWeeks(internship, '2027-01-15', ['2027-01-11'])).toEqual(['2027-01-04']);
    expect(openWeeks(internship, '2027-01-15', ['2027-01-11'], '2027-01-11')).toEqual(['2027-01-11', '2027-01-04']);
  });

  it('stops at the end of the internship and offers nothing before it starts', () => {
    expect(openWeeks(internship, '2027-06-01', [])).toHaveLength(5);
    expect(openWeeks(internship, '2026-12-01', [])).toEqual([]);
  });

  it('writes dates without a time zone shift', () => {
    expect(dayLabel('2027-01-04')).toBe('4 Jan 2027');
  });
});
