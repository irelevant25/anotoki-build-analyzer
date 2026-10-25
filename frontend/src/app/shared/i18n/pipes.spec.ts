import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { AnotokiAgoPipe, AnotokiDatePipe } from '@anotoki/lib/ui/dates';
import { AuthService } from '../auth';
import { provideKit } from '../kit';
import { AnotokiPreferences } from '@anotoki/lib/auth';
import { TranslatePluralPipe } from './translate-plural';
import { TranslatePipe } from './translate';
import { TranslationService } from './translation';

const ENGLISH = {
  'whatsNew.version': 'Version {version}',
  'whatsNew.title': "What's new",
  'buildDetail.optionAny.one': '(any {count})',
  'buildDetail.optionAny.few': '(any {count})',
  'buildDetail.optionAny.other': '(any {count})',
  'recommendation.sets_missing_empty': '{set_label}: use the recommended {best}. Nothing is equipped now.',
};
const SLOVAK = {
  'whatsNew.version': 'Verzia {version}',
  'whatsNew.title': 'Čo je nové',
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
 * written (AuthService.preferences()). The dates are the family's pipes (@anotoki/lib/ui/dates) as this site sets
 * the kit up (shared/kit.ts: the translations' locale, the account's choices).
 */
function pipes(language: 'en' | 'sk', account?: Partial<AnotokiPreferences>) {
  TestBed.resetTestingModule();
  const preferences: AnotokiPreferences = { theme: 'auto', date_format: null, time_format: null, ...account };
  TestBed.configureTestingModule({
    providers: [
      language === 'sk' ? provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH }) : provideTestTranslations(ENGLISH),
      provideKit(),
      TranslatePipe,
      TranslatePluralPipe,
      AnotokiAgoPipe,
      AnotokiDatePipe,
      account
        ? { provide: AuthService, useValue: { user: signal({ sub: '7', preferences }), preferences: signal(preferences) } }
        : { provide: AuthService, useValue: { user: () => null, signedIn: () => false, preferences: () => null } },
    ],
  });
  return {
    i18n: TestBed.inject(TranslationService),
    translate: TestBed.inject(TranslatePipe),
    plural: TestBed.inject(TranslatePluralPipe),
    ago: TestBed.inject(AnotokiAgoPipe),
    date: TestBed.inject(AnotokiDatePipe),
  };
}

/** How long ago, as the member pages ask it: the family's anotokiAgo with no date in place of a count (`{ dateAfter: null }`). */
const AGO = { dateAfter: null } as const;

describe('the translate pipes', () => {
  it('word a key, and fill its placeholders', () => {
    expect(pipes('en').translate.transform('whatsNew.version', { version: '0.4.0' })).toBe('Version 0.4.0');
    expect(pipes('sk').translate.transform('whatsNew.version', { version: '0.4.0' })).toBe('Verzia 0.4.0');
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
    expect(translate.transform('whatsNew.title')).toBe('Čo je nové');
    i18n.noteAddress('/admin/builds');
    expect(translate.transform('whatsNew.title')).toBe("What's new");
    expect(plural.transform('buildDetail.optionAny', 3)).toBe('(any 3)');
    i18n.noteAddress('/');
    expect(translate.transform('whatsNew.title')).toBe('Čo je nové');
    expect(await i18n.setLanguage('en', { remember: false })).toBe(true);
    expect(translate.transform('whatsNew.title')).toBe("What's new");
  });
});

