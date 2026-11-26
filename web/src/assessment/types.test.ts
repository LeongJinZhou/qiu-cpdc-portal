import { describe, expect, it } from 'vitest';
import { editorProblem, toSaveBody, traitName } from './types';

const good = {
  title: 'Fake readiness',
  description: '',
  answer_style: 'choice' as const,
  reflection_prompts: [] as string[],
  bands: [{ min_percent: 0, label: 'Start' }, { min_percent: 70, label: 'Ready' }],
  questions: [{ id: 3, prompt: 'Ready?', options: [{ id: 9, label: 'No', points: 0 }, { label: 'Yes', points: 2 }] }],
};

describe('assessment editor rules', () => {
  it('accepts a complete assessment', () => {
    expect(editorProblem(good)).toBeNull();
  });

  it('names the first problem the server would refuse', () => {
    expect(editorProblem({ ...good, bands: [{ min_percent: 10, label: 'A' }] })).toBe('One result band must start at 0%.');
    expect(editorProblem({ ...good, bands: [{ min_percent: 0, label: 'A' }, { min_percent: 0, label: 'B' }] })).toMatch(/same score/);
    expect(editorProblem({ ...good, questions: [] })).toBe('Add at least one question.');
    expect(editorProblem({ ...good, questions: [{ prompt: 'Ready?', options: [{ label: 'Only', points: 1 }] }] })).toMatch(/two answers/);
    expect(editorProblem({ ...good, reflection_prompts: ['Why?', ' Why? '] })).toMatch(/same/);
  });

  it('a rating assessment needs no answers of its own', () => {
    const likert = { ...good, answer_style: 'likert' as const, questions: [{ prompt: 'Resilience', options: [] }] };
    expect(editorProblem(likert)).toBeNull();
    expect(toSaveBody(likert).questions[0].options).toHaveLength(2);
  });

  it('sends no ids and leaves out blank reflection prompts', () => {
    const body = toSaveBody({ ...good, reflection_prompts: [' Next? ', '  '] });
    expect(JSON.stringify(body)).not.toContain('"id"');
    expect(body.reflection_prompts).toEqual(['Next?']);
  });

  it('shortens a characteristics prompt to its trait name', () => {
    expect(traitName('Resilience: I keep going.')).toBe('Resilience');
    expect(traitName('Do you research the employer first?')).toBe('Do you research the employer first?');
  });
});
