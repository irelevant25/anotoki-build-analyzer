import { chosenMoment, formatClock, formatDatePattern } from './date-patterns';

/** The page's own way of a test: easy to tell from any of the account's. */
const OWN = { day: () => 'own day', time: () => 'own time' };

describe("the account's date orders and clocks (the anotoki family's, as the IAM writes them)", () => {
  // 6 October 2026, 14:30 and 9:05 in the reader's time zone.
  const afternoon = new Date(2026, 9, 6, 14, 30);
  const morning = new Date(2026, 9, 6, 9, 5);

  it('writes a day in each of the four orders', () => {
    expect(formatDatePattern(afternoon, 'dmy_dot')).toBe('6.10.2026');
    expect(formatDatePattern(afternoon, 'dmy_slash')).toBe('06/10/2026');
    expect(formatDatePattern(afternoon, 'mdy_slash')).toBe('10/06/2026');
    expect(formatDatePattern(afternoon, 'ymd_dash')).toBe('2026-10-06');
    expect(formatDatePattern(new Date(2026, 0, 31), 'dmy_dot')).toBe('31.1.2026');
  });

  it('writes a calendar day as that day wherever the reader is', () => {
    const day = new Date(Date.UTC(2026, 8, 29));
    expect(formatDatePattern(day, 'ymd_dash', true)).toBe('2026-09-29');
    expect(formatDatePattern(day, 'dmy_dot', true)).toBe('29.9.2026');
    expect(formatDatePattern(day, 'mdy_slash', true)).toBe('09/29/2026');
  });

  it('writes the time on a 24-hour clock with two digits, and on a 12-hour one in the language’s words for am and pm', () => {
    expect(formatClock(afternoon, 'en-GB', '24')).toBe('14:30');
    expect(formatClock(morning, 'en-GB', '24')).toBe('09:05');
    expect(formatClock(morning, 'sk-SK', '24')).toBe('09:05');
    expect(formatClock(afternoon, 'en-GB', '12')).toMatch(/^2:30\s?pm$/i);
    expect(formatClock(morning, 'en-GB', '12')).toMatch(/^9:05\s?am$/i);
    expect(formatClock(afternoon, 'sk-SK', '12')).toMatch(/^2:30\s?PM$/i);
  });

  it('writes a moment as the day and its time, each the account’s where chosen and the page’s own where not', () => {
    expect(
      chosenMoment(afternoon, { date_format: 'ymd_dash', time_format: '24' }, 'en-GB', OWN),
    ).toBe('2026-10-06 14:30');
    expect(
      chosenMoment(afternoon, { date_format: 'dmy_dot', time_format: null }, 'en-GB', OWN),
    ).toBe('6.10.2026 own time');
    expect(chosenMoment(afternoon, { date_format: null, time_format: '12' }, 'en-GB', OWN)).toMatch(
      /^own day 2:30\s?pm$/i,
    );
  });

  it('leaves the whole moment to the page when nothing is chosen, or there is no account to ask', () => {
    expect(
      chosenMoment(afternoon, { date_format: null, time_format: null }, 'en-GB', OWN),
    ).toBeNull();
    expect(chosenMoment(afternoon, null, 'en-GB', OWN)).toBeNull();
  });
});