describe("the dates: the family's pipes as this site sets them up (anotokiAgo, anotokiDate)", () => {
  const NOW = new Date(2026, 8, 30, 10, 0, 0).getTime();

  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(NOW);
  });

  afterEach(() => vi.useRealTimers());

  function ago(language: 'en' | 'sk', milliseconds: number): string {
    return pipes(language).ago.transform(new Date(NOW - milliseconds).toISOString(), AGO);
  }

  it('say how long ago in English as the site always did - minutes, hours, days, months, years, never a date in place of a count', () => {
    expect(ago('en', MINUTE)).toBe('1 minute ago');
    expect(ago('en', 5 * MINUTE)).toBe('5 minutes ago');
    expect(ago('en', 3 * HOUR + 5 * SECOND)).toBe('3 hours ago');
    expect(ago('en', 2 * DAY)).toBe('2 days ago');
    expect(ago('en', 13 * DAY)).toBe('13 days ago');
    expect(ago('en', 75 * DAY)).toBe('2 months ago');
    expect(ago('en', 800 * DAY)).toBe('2 years ago');
    expect(ago('en', -2 * HOUR)).toBe('in 2 hours');
    // Where the family's words differ from the site's own (2026-10-25, anotoki-lib 0.11.0): under a minute "just now"
    // (was "30 seconds ago"), the day before "yesterday" (was "1 day ago"), a month and a year "last month", "last year".
    expect(ago('en', 30 * SECOND)).toBe('just now');
    expect(ago('en', DAY)).toBe('yesterday');
    expect(ago('en', 45 * DAY)).toBe('last month');
  });

  it('say how long ago in Slovak, in phrases that stand inside a sentence', () => {
    expect(ago('sk', 5 * MINUTE)).toBe('pred 5 minútami');
    expect(ago('sk', 3 * HOUR)).toBe('pred 3 hodinami');
    expect(ago('sk', 5 * DAY)).toBe('pred 5 dňami');
    expect(ago('sk', 75 * DAY)).toBe('pred 2 mesiacmi');
    expect(ago('sk', 800 * DAY)).toBe('pred 2 rokmi');
    expect(ago('sk', -2 * HOUR)).toBe('o 2 hodiny');
    expect(ago('sk', 10 * SECOND)).toBe('práve teraz');
    expect(ago('sk', DAY)).toBe('včera');
    expect(ago('sk', 2 * DAY)).toBe('predvčerom');
  });

  it('write a calendar day in full, as that day wherever the reader is - as the site always did', () => {
    expect(pipes('en').date.transform('2026-09-29', 'day')).toBe('29 September 2026');
    expect(pipes('en').date.transform('2026-01-01', 'day')).toBe('1 January 2026');
    // The genitive, as Slovak writes a date.
    expect(pipes('sk').date.transform('2026-09-29', 'day')).toBe('29. septembra 2026');
    expect(pipes('sk').date.transform('2026-01-01', 'day')).toBe('1. januára 2026');
    // A moment is the day it falls on here.
    expect(pipes('en').date.transform(new Date(2026, 9, 20, 12).toISOString(), 'day')).toBe('20 October 2026');
  });

  it('give nothing for a missing time - never "never" - and what is not a date back as it came', () => {
    const { ago, date } = pipes('en');
    expect(ago.transform(null, AGO)).toBe('');
    expect(ago.transform(undefined, AGO)).toBe('');
    expect(date.transform('', 'day')).toBe('');
    expect(ago.transform('soon', AGO)).toBe('soon');
    expect(date.transform('soon', 'day')).toBe('soon');
    expect(date.transform('2026-13-45', 'day')).toBe('2026-13-45');
  });

  it('are English on the English pages', () => {
    const { i18n, ago, date } = pipes('sk');
    i18n.noteAddress('/admin/builds');
    expect(date.transform('2026-09-29', 'day')).toBe('29 September 2026');
    expect(ago.transform(new Date(NOW - 3 * HOUR).toISOString(), AGO)).toBe('3 hours ago');
  });

  it("write a day in the account's date order for somebody signed in who has chosen one - in every language", () => {
    expect(pipes('en', { date_format: 'ymd_dash' }).date.transform('2026-09-29', 'day')).toBe('2026-09-29');
    expect(pipes('sk', { date_format: 'dmy_dot' }).date.transform('2026-09-29', 'day')).toBe('29.9.2026');
    expect(pipes('sk', { date_format: 'mdy_slash' }).date.transform('2026-01-05', 'day')).toBe('01/05/2026');
    // A moment is the day it falls on here, in that order too.
    expect(pipes('en', { date_format: 'dmy_slash' }).date.transform(new Date(2026, 9, 20, 12).toISOString(), 'day')).toBe('20/10/2026');
    // What is not a date still comes back as it came.
    expect(pipes('en', { date_format: 'ymd_dash' }).date.transform('2026-13-45', 'day')).toBe('2026-13-45');
  });

  it("write days the page's own way while the account leaves them at automatic; how long ago is the same for everybody", () => {
    expect(pipes('sk', { date_format: null, time_format: '12' }).date.transform('2026-09-29', 'day')).toBe('29. septembra 2026');
    const { ago } = pipes('sk', { date_format: 'ymd_dash', time_format: '24' });
    expect(ago.transform(new Date(NOW - 3 * HOUR).toISOString(), AGO)).toBe('pred 3 hodinami');
  });
});
