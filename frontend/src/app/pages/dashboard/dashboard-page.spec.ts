import { HttpErrorResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatSnackBar } from '@angular/material/snack-bar';
import { provideRouter } from '@angular/router';
import { Observable, Subject, of, throwError } from 'rxjs';
import { Api } from '../../core/api';
import { AuthService } from '../../core/auth';
import { en } from '../../core/i18n/en';
import { provideTestTranslations } from '../../core/i18n/testing';
import { AccountSummary, SourceStatus } from '../../core/models';
import { VisitorKey } from '../../core/visitor-key';
import { DashboardPage } from './dashboard-page';

const HOUR = 3_600_000;
/** A moment that reads "N hours ago" for as long as the spec runs. */
const hoursAgo = (hours: number) => new Date(Date.now() - hours * HOUR - 5000).toISOString();

function account(id: number, overrides: Partial<AccountSummary> = {}): AccountSummary {
  return {
    id,
    game: 'genshin',
    uid: '800000001',
    nickname: 'Mona',
    level: 58,
    world_level: 8,
    signature: null,
    has_full_inventory: true,
    last_import_at: hoursAgo(48),
    counts: { characters: 12, weapons: 23, items: 187 },
    meta: null,
    ...overrides,
  };
}

function source(key: string, overrides: Partial<SourceStatus> = {}): SourceStatus {
  return {
    key,
    game: 'genshin',
    name: 'Source 1',
    enabled: true,
    last_synced_at: hoursAgo(3),
    last_status: 'success',
    last_error: null,
    builds: 40,
    characters: 12,
    running: false,
    latest_run: null,
    ...overrides,
  };
}

/** The dashboard's Slovak, as the catalogue has it; English underneath, as the server merges it. */
const SLOVAK = {
  ...en,
  'common.cancel': 'Zrušiť',
  'common.close': 'Zavrieť',
  'common.retry': 'Skúsiť znova',
  'nav.sources': 'Zdroje',
  'confirm.deleteAccount': 'Vymazať účet „{name}“ a všetko, čo sa preň importovalo?',
  'confirm.deleteAccountKept': 'Buildy zostanú.',
  'confirm.delete': 'Vymazať',
  'dashboard.title': 'Tvoje účty',
  'dashboard.subtitle': 'Importuj účet z hry Genshin Impact alebo Honkai: Star Rail a porovnaj každú postavu s buildmi od komunity.',
  'dashboard.importAccount': 'Importovať účet',
  'dashboard.emptyTitle': 'Pozrime sa na tvoje postavy',
  'dashboard.emptyText': 'Sprievodca importom ťa prevedie krok za krokom.',
  'dashboard.importGame': 'Importovať z hry {game}',
  'dashboard.noUid': 'UID chýba',
  'dashboard.imported': 'Importované {when}',
  'dashboard.notImportedYet': 'Zatiaľ bez importu',
  'dashboard.open': 'Otvoriť',
  'dashboard.updateData': 'Aktualizovať údaje',
  'dashboard.moreActions': 'Ďalšie akcie',
  'dashboard.deleteAccount': 'Vymazať účet',
  'dashboard.deleted': 'Účet „{name}“ je vymazaný.',
  'dashboard.sources': 'Zdroje buildov',
  'dashboard.manageSources': 'Spravovať',
  'dashboard.noBuildsAdmin': 'Zatiaľ nie sú stiahnuté žiadne buildy. Prvú synchronizáciu spusti na stránke {sources}.',
  'dashboard.noBuildsMember': 'Zatiaľ nie sú stiahnuté žiadne buildy. Pribudnú, keď správca synchronizuje zdroje buildov.',
  'dashboard.sourceCharacters.one': '{count} postava',
  'dashboard.sourceCharacters.few': '{count} postavy',
  'dashboard.sourceCharacters.other': '{count} postáv',
  'dashboard.syncing': 'synchronizuje sa…',
  'dashboard.notSyncedYet': 'zatiaľ bez synchronizácie',
  'account.characters': 'Postavy',
  'account.fullInventory': 'Celý inventár',
  'account.showcaseOnly': 'Len Showcase',
  'error.offline': 'Nepodarilo sa spojiť so serverom. Skontroluj pripojenie a skús to znova.',
  'error.editor_only': 'Toto môže urobiť len editor alebo správca.',
  'error.server_error': 'Na serveri sa niečo pokazilo.',
};

