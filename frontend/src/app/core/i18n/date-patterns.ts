import { AnotokiDateFormat, AnotokiPreferences, AnotokiTimeFormat } from '@anotoki/lib/auth';

/**
 * The account's date order and clock (AuthService.preferences(), the IAM's migration 015) - the anotoki
 * family's one vocabulary, the same as the IAM's own pages (anotoki-iam's core/i18n/date-patterns.ts): a date
 * in a fixed order of numbers, and the clock with or without am/pm. A part the account leaves at null
 * ("automatic") is the page's own way of writing it, which the callers keep - so for somebody who chose
 * nothing, and for everybody not signed in, every date reads as it always has.
 */

/** The parts of the account's preferences that say how a date and a time are written. */
export type DateChoices = Pick<AnotokiPreferences, 'date_format' | 'time_format'>;

/**
 * A date in one of the fixed orders: 6.10.2026, 06/10/2026, 10/06/2026, 2026-10-06. The day it is in the
 * reader's time zone - or, with `utc`, a calendar day ("2026-09-29" from the changelog), which is that day
 * wherever the reader is.
 */
export function formatDatePattern(date: Date, format: AnotokiDateFormat, utc = false): string {
  const day = utc ? date.getUTCDate() : date.getDate();
  const month = (utc ? date.getUTCMonth() : date.getMonth()) + 1;
  const year = utc ? date.getUTCFullYear() : date.getFullYear();
  const pad = (value: number): string => String(value).padStart(2, '0');

  switch (format) {
    case 'dmy_dot':
      return `${day}.${month}.${year}`;
    case 'dmy_slash':
      return `${pad(day)}/${pad(month)}/${year}`;
    case 'mdy_slash':
      return `${pad(month)}/${pad(day)}/${year}`;
    case 'ymd_dash':
      return `${year}-${pad(month)}-${pad(day)}`;
  }
}

const CLOCKS = new Map<string, Intl.DateTimeFormat>();

/** The time of day on the chosen clock, in the page language's words for am and pm: 14:30, or 2:30 pm. */
export function formatClock(date: Date, locale: string, clock: AnotokiTimeFormat): string {
  const key = `${locale}|${clock}`;
  let format = CLOCKS.get(key);
  if (!format) {
    format = new Intl.DateTimeFormat(locale, clock === '12' ? { hour: 'numeric', minute: '2-digit', hourCycle: 'h12' } : { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
    CLOCKS.set(key, format);
  }
  return format.format(date);
}

/** How a page writes a day and a time of day itself: the part the account leaves to it. */
export interface OwnDateParts {
  day(date: Date): string;
  time(date: Date): string;
}

/**
 * A moment - a day and its time - with the account's date order and clock where it has chosen them:
 * `${day} ${time}`, the part it left at "automatic" written the page's own way (`own`). Null when it has
 * chosen neither, or there is no account to ask (`chosen` null): the caller then writes the whole moment its
 * own way, exactly as before.
 */
export function chosenMoment(date: Date, chosen: DateChoices | null, locale: string, own: OwnDateParts): string | null {
  if (!chosen || (!chosen.date_format && !chosen.time_format)) {
    return null;
  }
  const day = chosen.date_format ? formatDatePattern(date, chosen.date_format) : own.day(date);
  const time = chosen.time_format ? formatClock(date, locale, chosen.time_format) : own.time(date);
  return `${day} ${time}`;
}
