import { HttpErrorResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of, throwError } from 'rxjs';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { STEPPER_LIBRARY_WORDS } from '@anotoki/lib/words/stepper';
import { By } from '@angular/platform-browser';
import { Api } from '../../../api/api';
import { AuthService } from '../../../shared/auth';
import { en } from '../../../shared/i18n/en';
import { TranslationService } from '../../../shared/i18n/translation';
import { AccountSummary, ImportRun } from '../../../api/models';
import { VisitorKey } from '../../../shared/visitor-key';
import { ImportWizard } from './import-wizard';
import { provideKit } from '../../../shared/kit';

/**
 * Every string of the site, marked: what a page shows without these marks did not come from a key. The kit's
 * stepper's words too - the library's, which a site reads from its database's rows -, around which the stepper sets
 * only its punctuation ("Step 2 of 4: ", ", done").
 */
const MARKED: Record<string, string> = Object.fromEntries(Object.entries({ ...en, ...STEPPER_LIBRARY_WORDS.en }).map(([key, value]) => [key, `«${value}»`]));

/** The informal Slovak of what these tests read; everything else is the marked English. */
const SLOVAK: Record<string, string> = {
  ...MARKED,
  'importWizard.title': 'Importuj svoj účet',
  'importWizard.step.method': 'Spôsob',
  'importWizard.step.prepare': 'Príprava',
  'importWizard.step.import': 'Import',
  'importWizard.step.done': 'Hotovo',
  'importWizard.prepare.ready': 'Mám pripravené',
  'importWizard.uid.example': 'napr. 700123456',
  'importWizard.uid.submit': 'Importovať Character Showcase',
  'importWizard.target.accountUid': '{name} · UID {uid}',
  'importWizard.target.accountNoUid': '{name} · UID zatiaľ chýba',
  'importWizard.target.fileHint': 'Úplný export nahradí v účte toto: {weapons}, {items}. Postavy zostanú.',
  'importWizard.file.info': '{name} · {kilobytes} KB',
  'importWizard.file.infoDetected': '{name} · {kilobytes} KB · rozpoznaný export z hry {game}',
  'importWizard.file.submit': 'Importovať súbor',
  'importWizard.fileTooLarge': 'Súbor má viac ako 20 MB; to nie je súbor z exportovacieho nástroja.',
  'importWizard.done.heading': 'Import je hotový: {name}',
  'importWizard.done.headingNoName': 'Import je hotový',
  'importWizard.done.characters': 'Postavy',
  'importWizard.done.unmatched': 'Zatiaľ chýbajú v katalógu hry (zostávajú pod vlastnými názvami): {names}',
  'importGuide.genshin.showcase.title': 'Character Showcase (UID)',
  'importGuide.genshin.file.step1.title': 'Zaobstaraj si exportovací nástroj',
  'importGuide.genshin.file.fileHint': 'Súbor .json vo formáte GOOD',
  'import.error.file_not_json': 'Súbor nie je platný JSON.',
  'import.error.showcase_player_not_found': 'Hráč sa nenašiel (skontroluj UID aj hru).',
  'import.error.account_uid_mismatch': 'Údaje majú UID {data_uid}, ale vybraný účet má UID {account_uid}.',
  'import.error.uid_invalid': 'UID má 9 alebo 10 číslic.',
  'import.error.account_missing': 'Vybraný účet neexistuje.',
  'import.warning.unknown_character': 'Neznáma postava, import ju preskočil: {ref}',
  'import.warning.good_artifacts_skipped': 'Artifacts, ktoré import preskočil (chýba set, slot alebo main stat): {count}',
  'error.invalid': 'Niektoré zadané údaje nie sú platné. Skontroluj ich a skús to znova.',
  'error.offline': 'Nepodarilo sa spojiť so serverom. Skontroluj pripojenie a skús to znova.',
  'error.too_many_imports': 'To je veľa importov za jednu minútu. Chvíľu počkaj a skús to znova.',
  'error.http': 'Požiadavka zlyhala (HTTP {status}).',
  'error.unknown': 'Niečo sa pokazilo.',
  'importWizard.steps': 'Kroky importu',
  // The library's own words, as its migration writes them to a site's database.
  ...STEPPER_LIBRARY_WORDS.sk,
};

