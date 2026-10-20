import { TestBed } from '@angular/core/testing';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { en } from './en';
import { TranslationService } from './translation';
import { rankMark, roleLabel, variationLabel } from './variation-words';

/** The informal Slovak of the roles. */
const SLOVAK: Record<string, string> = {
  'builds.role.main_dps': 'Hlavný DPS',
  'builds.role.sub_dps': 'Vedľajší DPS',
  'builds.role.support': 'Podpora',
  'builds.role.unknown': 'Build',
};

describe('a build’s variation in words: its role in the reader’s language, its qualifier and constellation as they are', () => {
  let warn: ReturnType<typeof vi.spyOn>;

  function words(language: 'en' | 'sk'): TranslationService {
    TestBed.configureTestingModule({
      providers: [language === 'en' ? provideTestTranslations(en) : provideTestTranslations(SLOVAK, { language: 'sk', english: en })],
    });
    return TestBed.inject(TranslationService);
  }

  beforeEach(() => {
    warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
  });

  afterEach(() => vi.restoreAllMocks());

  it('words each role the server knows, and a role a newer server adds as "Build"', () => {
    const i18n = words('en');
    expect(['main_dps', 'sub_dps', 'support', 'healer'].map((role) => roleLabel(i18n, role))).toEqual(['Main DPS', 'Sub DPS', 'Support', 'Build']);
    expect(warn).not.toHaveBeenCalled();
  });

  it('marks a constellation in the game’s notation, none from the start', () => {
    expect([rankMark('C', 0), rankMark('C', 1), rankMark('C', 6)]).toEqual([null, 'C1+', 'C6+']);
  });

  it('joins the role, the qualifier and the constellation, leaving out what a build has not', () => {
    const i18n = words('en');
    expect(variationLabel(i18n, { role: 'main_dps', qualifier: '', min_rank: 0 }, 'C')).toBe('Main DPS');
    expect(variationLabel(i18n, { role: 'main_dps', qualifier: 'Melt', min_rank: 0 }, 'C')).toBe('Main DPS · Melt');
    expect(variationLabel(i18n, { role: 'support', qualifier: '', min_rank: 4 }, 'C')).toBe('Support · C4+');
    expect(variationLabel(i18n, { role: 'sub_dps', qualifier: 'Quickswap', min_rank: 2 }, 'C')).toBe('Sub DPS · Quickswap · C2+');
  });

  it('reads Slovak around the build’s own words', () => {
    const i18n = words('sk');
    expect(variationLabel(i18n, { role: 'main_dps', qualifier: 'Melt', min_rank: 2 }, 'C')).toBe('Hlavný DPS · Melt · C2+');
    expect(variationLabel(i18n, { role: 'support', qualifier: '', min_rank: 0 }, 'C')).toBe('Podpora');
    expect(warn).not.toHaveBeenCalled();
  });
});
