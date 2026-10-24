import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { AnotokiDialog } from '@anotoki/lib/ui/dialog';
import { Changelog, ChangelogEntry, compareVersions } from './changelog';

const ENTRIES: ChangelogEntry[] = [
  { version: '1.2.0', date: '2026-11-01', changes: { added: ['Three'] } },
  { version: '1.1.0', date: '2026-10-15', changes: { fixed: ['Two'] } },
  { version: '1.0.0', date: '2026-09-29', changes: { updated: ['One'] } },
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
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), { provide: AnotokiDialog, useValue: dialog }] });
    changelog = TestBed.inject(Changelog);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
    localStorage.clear();
  });

  it('shows the newest release as the version', async () => {
    await load(ENTRIES);
    expect(changelog.current()).toBe('1.2.0');
  });

  it('opens only the newest release on a first visit, and once', async () => {
    await load(ENTRIES);
    changelog.showIfNew();
    changelog.showIfNew();
    expect(shown()).toEqual([['1.2.0']]);
  });

  it('opens every release since the one this browser saw last', async () => {
    localStorage.setItem('build-analyzer-version-seen', '1.0.0');
    await load(ENTRIES);
    changelog.showIfNew();
    expect(shown()).toEqual([['1.2.0', '1.1.0']]);
    expect(localStorage.getItem('build-analyzer-version-seen')).toBe('1.2.0');
  });

  it('takes a version kept from before the versions had three numbers for the same release: "1.2" is 1.2.0, never shown again', async () => {
    localStorage.setItem('build-analyzer-version-seen', '1.2');
    await load(ENTRIES);
    changelog.showIfNew();
    expect(shown()).toEqual([]);

    // "1.1" is 1.1.0: what came after it, and not 1.1.0 again.
    localStorage.setItem('build-analyzer-version-seen', '1.1');
    changelog.showIfNew();
    expect(shown()).toEqual([['1.2.0']]);
    expect(localStorage.getItem('build-analyzer-version-seen')).toBe('1.2.0');
  });

  it('opens nothing for a browser that saw a later release (one taken back), and the newest alone for a seen version that is not one', async () => {
    localStorage.setItem('build-analyzer-version-seen', '1.3.0');
    await load(ENTRIES);
    changelog.showIfNew();
    expect(shown()).toEqual([]);

    localStorage.setItem('build-analyzer-version-seen', 'yesterday');
    changelog.showIfNew();
    expect(shown()).toEqual([['1.2.0']]);
  });

  it("What's new at the foot of the page opens the whole list, in the kit's wide dialog", async () => {
    localStorage.setItem('build-analyzer-version-seen', '1.2.0');
    await load(ENTRIES);
    changelog.showIfNew();
    changelog.open();
    expect(shown()).toEqual([['1.2.0', '1.1.0', '1.0.0']]);
    expect(dialog.open.mock.calls[0][1].size).toBe('lg');
  });

  it('works without a changelog', async () => {
    await load(null);
    expect(changelog.current()).toBeNull();
    changelog.showIfNew();
    changelog.open();
    expect(dialog.open).not.toHaveBeenCalled();
  });
});

describe('compareVersions', () => {
  it('compares the numbers part by part, a missing part counting as 0', () => {
    expect(compareVersions('0.3', '0.3.0')).toBe(0);
    expect(compareVersions('0.3.0', '0.3')).toBe(0);
    expect(compareVersions('1', '1.0.0')).toBe(0);
    expect(compareVersions('0.3', '0.4.0')).toBe(-1);
    expect(compareVersions('0.4.0', '0.3')).toBe(1);
    expect(compareVersions('0.3.1', '0.3')).toBe(1);
    // Numbers, not text: 0.10.0 comes after 0.9.0.
    expect(compareVersions('0.10.0', '0.9.0')).toBe(1);
    expect(compareVersions('1.0.0', '0.99.99')).toBe(1);
  });

  it('is null for what is not a version', () => {
    for (const odd of ['', 'v0.3', '0.3-beta', '0..3', '0.3.', ' 0.3', 'yesterday']) {
      expect(compareVersions(odd, '0.3.0')).toBeNull();
      expect(compareVersions('0.3.0', odd)).toBeNull();
    }
  });
});