/** What the server would say in English: a member must never read it. */
const SERVER_ENGLISH = 'The server’s own English sentence.';

function account(id: number, change: Partial<AccountSummary> = {}): AccountSummary {
  return {
    id,
    game: 'genshin',
    uid: '700000001',
    nickname: 'Mona',
    level: 60,
    world_level: 8,
    signature: null,
    has_full_inventory: false,
    last_import_at: null,
    counts: { characters: 8, weapons: 8, items: 40 },
    meta: null,
    ...change,
  };
}

function finished(change: Partial<ImportRun> = {}): ImportRun {
  return {
    id: 11,
    game: 'genshin',
    source: 'file',
    status: 'success',
    file_name: 'inventory.json',
    summary: { characters: 12, weapons: 31, items: 1523, full_inventory: true, warnings: [], unmatched: {} },
    error: null,
    error_code: null,
    error_params: null,
    created_at: '2026-09-30T10:00:00Z',
    account: account(1),
    account_id: 1,
    ...change,
  };
}

function refusedWith(status: number, body: unknown): HttpErrorResponse {
  return new HttpErrorResponse({ status, error: body });
}

/** A file as the browser hands it over; only what the wizard reads. */
function file(name: string, content: string, size = content.length): File {
  return { name, size, text: () => Promise.resolve(content) } as unknown as File;
}

