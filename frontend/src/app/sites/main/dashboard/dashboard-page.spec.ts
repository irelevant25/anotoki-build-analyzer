import { HttpErrorResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { Observable, Subject, of, throwError } from 'rxjs';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { ConfirmHostComponent } from '@anotoki/lib/ui/dialog';
import { ToastService } from '@anotoki/lib/ui/toast';
import { Api } from '../../../api/api';
import { AuthService } from '../../../shared/auth';
import { en } from '../../../shared/i18n/en';
import { AccountSummary, BuildsOverview } from '../../../api/models';
import { VisitorKey } from '../../../shared/visitor-key';
import { DashboardPage } from './dashboard-page';
import { provideKit } from '../../../shared/kit';

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

/** A game's builds as GET /api/builds tells of them. */
function overview(overrides: Partial<BuildsOverview> = {}): BuildsOverview {
  return { game: 'genshin', characters: 12, builds: 40, checked_at: hoursAgo(3), changed_at: hoursAgo(3), ...overrides };
}

/** The dashboard's Slovak, as the catalogue has it; English underneath, as the server merges it. */
const SLOVAK = {
  ...en,
  'common.cancel': 'Zrušiť',
  'common.close': 'Zavrieť',
  'common.retry': 'Skúsiť znova',
  'confirm.deleteAccount': 'Vymazať účet „{name}“ a všetko, čo sa preň importovalo?',
  'confirm.deleteAccountKept': 'Buildy zostanú.',
  'confirm.delete': 'Vymazať',
  'dashboard.title': 'Tvoje účty',
  'dashboard.subtitle': 'Importuj účet z hry Genshin Impact a porovnaj každú postavu s buildmi od komunity.',
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
  'builds.adminPage': 'Builds',
  'dashboard.builds': 'Buildy',
  'dashboard.manageBuilds': 'Spravovať',
  'dashboard.buildsFor.one': 'Buildy pre {count} postavu',
  'dashboard.buildsFor.few': 'Buildy pre {count} postavy',
  'dashboard.buildsFor.other': 'Buildy pre {count} postáv',
  'dashboard.buildsUpdated': 'aktualizované {when}',
  'dashboard.buildsNever': 'zatiaľ neimportované',
  'dashboard.noBuildsStaff': 'Zatiaľ tu nie sú žiadne buildy. Importuješ ich na stránke {link}.',
  'dashboard.noBuildsMember': 'Zatiaľ tu nie sú žiadne buildy. Pribudnú, keď ich editor importuje.',
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
  let api: { accounts: ReturnType<typeof vi.fn>; buildsOverview: ReturnType<typeof vi.fn>; deleteAccount: ReturnType<typeof vi.fn> };
  let toasts: { success: ReturnType<typeof vi.fn>; error: ReturnType<typeof vi.fn> };
  /** The kit's "Are you sure?", as the app's root draws it. */
  let confirmHost: ComponentFixture<ConfirmHostComponent>;
  let auth: { signedIn: ReturnType<typeof signal<boolean>>; isStaff: ReturnType<typeof signal<boolean>> };
  let visitor: { key: ReturnType<typeof signal<string | null>>; lost: ReturnType<typeof signal<boolean>>; moves: ReturnType<typeof signal<number>> };

  /** What an element says. */
  const text = (element: Element | null | undefined) => element?.textContent?.replace(/\s+/g, ' ').trim();
  const texts = (selector: string) => [...host.querySelectorAll(selector)].map(text);
  /** A card's counts: each a number above its caption. */
  const counts = (card: HTMLElement) => [...card.querySelectorAll('.counts div')].map((count) => [text(count.querySelector('strong')), text(count.querySelector('span'))].join(' '));
  const cards = () => [...host.querySelectorAll<HTMLElement>('article.account-card')];
  /** An account card's line under its counts: its data's kind, and when it was imported. */
  const imported = (card: HTMLElement) => text(card.querySelector('.account-main .cluster .muted'));
  const rows = () => [...host.querySelectorAll<HTMLElement>('.builds-row')];
  /** A builds row's words: its link's, then when. */
  const rowWords = (row: HTMLElement) => [...row.querySelectorAll('a, .muted')].map(text);
  /** What the page says about the accounts and the builds - the "Your data" card aside (it has cases of its own). */
  const pageText = () => {
    const copy = host.cloneNode(true) as HTMLElement;
    copy.querySelectorAll('app-data-card').forEach((card) => card.remove());
    return copy.textContent ?? '';
  };

  interface Options {
    language?: 'en' | 'sk';
    strings?: Record<string, string>;
    accounts?: AccountSummary[] | Observable<AccountSummary[]>;
    builds?: BuildsOverview[];
    /** An editor or an admin: the admin panel's Builds page is theirs. */
    admin?: boolean;
    /** Who looks: somebody signed in (the default), a visitor (a key held), or nobody. */
    state?: 'person' | 'visitor' | 'nobody';
  }

  async function setup(options: Options = {}): Promise<void> {
    const accounts = options.accounts ?? [account(1)];
    api = {
      accounts: vi.fn(() => (Array.isArray(accounts) ? of(accounts) : accounts)),
      buildsOverview: vi.fn(() => of(options.builds ?? [overview()])),
      deleteAccount: vi.fn(() => of(undefined)),
    };
    toasts = { success: vi.fn(), error: vi.fn() };
    const state = options.state ?? 'person';
    auth = { signedIn: signal(state === 'person'), isStaff: signal(options.admin ?? false) };
    visitor = { key: signal(state === 'visitor' ? 'K'.repeat(43) : null), lost: signal(false), moves: signal(0) };
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        (options.language ?? 'sk') === 'sk' ? provideTestTranslations({ ...SLOVAK, ...options.strings }, { language: 'sk', english: en }) : provideTestTranslations(en),
        provideKit(),
        { provide: Api, useValue: api },
        { provide: AuthService, useValue: { ...auth, isAdmin: auth.isStaff, available: signal(true), failure: signal(null), user: signal(null) } },
        { provide: VisitorKey, useValue: visitor },
        { provide: ToastService, useValue: toasts },
      ],
    });
    fixture = TestBed.createComponent(DashboardPage);
    host = fixture.nativeElement;
    confirmHost = TestBed.createComponent(ConfirmHostComponent);
    document.body.appendChild(confirmHost.nativeElement);
    await fixture.whenStable();
  }

  /** Opens an account card's menu (the kit's) and chooses Delete: the kit's question is then on the page. */
  async function askToDelete(card: HTMLElement): Promise<void> {
    card.querySelector<HTMLButtonElement>('.account-actions button')!.click();
    await fixture.whenStable();
    card.querySelector<HTMLButtonElement>('anotoki-menu [role="menuitem"]')!.click();
    await settleAll();
  }
  /** The page and the question both drawn after whatever a click began. */
  async function settleAll(): Promise<void> {
    for (let turn = 0; turn < 3; turn++) {
      await new Promise((done) => setTimeout(done));
      await fixture.whenStable();
      await confirmHost.whenStable();
    }
  }
  const dialog = () => confirmHost.nativeElement.querySelector('anotoki-dialog') as HTMLElement | null;
  const dialogButtons = () => [...(dialog()?.querySelectorAll<HTMLButtonElement>('[data-answer]') ?? [])];

  afterEach(() => {
    vi.restoreAllMocks();
    confirmHost?.nativeElement.remove();
  });

  it('words an account’s card in Slovak around the games’ own words', async () => {
    await setup({
      accounts: [account(1), account(2, { uid: null, nickname: 'Lumine', level: 70, has_full_inventory: false, last_import_at: null })],
    });

    expect(texts('h1')).toEqual(['Tvoje účty']);
    expect(text(host.querySelector('anotoki-page-header a'))).toBe('Importovať účet');

    const [first, second] = cards();
    // UID and AR are the game's words: written as they are.
    expect(text(first.querySelector('.names .small'))).toBe('UID 800000001 · AR 58');
    expect(text(second.querySelector('.names .small'))).toBe('UID chýba · AR 70');
    // A number with its caption under it: the site's word for characters, the game's for the rest.
    expect(counts(first)).toEqual(['12 Postavy', '187 Artifacts', '23 Weapons']);
    expect(text(first.querySelector('anotoki-badge'))).toBe('Celý inventár');
    expect(text(second.querySelector('anotoki-badge'))).toBe('Len Showcase');
    // A relative time goes into the sentence; a missing one is a sentence of its own - never "never" glued on.
    expect(imported(first)).toBe('Importované pred 2 dňami');
    expect(imported(second)).toBe('Zatiaľ bez importu');
    expect([...first.querySelectorAll('.account-actions a')].map(text)).toEqual(['Otvoriť', 'Aktualizovať údaje']);
    expect(first.querySelector('.account-actions button')?.getAttribute('aria-label')).toBe('Ďalšie akcie');

    expect(pageText()).not.toMatch(A_KEY);
    expect(pageText()).not.toMatch(/never|characters|artifacts|weapons/);
  });

  it('counts the characters with builds the way Slovak counts, and says when they were brought up to date', async () => {
    await setup({ builds: [overview({ characters: 1 })] });
    expect(texts('.section-head h2').at(-1)).toBe('Buildy');
    expect(rows().map(rowWords)).toEqual([['Buildy pre 1 postavu', 'aktualizované pred 3 hodinami']]);
    expect(rows()[0].querySelector('a')?.getAttribute('href')).toBe('/builds/genshin');
    expect(rows()[0].querySelector('.status-dot')?.className).toBe('status-dot success');

    TestBed.resetTestingModule();
    await setup({ builds: [overview({ characters: 3 })] });
    expect(rows().map(rowWords)).toEqual([['Buildy pre 3 postavy', 'aktualizované pred 3 hodinami']]);

    TestBed.resetTestingModule();
    await setup({ builds: [overview({ characters: 12, builds: 0, checked_at: null, changed_at: null })] });
    expect(rows().map(rowWords)).toEqual([['Buildy pre 12 postáv', 'zatiaľ neimportované']]);
    // The status dot's class is not a word: a game without builds has none.
    expect(rows()[0].querySelector('.status-dot')?.className).toBe('status-dot');
  });

  it('reads English as it always did', async () => {
    await setup({
      language: 'en',
      accounts: [account(1), account(2, { uid: null, last_import_at: null, has_full_inventory: false })],
      builds: [overview({ characters: 1 })],
    });

    expect(texts('h1')).toEqual(['Your accounts']);
    const [first, second] = cards();
    expect(text(first.querySelector('.names .small'))).toBe('UID 800000001 · AR 58');
    expect(text(second.querySelector('.names .small'))).toBe('No UID · AR 58');
    expect(counts(first)).toEqual(['12 Characters', '187 Artifacts', '23 Weapons']);
    expect(imported(first)).toBe('Imported 2 days ago');
    expect(imported(second)).toBe('Not imported yet');
    expect(rows().map(rowWords)).toEqual([['Builds for 1 character', 'updated 3 hours ago']]);
    expect(texts('.section-head h2').at(-1)).toBe('Builds');
  });

  it('invites to import, a button for each game the site offers, while there is no account', async () => {
    await setup({ accounts: [] });

    expect(texts('anotoki-empty-state .anotoki-empty-state__heading')).toEqual(['Pozrime sa na tvoje postavy']);
    const buttons = [...host.querySelectorAll<HTMLAnchorElement>('anotoki-empty-state a')];
    expect(buttons.map(text)).toEqual(['Importovať z hry Genshin Impact']);
    expect(buttons.map((button) => button.getAttribute('href'))).toEqual(['/import?game=genshin']);
  });

  describe('while no build has been imported', () => {
    const NONE = [overview({ builds: 0, characters: 0, checked_at: null, changed_at: null })];
    const sentence = () => host.querySelector<HTMLElement>('.game-section p.muted');

    it('sends an editor to the admin panel’s Builds page, the link inside the sentence', async () => {
      await setup({ admin: true, builds: NONE });

      expect(text(sentence())).toBe('Zatiaľ tu nie sú žiadne buildy. Importuješ ich na stránke Builds.');
      const links = [...sentence()!.querySelectorAll('a')];
      expect(links.map((link) => [text(link), link.getAttribute('href')])).toEqual([['Builds', '/admin/builds']]);
      expect(texts('.section-head a')).toEqual(['Spravovať']);
    });

    it('reads the same in English', async () => {
      await setup({ language: 'en', admin: true, builds: NONE });

      expect(text(sentence())).toBe('No builds yet. Import them on the Builds page.');
      expect(text(sentence()!.querySelector('a'))).toBe('Builds');
    });

    it('draws no link for a language whose sentence does not name the page', async () => {
      await setup({ admin: true, builds: NONE, strings: { 'dashboard.noBuildsStaff': 'Zatiaľ tu nie sú žiadne buildy.' } });

      expect(text(sentence())).toBe('Zatiaľ tu nie sú žiadne buildy.');
      expect(sentence()!.querySelector('a')).toBeNull();
    });

    it('tells everybody else to wait for an editor', async () => {
      await setup({ admin: false, builds: NONE });

      expect(text(sentence())).toBe('Zatiaľ tu nie sú žiadne buildy. Pribudnú, keď ich editor importuje.');
      expect(sentence()!.querySelector('a')).toBeNull();
      expect(host.querySelector('.section-head a')).toBeNull();
    });
  });

  describe('when its data cannot be loaded', () => {
    const refused = new HttpErrorResponse({ status: 403, error: { message: 'Only an editor or an admin can do this.', code: 'editor_only' } });

    it('words the failure from its code, never with the server’s sentence', async () => {
      await setup({ accounts: throwError(() => refused) });

      expect(text(host.querySelector('anotoki-error-state .anotoki-alert__text'))).toBe('Toto môže urobiť len editor alebo správca.');
      expect(host.textContent).not.toContain('Only an editor or an admin can do this.');
      expect(text(host.querySelector('anotoki-error-state button'))).toBe('Skúsiť znova');
      expect(host.querySelector('anotoki-error-state .anotoki-alert__heading')).toBeNull();
      expect(host.querySelector('anotoki-empty-state')).toBeNull();
    });

    it('says the server is out of reach - and nothing about starting a backend', async () => {
      await setup({ accounts: throwError(() => new HttpErrorResponse({ status: 0 })) });

      expect(text(host.querySelector('anotoki-error-state .anotoki-alert__text'))).toBe('Nepodarilo sa spojiť so serverom. Skontroluj pripojenie a skús to znova.');
      expect(host.querySelector('anotoki-error-state .anotoki-alert__heading')).toBeNull();
    });

    it('follows the language: the same failure is worded again', async () => {
      await setup({ language: 'en', accounts: throwError(() => refused) });

      expect(text(host.querySelector('anotoki-error-state .anotoki-alert__text'))).toBe('Only an editor or an admin can do this.');
      expect(text(host.querySelector('anotoki-error-state button'))).toBe('Retry');
    });

    it('asks again', async () => {
      await setup({ accounts: throwError(() => refused) });
      api.accounts.mockImplementation(() => of([account(1)]));
      host.querySelector<HTMLButtonElement>('anotoki-error-state button')!.click();
      await fixture.whenStable();

      expect(host.querySelector('anotoki-error-state')).toBeNull();
      expect(cards()).toHaveLength(1);
    });
  });

  describe('three states: nobody, a visitor, somebody signed in', () => {
    it("shows nobody what the site does and the ways on - and asks for nobody's accounts", async () => {
      await setup({ language: 'en', state: 'nobody' });

      expect(api.accounts).not.toHaveBeenCalled();
      expect(api.buildsOverview).toHaveBeenCalledTimes(1);
      expect(texts('app-visitor-hero h2')).toEqual([en['home.title']]);
      const links = [...host.querySelectorAll<HTMLAnchorElement>('app-visitor-hero a')];
      expect(links.map((link) => [text(link), link.getAttribute('href')])).toEqual([
        ['Import your account', '/import'],
        ['Browse the Genshin Impact builds', '/builds/genshin'],
      ]);
      expect(texts('app-visitor-hero button')).toEqual(['Sign in']);
      // The builds are everybody's.
      expect(rows()).toHaveLength(1);
      expect(host.querySelector('anotoki-page-header')).toBeNull();
      expect(host.querySelector('app-visitor-notice anotoki-alert')).toBeNull();
      expect(host.querySelector('app-data-card')).toBeNull();
      expect(host.textContent).not.toMatch(/\b(home|visitor|data)\.[A-Za-z]/);
    });

    it('shows a visitor their accounts under the notice that says how long they are kept - and no data card', async () => {
      await setup({ language: 'en', state: 'visitor' });

      expect(api.accounts).toHaveBeenCalledTimes(1);
      expect(cards()).toHaveLength(1);
      expect(text(host.querySelector('app-visitor-notice .anotoki-alert__text'))).toBe(
        'You are not signed in. What you import is kept for this browser on our server for 30 days after your last visit. Signing in keeps it in your anotoki account.',
      );
      expect(host.querySelector('app-visitor-hero')).toBeNull();
      expect(host.querySelector('app-data-card')).toBeNull();
    });

    it('shows somebody signed in their accounts and the "Your data" card - and no notice', async () => {
      await setup({ language: 'en' });

      expect(cards()).toHaveLength(1);
      expect(text(host.querySelector('app-data-card h2'))).toBe('Your data');
      expect(host.querySelector('app-visitor-notice anotoki-alert')).toBeNull();
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
      expect(texts('anotoki-alert.lost .anotoki-alert__text')).toEqual([en['error.visitor_unknown']]);
      expect(host.querySelector('app-visitor-hero')).not.toBeNull();
      expect(cards()).toEqual([]);
    });

    it("loads the accounts again after a sign-in, and after the visitor's accounts moved in", async () => {
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

    it("shows only the last load's answer when an earlier one fails after the state changed", async () => {
      const failing = new Subject<AccountSummary[]>();
      await setup({ language: 'en', state: 'visitor', accounts: failing });
      visitor.key.set(null);
      await fixture.whenStable();
      failing.error(new HttpErrorResponse({ status: 401, error: { code: 'visitor_unknown' } }));
      await fixture.whenStable();

      expect(host.querySelector('anotoki-error-state')).toBeNull();
      expect(host.querySelector('app-visitor-hero')).not.toBeNull();
    });
  });

  describe('deleting an account', () => {
    it("asks first, in the kit's question and in the reader’s language - its no first, its yes the danger", async () => {
      const browser = vi.spyOn(window, 'confirm');
      await setup();
      await askToDelete(cards()[0]);

      expect(text(dialog()?.querySelector('h2'))).toBe('Vymazať účet „Mona“ a všetko, čo sa preň importovalo?');
      expect(text(dialog()?.querySelector('.anotoki-confirm-host__message'))).toBe('Buildy zostanú.');
      expect(dialogButtons().map(text)).toEqual(['Zrušiť', 'Vymazať']);
      expect(dialogButtons()[1].classList).toContain('anotoki-button--danger');
      expect(browser).not.toHaveBeenCalled();
      expect(api.deleteAccount).not.toHaveBeenCalled();
    });

    it('names an account without a name by its UID', async () => {
      await setup({ language: 'en', accounts: [account(1, { nickname: null })] });
      await askToDelete(cards()[0]);

      expect(text(dialog()?.querySelector('h2'))).toBe('Delete "UID 800000001" and everything imported for it?');
      expect(text(dialog()?.querySelector('.anotoki-confirm-host__message'))).toBe('Builds are kept.');
      expect(dialogButtons().map(text)).toEqual(['Cancel', 'Delete']);
    });

    it('deletes nothing when the answer is no', async () => {
      await setup();
      await askToDelete(cards()[0]);
      dialogButtons()[0].click();
      await settleAll();

      expect(api.deleteAccount).not.toHaveBeenCalled();
      expect(cards()).toHaveLength(1);
      expect(toasts.success).not.toHaveBeenCalled();
    });

    it('deletes on a yes, takes the card away and says so', async () => {
      await setup({ accounts: [account(1), account(2, { nickname: 'Keqing' })] });
      await askToDelete(cards()[0]);
      dialogButtons()[1].click();
      await settleAll();

      expect(api.deleteAccount).toHaveBeenCalledWith(1);
      expect(cards().map((card) => text(card.querySelector('.nickname')))).toEqual(['Keqing']);
      expect(toasts.success).toHaveBeenCalledWith('Účet „Mona“ je vymazaný.');
    });

    it('words a failure from its code, and keeps the card', async () => {
      await setup();
      api.deleteAccount.mockImplementation(() => throwError(() => new HttpErrorResponse({ status: 500, error: { message: 'Something went wrong on the server.', code: 'server_error' } })));
      await askToDelete(cards()[0]);
      dialogButtons()[1].click();
      await settleAll();

      expect(toasts.error).toHaveBeenCalledWith('Na serveri sa niečo pokazilo.');
      expect(cards()).toHaveLength(1);
    });
  });
});
