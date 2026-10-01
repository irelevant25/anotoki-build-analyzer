import { Injectable, InjectionToken, Provider, inject } from '@angular/core';
import { SiteLanguage, TranslationBundle } from '../models';
import { FALLBACK_LANGUAGE, TranslationService } from './translation';

/** The two languages the site is seeded with. */
export const TEST_LANGUAGES: SiteLanguage[] = [
  { code: 'en', name: 'English', native_name: 'English' },
  { code: 'sk', name: 'Slovak', native_name: 'Slovenčina' },
];

const TEST_BUNDLE = new InjectionToken<TranslationBundle>('the bundle a spec runs with');

/**
 * The real service with a bundle already in memory, and no HTTP: what it would fetch is answered from that
 * bundle - its own language, English for any other, as the server answers a language it does not offer.
 *
 * The bundle counts as the server's own, so a navigation in a spec asks for nothing (noteAddress()), and
 * neither the API nor the metadata is ever touched.
 */
@Injectable()
class TestTranslationService extends TranslationService {
  private readonly _bundle = inject(TEST_BUNDLE);

  constructor() {
    super();
    this.use(this._bundle);
  }

  protected override fetchBundle(code: string): Promise<unknown> {
    const { language, languages, values, english } = this._bundle;
    return Promise.resolve(code === language ? this._bundle : { language: FALLBACK_LANGUAGE, languages, values: english ?? values });
  }

  protected override canAsk(): boolean {
    return true;
  }

  /** The start: here the bundle is in force already - nothing is read from storage or from the address. */
  override init(): Promise<void> {
    return Promise.resolve();
  }

  /**
   * What a page calls after it has changed the strings or the languages: here it answers at once and
   * changes nothing - the language the spec chose stays (the real one settles again on the browser's
   * wishes, which would move a Slovak spec to whatever jsdom says it speaks). A spec that cares asks
   * whether it was called: `vi.spyOn(i18n, 'reload')`.
   */
  override reload(): Promise<void> {
    return Promise.resolve();
  }
}

/**
 * For a spec that runs the translation service, a pipe or a component with given strings:
 * `provideTestTranslations({ 'title.builds': 'Builds' })` is an English bundle of them; for another
 * language, say which, and give English beside it (what the English pages read) -
 * `provideTestTranslations({ 'title.builds': 'Buildy' }, { language: 'sk', english: { 'title.builds': 'Builds' } })`.
 * For the whole compiled English: `provideTestTranslations(en)` (core/i18n/en.ts).
 *
 * A key that is not given comes back as itself and warns, as on the site with a bundle in memory - the
 * compiled English never fills a hole here either. (Without `english`, a Slovak spec's English pages read
 * the compiled English, as they would for a bundle that came without it.)
 */
export function provideTestTranslations(values: Record<string, string> = {}, bundle: Partial<Omit<TranslationBundle, 'values'>> = {}): Provider[] {
  const language = bundle.language ?? FALLBACK_LANGUAGE;
  const whole: TranslationBundle = { language, languages: bundle.languages ?? TEST_LANGUAGES, values, ...(language === FALLBACK_LANGUAGE ? {} : { english: bundle.english ?? {} }) };
  return [
    { provide: TEST_BUNDLE, useValue: whole },
    { provide: TranslationService, useClass: TestTranslationService },
  ];
}
