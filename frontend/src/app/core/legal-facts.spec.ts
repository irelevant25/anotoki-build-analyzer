import { ChangeDetectionStrategy, Component, Type, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { AnotokiAuth } from '@anotoki/lib/auth';
import { provideTestAnotokiAuth } from '@anotoki/lib/auth/testing';
import { ANOTOKI_LEGAL_CONFIG } from '@anotoki/lib/legal';
import { ContactPageComponent, PrivacyPageComponent, TermsPageComponent } from '@anotoki/lib/legal/pages';
import { SiteStatus } from '@anotoki/lib/migrations';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { provideFeedback } from './feedback';
import { TranslationService } from './i18n/translation';
import { provideLegal } from './legal';
import { HOSTING, SIGN_IN_HINT_COOKIE, SITE_LEGAL_UPDATED, legalPagesConfig, siteLegalFacts } from './legal-facts';
import { MetaStore } from './meta-store';

/** The IAM this test's site signs in with: /api/meta's `auth`. */
const IAM = 'https://iam.anotoki.example';

/**
 * Every address a legal page of this site's may link to: the anotoki account's pages, its own Contact page, the
 * family's address and operator, the authority, the hosting company, Cloudflare - and nothing else (the showcase
 * service has no link: the site names no other service).
 */
const ADDRESSES = [
  `${IAM}/account`,
  `${IAM}/privacy`,
  '/contact',
  'mailto:support@anotoki.eu',
  'https://codehero.sk',
  'https://dataprotection.gov.sk/',
  'https://www.websupport.sk',
  'https://www.cloudflare.com/privacypolicy/',
];

/**
 * This site's own strings the facts name, as stand-ins: the words themselves are migration 011's (and the backend's
 * tests hold them - both languages, no other site named, no address); what is checked here is where each goes.
 */
const WORDS: Record<string, string> = {
  'legal.about': 'Compares builds.',
  'legal.fanClause': 'A fan project, about Genshin Impact and Honkai: Star Rail.',
  'legal.record.name': 'Your record',
  'legal.record.what': 'The record.',
  'legal.record.why': 'Why the record.',
  'legal.record.kept': 'How long the record.',
  'legal.accounts.name': 'Your game accounts',
  'legal.accounts.what': 'The game accounts.',
  'legal.accounts.why': 'Why the game accounts.',
  'legal.accounts.kept': 'How long the game accounts.',
  'legal.staff.name': "If you run syncs or keep the site's words",
  'legal.staff.what': 'Who synced, who saved a word.',
  'legal.staff.why': 'Why who synced.',
  'legal.staff.kept': 'How long who synced.',
  'legal.limits.name': 'Limits against floods',
  'legal.limits.what': 'The counts.',
  'legal.limits.why': 'Why the counts.',
  'legal.limits.kept': 'How long the counts.',
  'legal.showcase.name': 'Showcase answers',
  'legal.showcase.what': 'A showcase answer.',
  'legal.showcase.why': 'Why a showcase answer.',
  'legal.showcase.kept': 'A few minutes.',
  'legal.log.name': "The site's own log",
  'legal.log.what': 'The log.',
  'legal.log.why': 'Why the log.',
  'legal.log.kept': 'About 10 MB.',
  'legal.visitors.what': 'A visitor.',
  'legal.visitors.why': 'Why a visitor.',
  'legal.visitors.kept': 'Until a sign-in, or {days} days after the last visit.',
  'legal.feedback.what': 'A message, its kind and the part of the site.',
  'legal.feedback.kept': 'Until an administrator deletes it.',
  'legal.auditLog.what': "The editors' and administrators' changes.",
  'legal.backups.what': 'A copy on each day the site is used.',
  'legal.showcaseService.name': 'The showcase service',
  'legal.showcaseService.role': 'Gets a UID from our server.',
  'legal.storage.signIn.purpose': 'The sign-in.',
  'legal.storage.hintTried.purpose': 'The last silent try.',
  'legal.storage.hintTried.kept': 'Until the next try.',
  'legal.storage.signInUnderWay.purpose': 'A sign-in under way.',
  'legal.storage.signInUnderWay.kept': 'Until it comes back.',
  'legal.storage.signInKind.purpose': 'How the sign-in began.',
  'legal.storage.signInKind.kept': 'Until the next sign-in.',
  'legal.storage.tabLanguage.purpose': "A tab's language.",
  'legal.storage.untilTabClosed': 'Until the tab is closed.',
  'legal.storage.visitorKey.kept': 'Until a sign-in moves your imports.',
  'legal.storage.whatsNew.purpose': "What's new seen.",
  'legal.storage.whatsNew.kept': 'Until a newer version.',
  'legal.storage.wordDrafts.purpose': 'Unsaved words.',
};

/** The legal pages' frame, as layouts/legal-layout gives the pages their configuration: the site's, with its facts. */
@Component({
  selector: 'app-test-privacy-frame',
  imports: [PrivacyPageComponent],
  providers: [{ provide: ANOTOKI_LEGAL_CONFIG, useFactory: legalPagesConfig }],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<anotoki-privacy-page />',
})
class PrivacyFrame {}

@Component({
  selector: 'app-test-terms-frame',
  imports: [TermsPageComponent],
  providers: [{ provide: ANOTOKI_LEGAL_CONFIG, useFactory: legalPagesConfig }],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<anotoki-terms-page />',
})
class TermsFrame {}

@Component({
  selector: 'app-test-contact-frame',
  imports: [ContactPageComponent],
  providers: [{ provide: ANOTOKI_LEGAL_CONFIG, useFactory: legalPagesConfig }],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<anotoki-contact-page />',
})
class ContactFrame {}

/**
 * The family's legal pages (@anotoki/lib/legal/pages) as this site fills them in: the root configuration
 * (core/legal.ts - the language, the database's words, the site's own fan clause, no facts) and the facts the
 * pages' frame gives them (core/legal-facts.ts) - drawn for real, so that what the pages say of the site is
 * checked where people read it: its parts, its cookies and stored items by name, who else handles data, the
 * anotoki account's pages at the IAM's address the site signs in with - and every link where the code says,
 * whatever an administrator rewords.
 */
describe('the legal pages, as this site fills them in', () => {
  let fixture: ComponentFixture<unknown> | null = null;

  /** `loginRequired`: the IAM requires signing in here - nobody signed in visits, they are signed out. */
  async function configure(values: Record<string, string> = {}, language: 'en' | 'sk' = 'en', loginRequired = false): Promise<void> {
    TestBed.configureTestingModule({
      providers: [
        // Each page in a frame of its own, on its own path - as app.routes.ts has them.
        provideRouter([
          { path: 'terms', component: TermsFrame },
          { path: 'privacy', component: PrivacyFrame },
          { path: 'contact', component: ContactFrame },
        ]),
        language === 'en' ? provideTestTranslations({ ...WORDS, ...values }) : provideTestTranslations({ ...WORDS, ...values }, { language: 'sk', english: WORDS }),
        provideLegal(),
        provideFeedback(),
        ...provideTestAnotokiAuth({
          config: {
            config: () => ({
              issuer: IAM,
              client_id: 'build-analyzer',
              login_required: loginRequired,
              sign_in_available: true,
            }),
          },
        }),
        // The days a visitor's data is kept after the last visit: /api/meta's word.
        { provide: MetaStore, useValue: { visitorDays: () => 30 } },
        // The site is ready: the gate shows the pages.
        { provide: SiteStatus, useValue: { blocked: signal(false) } },
      ],
    });
    await TestBed.inject(AnotokiAuth).init();
  }

  async function draw(frame: Type<unknown>): Promise<HTMLElement> {
    fixture = TestBed.createComponent(frame);
    document.body.appendChild(fixture.nativeElement);
    await fixture.whenStable();
    return fixture.nativeElement as HTMLElement;
  }

  /** A page as the router shows it, at its address: its frame knows which page it frames by its route. */
  async function open(path: string): Promise<HTMLElement> {
    const harness = await RouterTestingHarness.create();
    fixture = harness.fixture;
    document.body.appendChild(harness.fixture.nativeElement);
    await harness.navigateByUrl(path);
    await harness.fixture.whenStable();
    return harness.fixture.nativeElement as HTMLElement;
  }

  const texts = (host: HTMLElement, selector: string) => Array.from(host.querySelectorAll(selector)).map((element) => element.textContent?.replace(/\s+/g, ' ').trim());

  afterEach(() => {
    fixture?.destroy();
    fixture = null;
    document.body.innerHTML = '';
  });

  it("the root configuration: the page's language, the database's words of the library's keys - but the fan clause, the site's own - and no facts, which come with the pages' frame", async () => {
    await configure({
      'anotoki.legal.privacyTitle': 'Our privacy',
      'anotoki.legal.fanHoyoverse': 'The library key, reworded',
    });
    const config = TestBed.inject(ANOTOKI_LEGAL_CONFIG);
    expect(config.language?.()).toBe('en');
    expect(config.translate?.('anotoki.legal.privacyTitle')).toBe('Our privacy');
    // The library's fan clause names Genshin Impact alone: the site's names both games, whatever the library key says.
    expect(config.translate?.('anotoki.legal.fanHoyoverse')).toBe(WORDS['legal.fanClause']);
    expect(config.paths).toBeUndefined();
    expect(config.operator).toBeUndefined();
    expect(() => config.facts()).toThrow(/frame/);
  });

  it('the facts: six parts of its own, the visitors for the days the sweep keeps them, feedback, its audit log and backups in its words, its cookies and storage by name, the hosting company, Cloudflare and the showcase service, the fan clause', async () => {
    await configure();
    const facts = siteLegalFacts(TestBed.inject(TranslationService), TestBed.inject(AnotokiAuth), 30);
    expect(facts.site).toBe('Build Analyzer');
    expect(facts.about).toBe(WORDS['legal.about']);
    expect(facts.updated).toBe(SITE_LEGAL_UPDATED);
    // The anotoki account's pages at the IAM the site signs in with - never a string's address.
    expect(facts.account).toEqual({ deleteUrl: `${IAM}/account`, privacyUrl: `${IAM}/privacy` });
    expect(facts.data.map((part) => part.name)).toEqual([
      'Your record',
      'Your game accounts',
      "If you run syncs or keep the site's words",
      'Limits against floods',
      'Showcase answers',
      "The site's own log",
    ]);
    expect(facts.visitors).toEqual({
      what: WORDS['legal.visitors.what'],
      why: WORDS['legal.visitors.why'],
      kept: 'Until a sign-in, or 30 days after the last visit.',
    });
    // The site takes feedback (Feedback in the family's footer): its own words for what a message keeps and how long -
    // and none where the Contact page's frame says no message can be sent now (legalPagesConfig()).
    expect(facts.feedback).toEqual({
      what: WORDS['legal.feedback.what'],
      kept: WORDS['legal.feedback.kept'],
    });
    expect(siteLegalFacts(TestBed.inject(TranslationService), TestBed.inject(AnotokiAuth), 30, false).feedback).toBe(false);
    expect(facts.errorLog).toBeUndefined();
    expect(facts.analytics).toBeUndefined();
    expect(facts.fanDisclaimer).toBe('hoyoverse');
    expect(facts.auditLog).toEqual({ what: WORDS['legal.auditLog.what'] });
    expect(facts.backups).toEqual({ what: WORDS['legal.backups.what'] });
    expect(facts.storage.map((item) => [item.name, item.kind ?? null, item.preset ?? null])).toEqual([
      ['anotoki-auth:build-analyzer', null, 'signIn'],
      ['anotoki-auth:build-analyzer:hint', 'local', null],
      ['anotoki-auth:build-analyzer:login:…, anotoki-auth:discovery:…', 'session', null],
      ['build-analyzer-sign-in', 'local', null],
      [SIGN_IN_HINT_COOKIE, null, 'signInHint'],
      ['build-analyzer-theme', null, 'theme'],
      ['build-analyzer-language, build-analyzer-language-cache', null, 'language'],
      ['build-analyzer-language', 'session', null],
      ['build-analyzer-visitor', null, 'visitorKey'],
      ['build-analyzer-version-seen', 'local', null],
      ['build-analyzer-localization-drafts', 'session', null],
    ]);
    expect(facts.processors).toEqual([
      { preset: 'hosting', name: 'WebSupport s.r.o.', url: 'https://www.websupport.sk' },
      { preset: 'cloudflare' },
      { name: WORDS['legal.showcaseService.name'], role: WORDS['legal.showcaseService.role'] },
    ]);
    expect(HOSTING.url).toBe('https://www.websupport.sk');
  });

  it("the Privacy page, drawn for real in the frame's configuration: the site's parts in order, its storage, who else handles data, the account kept by the anotoki account - and every link where the code says, a rewording changing words, never an address", async () => {
    await configure({
      'legal.accounts.what': 'See https://evil.example for more.',
      'legal.showcaseService.role': 'Ask https://evil.example.',
      'anotoki.legal.accountPage': 'Delete it at https://evil.example',
    });
    const host = await draw(PrivacyFrame);

    expect(host.querySelector('h1')?.textContent?.trim()).toBe('Privacy policy');
    expect(Array.from(host.querySelectorAll('[data-kept]')).map((part) => part.getAttribute('data-kept'))).toEqual([
      'data-0',
      'data-1',
      'data-2',
      'data-3',
      'data-4',
      'data-5',
      'visitors',
      'feedback',
      'error-log',
      'audit-log',
      'backups',
    ]);
    // The site's words, then the library's sentence for plain backups: on the server, out of public reach - never encrypted.
    const backups = texts(host, '[data-kept="backups"] .anotoki-legal-page__text')[0] ?? '';
    expect([backups.startsWith(`${WORDS['legal.backups.what']} `), backups.includes('out of public reach'), backups.includes('encrypted')]).toEqual([true, true, false]);
    expect(texts(host, '[data-duration="visitors"] dd')).toEqual(['Until a sign-in, or 30 days after the last visit.']);
    expect(texts(host, '[data-duration="feedback"] dd')).toEqual([WORDS['legal.feedback.kept']]);
    // The error log in the library's words: 30 days, the address as a keyed code.
    expect(texts(host, '[data-duration="error-log"] dd')).toEqual(['30 days.']);
    expect(texts(host, '[data-stored] code')).toEqual([
      'anotoki-auth:build-analyzer',
      'anotoki-auth:build-analyzer:hint',
      'anotoki-auth:build-analyzer:login:…, anotoki-auth:discovery:…',
      'build-analyzer-sign-in',
      'anotoki_signed_in',
      'build-analyzer-theme',
      'build-analyzer-language, build-analyzer-language-cache',
      'build-analyzer-language',
      'build-analyzer-visitor',
      'build-analyzer-version-seen',
      'build-analyzer-localization-drafts',
    ]);
    expect(texts(host, '[data-recipient] .anotoki-legal-page__item-name')).toEqual(['WebSupport s.r.o.', 'Cloudflare, Inc.', 'The showcase service']);
    expect(host.querySelector('[data-analytics]')?.textContent).toContain('umami.anotoki.eu');

    // The account: kept by the anotoki account, whose own Privacy page - at the IAM the site signs in with - is linked.
    expect(host.querySelector('[data-account-privacy]')?.getAttribute('href')).toBe(`${IAM}/privacy`);
    const deletion = host.querySelector<HTMLAnchorElement>('[data-account-page]')!;
    expect([deletion.getAttribute('href'), deletion.textContent?.trim()]).toEqual([`${IAM}/account`, 'Delete it at https://evil.example']);
    expect(host.querySelector('[data-kept="data-1"]')?.textContent).toContain('See https://evil.example for more.');
    const links = Array.from(host.querySelectorAll('a[href]')).map((link) => link.getAttribute('href')!);
    expect(links.filter((href) => !href.startsWith('#') && !ADDRESSES.includes(href))).toEqual([]);
    expect(links).not.toContain('https://evil.example');
  });

  it("Terms: what Build Analyzer is, first, under its name - and the site's own fan clause, naming both games", async () => {
    await configure();
    const host = await draw(TermsFrame);
    expect(host.querySelector('[data-about]')?.textContent?.trim()).toBe(WORDS['legal.about']);
    expect(host.querySelector('[data-section="what"] h2')?.textContent?.trim()).toBe('What Build Analyzer is');
    expect(host.querySelector('[data-fan]')?.textContent?.trim()).toBe(WORDS['legal.fanClause']);
  });

  it("Contact: the feedback form first - the site takes feedback -, then the address, the anotoki account's page to delete the account, and the fan clause", async () => {
    // A visitor (the IAM lets visitors in here): a message can be sent.
    await configure();
    const host = await open('/contact');
    expect(host.querySelector('[data-section="feedback"] [data-open-feedback]')?.textContent?.trim()).toBe('Send feedback');
    expect(host.querySelector('[data-section="email"] h2')?.textContent?.trim()).toBe('Everything else');
    expect(host.querySelector('[data-section="email"] a')?.getAttribute('href')).toBe('mailto:support@anotoki.eu');
    expect(host.querySelector('[data-account-page]')?.getAttribute('href')).toBe(`${IAM}/account`);
    expect(host.querySelector('[data-section="delete"]')?.textContent).toContain('Build Analyzer then keeps what you did here without your name');
    expect(host.textContent).toContain(WORDS['legal.fanClause']);
  });

  it('Contact offers no form to somebody signed out while the IAM requires signing in here - the server would refuse their message: the address alone; Privacy still says what a message holds', async () => {
    await configure({}, 'en', true);
    expect(TestBed.inject(AnotokiAuth).state()).toBe('signed-out');

    const host = await open('/contact');
    expect(host.querySelector('anotoki-contact-page')).not.toBeNull();
    expect(host.querySelector('[data-section="feedback"]')).toBeNull();
    expect(host.querySelector('[data-open-feedback]')).toBeNull();
    expect(host.querySelector('[data-section="email"] h2')?.textContent?.trim()).toBe('By e-mail');
    expect(host.querySelector('[data-section="email"] a')?.getAttribute('href')).toBe('mailto:support@anotoki.eu');

    // The Privacy page, in the same tab: what a message holds and how long it is kept - said to everybody.
    await TestBed.inject(Router).navigateByUrl('/privacy');
    await fixture!.whenStable();
    expect(host.querySelector('anotoki-privacy-page')).not.toBeNull();
    expect(texts(host, '[data-duration="feedback"] dd')).toEqual([WORDS['legal.feedback.kept']]);
  });

  it("in Slovak: the library's words - which address nobody - around the site's, its {days} filled in", async () => {
    await configure({ 'legal.visitors.kept': 'Kým ich prihlásenie nepresunie, alebo toľko dní: {days}.' }, 'sk');
    const host = await draw(PrivacyFrame);
    expect(host.querySelector('anotoki-privacy-page')?.getAttribute('lang')).toBe('sk');
    expect(host.querySelector('h1')?.textContent?.trim()).toBe('Ochrana osobných údajov');
    expect(texts(host, '[data-duration="visitors"] dd')).toEqual(['Kým ich prihlásenie nepresunie, alebo toľko dní: 30.']);
  });
});