describe('the import wizard', () => {
  let fixture: ComponentFixture<ImportWizard>;
  let host: HTMLElement;
  let i18n: TranslationService;
  let api: { accounts: ReturnType<typeof vi.fn>; importShowcase: ReturnType<typeof vi.fn>; importFile: ReturnType<typeof vi.fn> };

  /** `who`: somebody signed in (the default), a visitor (a key held), or nobody. */
  async function wizard(options: { language?: 'en' | 'sk'; strings?: Record<string, string>; accounts?: AccountSummary[]; who?: 'person' | 'visitor' | 'nobody' } = {}): Promise<void> {
    const who = options.who ?? 'person';
    api = { accounts: vi.fn(() => of(options.accounts ?? [])), importShowcase: vi.fn(), importFile: vi.fn() };
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        options.language === 'en' ? provideTestTranslations(en) : provideTestTranslations(options.strings ?? SLOVAK, { language: 'sk', english: en }),
        provideKit(),
        { provide: Api, useValue: api },
        { provide: AuthService, useValue: { signedIn: signal(who === 'person'), available: signal(true), user: signal(null), failure: signal(null) } },
        { provide: VisitorKey, useValue: { current: () => (who === 'visitor' ? 'K'.repeat(43) : null) } },
      ],
    });
    fixture = TestBed.createComponent(ImportWizard);
    host = fixture.nativeElement;
    i18n = TestBed.inject(TranslationService);
    await settle();
  }

  /** Lets what a click or a file began finish, and the page be drawn. */
  async function settle(): Promise<void> {
    for (let turn = 0; turn < 3; turn++) {
      await Promise.resolve();
      await fixture.whenStable();
    }
  }

  /** An element's words (the kit's icons are drawings, with no words of their own). */
  const text = (element: Element | null | undefined): string | undefined => element?.textContent?.replace(/\s+/g, ' ').trim();
  /** The import's button: by UID, or of a file. */
  const submit = (which: 'uid' | 'file') => host.querySelector<HTMLButtonElement>(`.${which}-submit`)!;
  /** What went wrong: the kit's alert, said aloud as it appears. */
  const problem = () => host.querySelector<HTMLElement>('.error');
  const problemText = () => text(problem()?.querySelector('.anotoki-alert__text'));
  /** The names of the kit's icons. */
  const icons = (selector: string) => fixture.debugElement.queryAll(By.css(selector)).map((icon) => (icon.componentInstance as IconComponent).name());

  /** Step 1 by its card (the game is the one the site offers), step 2 by its only filled button. */
  async function choose(method: 'showcase' | 'file'): Promise<void> {
    host.querySelectorAll<HTMLButtonElement>('button.choice.method')[method === 'showcase' ? 0 : 1].click();
    await settle();
    [...host.querySelectorAll<HTMLButtonElement>('button')].find((button) => text(button) === i18n.t('importWizard.prepare.ready'))!.click();
    await settle();
  }

  /** Back to a step done, by its button in the row of steps (0 the way in, 1 the guide). */
  async function backTo(step: number): Promise<void> {
    host.querySelectorAll<HTMLButtonElement>('.anotoki-stepper__button')[step].click();
    await settle();
  }

  async function typeUid(uid: string): Promise<void> {
    const input = host.querySelector<HTMLInputElement>('input[name="uid"]')!;
    input.value = uid;
    input.dispatchEvent(new Event('input'));
    await settle();
  }

  async function drop(dropped: File): Promise<void> {
    const event = new Event('drop', { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'dataTransfer', { value: { files: [dropped] } });
    host.querySelector('.drop')!.dispatchEvent(event);
    await settle();
  }

  /** Every piece of text the page shows, except the icons' names. */
  function shown(): string[] {
    const walker = document.createTreeWalker(host, NodeFilter.SHOW_TEXT);
    const found: string[] = [];
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
      const value = node.textContent?.replace(/\s+/g, ' ').trim() ?? '';
      if (value) {
        found.push(value);
      }
    }
    return found;
  }

  /**
   * What may stand on the page without having come from a key: the games' own words, names, and plain numbers - and
   * the punctuation the kit's stepper sets around its words ("«Step 3 of 4»:", ", «done»").
   */
  const notFromAKey = (allowed: string[]) => [...new Set(shown())].filter((value) => !/^,? ?«.*»:?$/.test(value) && !/^\d+$/.test(value) && !allowed.includes(value));

  afterEach(() => vi.restoreAllMocks());

  // ── The words ──────────────────────────────────────────────────────────────

  it('names its steps in the language on the page - the one in hand marked, the ones done a way back, saying they are done', async () => {
    await wizard();

    expect(text(host.querySelector('h1'))).toBe('Importuj svoj účet');
    // The kit's stepper: a list named for a screen reader, the step in hand marked and heard as which of how many.
    expect(host.querySelector('.anotoki-stepper__steps')?.getAttribute('aria-label')).toBe('Kroky importu');
    expect([...host.querySelectorAll('.anotoki-stepper__label')].map(text)).toEqual(['Spôsob', 'Príprava', 'Import', 'Hotovo']);
    expect([...host.querySelectorAll('.anotoki-stepper__step')].map((step) => step.getAttribute('aria-current'))).toEqual(['step', null, null, null]);
    expect(text(host.querySelector('.anotoki-stepper__step--current'))).toBe('1 Krok 1 z 4: Spôsob');

    document.body.appendChild(host);
    host.querySelector<HTMLButtonElement>('button.choice')!.click();
    await settle();
    expect([...host.querySelectorAll('.anotoki-stepper__step')].map((step) => step.getAttribute('aria-current'))).toEqual([null, 'step', null, null]);
    // The step done: a button back to it, its name and that it is done for a screen reader.
    const done = host.querySelector<HTMLButtonElement>('.anotoki-stepper__button')!;
    expect(text(done)).toBe('Spôsob, hotovo');
    expect(text(done.querySelector('.anotoki-visually-hidden'))).toBe(', hotovo');
    // The focus on the new step's heading: a keyboard and a screen reader follow.
    expect(document.activeElement?.classList.contains('step-title')).toBe(true);
    expect(text(document.activeElement)).toBe(text(host.querySelector('.step-panel h2')));
    host.remove();

    expect(await i18n.setLanguage('en', { remember: false })).toBe(true);
    await settle();
    expect(text(host.querySelector('h1'))).toBe('Import your account');
    expect([...host.querySelectorAll('.anotoki-stepper__label')].map(text)).toEqual(['Method', 'Prepare', 'Import', 'Done']);
    expect(host.querySelector('.anotoki-stepper__steps')?.getAttribute('aria-label')).toBe("The import's steps");
    expect(text(host.querySelector('.anotoki-stepper__button .anotoki-visually-hidden'))).toBe(', done');

    // Back to the step done.
    host.querySelector<HTMLButtonElement>('.anotoki-stepper__button')!.click();
    await settle();
    expect(host.querySelector('.anotoki-stepper__step')?.getAttribute('aria-current')).toBe('step');
    expect(host.querySelectorAll('button.choice.method')).toHaveLength(2);
  });

  it('takes every word of the way to a file import from a key', async () => {
    await wizard({ strings: MARKED, accounts: [account(1), account(2, { uid: null, nickname: 'Second' })] });
    await choose('file');

    expect(notFromAKey(['Genshin Impact', 'Second'])).toEqual([]);
    expect(text(host.querySelector('.drop .small'))).toBe('«A .json file in the GOOD format»');
    expect(text(host.querySelector('.account-field .anotoki-field__hint'))).toBe("«A full export replaces the account's Weapons and Artifacts; characters are kept.»");

    // Back to the guide (a step is drawn at a time): the chosen game's and way's, step by step.
    await backTo(1);
    expect(notFromAKey(['Genshin Impact', 'Second'])).toEqual([]);
    expect([...host.querySelectorAll('.guide-title')].map(text)).toEqual([
      '«Get an inventory exporter»',
      '«Start the exporter before the game»',
      '«Log into Genshin Impact»',
      '«Export the GOOD file»',
    ]);
    expect([...host.querySelectorAll('.tip')].map(text)).toHaveLength(2);
    expect([...host.querySelectorAll('.trouble li')].map(text)).toHaveLength(3);
  });

  it('takes every word of the way to an import by UID from a key', async () => {
    await wizard({ strings: MARKED, accounts: [account(1), account(2, { uid: null, nickname: 'Second' })] });
    await choose('showcase');

    expect(notFromAKey(['Genshin Impact', 'Genshin Impact UID'])).toEqual([]);
    expect(host.querySelector<HTMLInputElement>('input[name="uid"]')!.placeholder).toBe('«e.g. 700123456»');
    await backTo(1);
    expect(notFromAKey(['Genshin Impact'])).toEqual([]);
    expect([...host.querySelectorAll('.guide-title')].map(text)).toHaveLength(5);
  });

  it('lists the person’s accounts of the game with their UID, or says that one has none yet', async () => {
    await wizard({ accounts: [account(1), account(2, { uid: null, nickname: 'Second' })] });
    await choose('showcase');

    const options = [...host.querySelectorAll('.account-field option')].map(text);
    expect(options.slice(1)).toEqual(['Mona · UID 700000001', 'Second · UID zatiaľ chýba']);
  });

  // ── Somebody not signed in ─────────────────────────────────────────────────

  it('asks nobody for accounts they cannot have, and says before the first import what will be kept', async () => {
    await wizard({ language: 'en', who: 'nobody' });

    expect(api.accounts).not.toHaveBeenCalled();
    expect(text(host.querySelector('app-visitor-notice .anotoki-alert__text'))).toBe(
      'You are not signed in. What you import is kept for this browser on our server for 30 days after your last visit. Signing in keeps it in your anotoki account.',
    );
    // The way on is the same as anybody's.
    await choose('showcase');
    expect(host.querySelector('.account-field')).toBeNull();
  });

  it("lists a visitor's own accounts, under the notice", async () => {
    await wizard({ language: 'en', who: 'visitor', accounts: [account(1)] });

    expect(api.accounts).toHaveBeenCalledTimes(1);
    expect(host.querySelector('app-visitor-notice anotoki-alert')).not.toBeNull();
  });

  it("words a visitor's limits from their code and {max} - each one offering the sign-in", async () => {
    await wizard({ language: 'en', who: 'visitor' });
    await choose('showcase');
    await typeUid('700123456');
    const cases: [HttpErrorResponse, string][] = [
      [
        refusedWith(422, { message: SERVER_ENGLISH, code: 'visitor_limit', params: { limit: 'accounts', max: 5 } }),
        'Without signing in, this browser keeps at most 5 game accounts. Import into one of them, or sign in to add more.',
      ],
      [
        refusedWith(422, { message: SERVER_ENGLISH, code: 'visitor_limit', params: { limit: 'file_size', max: 5120 } }),
        'Without signing in, a file can be up to 5120 KB. Sign in to import a larger one.',
      ],
      [refusedWith(429, { message: SERVER_ENGLISH, code: 'too_many_visitors' }), 'Too many people are importing without signing in right now. Sign in, or try again later.'],
      [refusedWith(503, { message: SERVER_ENGLISH, code: 'visitors_unavailable' }), 'Importing without signing in is not ready on this server yet. Sign in to import.'],
    ];
    for (const [answer, sentence] of cases) {
      api.importShowcase.mockReturnValueOnce(throwError(() => answer));
      submit('uid').click();
      await settle();
      expect(problemText(), JSON.stringify(answer.error)).toBe(sentence);
    }
  });

  // ── A request that fails ───────────────────────────────────────────────────

  it('words a refused import from its code and parameters - never with the server’s sentence', async () => {
    await wizard();
    await choose('showcase');
    await typeUid('700123456');

    const cases: [HttpErrorResponse, string][] = [
      [refusedWith(422, { message: SERVER_ENGLISH, code: 'showcase_player_not_found', params: {} }), 'Hráč sa nenašiel (skontroluj UID aj hru).'],
      [
        refusedWith(422, { message: SERVER_ENGLISH, code: 'account_uid_mismatch', params: { data_uid: '700000002', account_uid: '700000001' } }),
        'Údaje majú UID 700000002, ale vybraný účet má UID 700000001.',
      ],
      // A code this build does not know, or one that came without what its sentence needs: one general sentence.
      [refusedWith(422, { message: SERVER_ENGLISH, code: 'something_new', params: {} }), 'Niečo sa pokazilo.'],
      [refusedWith(422, { message: SERVER_ENGLISH, code: 'account_uid_mismatch', params: {} }), 'Niečo sa pokazilo.'],
      // The route's own check of what was sent: the field says which sentence.
      [refusedWith(422, { message: SERVER_ENGLISH, code: 'invalid', errors: { uid: ['A UID is 9 or 10 digits long.'] } }), 'UID má 9 alebo 10 číslic.'],
      [refusedWith(422, { message: SERVER_ENGLISH, code: 'invalid', errors: { account_id: ['The selected account does not exist.'] } }), 'Vybraný účet neexistuje.'],
      [refusedWith(422, { message: SERVER_ENGLISH, code: 'invalid', errors: { game: ['The selected game is invalid.'] } }), 'Niektoré zadané údaje nie sú platné. Skontroluj ich a skús to znova.'],
      [refusedWith(429, { message: SERVER_ENGLISH, code: 'too_many_imports' }), 'To je veľa importov za jednu minútu. Chvíľu počkaj a skús to znova.'],
      [refusedWith(502, '<html>Bad gateway</html>'), 'Požiadavka zlyhala (HTTP 502).'],
      [refusedWith(0, null), 'Nepodarilo sa spojiť so serverom. Skontroluj pripojenie a skús to znova.'],
    ];
    for (const [answer, sentence] of cases) {
      api.importShowcase.mockReturnValueOnce(throwError(() => answer));
      submit('uid').click();
      await settle();
      expect(problemText(), JSON.stringify(answer.error)).toBe(sentence);
    }
    expect(api.importShowcase).toHaveBeenCalledTimes(cases.length);
    expect(api.importShowcase).toHaveBeenLastCalledWith('genshin', '700123456', null);
    expect(problem()!.getAttribute('role')).toBe('alert');
    expect(host.textContent).not.toContain(SERVER_ENGLISH);
  });

  it('keeps the failure as a key: another language words the same failure again', async () => {
    await wizard();
    await choose('showcase');
    await typeUid('700123456');
    api.importShowcase.mockReturnValueOnce(throwError(() => refusedWith(422, { message: SERVER_ENGLISH, code: 'showcase_player_not_found', params: {} })));
    submit('uid').click();
    await settle();
    expect(problemText()).toBe('Hráč sa nenašiel (skontroluj UID aj hru).');

    expect(await i18n.setLanguage('en', { remember: false })).toBe(true);
    await settle();
    expect(problemText()).toBe('No player with this UID exists (check the UID and the game).');
  });

  it('sends nothing while the UID is not 9 or 10 digits', async () => {
    await wizard();
    await choose('showcase');
    await typeUid('7001');

    expect(submit('uid').disabled).toBe(true);
    host.querySelector('form')!.dispatchEvent(new Event('submit', { cancelable: true }));
    await settle();
    expect(api.importShowcase).not.toHaveBeenCalled();
    // Once asked for (or once the box is left), the box says why: the kit's field, its error tied to it.
    expect(text(host.querySelector('.uid-field .anotoki-field__error'))).toBe(SLOVAK['importWizard.uid.invalid'] ?? MARKED['importWizard.uid.invalid']);
    expect(host.querySelector('input[name="uid"]')?.getAttribute('aria-invalid')).toBe('true');
  });

  // ── The file ───────────────────────────────────────────────────────────────

  it('refuses a file that cannot be an export before anything is sent, in the words the server has for it', async () => {
    await wizard();
    await choose('file');

    await drop(file('huge.json', '{}', 20 * 1024 * 1024 + 1));
    expect(problemText()).toBe('Súbor má viac ako 20 MB; to nie je súbor z exportovacieho nástroja.');

    await drop(file('notes.json', 'not json at all'));
    expect(problemText()).toBe('Súbor nie je platný JSON.');

    expect(host.querySelector('.drop-icon.ok')).toBeNull();
    expect(submit('file').disabled).toBe(true);
    expect(api.importFile).not.toHaveBeenCalled();

    // The refusal is a key, so it follows the language.
    expect(await i18n.setLanguage('en', { remember: false })).toBe(true);
    await settle();
    expect(problemText()).toBe('The file is not valid JSON.');
  });

  it('says which file was chosen - its name, its size, the game it is from - in the language on the page', async () => {
    await wizard();
    await choose('file');

    await drop(file('inventory.json', '{"format": "GOOD"}', 20_971_520));
    expect(problem()).toBeNull();
    expect(text(host.querySelector('.drop strong'))).toBe('inventory.json · 20 480 KB · rozpoznaný export z hry Genshin Impact');

    expect(await i18n.setLanguage('en', { remember: false })).toBe(true);
    await settle();
    expect(text(host.querySelector('.drop strong'))).toBe('inventory.json · 20,480 KB · Genshin Impact export detected');

    // A file that does not say which game it is from is named without one.
    await drop(file('{name}.json', '{"characters": []}', 2300));
    expect(text(host.querySelector('.drop strong'))).toBe('{name}.json · 2 KB');
  });

  it('sends the chosen file, and what is pasted under a name of its own', async () => {
    await wizard({ accounts: [account(1)] });
    await choose('file');
    api.importFile.mockReturnValue(throwError(() => refusedWith(422, { message: SERVER_ENGLISH, code: 'good_empty', params: {} })));

    await drop(file('inventory.json', '{"format": "GOOD"}'));
    submit('file').click();
    await settle();
    expect(api.importFile).toHaveBeenLastCalledWith({ account_id: 1, content: '{"format": "GOOD"}', file_name: 'inventory.json' });
    expect(problemText()).toBe(MARKED['import.error.good_empty']);

    [...host.querySelectorAll<HTMLButtonElement>('.drop button')][0].click();
    await settle();
    const pasted = host.querySelector<HTMLTextAreaElement>('textarea')!;
    pasted.value = ' {"format": "GOOD", "artifacts": []} ';
    pasted.dispatchEvent(new Event('input'));
    await settle();
    submit('file').click();
    await settle();
    expect(api.importFile).toHaveBeenLastCalledWith({ account_id: 1, content: '{"format": "GOOD", "artifacts": []}', file_name: 'pasted.json' });
  });

  // ── Done ───────────────────────────────────────────────────────────────────

  it('says what was imported: the counts under the games’ own words, each warning worded from its kind', async () => {
    await wizard();
    await choose('file');
    api.importFile.mockReturnValue(
      of(
        finished({
          status: 'partial',
          summary: {
            characters: 12,
            weapons: 31,
            items: 1523,
            full_inventory: true,
            warnings: ['Unknown character "Newcomer" was skipped.', '3 artifacts were skipped because their set, slot or main stat is missing.'],
            warning_kinds: [
              { code: 'unknown_character', params: { ref: 'Newcomer' } },
              { code: 'good_artifacts_skipped', params: { count: 3 } },
            ],
            unmatched: { characters: ['Newcomer'], weapons: ['Odd Blade'] },
          },
        }),
      ),
    );
    await drop(file('inventory.json', '{"format": "GOOD"}'));
    submit('file').click();
    await settle();

    const done = host.querySelector<HTMLElement>('.done')!;
    expect(text(done.querySelector('h2'))).toBe('Import je hotový: Mona');
    expect(icons('.done-icon')).toEqual(['info']);
    expect([...done.querySelectorAll('.counts > div')].map((tile) => [text(tile.querySelector('strong')), text(tile.querySelector('span'))])).toEqual([
      ['12', 'Postavy'],
      ['31', 'Weapons'],
      ['1 523', 'Artifacts'],
    ]);
    const warnings = [...done.querySelectorAll<HTMLElement>('.warnings li span')];
    expect(warnings.map(text)).toEqual(['Neznáma postava, import ju preskočil: Newcomer', 'Artifacts, ktoré import preskočil (chýba set, slot alebo main stat): 3']);
    expect(warnings.map((warning) => warning.hasAttribute('lang'))).toEqual([false, false]);
    expect(text(done.querySelector('p.small'))).toBe('Zatiaľ chýbajú v katalógu hry (zostávajú pod vlastnými názvami): Newcomer, Odd Blade');
  });

  it('shows a warning that came without a kind as the stored English sentence, and says it is English', async () => {
    await wizard();
    await choose('file');
    api.importFile.mockReturnValue(
      of(
        finished({
          account: null,
          summary: {
            characters: 4,
            weapons: 9,
            items: 80,
            full_inventory: true,
            warnings: ['A warning of a newer server.', 'Unknown character "X" was skipped.'],
            warning_kinds: [
              { code: 'something_new', params: {} },
              { code: 'unknown_character', params: { ref: 'X' } },
            ],
            unmatched: {},
          },
        }),
      ),
    );
    await drop(file('inventory.json', '{"format": "GOOD"}'));
    submit('file').click();
    await settle();

    const done = host.querySelector<HTMLElement>('.done')!;
    expect(text(done.querySelector('h2'))).toBe('Import je hotový');
    expect(icons('.done-icon')).toEqual(['checkCircle']);
    expect([...done.querySelectorAll('.counts span')].map(text)).toEqual(['Postavy', 'Weapons', 'Artifacts']);
    expect([...done.querySelectorAll<HTMLElement>('.warnings li span')].map((warning) => [text(warning), warning.getAttribute('lang')])).toEqual([
      ['A warning of a newer server.', 'en'],
      ['Neznáma postava, import ju preskočil: X', null],
    ]);
    expect(done.querySelector('p.small')).toBeNull();
  });

  it('takes every word of the last step from a key', async () => {
    await wizard({ strings: MARKED });
    await choose('file');
    api.importFile.mockReturnValue(
      of(
        finished({
          summary: { characters: 12, weapons: 31, items: 40, full_inventory: true, warnings: ['Stored.'], warning_kinds: [{ code: 'catalog_behind', params: {} }], unmatched: { sets: ['NewSet'] } },
        }),
      ),
    );
    await drop(file('inventory.json', '{"format": "GOOD"}'));
    submit('file').click();
    await settle();

    expect(text(host.querySelector('.done h2'))).toBe('«Imported Mona»');
    expect(notFromAKey(['Genshin Impact', 'Weapons', 'Artifacts'])).toEqual([]);
  });
});
