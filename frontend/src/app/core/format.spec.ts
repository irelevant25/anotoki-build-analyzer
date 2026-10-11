import { gradeColor, scoreColor, timeAgo, titleCase } from './format';

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

  it('formats relative times', () => {
    const threeHoursAgo = new Date(Date.now() - 3 * 3600 * 1000 - 5000).toISOString();
    expect(timeAgo(threeHoursAgo)).toBe('3 hours ago');
    expect(timeAgo(new Date(Date.now() - 30 * 1000).toISOString())).toBe('30 seconds ago');
    expect(timeAgo(null)).toBe('never');
  });
});
