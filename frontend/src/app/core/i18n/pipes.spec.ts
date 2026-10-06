import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AuthService } from '../auth';
import { timeAgo } from '../format';
import { AnotokiPreferences } from '../iam/anotoki-auth';
import { LocalDatePipe } from './local-date';
import { provideTestTranslations } from './testing';
import { TranslatePluralPipe } from './translate-plural';
import { TranslatePipe } from './translate';
import { TranslationService } from './translation';

const ENGLISH = {
  'signIn.failed': 'The sign-in did not complete: {code}',
  'theme.label': 'Theme',
  'buildDetail.optionAny.one': '(any {count})',
  'buildDetail.optionAny.few': '(any {count})',
  'buildDetail.optionAny.other': '(any {count})',
  'recommendation.sets_missing_empty': '{set_label}: use the recommended {best}. Nothing is equipped now.',
};
const SLOVAK = {
  'signIn.failed': 'Prihlásenie sa nedokončilo: {code}',
  'theme.label': 'Vzhľad',
  'buildDetail.optionAny.one': '(ľubovoľný 1)',
  'buildDetail.optionAny.few': '(ľubovoľné {count})',
  'buildDetail.optionAny.other': '(ľubovoľných {count})',
  'recommendation.sets_missing_empty': '{set_label}: odporúča sa {best}. Teraz nemáš nasadené nič.',
};

const SECOND = 1000;
const MINUTE = 60 * SECOND;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

/**
 * The pipes in a language - and, given `account`, for somebody signed in whose account says how dates are
 * written (AuthService.preferences()).
 */
function pipes(language: 'en' | 'sk', account?: Partial<AnotokiPreferences>) {
  TestBed.resetTestingModule();
  const preferences: AnotokiPreferences = { theme: 'auto', date_format: null, time_format: null, ...account };
  TestBed.configureTestingModule({
    providers: [
      language === 'sk' ? provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH }) : provideTestTranslations(ENGLISH),
      TranslatePipe,
      TranslatePluralPipe,
      LocalDatePipe,
      ...(account ? [{ provide: AuthService, useValue: { user: signal({ sub: '7', preferences }), preferences: signal(preferences) } }] : []),
    ],
  });
  return {
    i18n: TestBed.inject(TranslationService),
    translate: TestBed.inject(TranslatePipe),
    plural: TestBed.inject(TranslatePluralPipe),
    date: TestBed.inject(LocalDatePipe),
  };
}

describe('the translate pipes', () => {
  it('word a key, and fill its placeholders', () => {
    expect(pipes('en').translate.transform('signIn.failed', { code: 'invalid_grant' })).toBe('The sign-in did not complete: invalid_grant');
    expect(pipes('sk').translate.transform('signIn.failed', { code: 'invalid_grant' })).toBe('Prihlásenie sa nedokončilo: invalid_grant');
  });

  it('pick the plural form, and a sentence takes the whole phrase as a placeholder', () => {
    const { translate, plural } = pipes('sk');
    expect(plural.transform('buildDetail.optionAny', 1)).toBe('(ľubovoľný 1)');
    expect(plural.transform('buildDetail.optionAny', 4)).toBe('(ľubovoľné 4)');
    expect(plural.transform('buildDetail.optionAny', 5)).toBe('(ľubovoľných 5)');
    // The game's own words go in as they are; the phrase beside them is the site's.
    expect(translate.transform('recommendation.sets_missing_empty', { set_label: 'Artifact set', best: `2pc A + 2pc B ${plural.transform('buildDetail.optionAny', 2)}` })).toBe(
      'Artifact set: odporúča sa 2pc A + 2pc B (ľubovoľné 2). Teraz nemáš nasadené nič.',
    );
  });

  it('answer again when the language on the page changes under the same key', async () => {
    const { i18n, translate, plural } = pipes('sk');
    expect(translate.transform('theme.label')).toBe('Vzhľad');
    i18n.noteAddress('/admin/sources');
    expect(translate.transform('theme.label')).toBe('Theme');
    expect(plural.transform('buildDetail.optionAny', 3)).toBe('(any 3)');
    i18n.noteAddress('/');
    expect(translate.transform('theme.label')).toBe('Vzhľad');
    expect(await i18n.setLanguage('en', false)).toBe(true);
    expect(translate.transform('theme.label')).toBe('Theme');
  });
});

