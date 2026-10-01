import { HttpErrorResponse, HttpResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Subject, of } from 'rxjs';
import { Api } from '../../core/api';
import { provideTestTranslations } from '../../core/i18n/testing';
import { TranslationService } from '../../core/i18n/translation';
import { SourceStatus } from '../../core/models';
import { BUILD_FILE_MAX_BYTES } from './build-file';
import { SourcesPage } from './sources-page';

function source(key: string, name: string): SourceStatus {
  return {
    key, game: 'genshin', name, enabled: true, last_synced_at: null, last_status: null, last_error: null,
    builds: 3, characters: 2, running: false, latest_run: null,
  };
}

describe('SourcesPage, moving builds through a file', () => {
  let exported: Subject<HttpResponse<Blob>>;
  let api: Record<string, ReturnType<typeof vi.fn>>;
  let snackBar: { open: ReturnType<typeof vi.fn> };

  beforeEach(() => {
    exported = new Subject();
    api = {
      sources: vi.fn(() => of([source('genshin-1', 'Source 1'), source('genshin-2', 'Source 2')])),
      syncRuns: vi.fn(() => of([])),
      migrations: vi.fn(() => of({ pending: [], applied_count: 2, last_applied: '002_schema.sql' })),
      syncStatus: vi.fn(() => of({ active: false, wait_ms: null, runs: [] })),
      exportSourceBuilds: vi.fn(() => exported),
      importSourceBuilds: vi.fn(),
    };
    snackBar = { open: vi.fn() };
    TestBed.configureTestingModule({
      imports: [SourcesPage],
      providers: [
        { provide: Api, useValue: api },
        { provide: MatSnackBar, useValue: snackBar },
        provideTestTranslations({}),
      ],
    });
  });

  async function page() {
    const fixture = TestBed.createComponent(SourcesPage);
    await fixture.whenStable();
    const element: HTMLElement = fixture.nativeElement;
    const card = (key: string) =>
      [...element.querySelectorAll<HTMLElement>('article.source')].find((article) => article.textContent?.includes(key))!;
    const button = (within: HTMLElement, label: string) =>
      [...within.querySelectorAll<HTMLButtonElement>('button')].find((candidate) => candidate.textContent?.includes(label))!;
    return { fixture, element, card, button };
  }

  it('keeps Sync now and Sync all waiting while a file of the source is on its way', async () => {
    const { fixture, element, card, button } = await page();

    button(card('genshin-1'), 'Export').click();
    await fixture.whenStable();
    expect(button(card('genshin-1'), 'Sync now').disabled).toBe(true);
    expect(button(card('genshin-1'), 'Import').disabled).toBe(true);
    expect(button(card('genshin-2'), 'Sync now').disabled).toBe(false);
    expect(button(element, 'Sync all').disabled).toBe(true);

    // The export ends (here with the API's refusal, read out of the file answer).
    exported.error(new HttpErrorResponse({ status: 403, error: new Blob([JSON.stringify({ message: 'Only an admin can do this.' })]) }));
    await fixture.whenStable();
    expect(button(card('genshin-1'), 'Sync now').disabled).toBe(false);
    expect(button(element, 'Sync all').disabled).toBe(false);
    await vi.waitFor(() => expect(snackBar.open).toHaveBeenCalledWith('Only an admin can do this.', 'Close'));
  });

  it('refuses a file larger than a server may accept before reading or sending it', async () => {
    const { fixture, card } = await page();
    const input = card('genshin-2').querySelector<HTMLInputElement>('input[type=file]')!;
    const file = new File(['{}'], 'builds-genshin-2.json', { type: 'application/json' });
    Object.defineProperty(file, 'size', { value: BUILD_FILE_MAX_BYTES + 1 });
    const read = vi.spyOn(file, 'text');
    Object.defineProperty(input, 'files', { value: [file], configurable: true });

    input.dispatchEvent(new Event('change'));
    await fixture.whenStable();

    expect(snackBar.open).toHaveBeenCalledWith(
      'This file is larger than 8 MB: more than the server may accept, and far more than the builds of a source take. Is it the right file?',
      'Close',
    );
    expect(read).not.toHaveBeenCalled();
    expect(api['importSourceBuilds']).not.toHaveBeenCalled();
  });
});

describe('SourcesPage, applying database updates', () => {
  let api: Record<string, ReturnType<typeof vi.fn>>;

  beforeEach(() => {
    api = {
      sources: vi.fn(() => of([])),
      syncRuns: vi.fn(() => of([])),
      migrations: vi.fn(() => of({ pending: ['003_languages_and_translations.sql'], applied_count: 2, last_applied: '002_schema.sql' })),
      syncStatus: vi.fn(() => of({ active: false, wait_ms: null, runs: [] })),
      applyMigrations: vi.fn(),
    };
    TestBed.configureTestingModule({
      imports: [SourcesPage],
      providers: [
        { provide: Api, useValue: api },
        { provide: MatSnackBar, useValue: { open: vi.fn() } },
        provideTestTranslations({}),
      ],
    });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
  });

  afterEach(() => vi.restoreAllMocks());

  async function apply(result: { ok: boolean; applied: string[] }) {
    api['applyMigrations'].mockReturnValue(of({ ...result, pending: [], applied_count: 3, last_applied: '003_languages_and_translations.sql', output: '' }));
    const reload = vi.spyOn(TestBed.inject(TranslationService), 'reload').mockResolvedValue();
    const fixture = TestBed.createComponent(SourcesPage);
    await fixture.whenStable();
    const element: HTMLElement = fixture.nativeElement;
    [...element.querySelectorAll<HTMLButtonElement>('button')].find((button) => button.textContent?.includes('Apply'))!.click();
    await fixture.whenStable();
    expect(api['applyMigrations']).toHaveBeenCalledTimes(1);
    return reload;
  }

  it('reads the strings again after an Apply that applied something - they may only now exist', async () => {
    expect(await apply({ ok: true, applied: ['003_languages_and_translations.sql'] })).toHaveBeenCalledTimes(1);
  });

  it('leaves the strings as they are after an Apply that applied nothing', async () => {
    expect(await apply({ ok: true, applied: [] })).not.toHaveBeenCalled();
  });

  it('leaves the strings as they are after an Apply that failed', async () => {
    expect(await apply({ ok: false, applied: [] })).not.toHaveBeenCalled();
  });
});
