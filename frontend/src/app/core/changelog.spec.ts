import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { Changelog, ChangelogEntry } from './changelog';

const ENTRIES: ChangelogEntry[] = [
  { version: '1.2', date: '2026-11-01', changes: { added: ['Three'] } },
  { version: '1.1', date: '2026-10-15', changes: { fixed: ['Two'] } },
  { version: '1.0', date: '2026-09-29', changes: { updated: ['One'] } },
];

describe('Changelog', () => {
  let changelog: Changelog;
  let http: HttpTestingController;
  let dialog: { open: ReturnType<typeof vi.fn> };

  /** The releases a dialog was opened with, by version. */
  const shown = () => dialog.open.mock.calls.map(([, config]) => (config.data as ChangelogEntry[]).map((entry) => entry.version));

  async function load(entries: ChangelogEntry[] | null): Promise<void> {
    const loading = changelog.load();
    const request = http.expectOne('changelog.json');
    if (entries) {
      request.flush(entries);
    } else {
      request.flush('Not found', { status: 404, statusText: 'Not Found' });
    }
    await loading;
  }

  beforeEach(() => {
    localStorage.clear();
    dialog = { open: vi.fn() };
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), { provide: MatDialog, useValue: dialog }] });
    changelog = TestBed.inject(Changelog);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
    localStorage.clear();
  });

  it('shows the newest release as the version', async () => {
    await load(ENTRIES);
    expect(changelog.current()).toBe('1.2');
  });

  it('opens only the newest release on a first visit, and once', async () => {
    await load(ENTRIES);
    changelog.showIfNew();
    changelog.showIfNew();
    expect(shown()).toEqual([['1.2']]);
  });

  it('opens every release since the one this browser saw last', async () => {
    localStorage.setItem('build-analyzer-version-seen', '1.0');
    await load(ENTRIES);
    changelog.showIfNew();
    expect(shown()).toEqual([['1.2', '1.1']]);
    expect(localStorage.getItem('build-analyzer-version-seen')).toBe('1.2');
  });

  it('the version button opens the whole list', async () => {
    localStorage.setItem('build-analyzer-version-seen', '1.2');
    await load(ENTRIES);
    changelog.showIfNew();
    changelog.open();
    expect(shown()).toEqual([['1.2', '1.1', '1.0']]);
  });

  it('works without a changelog', async () => {
    await load(null);
    expect(changelog.current()).toBeNull();
    changelog.showIfNew();
    changelog.open();
    expect(dialog.open).not.toHaveBeenCalled();
  });
});