describe('the localDate pipe', () => {
  const NOW = new Date(2026, 8, 30, 10, 0, 0).getTime();

  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(NOW);
  });

  afterEach(() => vi.useRealTimers());

  function ago(language: 'en' | 'sk', milliseconds: number): string {
    return pipes(language).date.transform(new Date(NOW - milliseconds).toISOString(), 'ago');
  }

  it('says how long ago in English exactly as timeAgo() does - the same thresholds, from seconds to years', () => {
    const { date } = pipes('en');
    const offsets = [
      0,
      400,
      SECOND,
      30 * SECOND,
      59 * SECOND,
      MINUTE,
      90 * SECOND,
      59 * MINUTE + 59 * SECOND,
      HOUR,
      3 * HOUR + 5 * SECOND,
      23 * HOUR + 59 * MINUTE,
      DAY,
      13 * DAY,
      29 * DAY + 23 * HOUR,
      30 * DAY,
      45 * DAY,
      359 * DAY,
      360 * DAY,
      800 * DAY,
      // In the future too: a clock that runs behind, a time still to come.
      -30 * SECOND,
      -2 * HOUR,
      -3 * DAY,
    ];
    for (const offset of offsets) {
      const iso = new Date(NOW - offset).toISOString();
      expect(date.transform(iso, 'ago'), String(offset)).toBe(timeAgo(iso));
    }
    expect(ago('en', 0)).toBe('1 second ago');
    expect(ago('en', 30 * SECOND)).toBe('30 seconds ago');
    expect(ago('en', 3 * HOUR + 5 * SECOND)).toBe('3 hours ago');
    expect(ago('en', DAY)).toBe('1 day ago');
    expect(ago('en', 30 * DAY)).toBe('1 month ago');
    expect(ago('en', 800 * DAY)).toBe('2 years ago');
    expect(ago('en', -2 * HOUR)).toBe('in 2 hours');
    // 'ago' is what the pipe does when nothing else is asked for.
    expect(date.transform(new Date(NOW - 5 * MINUTE).toISOString())).toBe('5 minutes ago');
  });

  it('says how long ago in Slovak, in phrases that stand inside a sentence', () => {
    expect(ago('sk', 10 * SECOND)).toBe('pred 10 sekundami');
    expect(ago('sk', 5 * MINUTE)).toBe('pred 5 minútami');
    expect(ago('sk', 3 * HOUR)).toBe('pred 3 hodinami');
    expect(ago('sk', DAY)).toBe('pred 1 dňom');
    expect(ago('sk', 5 * DAY)).toBe('pred 5 dňami');
    expect(ago('sk', 60 * DAY)).toBe('pred 2 mesiacmi');
    expect(ago('sk', 800 * DAY)).toBe('pred 2 rokmi');
    expect(ago('sk', -2 * HOUR)).toBe('o 2 hodiny');
  });

  it('writes a calendar day in full, as that day wherever the reader is', () => {
    expect(pipes('en').date.transform('2026-09-29', 'day')).toBe('29 September 2026');
    expect(pipes('en').date.transform('2026-01-01', 'day')).toBe('1 January 2026');
    // The genitive, as Slovak writes a date.
    expect(pipes('sk').date.transform('2026-09-29', 'day')).toBe('29. septembra 2026');
    expect(pipes('sk').date.transform('2026-01-01', 'day')).toBe('1. januára 2026');
    // A moment is the day it falls on here.
    expect(pipes('en').date.transform(new Date(2026, 9, 20, 12).toISOString(), 'day')).toBe('20 October 2026');
  });

  it('gives nothing for a missing time - never "never" - and what is not a date back as it came', () => {
    const { date } = pipes('en');
    expect(date.transform(null, 'ago')).toBe('');
    expect(date.transform(undefined, 'ago')).toBe('');
    expect(date.transform('', 'day')).toBe('');
    expect(date.transform('soon', 'ago')).toBe('soon');
    expect(date.transform('soon', 'day')).toBe('soon');
    expect(date.transform('2026-13-45', 'day')).toBe('2026-13-45');
  });

  it('is English on the English pages', () => {
    const { i18n, date } = pipes('sk');
    i18n.noteAddress('/admin/sources');
    expect(date.transform('2026-09-29', 'day')).toBe('29 September 2026');
    expect(date.transform(new Date(NOW - 3 * HOUR).toISOString(), 'ago')).toBe('3 hours ago');
  });

  it("writes a day in the account's date order for somebody signed in who has chosen one - in every language", () => {
    expect(pipes('en', { date_format: 'ymd_dash' }).date.transform('2026-09-29', 'day')).toBe('2026-09-29');
    expect(pipes('sk', { date_format: 'dmy_dot' }).date.transform('2026-09-29', 'day')).toBe('29.9.2026');
    expect(pipes('sk', { date_format: 'mdy_slash' }).date.transform('2026-01-05', 'day')).toBe('01/05/2026');
    // A moment is the day it falls on here, in that order too.
    expect(pipes('en', { date_format: 'dmy_slash' }).date.transform(new Date(2026, 9, 20, 12).toISOString(), 'day')).toBe('20/10/2026');
    // What is not a date still comes back as it came.
    expect(pipes('en', { date_format: 'ymd_dash' }).date.transform('2026-13-45', 'day')).toBe('2026-13-45');
  });

  it("writes days the page's own way while the account leaves them at automatic; 'ago' is the same for everybody", () => {
    expect(pipes('sk', { date_format: null, time_format: '12' }).date.transform('2026-09-29', 'day')).toBe('29. septembra 2026');
    const { date } = pipes('sk', { date_format: 'ymd_dash', time_format: '24' });
    expect(date.transform(new Date(NOW - 3 * HOUR).toISOString(), 'ago')).toBe('pred 3 hodinami');
  });
});
