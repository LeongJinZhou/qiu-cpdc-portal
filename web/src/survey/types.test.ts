import { describe, expect, it } from 'vitest';
import { itemKeys, meanText, missingIn, submitProblem, toBody, type Draft, type SurveyFormDef } from './types';

const form: SurveyFormDef = {
  sections: [
    { key: 'programme', title: 'Programme', intro: '', scale: 'agree', na: false, items: [{ key: 'pc1', text: 'A' }, { key: 'pc2', text: 'B' }] },
    { key: 'support', title: 'Support', intro: '', scale: 'quality', na: true, items: [{ key: 'ss_hostel', text: 'Hostel' }] },
  ],
  scales: { agree: ['1', '2', '3', '4', '5'], quality: ['1', '2', '3', '4', '5'] },
  na: 'na',
  comment_max: 2000,
};
const full: Draft = { programme: 'Fake', semester: 2, answers: { pc1: 4, pc2: 5, ss_hostel: 'na' }, comments: {} };

describe('survey rules', () => {
  it('lists item keys in form order', () => {
    expect(itemKeys(form)).toEqual(['pc1', 'pc2', 'ss_hostel']);
  });

  it('counts N/A as answered', () => {
    expect(missingIn(form.sections[1], { ss_hostel: 'na' })).toEqual([]);
    expect(missingIn(form.sections[0], { pc2: 3 })).toEqual(['pc1']);
  });

  it('names the first thing that stops a submit, with its step', () => {
    expect(submitProblem(form, full)).toBeNull();
    expect(submitProblem(form, { ...full, programme: ' ' })).toEqual({ step: 0, text: 'Enter your programme.' });
    expect(submitProblem(form, { ...full, semester: null })?.step).toBe(0);
    expect(submitProblem(form, { ...full, answers: { pc1: 4, pc2: 5 } })).toEqual({
      step: 2,
      text: 'Support: 1 item not answered yet. Pick N/A for any you have not used.',
    });
  });

  it('sends only comments with text', () => {
    expect(toBody({ ...full, comments: { programme: 'More labs', support: '  ' } }, true)).toEqual({
      programme: 'Fake', semester: 2, answers: full.answers, comments: { programme: 'More labs' }, submit: true,
    });
  });

  it('shows means with two decimals or a dash', () => {
    expect(meanText(3.5)).toBe('3.50');
    expect(meanText(null)).toBe('-');
  });
});
