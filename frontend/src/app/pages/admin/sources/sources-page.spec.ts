import { HttpErrorResponse, HttpResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { ToastService } from '@anotoki/lib/ui/toast';
import { Subject, of } from 'rxjs';
import { signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { Api } from '../../../core/api';
import { AuthService } from '../../../core/auth';
import { SourceStatus, SyncRunSummary } from '../../../core/models';
import { BUILD_FILE_MAX_BYTES } from './build-file';
import { SourcesPage } from './sources-page';
import { provideKit } from '../../../core/kit';

function source(key: string, name: string): SourceStatus {
  return {
    key,
    game: 'genshin',
    name,
    enabled: true,
    last_synced_at: null,
    last_status: null,
    last_error: null,
    builds: 3,
    characters: 2,
    running: false,
    latest_run: null,
  };
}

describe('SourcesPage, moving builds through a file', () => {
  let exported: Subject<HttpResponse<Blob>>;
  let api: Record<string, ReturnType<typeof vi.fn>>;
  let toasts: { error: ReturnType<typeof vi.fn>; show: ReturnType<typeof vi.fn> };

  beforeEach(() => {
    exported = new Subject();
    api = {
      sources: vi.fn(() => of([source('genshin-1', 'Source 1'), source('genshin-2', 'Source 2')])),
      syncRuns: vi.fn(() => of([])),
      syncStatus: vi.fn(() => of({ active: false, wait_ms: null, runs: [] })),
      exportSourceBuilds: vi.fn(() => exported),
      importSourceBuilds: vi.fn(),
    };
    toasts = { error: vi.fn(), show: vi.fn() };
    TestBed.configureTestingModule({
      imports: [SourcesPage],
      providers: [
        { provide: Api, useValue: api },
        { provide: ToastService, useValue: toasts },
        { provide: AuthService, useValue: { isAdmin: signal(true), user: signal(null) } },
        provideTestTranslations({}),
        provideKit(),
      ],
    });
  });

  async function page() {
    const fixture = TestBed.createComponent(SourcesPage);
    await fixture.whenStable();
    const element: HTMLElement = fixture.nativeElement;
    const card = (key: string) => [...element.querySelectorAll<HTMLElement>('anotoki-card.source')].find((article) => article.textContent?.includes(key))!;
    const button = (within: HTMLElement, label: string) => [...within.querySelectorAll<HTMLButtonElement>('button')].find((candidate) => candidate.textContent?.includes(label))!;
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
    await vi.waitFor(() => expect(toasts.error).toHaveBeenCalledWith('Only an admin can do this.'));
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

    expect(toasts.error).toHaveBeenCalledWith('This file is larger than 8 MB: more than the server may accept, and far more than the builds of a source take. Is it the right file?');
    expect(read).not.toHaveBeenCalled();
    expect(api['importSourceBuilds']).not.toHaveBeenCalled();
  });
});

describe('SourcesPage, the syncs on their way', () => {
  function run(id: number, change: Partial<SyncRunSummary>): SyncRunSummary {
    return {
      id,
      kind: 'builds',
      game: 'genshin',
      status: 'running',
      started_at: '2026-10-18T08:00:00Z',
      finished_at: null,
      stats: null,
      progress: null,
      next_step_at: null,
      last_step_at: null,
      started_by: null,
      source: { key: 'genshin-1', name: 'Source 1', game: 'genshin' },
      ...change,
    };
  }

  it("shows each run's progress as the kit's progress bar, named by the run - moving on its own while nobody knows the total", async () => {
    const api = {
      sources: vi.fn(() => of([source('genshin-1', 'Source 1')])),
      syncRuns: vi.fn(() => of([])),
      syncStatus: vi.fn(() =>
        of({
          active: true,
          wait_ms: 0,
          runs: [run(1, { progress: { done: 9, total: 20 } }), run(2, { kind: 'catalog', source: null, progress: { done: 0, total: null } })],
        }),
      ),
    };
    TestBed.configureTestingModule({
      imports: [SourcesPage],
      providers: [{ provide: Api, useValue: api }, { provide: AuthService, useValue: { isAdmin: signal(true), user: signal(null) } }, provideTestTranslations({}), provideKit(), provideRouter([])],
    });
    const fixture = TestBed.createComponent(SourcesPage);
    await fixture.whenStable();
    const element: HTMLElement = fixture.nativeElement;
    const bars = [...element.querySelectorAll('.active-run anotoki-progress-bar')];
    const said = (attribute: string) => bars.map((bar) => bar.querySelector('[role=progressbar]')?.getAttribute(attribute) ?? null);

    expect(said('aria-label')).toEqual(['Source 1 · Genshin Impact', 'Game catalog · Genshin Impact']);
    // 9 of 20 characters: 45 %, said in the admin panel's English.
    expect(said('aria-valuenow')).toEqual(['45', null]);
    expect(said('aria-valuetext')).toEqual(['45%', null]);
    expect(bars.map((bar) => bar.classList.contains('anotoki-progress-bar--indeterminate'))).toEqual([false, true]);
    expect(element.querySelector('progress')).toBeNull();
  });
});

describe('SourcesPage and database updates', () => {
  it('says nothing of them and asks nothing for an admin: while one waits the site gate shows its own page instead', async () => {
    const api = {
      sources: vi.fn(() => of([source('genshin-1', 'Source 1')])),
      syncRuns: vi.fn(() => of([])),
      syncStatus: vi.fn(() => of({ active: false, wait_ms: null, runs: [] })),
    };
    TestBed.configureTestingModule({
      imports: [SourcesPage],
      providers: [{ provide: Api, useValue: api }, { provide: AuthService, useValue: { isAdmin: signal(true), user: signal(null) } }, provideTestTranslations({}), provideKit(), provideRouter([])],
    });
    const fixture = TestBed.createComponent(SourcesPage);
    await fixture.whenStable();
    const element: HTMLElement = fixture.nativeElement;
    expect(element.querySelector('anotoki-card.source')).not.toBeNull();
    expect(element.textContent).not.toMatch(/database update|migration/i);
    expect(element.querySelector('a[href="/admin/migrations"]')).toBeNull();
  });
});
