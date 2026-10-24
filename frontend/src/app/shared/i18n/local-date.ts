import { Pipe, PipeTransform, inject } from '@angular/core';
import { AuthService } from '../auth';
import { formatDatePattern } from './date-patterns';
import { TranslationService } from './translation';

/** How long ago, or a day in full. */
export type LocalDateStyle = 'ago' | 'day';

/** timeAgo()'s own steps (shared/format.ts): how many of a unit make the next one. */
const STEPS: readonly [size: number, unit: Intl.RelativeTimeFormatUnit][] = [
  [60, 'second'],
  [60, 'minute'],
  [24, 'hour'],
  [30, 'day'],
  [12, 'month'],
  [Number.POSITIVE_INFINITY, 'year'],
];

const DAY_FORMATS = new Map<string, Intl.DateTimeFormat>();
const RELATIVE_FORMATS = new Map<string, Intl.RelativeTimeFormat>();

/**
 * `{{ iso | localDate: 'ago' }}`: a time from the API, written the way the language on the page writes it,
 * through `Intl`.
 *
 *   'ago'   "3 hours ago" / "pred 3 hodinami", "in 2 days" / "o 2 dni" - with the thresholds of timeAgo()
 *           (seconds up to years, rounded down, never less than 1), which stays for the English pages.
 *           A lower-case phrase that stands inside a sentence as `{when}`.
 *   'day'   "29 September 2026" / "29. septembra 2026" - the What's-new dates. A calendar day
 *           ("2026-09-29") is that day wherever the reader is; a moment is the day it falls on here.
 *           For a signed-in person whose account has a date order (every anotoki site writes dates in it):
 *           that order - "2026-09-29", "29.9.2026" (shared/i18n/date-patterns.ts).
 *
 * 'ago' has neither a date order nor a clock in it, so it reads the same for everybody.
 *
 * A missing time is an empty string, never "never": the caller shows a key of its own instead
 * (`@if (at) { … } @else { … }`). What is not a date comes back as it came.
 *
 * Impure, like the translate pipe: the language - and the account's date order - change under the same value.
 */
@Pipe({ name: 'localDate', pure: false })
export class LocalDatePipe implements PipeTransform {
  private readonly _i18n = inject(TranslationService);
  private readonly _auth = inject(AuthService);

  transform(value: string | null | undefined, style: LocalDateStyle = 'ago'): string {
    if (!value) {
      return '';
    }
    const locale = this._i18n.locale();
    if (style === 'day') {
      // Null: nobody is signed in, the account left it at "automatic", or the IAM keeps no preferences.
      const order = this._auth.preferences()?.date_format ?? null;
      const calendar = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
      if (!calendar) {
        const moment = new Date(value);
        if (Number.isNaN(moment.getTime())) {
          return value;
        }
        return order ? formatDatePattern(moment, order) : dayFormat(locale, false).format(moment);
      }
      const [year, month, day] = [Number(calendar[1]), Number(calendar[2]), Number(calendar[3])];
      const date = new Date(Date.UTC(year, month - 1, day));
      // A day the calendar does not have ("2026-13-45") would roll over into another one: it is not a date.
      const real = date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
      if (!real) {
        return value;
      }
      return order ? formatDatePattern(date, order, true) : dayFormat(locale, true).format(date);
    }
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : ago(date, locale);
  }
}

function ago(date: Date, locale: string): string {
  const seconds = Math.round((Date.now() - date.getTime()) / 1000);
  let value = Math.abs(seconds);
  for (const [size, unit] of STEPS) {
    if (value < size) {
      const rounded = Math.max(1, Math.floor(value));
      return relativeFormat(locale).format(seconds >= 0 ? -rounded : rounded, unit);
    }
    value /= size;
  }
  return '';
}

/** A calendar day is written as that day (UTC); a moment as the day it is in the reader's time zone. */
function dayFormat(locale: string, calendar: boolean): Intl.DateTimeFormat {
  const key = `${locale}|${calendar}`;
  let format = DAY_FORMATS.get(key);
  if (!format) {
    format = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', year: 'numeric', ...(calendar ? { timeZone: 'UTC' } : {}) });
    DAY_FORMATS.set(key, format);
  }
  return format;
}

function relativeFormat(locale: string): Intl.RelativeTimeFormat {
  let format = RELATIVE_FORMATS.get(locale);
  if (!format) {
    // "1 day ago", not "yesterday": the number is what timeAgo() says, and a day here is 24 hours.
    format = new Intl.RelativeTimeFormat(locale, { numeric: 'always' });
    RELATIVE_FORMATS.set(locale, format);
  }
  return format;
}
