import { describe, expect, it } from 'vitest';
import { filterQuery, percent, ringgit } from './types';

describe('analytics formatting', () => {
  it('shows rates as whole percentages and a dash for nothing counted', () => {
    expect(percent(null)).toBe('-');
    expect(percent(0)).toBe('0%');
    expect(percent(0.667)).toBe('67%');
    expect(percent(1)).toBe('100%');
  });

  it('shows ringgit with grouping and no needless decimals', () => {
    expect(ringgit(null)).toBe('-');
    expect(ringgit('900.00')).toBe('RM 900');
    expect(ringgit(1250.5)).toBe('RM 1,250.5');
  });

  it('drops empty filters from the query', () => {
    expect(filterQuery({ faculty: 'Faculty of Fake Studies', role: '', cohort: '2025' }, { report: 'outcomes' }))
      .toBe('faculty=Faculty+of+Fake+Studies&cohort=2025&report=outcomes');
    expect(filterQuery({ faculty: '' })).toBe('');
  });
});
