import { HttpErrorResponse } from '@angular/common/http';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatStepperIntl } from '@angular/material/stepper';
import { provideRouter } from '@angular/router';
import { of, throwError } from 'rxjs';
import { Api } from '../../core/api';
import { en } from '../../core/i18n/en';
import { provideTestTranslations } from '../../core/i18n/testing';
import { TranslationService } from '../../core/i18n/translation';
import { AccountSummary, ImportRun } from '../../core/models';
import { ImportWizard } from './import-wizard';
import { StepperWords } from './stepper-words';

/** Every string of the site, marked: what a page shows without these marks did not come from a key. */
const MARKED: Record<string, string> = Object.fromEntries(Object.entries(en).map(([key, value]) => [key, `«${value}»`]));

/** The informal Slovak of what these tests read; everything else is the marked English. */
const SLOVAK: Record<string, string> = {
  ...MARKED,
  'importWizard.title': 'Importuj svoj účet',
  'importWizard.step.game': 'Hra',
  'importWizard.step.method': 'Spôsob',
  'importWizard.step.prepare': 'Príprava',
  'importWizard.step.import': 'Import',
  'importWizard.step.done': 'Hotovo',
  'importWizard.game.holds': 'Postavy, {inventory}',
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
  'import.error.file_game_mismatch': 'Súbor obsahuje údaje z hry {file_game}, ale zvolená hra je {chosen_game}.',
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
  'stepper.completed': 'Dokončené',
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

  async function wizard(options: { language?: 'en' | 'sk'; strings?: Record<string, string>; accounts?: AccountSummary[] } = {}): Promise<void> {
    api = { accounts: vi.fn(() => of(options.accounts ?? [])), importShowcase: vi.fn(), importFile: vi.fn() };
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        options.language === 'en' ? provideTestTranslations(en) : provideTestTranslations(options.strings ?? SLOVAK, { language: 'sk', english: en }),
        { provide: Api, useValue: api },
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
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

  /** An element's words, without the names of the icons in it. */
  const text = (element: Element | null | undefined): string | undefined => {
    if (!element) {
      return undefined;
    }
    const copy = element.cloneNode(true) as Element;
    copy.querySelectorAll('mat-icon').forEach((icon) => icon.remove());
    return copy.textContent?.replace(/\s+/g, ' ').trim();
  };
  const buttonWith = (icon: string) => [...host.querySelectorAll<HTMLButtonElement>('button')].find((button) => button.querySelector('mat-icon')?.textContent?.trim() === icon)!;
  const problem = () => host.querySelector<HTMLElement>('.error');

  /** Step 1 and 2 by their cards, step 3 by its only filled button. */
  async function choose(game: 'genshin' | 'hsr', method: 'showcase' | 'file'): Promise<void> {
    host.querySelectorAll<HTMLButtonElement>('button.choice:not(.method)')[game === 'genshin' ? 0 : 1].click();
    await settle();
    host.querySelectorAll<HTMLButtonElement>('button.choice.method')[method === 'showcase' ? 0 : 1].click();
    await settle();
    [...host.querySelectorAll<HTMLButtonElement>('button')].find((button) => text(button) === i18n.t('importWizard.prepare.ready'))!.click();
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
      if (value && !node.parentElement?.closest('mat-icon')) {
        found.push(value);
      }
    }
    return found;
  }

  /** What may stand on the page without having come from a key: the games' own words, names, and plain numbers. */
  const notFromAKey = (allowed: string[]) => [...new Set(shown())].filter((value) => !/^«.*»$/.test(value) && !/^\d+$/.test(value) && !allowed.includes(value));

  afterEach(() => vi.restoreAllMocks());

  // ── The words ──────────────────────────────────────────────────────────────

  it('names its steps in the language on the page, and gives the stepper its own words there too', async () => {
    await wizard();

    expect(text(host.querySelector('h1'))).toBe('Importuj svoj účet');
    expect([...host.querySelectorAll('.mat-step-label')].map(text)).toEqual(['Hra', 'Spôsob', 'Príprava', 'Import', 'Hotovo']);
    const intl = fixture.debugElement.injector.get(MatStepperIntl);
    expect(intl).toBeInstanceOf(StepperWords);
    expect(intl.completedLabel).toBe('Dokončené');

    expect(await i18n.setLanguage('en', false)).toBe(true);
    await settle();
    expect(text(host.querySelector('h1'))).toBe('Import your account');
    expect([...host.querySelectorAll('.mat-step-label')].map(text)).toEqual(['Game', 'Method', 'Prepare', 'Import', 'Done']);
    expect(intl.completedLabel).toBe('Completed');
  });

  it('says what a game’s import holds in the game’s own words, never lower-cased or translated', async () => {
    await wizard();
    expect([...host.querySelectorAll('button.choice:not(.method)')].map((card) => [text(card.querySelector('.choice-title')), text(card.querySelector('.small'))])).toEqual([
      ['Genshin Impact', 'Postavy, Weapons, Artifacts'],
      ['Honkai: Star Rail', 'Postavy, Light Cones, Relics, Planar Ornaments'],
    ]);
  });

  it('takes every word of the way to a file import from a key', async () => {
    await wizard({ strings: MARKED, accounts: [account(1), account(2, { uid: null, nickname: 'Second' })] });
    await choose('genshin', 'file');

    expect(notFromAKey(['Genshin Impact', 'Honkai: Star Rail', 'Second'])).toEqual([]);
    // The guide of the chosen game and method is what is drawn, step by step.
    expect([...host.querySelectorAll('.guide-title')].map(text)).toEqual([
      '«Get an inventory exporter»',
      '«Start the exporter before the game»',
      '«Log into Genshin Impact»',
      '«Export the GOOD file»',
    ]);
    expect([...host.querySelectorAll('.tip')].map(text)).toHaveLength(2);
    expect([...host.querySelectorAll('.trouble li')].map(text)).toHaveLength(3);
    expect(text(host.querySelector('.drop .small'))).toBe('«A .json file in the GOOD format»');
    expect(text(host.querySelector('.account-field mat-hint'))).toBe('«A full export replaces the account\'s Weapons and Artifacts; characters are kept.»');
  });

  it('takes every word of the way to an import by UID from a key', async () => {
    await wizard({ strings: MARKED, accounts: [account(1), account(2, { uid: null, nickname: 'Second' })] });
    await choose('hsr', 'showcase');

    expect(notFromAKey(['Genshin Impact', 'Honkai: Star Rail', 'Honkai: Star Rail UID'])).toEqual([]);
    expect([...host.querySelectorAll('.guide-title')].map(text)).toHaveLength(5);
    expect(host.querySelector<HTMLInputElement>('input[name="uid"]')!.placeholder).toBe('«e.g. 700123456»');
  });

  it('lists the person’s accounts of the game with their UID, or says that one has none yet', async () => {
    await wizard({ accounts: [account(1), account(2, { uid: null, nickname: 'Second' }), account(3, { game: 'hsr', nickname: 'Elsewhere' })] });
    await choose('genshin', 'showcase');

    host.querySelector<HTMLElement>('.account-field mat-select')!.click();
    await settle();
    const options = [...document.querySelectorAll('.cdk-overlay-container mat-option')].map(text);
    expect(options.slice(1)).toEqual(['Mona · UID 700000001', 'Second · UID zatiaľ chýba']);
  });

  // ── A request that fails ───────────────────────────────────────────────────

  it('words a refused import from its code and parameters - never with the server’s sentence', async () => {
    await wizard();
    await choose('genshin', 'showcase');
    await typeUid('700123456');

    const cases: [HttpErrorResponse, string][] = [
      [refusedWith(422, { message: SERVER_ENGLISH, code: 'showcase_player_not_found', params: {} }), 'Hráč sa nenašiel (skontroluj UID aj hru).'],
      [refusedWith(422, { message: SERVER_ENGLISH, code: 'account_uid_mismatch', params: { data_uid: '700000002', account_uid: '700000001' } }), 'Údaje majú UID 700000002, ale vybraný účet má UID 700000001.'],
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
      buttonWith('download').click();
      await settle();
      expect(text(problem()), JSON.stringify(answer.error)).toBe(sentence);
    }
    expect(api.importShowcase).toHaveBeenCalledTimes(cases.length);
    expect(api.importShowcase).toHaveBeenLastCalledWith('genshin', '700123456', null);
    expect(problem()!.getAttribute('role')).toBe('alert');
    expect(host.textContent).not.toContain(SERVER_ENGLISH);
  });

  it('keeps the failure as a key: another language words the same failure again', async () => {
    await wizard();
    await choose('genshin', 'showcase');
    await typeUid('700123456');
    api.importShowcase.mockReturnValueOnce(throwError(() => refusedWith(422, { message: SERVER_ENGLISH, code: 'showcase_player_not_found', params: {} })));
    buttonWith('download').click();
    await settle();
    expect(text(problem())).toBe('Hráč sa nenašiel (skontroluj UID aj hru).');

    expect(await i18n.setLanguage('en', false)).toBe(true);
    await settle();
    expect(text(problem())).toBe('No player with this UID exists (check the UID and the game).');
  });

  it('sends nothing while the UID is not 9 or 10 digits', async () => {
    await wizard();
    await choose('genshin', 'showcase');
    await typeUid('7001');

    expect(buttonWith('download').disabled).toBe(true);
    host.querySelector('form')!.dispatchEvent(new Event('submit'));
    await settle();
    expect(api.importShowcase).not.toHaveBeenCalled();
  });

  // ── The file ───────────────────────────────────────────────────────────────

  it('refuses a file that cannot be an export before anything is sent, in the words the server has for it', async () => {
    await wizard();
    await choose('genshin', 'file');

    await drop(file('huge.json', '{}', 20 * 1024 * 1024 + 1));
    expect(text(problem())).toBe('Súbor má viac ako 20 MB; to nie je súbor z exportovacieho nástroja.');

    await drop(file('notes.json', 'not json at all'));
    expect(text(problem())).toBe('Súbor nie je platný JSON.');

    await drop(file('rail.json', '{"relics": []}'));
    expect(text(problem())).toBe('Súbor obsahuje údaje z hry Honkai: Star Rail, ale zvolená hra je Genshin Impact.');

    expect(host.querySelector('.drop-icon.ok')).toBeNull();
    expect(buttonWith('upload').disabled).toBe(true);
    expect(api.importFile).not.toHaveBeenCalled();

    // The refusal is a key with the games' names, so it follows the language.
    expect(await i18n.setLanguage('en', false)).toBe(true);
    await settle();
    expect(text(problem())).toBe('This file contains Honkai: Star Rail data, but you chose Genshin Impact.');
  });

  it('says which file was chosen - its name, its size, the game it is from - in the language on the page', async () => {
    await wizard();
    await choose('genshin', 'file');

    await drop(file('inventory.json', '{"format": "GOOD"}', 20_971_520));
    expect(problem()).toBeNull();
    expect(text(host.querySelector('.drop strong'))).toBe('inventory.json · 20 480 KB · rozpoznaný export z hry Genshin Impact');

    expect(await i18n.setLanguage('en', false)).toBe(true);
    await settle();
    expect(text(host.querySelector('.drop strong'))).toBe('inventory.json · 20,480 KB · Genshin Impact export detected');

    // A file that does not say which game it is from is named without one.
    await drop(file('{name}.json', '{"characters": []}', 2300));
    expect(text(host.querySelector('.drop strong'))).toBe('{name}.json · 2 KB');
  });

  it('sends the chosen file, and what is pasted under a name of its own', async () => {
    await wizard({ accounts: [account(1)] });
    await choose('genshin', 'file');
    api.importFile.mockReturnValue(throwError(() => refusedWith(422, { message: SERVER_ENGLISH, code: 'good_empty', params: {} })));

    await drop(file('inventory.json', '{"format": "GOOD"}'));
    buttonWith('upload').click();
    await settle();
    expect(api.importFile).toHaveBeenLastCalledWith({ game: 'genshin', account_id: 1, content: '{"format": "GOOD"}', file_name: 'inventory.json' });
    expect(text(problem())).toBe(MARKED['import.error.good_empty']);

    [...host.querySelectorAll<HTMLButtonElement>('.drop button')][0].click();
    await settle();
    const pasted = host.querySelector<HTMLTextAreaElement>('textarea')!;
    pasted.value = ' {"format": "GOOD", "artifacts": []} ';
    pasted.dispatchEvent(new Event('input'));
    await settle();
    buttonWith('upload').click();
    await settle();
    expect(api.importFile).toHaveBeenLastCalledWith({ game: 'genshin', account_id: 1, content: '{"format": "GOOD", "artifacts": []}', file_name: 'pasted.json' });
  });

  // ── Done ───────────────────────────────────────────────────────────────────

  it('says what was imported: the counts under the games’ own words, each warning worded from its kind', async () => {
    await wizard();
    await choose('genshin', 'file');
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
    buttonWith('upload').click();
    await settle();

    const done = host.querySelector<HTMLElement>('.done')!;
    expect(text(done.querySelector('h2'))).toBe('Import je hotový: Mona');
    expect(done.querySelector('.done-icon')?.textContent?.trim()).toBe('info');
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
    await choose('hsr', 'file');
    api.importFile.mockReturnValue(
      of(
        finished({
          game: 'hsr',
          account: null,
          summary: { characters: 4, weapons: 9, items: 80, full_inventory: true, warnings: ['A warning of a newer server.', 'Unknown character "X" was skipped.'], warning_kinds: [{ code: 'something_new', params: {} }, { code: 'unknown_character', params: { ref: 'X' } }], unmatched: {} },
        }),
      ),
    );
    await drop(file('rail.json', '{"relics": []}'));
    buttonWith('upload').click();
    await settle();

    const done = host.querySelector<HTMLElement>('.done')!;
    expect(text(done.querySelector('h2'))).toBe('Import je hotový');
    expect(done.querySelector('.done-icon')?.textContent?.trim()).toBe('check_circle');
    expect([...done.querySelectorAll('.counts span')].map(text)).toEqual(['Postavy', 'Light Cones', 'Relics']);
    expect([...done.querySelectorAll<HTMLElement>('.warnings li span')].map((warning) => [text(warning), warning.getAttribute('lang')])).toEqual([
      ['A warning of a newer server.', 'en'],
      ['Neznáma postava, import ju preskočil: X', null],
    ]);
    expect(done.querySelector('p.small')).toBeNull();
  });

  it('takes every word of the last step from a key', async () => {
    await wizard({ strings: MARKED });
    await choose('genshin', 'file');
    api.importFile.mockReturnValue(of(finished({ summary: { characters: 12, weapons: 31, items: 40, full_inventory: true, warnings: ['Stored.'], warning_kinds: [{ code: 'catalog_behind', params: {} }], unmatched: { sets: ['NewSet'] } } })));
    await drop(file('inventory.json', '{"format": "GOOD"}'));
    buttonWith('upload').click();
    await settle();

    expect(text(host.querySelector('.done h2'))).toBe('«Imported Mona»');
    expect(notFromAKey(['Genshin Impact', 'Honkai: Star Rail', 'Weapons', 'Artifacts'])).toEqual([]);
  });
});
