import { HttpErrorResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { Observable, of, throwError } from 'rxjs';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { ToastService } from '@anotoki/lib/ui/toast';
import { Api } from '../../core/api';
import { AuthService } from '../../core/auth';
import { en } from '../../core/i18n/en';
import { TranslationService } from '../../core/i18n/translation';
import { MetaStore } from '../../core/meta-store';
import { AccountDetail, CharacterOverview, GameKey, ImportRun } from '../../core/models';
import { AccountPage } from './account-page';
import { provideKit } from '../../core/kit';

const HOUR = 3_600_000;
/** A moment that reads "N hours ago" for as long as the spec runs. */
const hoursAgo = (hours: number) => new Date(Date.now() - hours * HOUR - 5000).toISOString();

type Best = NonNullable<CharacterOverview['best']>;

function best(overrides: Partial<Best> = {}): Best {
  return {
    build_id: 7,
    source: 'Source 1',
    variant: 'Burst DPS',
    overall: 72,
    grade: 'B',
    parts: { weapon: 80, sets: 100, main_stats: 67, substats: 55, targets: null },
    top_issue: 'Level up Staff of Homa (level 80/90).',
    top_issue_kind: 'weapon_level',
    top_issue_params: { name: 'Staff of Homa', level: 80, max_level: 90 },
    issues: 3,
    ...overrides,
  };
}

function character(id: number, overrides: Partial<CharacterOverview> = {}): CharacterOverview {
  return {
    id,
    key: `character-${id}`,
    name: `Character ${id}`,
    element: 'pyro',
    rarity: 5,
    weapon_type: 'polearm',
    icon_url: null,
    level: 90,
    rank: 2,
    weapon: { name: 'Staff of Homa', icon_url: null, rarity: 5, level: 80, refinement: 1 },
    sets: [{ key: 'crimson', name: 'Crimson Witch of Flames', count: 4 }],
    items_count: 5,
    builds_count: 2,
    best: best(),
    ...overrides,
  };
}

function account(overrides: Partial<AccountDetail> = {}): AccountDetail {
  return {
    id: 3,
    game: 'genshin',
    uid: '800000001',
    nickname: 'Mona',
    level: 58,
    world_level: 8,
    signature: null,
    has_full_inventory: false,
    last_import_at: hoursAgo(3),
    counts: { characters: 12, weapons: 23, items: 187 },
    meta: null,
    characters: [character(1)],
    ...overrides,
  };
}

function run(id: number, overrides: Partial<ImportRun> = {}): ImportRun {
  return {
    id,
    game: 'genshin',
    source: 'file',
    status: 'success',
    file_name: 'inventory.json',
    summary: { characters: 12, weapons: 23, items: 1523, full_inventory: true, warnings: [], unmatched: {} },
    error: null,
    error_code: null,
    error_params: null,
    created_at: hoursAgo(2),
    account: null,
    account_id: 3,
    ...overrides,
  };
}

/** The account page's Slovak, as the catalogue has it; English underneath, as the server merges it. */
const SLOVAK = {
  ...en,
  'common.save': 'Uložiť',
  'common.cancel': 'Zrušiť',
  'common.close': 'Zavrieť',
  'account.nameLabel': 'Názov účtu',
  'account.rename': 'Premenovať účet',
  'account.renameShort': 'Premenovať',
  'account.imported': 'importované {when}',
  'account.notImportedYet': 'zatiaľ bez importu',
  'account.updateByUid': 'Aktualizovať zadaním UID',
  'account.uploadInventory': 'Nahrať inventár',
  'account.characters': 'Postavy',
  'account.averageScore': 'Priemerné skóre buildov',
  'account.data': 'Údaje',
  'account.fullInventory': 'Celý inventár',
  'account.showcaseOnly': 'Len Showcase',
  'account.showcaseNote': 'Známe sú len postavy, ktoré ukazuje Character Showcase.',
  'account.search': 'Hľadať',
  'account.elementFilter': 'Element',
  'account.allElements': 'Všetky',
  'account.sort': 'Zoradiť',
  'account.sortScoreAsc': 'Najprv tie, ktoré treba zlepšiť',
  'account.sortScoreDesc': 'Najprv najlepšie buildy',
  'account.sortLevel': 'Level',
  'account.sortName': 'Meno',
  'account.showUnbuilt': 'Zobraziť aj postavy bez výbavy ({count})',
  'account.noItemsEquipped': '{items}: nič nasadené',
  'account.partSets': 'Sety',
  'account.bestMatch': 'Najlepšia zhoda: {source} · {variant}',
  'account.noBuildsForCharacter': 'Pre túto postavu zatiaľ nie sú buildy.',
  'account.noMatches': 'Filtrom nezodpovedá žiadna postava.',
  'account.withoutBuilds.one': '{count} postava zatiaľ nemá buildy; pribudnú po ďalšej synchronizácii buildov.',
  'account.withoutBuilds.few': '{count} postavy zatiaľ nemajú buildy; pribudnú po ďalšej synchronizácii buildov.',
  'account.withoutBuilds.other': '{count} postáv zatiaľ nemá buildy; pribudnú po ďalšej synchronizácii buildov.',
  'account.importHistory': 'História importov',
  'account.sourceShowcase': 'Showcase (UID)',
  'account.sourceFile': 'Súbor s inventárom',
  'account.runSummary.one': '{count} postava, {items}: {itemCount}',
  'account.runSummary.few': '{count} postavy, {items}: {itemCount}',
  'account.runSummary.other': '{count} postáv, {items}: {itemCount}',
  'recommendation.weapon_level': 'Zvýš level: {name} (level {level}/{max_level}).',
  'import.status.success': 'úspešný',
  'import.status.partial': 'čiastočný',
  'import.status.failed': 'neúspešný',
  'import.error.showcase_busy': 'Služba má teraz príliš veľa požiadaviek. Počkaj minútu a skús to znova.',
  'error.not_found': 'Nenašlo sa.',
  'error.invalid': 'Niečo z odoslaného nie je v poriadku.',
};

/** Anything that looks like one of the site's keys: where a word should be, a key must never show. */
const A_KEY = /\b(common|account|error|import|recommendation)\.[A-Za-z]/;

describe('the account page', () => {
  let fixture: ComponentFixture<AccountPage>;
  let host: HTMLElement;
  let api: { account: ReturnType<typeof vi.fn>; accountImports: ReturnType<typeof vi.fn>; renameAccount: ReturnType<typeof vi.fn> };
  let toasts: { error: ReturnType<typeof vi.fn> };

  /** What an element says. */
  const text = (element: Element | null | undefined) => element?.textContent?.replace(/\s+/g, ' ').trim();
  /** The words of the option a select of the kit shows. */
  const chosen = (selector: string) => text(host.querySelector<HTMLSelectElement>(`${selector} select`)?.selectedOptions[0]);
  const texts = (selector: string, within: Element = host) => [...within.querySelectorAll(selector)].map(text);
  /** The tiles under the head: each a caption above its value. */
  const tiles = () => [...host.querySelectorAll('.stat')].map((tile) => [text(tile.querySelector('span')), text(tile.querySelector('strong'))]);
  const cards = () => [...host.querySelectorAll<HTMLElement>('.char-card')];
  const history = () => [...host.querySelectorAll<HTMLElement>('.history-table tr')].map((row) => [...row.querySelectorAll('td')]);

  interface Options {
    language?: 'en' | 'sk';
    account?: AccountDetail | Observable<AccountDetail>;
    imports?: ImportRun[];
    /** False: nobody signed in - a visitor, or nobody at all. */
    signedIn?: boolean;
  }

  async function setup(options: Options = {}): Promise<void> {
    const detail = options.account ?? account();
    api = {
      account: vi.fn(() => (detail instanceof Observable ? detail : of(detail))),
      accountImports: vi.fn(() => of(options.imports ?? [])),
      renameAccount: vi.fn(),
    };
    toasts = { error: vi.fn() };
    const meta = {
      // No metadata: the page falls back on the games' own prefixes.
      game: (_key: GameKey) => null,
      formatStat: (_game: GameKey, _stat: string, value: number) => `${value.toFixed(1)}%`,
      visitorDays: () => 30,
    };
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        (options.language ?? 'sk') === 'sk' ? provideTestTranslations(SLOVAK, { language: 'sk', english: en }) : provideTestTranslations(en),
        provideKit(),
        { provide: Api, useValue: api },
        { provide: MetaStore, useValue: meta },
        { provide: ToastService, useValue: toasts },
        // Somebody signed in: no visitor notice; one who is not is a case of its own.
        { provide: AuthService, useValue: { signedIn: signal(options.signedIn ?? true), available: signal(true), user: signal(null), failure: signal(null) } },
      ],
    });
    fixture = TestBed.createComponent(AccountPage);
    fixture.componentRef.setInput('id', '3');
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  afterEach(() => vi.restoreAllMocks());

  it('words its head and its tiles in Slovak around the games’ own words', async () => {
    await setup();

    // The game's name, UID and Adventure Rank stay; the relative time goes into the site's own word.
    expect(text(host.querySelector('.subtitle'))).toBe('Genshin Impact · UID 800000001 · Adventure Rank 58 · importované pred 3 hodinami');
    expect(texts('anotoki-page-header a')).toEqual(['Aktualizovať zadaním UID', 'Nahrať inventár']);
    const rename = host.querySelector('anotoki-page-header button.rename')!;
    expect(rename.getAttribute('aria-label')).toBe('Premenovať účet');

    // A caption above its number; what a character wears is named by the game.
    expect(tiles()).toEqual([
      ['Postavy', '12'],
      ['Priemerné skóre buildov', '72'],
      ['Artifacts', '187'],
      ['Údaje', 'Len Showcase'],
    ]);
    expect(text(host.querySelector('.note'))).toBe('Známe sú len postavy, ktoré ukazuje Character Showcase.');
    // The kit's fields: the search, the element (the game's names), the order.
    expect(texts('.filters .anotoki-field__label')).toEqual(['Hľadať', 'Element', 'Zoradiť']);
    expect(texts('.element option')).toEqual(['Všetky']);
    expect(chosen('.sort')).toBe('Najprv tie, ktoré treba zlepšiť');

    expect(host.textContent).not.toMatch(A_KEY);
  });

  it('names the other game’s things by that game’s words, and says when nothing was imported', async () => {
    await setup({ account: account({ game: 'hsr', uid: null, has_full_inventory: true, last_import_at: null, characters: [character(1, { sets: [] })] }) });

    expect(text(host.querySelector('.subtitle'))).toBe('Honkai: Star Rail · Trailblaze Level 58 · zatiaľ bez importu');
    expect(tiles()).toEqual([
      ['Postavy', '12'],
      ['Priemerné skóre buildov', '72'],
      ['Relics', '187'],
      ['Údaje', 'Celý inventár'],
    ]);
    expect(host.querySelector('.note')).toBeNull();
    // The game's noun first, then a colon: no Slovak word has to agree with it.
    expect(text(cards()[0].querySelector('.sets'))).toBe('Relics: nič nasadené');
    // Without metadata the prefixes are the game's own: E for Eidolons, S for Superimposition.
    expect(text(cards()[0].querySelector('.char-names .muted'))).toBe('Lv. 90 · E2');
    expect(text(cards()[0].querySelector('.weapon span.small'))).toBe('Staff of Homa S1');
    expect(host.textContent).not.toMatch(/never|relics|artifacts/);
  });

  it('reads English as it always did, a game noun standing first where it used to be lower-cased', async () => {
    await setup({ language: 'en', account: account({ characters: [character(1, { sets: [] })] }) });

    expect(text(host.querySelector('.subtitle'))).toBe('Genshin Impact · UID 800000001 · Adventure Rank 58 · imported 3 hours ago');
    expect(tiles()).toEqual([
      ['Characters', '12'],
      ['Average build score', '72'],
      ['Artifacts', '187'],
      ['Data', 'Showcase only'],
    ]);
    expect(text(cards()[0].querySelector('.sets'))).toBe('Artifacts: none equipped');
    expect(text(cards()[0].querySelector('.char-names .muted'))).toBe('Lv. 90 · C2');
    expect(text(cards()[0].querySelector('.best-line'))).toBe('Best match: Source 1 · Burst DPS');
    expect(chosen('.sort')).toBe('Needs work first');
  });

  describe('a character’s card', () => {
    it('labels the score bars: the site’s word for sets, the games’ own for the stats', async () => {
      await setup();

      expect(texts('app-score-bar .head span:first-child', cards()[0])).toEqual(['Sety', 'Main stats', 'Substats']);
      expect(text(cards()[0].querySelector('.best-line'))).toBe('Najlepšia zhoda: Source 1 · Burst DPS');
      expect(text(cards()[0].querySelector('.sets'))).toBe('4× Crimson Witch of Flames');
    });

    it('words the top issue from its kind, in the reader’s language', async () => {
      await setup();

      const issue = cards()[0].querySelector('.issue span')!;
      expect(text(issue)).toBe('Zvýš level: Staff of Homa (level 80/90).');
      expect(issue.hasAttribute('lang')).toBe(false);
      expect(host.textContent).not.toContain('Level up Staff of Homa');
    });

    it('shows the stored English sentence, saying it is English, where there is no kind to word it with', async () => {
      await setup({
        account: account({
          characters: [
            // A kind a newer server added, and a top issue from before kinds were sent.
            character(1, { best: best({ top_issue: 'Swap the goblet for a better one.', top_issue_kind: 'goblet_swap', top_issue_params: { slot: 'goblet' } }) }),
            character(2, { best: best({ top_issue: 'An older sentence.', top_issue_kind: null, top_issue_params: null }) }),
            // A known kind that lacks a parameter is not worded with a hole in it.
            character(3, { best: best({ top_issue_params: { name: 'Staff of Homa', level: 80 } }) }),
          ],
        }),
      });

      const issues = cards().map((card) => card.querySelector('.issue span')!);
      expect(issues.map(text)).toEqual(['Swap the goblet for a better one.', 'An older sentence.', 'Level up Staff of Homa (level 80/90).']);
      expect(issues.map((issue) => issue.getAttribute('lang'))).toEqual(['en', 'en', 'en']);
    });

    it('marks nothing on an English page, and follows the page when its language changes', async () => {
      await setup({ account: account({ characters: [character(1), character(2, { best: best({ top_issue: 'An older sentence.', top_issue_kind: null, top_issue_params: null }) })] }) });
      expect(cards().map((card) => text(card.querySelector('.issue span')))).toEqual(['Zvýš level: Staff of Homa (level 80/90).', 'An older sentence.']);

      expect(await TestBed.inject(TranslationService).setLanguage('en', { remember: false })).toBe(
        true,
      );
      await fixture.whenStable();

      const issues = cards().map((card) => card.querySelector('.issue span')!);
      expect(issues.map(text)).toEqual(['Level up Staff of Homa (level 80/90).', 'An older sentence.']);
      expect(issues.map((issue) => issue.hasAttribute('lang'))).toEqual([false, false]);
    });

    it('draws no issue where the best build recommends nothing, and says so where there is no build', async () => {
      await setup({
        account: account({
          characters: [character(1, { best: best({ top_issue: null, top_issue_kind: null, top_issue_params: null }) }), character(2, { best: null, builds_count: 0 })],
        }),
      });

      expect(cards().map((card) => card.querySelector('.issue'))).toEqual([null, null]);
      expect(text(cards()[1].querySelector(':scope > .muted'))).toBe('Pre túto postavu zatiaľ nie sú buildy.');
    });
  });

  it('counts the characters without builds the way Slovak counts', async () => {
    const without = (count: number) => account({ characters: [character(1), ...Array.from({ length: count }, (_, index) => character(10 + index, { best: null, builds_count: 0 }))] });
    const sentence = () => text(host.querySelector('.without-builds'));

    await setup({ account: without(1) });
    expect(sentence()).toBe('1 postava zatiaľ nemá buildy; pribudnú po ďalšej synchronizácii buildov.');

    TestBed.resetTestingModule();
    await setup({ account: without(3) });
    expect(sentence()).toBe('3 postavy zatiaľ nemajú buildy; pribudnú po ďalšej synchronizácii buildov.');

    TestBed.resetTestingModule();
    await setup({ account: without(5) });
    expect(sentence()).toBe('5 postáv zatiaľ nemá buildy; pribudnú po ďalšej synchronizácii buildov.');

    TestBed.resetTestingModule();
    await setup({ language: 'en', account: without(1) });
    expect(sentence()).toBe('1 character has no builds yet; they appear once a build sync has fetched them.');

    TestBed.resetTestingModule();
    await setup({ account: without(0) });
    expect(host.querySelector('.without-builds')).toBeNull();
  });

  it('offers the characters with nothing equipped, saying how many, and says when the filters leave none', async () => {
    await setup({ account: account({ characters: [character(1, { items_count: 0 }), character(2, { items_count: 0 })] }) });

    expect(text(host.querySelector('anotoki-switch .anotoki-switch__words'))).toBe('Zobraziť aj postavy bez výbavy (2)');
    expect(text(host.querySelector('anotoki-empty-state .anotoki-empty-state__heading'))).toBe('Filtrom nezodpovedá žiadna postava.');

    // Shown, the two come back.
    host.querySelector<HTMLInputElement>('anotoki-switch input')!.click();
    await fixture.whenStable();
    expect(cards()).toHaveLength(2);
  });

  describe('the import history', () => {
    const RUNS = [
      run(1, { status: 'success' }),
      run(2, { status: 'partial', source: 'showcase', summary: { characters: 1, weapons: 1, items: 5, full_inventory: false, warnings: [], unmatched: {} } }),
      run(3, { status: 'partial', summary: { characters: 3, weapons: 3, items: 15, full_inventory: false, warnings: [], unmatched: {} } }),
      run(4, { status: 'failed', source: 'showcase', summary: null, error: 'Too many showcase requests right now. Wait a minute and try again.', error_code: 'showcase_busy', error_params: {} }),
      // Stored before codes were kept: the sentence is all there is.
      run(5, { status: 'failed', summary: null, error: 'An older failure, as it was stored.' }),
    ];

    it('words each run: when, from where, how it ended, and what came in - a count beside the game’s noun as "noun: count"', async () => {
      await setup({ imports: RUNS });

      expect(text(host.querySelector('.history summary'))).toBe('História importov');
      const rows = history();
      expect(rows.map((cells) => text(cells[0]))).toEqual(Array(5).fill('pred 2 hodinami'));
      expect(rows.map((cells) => text(cells[1]))).toEqual(['Súbor s inventárom', 'Showcase (UID)', 'Súbor s inventárom', 'Showcase (UID)', 'Súbor s inventárom']);
      expect(rows.map((cells) => text(cells[2]))).toEqual(['úspešný', 'čiastočný', 'čiastočný', 'neúspešný', 'neúspešný']);
      expect(rows.map((cells) => cells[2].querySelector('anotoki-badge')?.getAttribute('data-tone'))).toEqual(['success', 'warning', 'warning', 'danger', 'danger']);
      // 1 523 is written the way Slovak writes numbers: a space that does not break.
      expect(rows.map((cells) => text(cells[3])?.replace(/ /g, ' '))).toEqual([
        '12 postáv, Artifacts: 1 523',
        '1 postava, Artifacts: 5',
        '3 postavy, Artifacts: 15',
        'Služba má teraz príliš veľa požiadaviek. Počkaj minútu a skús to znova.',
        'An older failure, as it was stored.',
      ]);
      // Only the stored sentence is English on this page, and says so.
      expect(rows.map((cells) => cells[3].getAttribute('lang'))).toEqual([null, null, null, null, 'en']);
      expect(host.textContent).not.toMatch(A_KEY);
      expect(host.textContent).not.toContain('Too many showcase requests');
    });

    it('reads English on an English page, with nothing marked', async () => {
      await setup({ language: 'en', imports: RUNS });

      const rows = history();
      expect(rows.map((cells) => text(cells[1]))).toEqual(['Inventory file', 'Showcase (UID)', 'Inventory file', 'Showcase (UID)', 'Inventory file']);
      expect(rows.map((cells) => text(cells[2]))).toEqual(['success', 'partial', 'partial', 'failed', 'failed']);
      expect(rows.map((cells) => text(cells[3]))).toEqual([
        '12 characters, Artifacts: 1,523',
        '1 character, Artifacts: 5',
        '3 characters, Artifacts: 15',
        'Too many showcase requests right now. Wait a minute and try again.',
        'An older failure, as it was stored.',
      ]);
      expect(rows.map((cells) => cells[3].hasAttribute('lang'))).toEqual(Array(5).fill(false));
    });

    it('decides that a run failed by its status, never by its summary', async () => {
      await setup({
        imports: [
          // A failed run that still carries a summary says why it failed, not what came in.
          run(1, { status: 'failed', error: 'An older failure, as it was stored.' }),
          // A run that went through without a summary says nothing - not an error it does not have.
          run(2, { status: 'success', summary: null }),
        ],
      });

      expect(history().map((cells) => text(cells[3]))).toEqual(['An older failure, as it was stored.', '']);
    });

    it('is not drawn for an account without imports', async () => {
      await setup({ imports: [] });

      expect(host.querySelector('.history')).toBeNull();
    });
  });

  describe('for somebody not signed in', () => {
    it('shows a visitor their account under the notice that says how long it is kept', async () => {
      await setup({ language: 'en', signedIn: false });

      expect(text(host.querySelector('app-visitor-notice .anotoki-alert__text'))).toBe(
        'You are not signed in. What you import is kept for this browser on our server for 30 days after your last visit. Signing in keeps it in your anotoki account.',
      );
      expect(host.querySelector('anotoki-page-header')).not.toBeNull();
    });

    it('shows nobody the ways in, not an error, for an account that is not theirs to open (401)', async () => {
      await setup({ language: 'en', signedIn: false, account: throwError(() => new HttpErrorResponse({ status: 401, error: { message: 'Sign in.', code: 'sign_in_required' } })) });

      expect(host.querySelector('app-visitor-hero')).not.toBeNull();
      expect(host.querySelector('app-visitor-notice')).toBeNull();
      expect(host.textContent).not.toContain(en['error.sign_in_required']);
    });

    it('says a 401 as an error to somebody signed in: their sign-in has ended', async () => {
      await setup({ language: 'en', account: throwError(() => new HttpErrorResponse({ status: 401, error: { message: 'Sign in.', code: 'sign_in_required' } })) });

      expect(host.querySelector('app-visitor-hero')).toBeNull();
      expect(text(host.querySelector('.failure .anotoki-alert__text'))).toBe(en['error.sign_in_required']);
    });
  });

  describe('when something fails', () => {
    it('words a page that could not be loaded from the failure’s code, never with the server’s sentence', async () => {
      await setup({ account: throwError(() => new HttpErrorResponse({ status: 404, error: { message: 'Not found.', code: 'not_found' } })) });

      expect(text(host.querySelector('.failure .anotoki-alert__text'))).toBe('Nenašlo sa.');
      expect(host.textContent).not.toContain('Not found.');
      expect(host.querySelector('anotoki-page-header')).toBeNull();
    });

    it('says why a new name was not saved, in a message worded when it is raised', async () => {
      await setup();
      api.renameAccount.mockImplementation(() => throwError(() => new HttpErrorResponse({ status: 422, error: { message: 'The nickname is too long.', code: 'invalid', errors: { nickname: ['The nickname is too long.'] } } })));

      host.querySelector<HTMLButtonElement>('anotoki-page-header button.rename')!.click();
      await fixture.whenStable();
      expect(texts('.rename-form .anotoki-field__label')).toEqual(['Názov účtu']);
      expect(texts('form button')).toEqual(['Uložiť', 'Zrušiť']);

      host.querySelector('form')!.dispatchEvent(new Event('submit'));
      await fixture.whenStable();
      expect(api.renameAccount).toHaveBeenCalledWith(3, 'Mona');
      expect(toasts.error).toHaveBeenCalledWith('Niečo z odoslaného nie je v poriadku.');
    });
  });
});