/** Anything that looks like one of the site's keys: where a word should be, a key must never show. */
const A_KEY = /\b(common|nav|confirm|dashboard|account|error)\.[A-Za-z]/;

describe('the dashboard', () => {
  let fixture: ComponentFixture<DashboardPage>;
  let host: HTMLElement;
  let api: { accounts: ReturnType<typeof vi.fn>; sources: ReturnType<typeof vi.fn>; deleteAccount: ReturnType<typeof vi.fn> };
  let snackBar: { open: ReturnType<typeof vi.fn> };
  let auth: { signedIn: ReturnType<typeof signal<boolean>>; isStaff: ReturnType<typeof signal<boolean>> };
  let visitor: { key: ReturnType<typeof signal<string | null>>; lost: ReturnType<typeof signal<boolean>>; moves: ReturnType<typeof signal<number>> };

  const overlay = () => document.querySelector<HTMLElement>('.cdk-overlay-container');
  /** What an element says, without the names of its icons. */
  const text = (element: Element | null | undefined) => {
    const copy = element?.cloneNode(true) as Element | undefined;
    copy?.querySelectorAll('mat-icon').forEach((icon) => icon.remove());
    return copy?.textContent?.replace(/\s+/g, ' ').trim();
  };
  const texts =(selector: string) => [...host.querySelectorAll(selector)].map(text);
  /** A card's counts: each a number above its caption. */
  const counts = (card: HTMLElement) => [...card.querySelectorAll('.counts div')].map((count) => [text(count.querySelector('strong')), text(count.querySelector('span'))].join(' '));
  const cards = () => [...host.querySelectorAll<HTMLElement>('article.account-card')];
  const rows = () => [...host.querySelectorAll<HTMLElement>('.source-row')];
  /** What the page says about the accounts and the sources - the "Your data" card aside (it has cases of its own). */
  const pageText = () => {
    const copy = host.cloneNode(true) as HTMLElement;
    copy.querySelectorAll('app-data-card').forEach((card) => card.remove());
    return copy.textContent ?? '';
  };

  interface Options {
    language?: 'en' | 'sk';
    strings?: Record<string, string>;
    accounts?: AccountSummary[] | Observable<AccountSummary[]>;
    sources?: SourceStatus[];
    /** An editor or an admin: the Sources page is theirs. */
    admin?: boolean;
    /** Who looks: somebody signed in (the default), a visitor (a key held), or nobody. */
    state?: 'person' | 'visitor' | 'nobody';
  }

  async function setup(options: Options = {}): Promise<void> {
    const accounts = options.accounts ?? [account(1)];
    api = {
      accounts: vi.fn(() => (Array.isArray(accounts) ? of(accounts) : accounts)),
      sources: vi.fn(() => of(options.sources ?? [source('genshin-1')])),
      deleteAccount: vi.fn(() => of(undefined)),
    };
    snackBar = { open: vi.fn() };
    const state = options.state ?? 'person';
    auth = { signedIn: signal(state === 'person'), isStaff: signal(options.admin ?? false) };
    visitor = { key: signal(state === 'visitor' ? 'K'.repeat(43) : null), lost: signal(false), moves: signal(0) };
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        (options.language ?? 'sk') === 'sk' ? provideTestTranslations({ ...SLOVAK, ...options.strings }, { language: 'sk', english: en }) : provideTestTranslations(en),
        { provide: Api, useValue: api },
        { provide: AuthService, useValue: { ...auth, isAdmin: auth.isStaff, available: signal(true), lastError: signal(null), user: signal(null) } },
        { provide: VisitorKey, useValue: visitor },
        { provide: MatSnackBar, useValue: snackBar },
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
      ],
    });
    fixture = TestBed.createComponent(DashboardPage);
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  /** Opens an account card's menu and chooses Delete: the question is then on the page. */
  async function askToDelete(card: HTMLElement): Promise<void> {
    card.querySelector<HTMLButtonElement>('.account-actions button')!.click();
    await fixture.whenStable();
    overlay()!.querySelector<HTMLButtonElement>('button[mat-menu-item]')!.click();
    await fixture.whenStable();
  }
  const dialog = () => overlay()?.querySelector<HTMLElement>('mat-dialog-container') ?? null;
  const dialogButtons = () => [...(dialog()?.querySelectorAll<HTMLButtonElement>('mat-dialog-actions button') ?? [])];

  afterEach(() => {
    vi.restoreAllMocks();
    overlay()?.replaceChildren();
  });

  it('words an account’s card in Slovak around the games’ own words', async () => {
    await setup({
      accounts: [account(1), account(2, { game: 'hsr', uid: null, nickname: 'Stelle', level: 70, has_full_inventory: false, last_import_at: null })],
    });

    expect(texts('h1')).toEqual(['Tvoje účty']);
    expect(text(host.querySelector('.page-header a'))).toBe('Importovať účet');

    const [genshin, hsr] = cards();
    // UID, AR and TL are the games' words: written as they are.
    expect(text(genshin.querySelector('.names .small'))).toBe('UID 800000001 · AR 58');
    expect(text(hsr.querySelector('.names .small'))).toBe('UID chýba · TL 70');
    // A number with its caption under it: the site's word for characters, the game's for the rest.
    expect(counts(genshin)).toEqual(['12 Postavy', '187 Artifacts', '23 Weapons']);
    expect(counts(hsr)).toEqual(['12 Postavy', '187 Relics', '23 Light Cones']);
    expect(text(genshin.querySelector('.chip'))).toBe('Celý inventár');
    expect(text(hsr.querySelector('.chip'))).toBe('Len Showcase');
    // A relative time goes into the sentence; a missing one is a sentence of its own - never "never" glued on.
    expect(text(genshin.querySelector('.account-main .row.wrap .muted'))).toBe('Importované pred 2 dňami');
    expect(text(hsr.querySelector('.account-main .row.wrap .muted'))).toBe('Zatiaľ bez importu');
    expect([...genshin.querySelectorAll('.account-actions a')].map(text)).toEqual(['Otvoriť', 'Aktualizovať údaje']);
    expect(genshin.querySelector('.account-actions button')?.getAttribute('aria-label')).toBe('Ďalšie akcie');

    expect(pageText()).not.toMatch(A_KEY);
    expect(pageText()).not.toMatch(/never|characters|artifacts|relics|light cones|weapons/);
  });

  it('counts a source’s characters the way Slovak counts, and says when it was synced', async () => {
    await setup({
      sources: [
        source('genshin-1', { characters: 1 }),
        source('genshin-2', { name: 'Source 2', characters: 3, last_synced_at: null, last_status: null }),
        source('genshin-3', { name: 'Source 3', characters: 12, running: true }),
        source('hsr-1', { game: 'hsr', characters: 0 }),
      ],
    });

    expect(texts('.section-title').at(-1)).toBe('Zdroje buildov');
    expect(rows().map((row) => [...row.querySelectorAll('span:not(.status-dot):not(.spacer)')].map(text))).toEqual([
      ['Source 1', '1 postava', 'pred 3 hodinami'],
      ['Source 2', '3 postavy', 'zatiaľ bez synchronizácie'],
      ['Source 3', '12 postáv', 'synchronizuje sa…'],
      ['Source 1', '0 postáv', 'pred 3 hodinami'],
    ]);
    // The status dot's class is not a word: a source never synced keeps its "never".
    expect(rows().map((row) => row.querySelector('.status-dot')?.className)).toEqual(['status-dot success', 'status-dot never', 'status-dot running', 'status-dot success']);
    expect(pageText()).not.toMatch(/never/);
  });

  it('reads English as it always did', async () => {
    await setup({
      language: 'en',
      accounts: [account(1), account(2, { game: 'hsr', uid: null, last_import_at: null, has_full_inventory: false })],
      sources: [source('genshin-1', { characters: 1 }), source('genshin-2', { characters: 12, last_synced_at: null })],
    });

    expect(texts('h1')).toEqual(['Your accounts']);
    const [genshin, hsr] = cards();
    expect(text(genshin.querySelector('.names .small'))).toBe('UID 800000001 · AR 58');
    expect(text(hsr.querySelector('.names .small'))).toBe('No UID · TL 58');
    expect(counts(genshin)).toEqual(['12 Characters', '187 Artifacts', '23 Weapons']);
    expect(text(genshin.querySelector('.account-main .row.wrap .muted'))).toBe('Imported 2 days ago');
    expect(text(hsr.querySelector('.account-main .row.wrap .muted'))).toBe('Not imported yet');
    expect(rows().map((row) => [...row.querySelectorAll('.muted')].map(text))).toEqual([
      ['1 character', '3 hours ago'],
      ['12 characters', 'not synced yet'],
    ]);
    // A missing time is a phrase of its own, not the word "never" standing where a time would.
    expect(pageText()).not.toMatch(/never/);
  });

  it('invites to import, a button for each game, while there is no account', async () => {
    await setup({ accounts: [] });

    expect(texts('.hero h2')).toEqual(['Pozrime sa na tvoje postavy']);
    const buttons = [...host.querySelectorAll<HTMLAnchorElement>('.hero a')];
    expect(buttons.map(text)).toEqual(['Importovať z hry Genshin Impact', 'Importovať z hry Honkai: Star Rail']);
    expect(buttons.map((button) => button.getAttribute('href'))).toEqual(['/import?game=genshin', '/import?game=hsr']);
  });

  describe('while no build has been downloaded', () => {
    const NONE = [source('genshin-1', { builds: 0, characters: 0, last_synced_at: null, last_status: null })];
    const sentence = () => host.querySelector<HTMLElement>('.game-section p.muted');

    it('sends an admin to the Sources page, the link inside the sentence', async () => {
      await setup({ admin: true, sources: NONE });

      expect(text(sentence())).toBe('Zatiaľ nie sú stiahnuté žiadne buildy. Prvú synchronizáciu spusti na stránke Zdroje.');
      const links = [...sentence()!.querySelectorAll('a')];
      expect(links.map((link) => [text(link), link.getAttribute('href')])).toEqual([['Zdroje', '/admin/sources']]);
    });

    it('reads the same in English', async () => {
      await setup({ language: 'en', admin: true, sources: NONE });

      expect(text(sentence())).toBe('No builds downloaded yet. Start the first sync on the Sources page.');
      expect(text(sentence()!.querySelector('a'))).toBe('Sources');
    });

    it('draws no link for a language whose sentence does not name the page', async () => {
      await setup({ admin: true, sources: NONE, strings: { 'dashboard.noBuildsAdmin': 'Zatiaľ nie sú stiahnuté žiadne buildy.' } });

      expect(text(sentence())).toBe('Zatiaľ nie sú stiahnuté žiadne buildy.');
      expect(sentence()!.querySelector('a')).toBeNull();
    });

    it('tells everybody else to wait for an admin', async () => {
      await setup({ admin: false, sources: NONE });

      expect(text(sentence())).toBe('Zatiaľ nie sú stiahnuté žiadne buildy. Pribudnú, keď správca synchronizuje zdroje buildov.');
      expect(sentence()!.querySelector('a')).toBeNull();
    });
  });

  describe('when its data cannot be loaded', () => {
    const refused = new HttpErrorResponse({ status: 403, error: { message: 'Only an editor or an admin can do this.', code: 'editor_only' } });

    it('words the failure from its code, never with the server’s sentence', async () => {
      await setup({ accounts: throwError(() => refused) });

      expect(text(host.querySelector('.error-panel strong'))).toBe('Toto môže urobiť len editor alebo správca.');
      expect(host.textContent).not.toContain('Only an editor or an admin can do this.');
      expect(text(host.querySelector('.error-panel button'))).toBe('Skúsiť znova');
      expect(host.querySelector('.error-panel p')).toBeNull();
      expect(host.querySelector('.hero')).toBeNull();
    });

    it('says the server is out of reach - and nothing about starting a backend', async () => {
      await setup({ accounts: throwError(() => new HttpErrorResponse({ status: 0 })) });

      expect(text(host.querySelector('.error-panel strong'))).toBe('Nepodarilo sa spojiť so serverom. Skontroluj pripojenie a skús to znova.');
      expect(host.querySelector('.error-panel p')).toBeNull();
    });

    it('follows the language: the same failure is worded again', async () => {
      await setup({ language: 'en', accounts: throwError(() => refused) });

      expect(text(host.querySelector('.error-panel strong'))).toBe('Only an editor or an admin can do this.');
      expect(text(host.querySelector('.error-panel button'))).toBe('Retry');
    });

    it('asks again', async () => {
      await setup({ accounts: throwError(() => refused) });
      api.accounts.mockImplementation(() => of([account(1)]));
      host.querySelector<HTMLButtonElement>('.error-panel button')!.click();
      await fixture.whenStable();

      expect(host.querySelector('.error-panel')).toBeNull();
      expect(cards()).toHaveLength(1);
    });
  });

  describe('three states: nobody, a visitor, somebody signed in', () => {
    it('shows nobody what the site does and the ways on - and asks for nobody\'s accounts', async () => {
      await setup({ language: 'en', state: 'nobody' });

      expect(api.accounts).not.toHaveBeenCalled();
      expect(api.sources).toHaveBeenCalledTimes(1);
      expect(texts('app-visitor-hero h2')).toEqual([en['home.title']]);
      const links = [...host.querySelectorAll<HTMLAnchorElement>('app-visitor-hero a')];
      expect(links.map((link) => [text(link), link.getAttribute('href')])).toEqual([
        ['Import your account', '/import'],
        ['Browse the Genshin Impact builds', '/builds/genshin'],
        ['Browse the Honkai: Star Rail builds', '/builds/hsr'],
      ]);
      expect(texts('app-visitor-hero button')).toEqual(['Sign in']);
      // The sources' freshness is everybody's.
      expect(rows()).toHaveLength(1);
      expect(host.querySelector('.page-header')).toBeNull();
      expect(host.querySelector('app-visitor-notice aside')).toBeNull();
      expect(host.querySelector('app-data-card')).toBeNull();
      expect(host.textContent).not.toMatch(/\b(home|visitor|data)\.[A-Za-z]/);
    });

    it('shows a visitor their accounts under the notice that says how long they are kept - and no data card', async () => {
      await setup({ language: 'en', state: 'visitor' });

      expect(api.accounts).toHaveBeenCalledTimes(1);
      expect(cards()).toHaveLength(1);
      expect(text(host.querySelector('app-visitor-notice p'))).toBe(
        'You are not signed in. What you import is kept for this browser on our server for 30 days after your last visit. Signing in keeps it in your anotoki account.',
      );
      expect(host.querySelector('app-visitor-hero')).toBeNull();
      expect(host.querySelector('app-data-card')).toBeNull();
    });

    it('shows somebody signed in their accounts and the "Your data" card - and no notice', async () => {
      await setup({ language: 'en' });

      expect(cards()).toHaveLength(1);
      expect(text(host.querySelector('app-data-card h2'))).toBe('Your data');
      expect(host.querySelector('app-visitor-notice aside')).toBeNull();
      expect(host.querySelector('app-visitor-hero')).toBeNull();
    });

    it('starts over as for somebody new when the key opens nothing any more - and says so once', async () => {
      await setup({ language: 'en', state: 'visitor' });
      api.accounts.mockClear();

      // The interceptor forgot the key (401 visitor_unknown).
      visitor.key.set(null);
      visitor.lost.set(true);
      await fixture.whenStable();

      expect(api.accounts).not.toHaveBeenCalled();
      expect(texts('p.lost')).toEqual([en['error.visitor_unknown']]);
      expect(host.querySelector('app-visitor-hero')).not.toBeNull();
      expect(cards()).toEqual([]);
    });

    it('loads the accounts again after a sign-in, and after the visitor\'s accounts moved in', async () => {
      await setup({ language: 'en', state: 'visitor' });
      expect(api.accounts).toHaveBeenCalledTimes(1);

      auth.signedIn.set(true);
      await fixture.whenStable();
      expect(api.accounts).toHaveBeenCalledTimes(2);

      api.accounts.mockImplementation(() => of([account(1), account(2)]));
      visitor.moves.set(1);
      await fixture.whenStable();
      expect(api.accounts).toHaveBeenCalledTimes(3);
      expect(cards()).toHaveLength(2);
    });

    it('shows only the last load\'s answer when an earlier one fails after the state changed', async () => {
      const failing = new Subject<AccountSummary[]>();
      await setup({ language: 'en', state: 'visitor', accounts: failing });
      visitor.key.set(null);
      await fixture.whenStable();
      failing.error(new HttpErrorResponse({ status: 401, error: { code: 'visitor_unknown' } }));
      await fixture.whenStable();

      expect(host.querySelector('.error-panel')).toBeNull();
      expect(host.querySelector('app-visitor-hero')).not.toBeNull();
    });
  });

  describe('deleting an account', () => {
    it('asks first, in its own dialog and in the reader’s language', async () => {
      const browser = vi.spyOn(window, 'confirm');
      await setup();
      await askToDelete(cards()[0]);

      expect(text(dialog()?.querySelector('h2'))).toBe('Vymazať účet „Mona“ a všetko, čo sa preň importovalo?');
      expect(text(dialog()?.querySelector('.message'))).toBe('Buildy zostanú.');
      expect(dialogButtons().map(text)).toEqual(['Zrušiť', 'Vymazať']);
      expect(browser).not.toHaveBeenCalled();
      expect(api.deleteAccount).not.toHaveBeenCalled();
    });

    it('names an account without a name by its UID', async () => {
      await setup({ language: 'en', accounts: [account(1, { nickname: null })] });
      await askToDelete(cards()[0]);

      expect(text(dialog()?.querySelector('h2'))).toBe('Delete "UID 800000001" and everything imported for it?');
      expect(text(dialog()?.querySelector('.message'))).toBe('Builds are kept.');
      expect(dialogButtons().map(text)).toEqual(['Cancel', 'Delete']);
    });

    it('deletes nothing when the answer is no', async () => {
      await setup();
      await askToDelete(cards()[0]);
      dialogButtons()[0].click();
      await fixture.whenStable();

      expect(api.deleteAccount).not.toHaveBeenCalled();
      expect(cards()).toHaveLength(1);
      expect(snackBar.open).not.toHaveBeenCalled();
    });

    it('deletes on a yes, takes the card away and says so', async () => {
      await setup({ accounts: [account(1), account(2, { nickname: 'Keqing' })] });
      await askToDelete(cards()[0]);
      dialogButtons()[1].click();
      await fixture.whenStable();

      expect(api.deleteAccount).toHaveBeenCalledWith(1);
      expect(cards().map((card) => text(card.querySelector('.nickname')))).toEqual(['Keqing']);
      expect(snackBar.open).toHaveBeenCalledWith('Účet „Mona“ je vymazaný.', undefined, { duration: 3000 });
    });

    it('words a failure from its code, and keeps the card', async () => {
      await setup();
      api.deleteAccount.mockImplementation(() => throwError(() => new HttpErrorResponse({ status: 500, error: { message: 'Something went wrong on the server.', code: 'server_error' } })));
      await askToDelete(cards()[0]);
      dialogButtons()[1].click();
      await fixture.whenStable();

      expect(snackBar.open).toHaveBeenCalledWith('Na serveri sa niečo pokazilo.', 'Zavrieť');
      expect(cards()).toHaveLength(1);
    });
  });
});
