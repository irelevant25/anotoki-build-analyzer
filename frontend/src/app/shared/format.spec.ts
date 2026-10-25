import { gradeColor, scoreColor, titleCase } from './format';

describe('format helpers', () => {
  it('maps grades and scores to theme colors', () => {
    expect(gradeColor('S')).toBe('var(--grade-s)');
    expect(gradeColor('D')).toBe('var(--grade-d)');
    expect(gradeColor('-')).toBe('var(--anotoki-muted)');
    expect(scoreColor(95)).toBe('var(--grade-s)');
    expect(scoreColor(55)).toBe('var(--grade-c)');
    expect(scoreColor(null)).toBe('var(--anotoki-muted)');
  });

  it('title-cases element and path names', () => {
    expect(titleCase('pyro')).toBe('Pyro');
    expect(titleCase('the hunt')).toBe('The Hunt');
    expect(titleCase(null)).toBe('');
  });
});
