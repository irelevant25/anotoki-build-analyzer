import { gradeColor, scoreColor, titleCase } from './format';

describe('format helpers', () => {
  it('maps grades and scores to theme colors', () => {
    expect(gradeColor('S')).toBe('var(--grade-s)');
    expect(gradeColor('D')).toBe('var(--grade-d)');
    expect(gradeColor('-')).toBe('var(--anotoki-muted)');
    // A bar's three colours, cut where the grades are: green from 80, gold from 50, red below.
    expect([scoreColor(95), scoreColor(80)]).toEqual(['var(--bar-high)', 'var(--bar-high)']);
    expect([scoreColor(79), scoreColor(55), scoreColor(50)]).toEqual(['var(--bar-mid)', 'var(--bar-mid)', 'var(--bar-mid)']);
    expect([scoreColor(49), scoreColor(0)]).toEqual(['var(--bar-low)', 'var(--bar-low)']);
    expect(scoreColor(null)).toBe('var(--anotoki-muted)');
  });

  it('title-cases element and path names', () => {
    expect(titleCase('pyro')).toBe('Pyro');
    expect(titleCase('the hunt')).toBe('The Hunt');
    expect(titleCase(null)).toBe('');
  });
});
