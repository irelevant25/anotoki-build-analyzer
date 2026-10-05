import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Router, provideRouter } from '@angular/router';
import { App } from './app';
import { AuthService } from './core/auth';
import { Changelog } from './core/changelog';
import { provideTestTranslations } from './core/i18n/testing';
import { MetaStore } from './core/meta-store';
import { VisitorMove } from './core/models';
import { VisitorKey } from './core/visitor-key';

const ENGLISH = {
  'brand.area': 'Build Analyzer',
  'common.close': 'Close',
};
const SLOVAK = {
  'brand.area': 'Build Analyzer',
  'common.close': 'Zavrieť',
  'visitor.moved.one': 'Účet importovaný bez prihlásenia je teraz v tvojom anotoki účte ({count}).',
  'visitor.moved.few': 'Účty importované bez prihlásenia sú teraz v tvojom anotoki účte ({count}).',
  'visitor.moved.other':
    'Účty importované bez prihlásenia sú teraz v tvojom anotoki účte ({count}).',
  'visitor.kept.one': 'Účet, ktorý už bol v tvojom anotoki účte, zostal, ako bol ({count}).',
  'visitor.kept.few': 'Účty, ktoré už boli v tvojom anotoki účte, zostali, ako boli ({count}).',
  'visitor.kept.other': 'Účty, ktoré už boli v tvojom anotoki účte, zostali, ako boli ({count}).',
};

@Component({ selector: 'app-test-page', template: '<p class="the-page">the page</p>' })
class TestPage {}

describe('the shell', () => {
  let fixture: ComponentFixture<App>;
  let host: HTMLElement;
  let snackBar: { open: ReturnType<typeof vi.fn> };
  let visitor: {
    key: ReturnType<typeof signal<string | null>>;
    moved: ReturnType<typeof signal<VisitorMove | null>>;
  };
  let changelog: {
    current: ReturnType<typeof signal<string | null>>;
    open: ReturnType<typeof vi.fn>;
    showIfNew: ReturnType<typeof vi.fn>;
  };

  async function setup(
    options: { signedIn?: boolean; key?: string | null; notSetUp?: string | null } = {},
  ): Promise<void> {
    const user = signal(options.signedIn === false ? null : { sub: '7', locale: null });
    visitor = { key: signal(options.key ?? null), moved: signal<VisitorMove | null>(null) };
    changelog = { current: signal<string | null>('0.3'), open: vi.fn(), showIfNew: vi.fn() };
    snackBar = { open: vi.fn() };
    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: '**', component: TestPage }]),
        provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH }),
        { provide: AuthService, useValue: { user, signedIn: () => user() !== null } },
        {
          provide: MetaStore,
          useValue: {
            notSetUp: signal<string | null>(options.notSetUp ?? null),
            loaded: signal(true),
          },
        },
        { provide: Changelog, useValue: changelog },
        { provide: VisitorKey, useValue: visitor },
        { provide: MatSnackBar, useValue: snackBar },
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
      ],
    });
    fixture = TestBed.createComponent(App);
    host = fixture.nativeElement;
    await TestBed.inject(Router).navigateByUrl('/');
    await fixture.whenStable();
  }

  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.documentElement.classList.remove('theme-light', 'theme-dark');
  });

  it('draws the router: the layouts bring the bar and the footer', async () => {
    await setup();
    expect(host.querySelector('.the-page')).not.toBeNull();
    expect(host.querySelector('app-top-bar')).toBeNull();
    expect(host.querySelector('app-not-set-up-page')).toBeNull();
  });

  it('before the site is set up: the brand alone over the page that says how - no bar, no page', async () => {
    await setup({ notSetUp: '/setup.php' });
    expect(host.querySelector('app-not-set-up-page')).not.toBeNull();
    expect(host.querySelector('.boot-bar app-brand .area')?.textContent?.trim()).toBe(
      'Build Analyzer',
    );
    expect(host.querySelector('.boot-bar a')).toBeNull();
    expect(host.querySelector('.the-page')).toBeNull();
  });

  it('puts the theme chosen on this device on <html> before anything is drawn', async () => {
    localStorage.setItem('build-analyzer-theme', 'dark');
    await setup();
    expect(document.documentElement.classList.contains('theme-dark')).toBe(true);
  });

  it('says once, after a sign-in, what moved from the visitor into the account - in the language on the page', async () => {
    await setup();
    visitor.moved.set({ found: true, moved: 2, kept: 1, imports: 3 });
    await fixture.whenStable();

    expect(snackBar.open).toHaveBeenCalledTimes(1);
    expect(snackBar.open).toHaveBeenCalledWith(
      'Účty importované bez prihlásenia sú teraz v tvojom anotoki účte (2). Účet, ktorý už bol v tvojom anotoki účte, zostal, ako bol (1).',
      'Zavrieť',
      expect.anything(),
    );
    expect(visitor.moved()).toBeNull();
  });

  it('shows what is new to somebody who has used the site before - signed in, or holding a visitor key - and never to a first visit', async () => {
    await setup();
    expect(changelog.showIfNew).toHaveBeenCalledTimes(1);

    TestBed.resetTestingModule();
    await setup({ signedIn: false, key: 'K'.repeat(43) });
    expect(changelog.showIfNew).toHaveBeenCalledTimes(1);

    TestBed.resetTestingModule();
    await setup({ signedIn: false });
    expect(changelog.showIfNew).not.toHaveBeenCalled();
  });
});
