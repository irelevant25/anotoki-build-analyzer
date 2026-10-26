// The browser check's tours (run.mjs starts everything and runs this):
//
//   node flow.mjs <site> <iam> <work folder> [--measure]
//
// Headless Edge through playwright-core (installed in $PW_DIR, never in the repository), one browser context
// per person, so each has storage of its own. Every step prints "ok" or "FAIL"; the screenshots go to
// <work>/shots - look at them. The database is the throwaway one in <work>/config, read through
// backend/tests/tools/browser-db.php.

import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(join(process.env.PW_DIR, 'node_modules', 'noop.js'));
const { chromium } = require('playwright-core');

const [SITE, IAM, WORK] = process.argv.slice(2);
const MEASURE = process.argv.includes('--measure');
const here = dirname(fileURLToPath(import.meta.url));
const repo = resolve(here, '../../../..');
const backend = join(repo, 'backend');
const shots = join(WORK, 'shots');
const php = process.env.PHP ?? 'php';

/** The bar's breakpoint, as top-bar.ts has it: everybody's pages' (the admin panel has the family's admin frame, no bar). */
const TOP_BAR_TS = readFileSync(join(repo, 'frontend/src/app/sites/main/common/top-bar/top-bar.ts'), 'utf8');
const PHONE_BAR_MAX = Number(/export const PHONE_BAR_MAX = (\d+);/.exec(TOP_BAR_TS)[1]);
/** Every key of the site's words: none of them may show where a word should be. */
const KEYS = readdirSync(join(repo, 'frontend/src/app/shared/i18n/en')).flatMap((file) => Object.keys(JSON.parse(readFileSync(join(repo, 'frontend/src/app/shared/i18n/en', file), 'utf8'))));

/**
 * The family's bar (@anotoki/lib/shell's <anotoki-top-bar>, inside the site's app-top-bar) and the kit's pieces
 * the tours press and read, by the kit's class names (anotoki-<piece>__<part>).
 */
const BAR = 'app-top-bar .anotoki-top-bar__topbar';
const BAR_LINK = 'app-top-bar .anotoki-top-bar__link';
const SIGN_IN = 'app-top-bar .anotoki-top-bar__sign-in';
const PERSON_BUTTON = 'app-top-bar .anotoki-top-bar__person-button';
const PERSON_NAME = 'app-top-bar .anotoki-top-bar__person-name';
const MENU_BUTTON = 'app-top-bar .anotoki-top-bar__menu-button';
/** The bar's own menu while it is open: the phone's, or the person's (the language's is the switcher's). */
const BAR_MENU = 'app-top-bar .anotoki-top-bar__actions > anotoki-menu > [role=menu]';
const LANGUAGE_BUTTON = 'app-top-bar .anotoki-language-switcher__switcher';
const LANGUAGE_MENU = 'app-top-bar anotoki-language-switcher [role=menu]';
const THEME_SWITCH = 'app-top-bar anotoki-theme-toggle';
/**
 * The admin panel's frame: the anotoki family's (@anotoki/lib/admin/frame's <anotoki-admin-frame>, inside the site's
 * app-admin-root, anotoki-lib 0.10.0) - its column on a computer (the panel's name, the person, Back, Sign out, the
 * theme, the pages), on a phone its row with Menu and the side navigation's drawer.
 */
const ADMIN_FRAME = 'app-admin-root anotoki-admin-frame';
const FRAME_COLUMN = `${ADMIN_FRAME} header.anotoki-admin-frame__column`;
const FRAME_LINK = `${FRAME_COLUMN} nav a.anotoki-side-nav__link`;
const FRAME_LABEL = `${FRAME_COLUMN} nav .anotoki-side-nav__label`;
const FRAME_MENU = `${FRAME_COLUMN} button.anotoki-admin-frame__menu`;
/** The drawer the frame's Menu opens, while it is open: the kit's, around a native <dialog>. */
const FRAME_DRAWER = 'dialog[open]';
/** Up to this width (px) the frame is a phone's: the library's default, which the site keeps. */
const FRAME_PHONE_MAX = 860;
/**
 * The ADMIN's pages in the frame's column, by their words: the family's one shape for every admin panel (anotoki-lib
 * 0.11.0) - Builds; Localization: Translations, Languages; System: the family's pages in the family's order.
 */
const ADMIN_PAGES = ['Builds', 'Translations', 'Languages', 'Site settings', 'People', 'Feedback', 'Error log', 'Audit log', 'Backups', 'Migrations'];
/** What open() waits for: everybody's pages' bar, the admin panel's frame, or the closed panel's card. */
const PAGE_FRAME = `${BAR}, ${ADMIN_FRAME}, app-admin-root .no-access`;
/** The kit's dialog - What's new, feedback, a question - around a native <dialog>. */
const DIALOG = 'anotoki-dialog';
/** A message of the kit's toasts. */
const TOAST = 'anotoki-toast-host .anotoki-toast-host__message';

const failures = [];
function check(ok, what) {
  console.log(`${ok ? '  ok  ' : '  FAIL'} ${what}`);
  if (!ok) {
    failures.push(what);
  }
}
function step(title) {
  console.log(`\n== ${title}`);
}

/** One query against the throwaway database: its rows. */
function sql(query, params = []) {
  const out = execFileSync(php, ['tests/tools/browser-db.php', 'sql', join(WORK, 'config'), query, JSON.stringify(params)], { cwd: backend, encoding: 'utf8' });
  return JSON.parse(out);
}

/** The error log's lines of the errors the pages met (source "client": the family's reporter, POST /api/client-errors). */
function pageErrors() {
  const folder = join(WORK, 'config', 'storage', 'logs');
  return (existsSync(folder) ? readdirSync(folder) : [])
    .filter((file) => /^errors-.*\.jsonl$/.test(file))
    .flatMap((file) =>
      readFileSync(join(folder, file), 'utf8')
        .split('\n')
        .filter((line) => line.trim() !== '')
        .map((line) => JSON.parse(line)),
    )
    .filter((line) => line.source === 'client');
}

/**
 * The one error a page meets on purpose (the admin tools' tour), and as the reporter scrubs it: an address the page
 * keeps nowhere is `{email}` - ada's own, which her session keeps in localStorage, would be `{stored}`.
 */
const THROWN = "A check of the pages' errors, for someone@example.test";
const THROWN_SCRUBBED = "A check of the pages' errors, for {email}";

/** The site settings' tour: an announcement, and what the maintenance page says - in English and informal Slovak. */
const ANNOUNCED = { en: 'New builds are in: every character has fresh ones on their builds page.', sk: 'Prišli nové buildy: každá postava má čerstvé na svojej stránke buildov.' };
const CLOSED = { en: 'We are moving the site to a new server. Back within the hour.', sk: 'Sťahujeme stránku na nový server. Do hodiny sme späť.' };

/** How the stand-in IAM behaves from now on: deny, login_required, ... (its header lists them). */
async function iamState(changes) {
  await fetch(`${IAM}/_test/state`, { method: 'POST', body: JSON.stringify(changes) });
}

/**
 * The site forgets what the IAM last said about requiring a sign-in (the SDK keeps it a minute in the settings
 * folder's storage/cache/iam/): its next request asks again.
 */
function forgetSwitch() {
  const folder = join(WORK, 'config', 'storage', 'cache', 'iam');
  for (const file of existsSync(folder) ? readdirSync(folder) : []) {
    if (file.startsWith('anotoki-iam-application-')) {
      rmSync(join(folder, file), { force: true });
    }
  }
}

/** Who the stand-in IAM signs in next. */
async function iamAs(user) {
  await fetch(`${IAM}/_test/state`, { method: 'POST', body: JSON.stringify({ user }) });
}

const MONA = { id: 7, username: 'mona', roles: ['MEMBER'] };
const EDDIE = { id: 8, username: 'eddie', roles: ['MEMBER', 'EDITOR'] };
const ADA = { id: 1, username: 'ada', roles: ['MEMBER', 'ADMIN'] };
const SAM = { id: 10, username: 'sam', roles: ['MEMBER'] };
const RITA = { id: 11, username: 'rita', roles: ['MEMBER'] };
/** An account at an IAM that keeps its theme, dates and clock (its migration 015): the others' IAM keeps none. */
const PIA = { id: 12, username: 'pia', roles: ['MEMBER'], locale: 'en', preferences: { theme: 'dark', date_format: 'ymd_dash', time_format: '24' } };
/** Signed in at the IAM (on another anotoki site), never here: the sign-in hint signs her in. */
const HANA = { id: 13, username: 'hana', roles: ['MEMBER'] };

/**
 * A GOOD file (the tests' own sample): one Genshin account, no UID - Hu Tao; Kazuha at C0, whom her main build's team
 * (tests/tools/browser-db.php) asks at C2; and Traveler (Anemo), who can stand in for him there.
 */
const GOOD = JSON.stringify({
  format: 'GOOD',
  version: 3,
  source: 'Some Exporter',
  characters: [
    { key: 'HuTao', level: 90, constellation: 1, ascension: 6, talent: { auto: 10, skill: 9, burst: 8 } },
    { key: 'KaedeharaKazuha', level: 90, constellation: 0, ascension: 6, talent: { auto: 1, skill: 9, burst: 9 } },
    { key: 'TravelerAnemo', level: 70, constellation: 0, ascension: 5, talent: { auto: 1, skill: 6, burst: 6 } },
  ],
  weapons: [{ key: 'StaffOfHoma', level: 90, ascension: 6, refinement: 1, location: 'HuTao', lock: true }],
  artifacts: [
    {
      setKey: 'CrimsonWitchOfFlames',
      slotKey: 'flower',
      level: 20,
      rarity: 5,
      mainStatKey: 'hp',
      location: 'HuTao',
      lock: true,
      substats: [
        { key: 'critRate_', value: 10.5 },
        { key: 'critDMG_', value: 14.0 },
      ],
    },
  ],
});
const goodFile = join(WORK, 'good.json');
writeFileSync(goodFile, GOOD);

const browser = await chromium.launch({ channel: 'msedge', headless: true });
const version = JSON.parse(readFileSync(join(repo, 'frontend/public/changelog.json'), 'utf8'))[0].version;

async function newContext({ width = 1280, height = 900, scheme = 'light' } = {}) {
  const context = await browser.newContext({ viewport: { width, height }, locale: 'en-GB', colorScheme: scheme, acceptDownloads: true });
  // What's new opens by itself for somebody who has used the site before: seen already here, so it does not
  // stand over the pages (the footer's button is checked on its own).
  await context.addInitScript((seen) => {
    try {
      localStorage.setItem('build-analyzer-version-seen', seen);
    } catch {
      // the IAM's pages
    }
  }, version);
  return context;
}

async function shot(page, name, options = {}) {
  await page.waitForTimeout(250);
  await page.screenshot({ path: join(shots, `${name}.png`), ...options });
}

/** Opens a page of the site and waits for its frame: the bar, the admin panel's frame, or the closed panel's card. */
async function open(page, path) {
  await page.goto(SITE + path);
  await page.waitForSelector(PAGE_FRAME, { timeout: 15000 });
  await page.waitForLoadState('networkidle');
}

/** Opens a page of the site while it is not ready: the site gate's page (@anotoki/lib) stands in its place. */
async function openGated(page, path) {
  await page.goto(SITE + path);
  await page.waitForSelector('anotoki-site-status h1', { timeout: 15000 });
  await page.waitForLoadState('networkidle');
}

/**
 * Whether an element's own background is a dark one (the theme followed into the family's pages) - within a
 * moment: a change of the device's scheme on an open page is drawn at the next render (the shell's ThemeService
 * writes <html data-theme> from an effect).
 */
async function darkSurface(page, selector) {
  return page
    .waitForFunction(
      (selector) => {
        const element = document.querySelector(selector);
        if (!element) {
          return false;
        }
        const [r, g, b] = (getComputedStyle(element).backgroundColor.match(/[\d.]+/g) ?? ['255', '255', '255']).map(Number);
        return (r + g + b) / 3 < 80;
      },
      selector,
      { timeout: 3000 },
    )
    .then(
      () => true,
      () => false,
    );
}

/** The gate's page as a person reads it: its title, all its words, its buttons, its links (words and address). */
async function gatePage(page) {
  return page.evaluate(() => {
    const status = document.querySelector('anotoki-site-status');
    const words = (element) => (element?.innerText ?? '').replace(/\s+/g, ' ').trim();
    return {
      title: words(status?.querySelector('h1')),
      text: words(status),
      role: status?.querySelector('section')?.getAttribute('role') ?? null,
      buttons: [...(status?.querySelectorAll('button') ?? [])].map(words),
      links: [...(status?.querySelectorAll('a') ?? [])].map((link) => `${words(link)} -> ${link.getAttribute('href')}`),
      page: words(document.body),
    };
  });
}

/**
 * What a page may ask the API for while the gate's page stands in its place: the open paths, and the site settings
 * read once as the app starts (anotoki-lib 0.11.0: no open path - refused 503 while an update waits, which leaves
 * nothing announced and every page anybody's, under the gate's page) - nothing else.
 */
const OPEN_WHILE_WAITING = /^\/api\/(meta|site-status|site-settings|translations\/[a-z-]+)$/;

/**
 * Listens to the API paths a page asks for from now on; the function it returns stops listening and gives them.
 * While the site waits for an update, anything but the open paths would be a page made behind the gate's page
 * (anotoki-lib 0.1.1 makes them only while they show).
 */
function apiAsked(page) {
  const paths = new Set();
  const listener = (request) => {
    const url = new URL(request.url());
    if (url.origin === new URL(SITE).origin && url.pathname.startsWith('/api/')) {
      paths.add(url.pathname);
    }
  };
  page.on('request', listener);
  return () => {
    page.off('request', listener);
    return [...paths];
  };
}

/** Through the stand-in IAM and back, from the bar's Sign in (or the phone menu's). */
async function signIn(page, user) {
  await iamAs(user);
  if (await page.locator(MENU_BUTTON).count()) {
    await page.click(MENU_BUTTON);
    await page.click(`${BAR_MENU} .anotoki-top-bar__sign-in-item`);
  } else {
    await page.click(SIGN_IN);
  }
  await page.waitForURL((url) => url.href.startsWith(SITE) && !url.pathname.startsWith('/auth/callback'), { timeout: 15000 });
  await page.waitForSelector(`${PERSON_BUTTON}, ${MENU_BUTTON}`, { timeout: 15000 });
  await page.waitForFunction(() => !!localStorage.getItem('anotoki-auth:build-analyzer'));
  await page.waitForLoadState('networkidle');
}

/** The bar's pieces: where each is, whether any two overlap, whether any leaves the screen, and the page's scroll width. */
async function barLayout(page) {
  return page.evaluate(() => {
    const pick = (selector) => [...document.querySelectorAll(selector)].filter((element) => element.getBoundingClientRect().width > 0);
    const pieces = [
      ...pick('app-top-bar anotoki-brand'),
      ...pick('app-top-bar .anotoki-top-bar__link'),
      ...pick('app-top-bar .anotoki-language-switcher__switcher'),
      ...pick('app-top-bar anotoki-theme-toggle'),
      ...pick('app-top-bar .anotoki-top-bar__person-button'),
      ...pick('app-top-bar .anotoki-top-bar__sign-in'),
      ...pick('app-top-bar .anotoki-top-bar__menu-button'),
    ].map((element) => {
      const box = element.getBoundingClientRect();
      return { what: (element.className || element.tagName).toString().split(' ')[0].toLowerCase(), left: Math.round(box.left), right: Math.round(box.right) };
    });
    const overlaps = [];
    for (let i = 0; i < pieces.length; i++) {
      for (let j = i + 1; j < pieces.length; j++) {
        if (pieces[i].left < pieces[j].right - 0.5 && pieces[j].left < pieces[i].right - 0.5) {
          overlaps.push(`${pieces[i].what}/${pieces[j].what}`);
        }
      }
    }
    const outside = pieces.filter((piece) => piece.left < 0 || piece.right > window.innerWidth).map((piece) => piece.what);
    const name = document.querySelector('app-top-bar .anotoki-top-bar__person-name');
    return {
      pieces,
      overlaps,
      outside,
      scroll: document.documentElement.scrollWidth,
      width: window.innerWidth,
      nameCut: name && getComputedStyle(name).display !== 'none' ? name.scrollWidth > name.clientWidth : null,
    };
  });
}

function describeLayout(layout) {
  return layout.pieces.map((piece) => `${piece.what} ${piece.left}-${piece.right}`).join(', ');
}

async function checkBar(page, label) {
  const layout = await barLayout(page);
  check(
    layout.overlaps.length === 0 && layout.outside.length === 0 && layout.scroll <= layout.width,
    `${label}: the bar fits - ${describeLayout(layout)}${layout.overlaps.length ? ` OVERLAP ${layout.overlaps}` : ''}${layout.outside.length ? ` OUTSIDE ${layout.outside}` : ''}, scroll ${layout.scroll}`,
  );
  return layout;
}

/** Keys where words should be: any key of the site's that stands in the page's text. */
async function keysShown(page) {
  const text = await page.evaluate(() => document.body.innerText);
  return KEYS.filter((key) => new RegExp(`(^|[^A-Za-z0-9_.])${key.replace(/[.]/g, '\\.')}($|[^A-Za-z0-9_])`).test(text));
}

/**
 * What the row needs to show everything but the person's name, in px: the padding, the brand, the links,
 * the controls, the gaps between. --measure prints it for each language and who is there.
 */
async function rowNeeds(page) {
  return page.evaluate(() => {
    const inner = document.querySelector('app-top-bar .anotoki-top-bar__row');
    const style = getComputedStyle(inner);
    const gap = parseFloat(style.columnGap);
    const actions = inner.querySelector('.anotoki-top-bar__actions');
    const actionGap = parseFloat(getComputedStyle(actions).columnGap);
    // A menu's own element draws no box (display: contents): only what shows is counted.
    const visible = [...actions.children].filter((child) => child.getBoundingClientRect().width > 0);
    let actionWidth = 0;
    for (const child of visible) {
      if (child.classList.contains('anotoki-top-bar__person-button')) {
        const name = child.querySelector('.anotoki-top-bar__person-name');
        // The round button alone: what it is without the name (and its gap), with the narrower right padding.
        actionWidth += child.getBoundingClientRect().width - name.getBoundingClientRect().width - parseFloat(getComputedStyle(child).columnGap) - (0.6 - 0.4) * 16;
      } else {
        actionWidth += child.getBoundingClientRect().width;
      }
    }
    actionWidth += actionGap * (visible.length - 1);
    const brand = inner.querySelector('anotoki-brand').getBoundingClientRect().width;
    const nav = inner.querySelector('.anotoki-top-bar__nav')?.getBoundingClientRect().width ?? 0;
    return Math.ceil(parseFloat(style.paddingLeft) + parseFloat(style.paddingRight) + brand + gap + (nav ? nav + gap : 0) + actionWidth);
  });
}

try {
  if (MEASURE) {
    // The bar is everybody's pages' alone: the admin panel has the family's admin frame, whose phone width is the library's.
    step("What the row needs (the person's name aside), at 1600 px");
    for (const language of ['en', 'sk']) {
      const context = await newContext({ width: 1800 });
      const page = await context.newPage();
      await open(page, `/?lang=${language}`);
      console.log(`  ${language}, signed out: ${await rowNeeds(page)} px`);
      await signIn(page, ADA);
      console.log(`  ${language}, an admin: ${await rowNeeds(page)} px`);
      await context.close();
    }
  } else {
    // ── Nobody ─────────────────────────────────────────────────────────────────────────────────────────
    step('Nobody: the dashboard is the ways in, and asks for no accounts');
    const nobody = await newContext();
    const page = await nobody.newPage();
    const asked = [];
    page.on('request', (request) => asked.push(new URL(request.url()).pathname));
    await open(page, '/?lang=en');
    check((await page.locator('app-visitor-hero').count()) === 1, 'the hero is there');
    check(!asked.some((path) => path.startsWith('/api/accounts')), 'no /api/accounts request');
    check((await page.locator(BAR_LINK).allInnerTexts()).join('|') === 'Dashboard|Import|Builds', 'the bar links Dashboard, Import, Builds');
    check((await page.locator(SIGN_IN).innerText()).includes('Sign in'), 'Sign in in the bar');
    check((await page.locator('anotoki-site-footer').innerText()).includes(`Version ${version}`), `the footer says Version ${version}`);
    await page.click('anotoki-site-footer [data-whats-new]');
    await page.waitForSelector(`${DIALOG} dialog[open] .release`, { timeout: 10000 });
    check(await page.locator(`${DIALOG} dialog[open]`).isVisible(), "the footer's What's new opens the notes");
    await shot(page, '01-nobody-whats-new');
    await page.keyboard.press('Escape');
    await shot(page, '02-nobody-dashboard-1280-light', { fullPage: true });

    // ── The sign-in hint ───────────────────────────────────────────────────────────────────────────────
    step('The sign-in hint: somebody signed in at the IAM is signed in here without a click - and signed out here once signed out there');
    {
      const authorizes = async () => (await (await fetch(`${IAM}/_test/state`)).json()).log.filter((line) => line.startsWith('GET /api/oidc/authorize')).length;
      const hinted = await newContext();
      const page = await hinted.newPage();
      await iamAs(HANA);
      const hopsBefore = await authorizes();
      // Signed in at the IAM itself (another anotoki site's sign-in): a session there, and the hint it sets.
      await page.goto(`${IAM}/_test/sign-in?return=${encodeURIComponent(`${SITE}/builds/genshin?lang=en`)}`);
      await page.waitForSelector(PERSON_BUTTON, { timeout: 20000 });
      await page.waitForLoadState('networkidle');
      check(new URL(page.url()).pathname === '/builds/genshin', `signed in without a click, on the page opened (${new URL(page.url()).pathname})`);
      check((await page.locator(PERSON_NAME).innerText()).trim() === 'hana', 'the bar names hana');
      check((await authorizes()) - hopsBefore === 1, 'one silent hop to the IAM (prompt=none), nothing to press');
      check(sql('SELECT name FROM users WHERE id = 13')[0]?.name === 'hana', 'the site knows hana now');
      check(JSON.parse((await page.evaluate(() => localStorage.getItem('anotoki-auth:build-analyzer:hint'))) ?? 'null')?.value?.length >= 16, 'the hint marked tried in this browser');
      await shot(page, '02b-hint-signed-in');
      // Signed out at the IAM (on another site): the next page load here follows it.
      await page.goto(`${IAM}/_test/sign-out?return=${encodeURIComponent(`${SITE}/?lang=en`)}`);
      await page.waitForSelector(SIGN_IN, { timeout: 20000 });
      await page.waitForLoadState('networkidle');
      check((await page.locator('app-visitor-hero').count()) === 1, 'signed out there: a visitor here again at the next page load');
      await hinted.close();

      // A hint with no session behind it this site may use (interaction_required): one quiet try, never again for it.
      const quiet = await newContext();
      const visitor = await quiet.newPage();
      await iamState({ interaction: true });
      const quietBefore = await authorizes();
      await visitor.goto(`${IAM}/_test/sign-in?return=${encodeURIComponent(`${SITE}/?lang=en`)}`);
      await visitor.waitForSelector(SIGN_IN, { timeout: 20000 });
      await visitor.waitForLoadState('networkidle');
      check((await authorizes()) - quietBefore === 1 && new URL(visitor.url()).pathname === '/', 'the hop came back without a sign-in: a visitor on the page opened');
      check((await visitor.locator('anotoki-sign-in-failure').count()) === 0, 'and nothing said of it - no failure screen');
      await open(visitor, '/import?lang=en');
      check((await authorizes()) - quietBefore === 1, 'the next page load makes no second try for the same hint');
      await iamState({ interaction: false });
      await quiet.close();

      // Search engines never get the cookie - and robots.txt (anotoki-lib's, from the site's PHP) keeps them off the callback.
      const robots = await (await fetch(`${SITE}/robots.txt`)).text();
      check(/User-agent: \*/.test(robots) && robots.includes('\nDisallow: /auth\n'), `robots.txt keeps robots off /auth: ${robots.replace(/\s+/g, ' ').trim()}`);
    }

    // ── A visitor's first import ───────────────────────────────────────────────────────────────────────
    step('A visitor: the first import, its key, its notice');
    const visitor = await newContext();
    const tab = await visitor.newPage();
    await open(tab, '/import?lang=en');
    check((await tab.locator('app-visitor-notice').count()) === 1, 'the notice is on the import page before the first import');
    await tab.locator('button.choice.method').nth(1).click();
    await tab.getByRole('button', { name: "I'm ready" }).click();
    await tab.setInputFiles('input[type=file]', goodFile);
    const answer = tab.waitForResponse((response) => response.url().endsWith('/api/imports/file') && response.request().method() === 'POST');
    await tab.getByRole('button', { name: 'Import file' }).click();
    const imported = await answer;
    const key = imported.headers()['x-visitor-key'] ?? '';
    check(imported.status() === 201 && /^[A-Za-z0-9_-]{43}$/.test(key), `the import answered 201 with an X-Visitor-Key through the router (${imported.status()})`);
    await tab.waitForTimeout(500);
    check((await tab.evaluate(() => localStorage.getItem('build-analyzer-visitor'))) === key, 'the browser keeps that key');
    const hash = createHash('sha256').update(key).digest('hex');
    const row = sql('SELECT id, last_seen_at FROM users WHERE visitor_key_hash = ?', [hash]);
    check(row.length === 1 && Number(row[0].id) < 0, `the server keeps a visitor row with a negative id (${row[0]?.id})`);
    await shot(tab, '03-visitor-import-done', { fullPage: true });
    await open(tab, '/');
    check((await tab.locator('app-visitor-notice').count()) === 1 && (await tab.locator('.account-grid .account-main').count()) === 1, 'the dashboard: the notice and the imported account');
    await shot(tab, '04-visitor-dashboard', { fullPage: true });

    step('A second tab of the same browser');
    const second = await visitor.newPage();
    await open(second, '/');
    check((await second.locator('.account-grid .account-main').count()) === 1, "it sees the same account (the key is the browser's)");
    await second.close();

    step('Two tabs that import first, one after the other: the first key stays');
    const twins = await newContext();
    const [one, two] = [await twins.newPage(), await twins.newPage()];
    for (const page of [one, two]) {
      await open(page, '/import?lang=en');
    }
    const keys = [];
    for (const page of [one, two]) {
      await page.locator('button.choice.method').nth(1).click();
      await page.getByRole('button', { name: "I'm ready" }).click();
      await page.setInputFiles('input[type=file]', goodFile);
      const done = page.waitForResponse((response) => response.url().endsWith('/api/imports/file'));
      await page.getByRole('button', { name: 'Import file' }).click();
      keys.push((await done).headers()['x-visitor-key'] ?? null);
      await page.waitForTimeout(300);
    }
    const kept = await one.evaluate(() => localStorage.getItem('build-analyzer-visitor'));
    check(keys[0] !== null && kept === keys[0], `the stored key is the first tab's (${keys.map((k) => (k ?? 'none').slice(0, 6)).join(', ')} -> ${kept?.slice(0, 6)})`);
    await twins.close();

    // ── The move at sign-in ────────────────────────────────────────────────────────────────────────────
    step("Signing in moves what the visitor imported into the person's account");
    await signIn(tab, MONA);
    const toast = await tab
      .locator(TOAST)
      .first()
      .innerText({ timeout: 10000 })
      .catch(() => '');
    check(toast.includes('now in your anotoki account'), `a toast says so: "${toast.replace(/\s+/g, ' ').trim()}"`);
    check((await tab.evaluate(() => localStorage.getItem('build-analyzer-visitor'))) === null, 'the key is gone from the browser');
    check(sql('SELECT count(*) AS n FROM users WHERE visitor_key_hash = ?', [hash])[0].n == 0, 'the visitor row is gone');
    check(sql('SELECT count(*) AS n FROM accounts WHERE user_id = 7')[0].n == 1, "the account is mona's now");
    check((await tab.locator('app-visitor-notice').count()) === 0 && (await tab.locator('app-data-card').count()) === 1, 'no notice; the "Your data" card');
    await shot(tab, '05-member-dashboard-after-move', { fullPage: true });

    // ── The member's own data ──────────────────────────────────────────────────────────────────────────
    step("The member's export, and the import's preview and confirm");
    const [download] = await Promise.all([tab.waitForEvent('download'), tab.click('app-data-card button.export')]);
    const exported = join(WORK, download.suggestedFilename());
    await download.saveAs(exported);
    check(/^build-analyzer-accounts-\d{4}-\d{2}-\d{2}\.json$/.test(download.suggestedFilename()), `the file's name: ${download.suggestedFilename()}`);
    const file = JSON.parse(readFileSync(exported, 'utf8'));
    check(file.format === 'build-analyzer.accounts' && file.accounts.length === 1, 'a file of this site with the one account');
    check(!/"(id|user_id|account_id)"\s*:/.test(readFileSync(exported, 'utf8')), 'no ids in it');
    await tab.setInputFiles('app-data-card input[type=file]', exported);
    await tab.waitForSelector('app-data-card .preview', { timeout: 10000 });
    const preview = (await tab.locator('app-data-card .preview .summary').innerText()).trim();
    check(preview.startsWith('0 new, 0 replaced by a newer copy, 1 kept'), `the preview: ${preview}`);
    await shot(tab, '06-member-import-preview', { fullPage: true });
    await tab.click('app-data-card button.confirm');
    await tab.waitForSelector('app-data-card .done', { timeout: 10000 });
    check((await tab.locator('app-data-card .done').innerText()).startsWith('Imported:'), 'confirmed: imported');

    // ── The bar ────────────────────────────────────────────────────────────────────────────────────────
    step(`The bar at 360, 390, ${PHONE_BAR_MAX}, ${PHONE_BAR_MAX + 1} and 1280 px, light and dark, signed in and out, English and Slovak`);
    for (const scheme of ['light', 'dark']) {
      for (const width of [360, 390, PHONE_BAR_MAX, PHONE_BAR_MAX + 1, 1280]) {
        for (const language of ['en', 'sk']) {
          await tab.setViewportSize({ width, height: 760 });
          await tab.emulateMedia({ colorScheme: scheme });
          await open(tab, `/?lang=${language}`);
          const layout = await checkBar(tab, `${width} px, ${scheme}, ${language}, signed in`);
          check((await tab.locator(MENU_BUTTON).count()) === (width <= PHONE_BAR_MAX ? 1 : 0), `${width} px: ${width <= PHONE_BAR_MAX ? "a phone's bar" : "a desktop's bar"}`);
          if (width === 1280 && scheme === 'light') {
            // Label in Name (WCAG 2.5.3): the code the button shows is in its accessible name - "click SK".
            const chooser = tab.locator(LANGUAGE_BUTTON);
            const shown = (await chooser.innerText()).trim();
            const name = await chooser.getAttribute('aria-label');
            check(
              shown === language.toUpperCase() && name === (language === 'sk' ? 'Jazyk: Slovenčina (SK)' : 'Language: English (EN)'),
              `${language}: the language button shows ${shown}, and its name says it too - ${name}`,
            );
          }
          if (language === 'sk' || width === 1280) {
            await shot(tab, `bar-${width}-${scheme}-${language}-signed-in`, { clip: { x: 0, y: 0, width, height: 120 } });
          }
          void layout;
        }
      }
      for (const width of [360, PHONE_BAR_MAX + 1]) {
        const page = await nobody.newPage();
        await page.setViewportSize({ width, height: 760 });
        await page.emulateMedia({ colorScheme: scheme });
        await open(page, '/?lang=sk');
        await checkBar(page, `${width} px, ${scheme}, sk, signed out`);
        await shot(page, `bar-${width}-${scheme}-sk-signed-out`, { clip: { x: 0, y: 0, width, height: 120 } });
        await page.close();
      }
    }

    step('The phone menu at 390 px, and a real Tab press out of it');
    await tab.setViewportSize({ width: 390, height: 800 });
    await tab.emulateMedia({ colorScheme: 'light' });
    await open(tab, '/?lang=sk');
    await tab.click(MENU_BUTTON);
    await tab.waitForSelector(BAR_MENU);
    // Each item's words, without its icons.
    const items = await tab.evaluate(
      (menu) =>
        [...document.querySelectorAll(`${menu} [role=menuitem]`)].map((item) => {
          const copy = item.cloneNode(true);
          copy.querySelectorAll('anotoki-icon').forEach((icon) => icon.remove());
          return copy.textContent.replace(/\s+/g, ' ').trim();
        }),
      BAR_MENU,
    );
    check(items.join('|') === 'Prehľad|Import|Buildy|Tvoj anotoki účet|Odhlásiť sa', `the menu: ${items.join(' | ')}`);
    check(await tab.evaluate((menu) => document.activeElement === document.querySelector(`${menu} [role=menuitem]`), BAR_MENU), 'the focus is on its first item');
    await shot(tab, '07-phone-menu-390-sk', { fullPage: false });
    const onMenuButton = () => tab.evaluate(() => document.activeElement?.classList.contains('anotoki-top-bar__menu-button') ?? false);
    await tab.keyboard.press('ArrowDown');
    await tab.keyboard.press('Tab');
    await tab.waitForTimeout(200);
    check((await tab.locator(BAR_MENU).count()) === 0, 'Tab closed it');
    check(await onMenuButton(), `the focus is on the menu button (${await tab.evaluate(() => document.activeElement?.className || document.activeElement?.tagName)})`);
    await tab.keyboard.press('Enter');
    await tab.waitForSelector(BAR_MENU);
    await tab.keyboard.press('Shift+Tab');
    await tab.waitForTimeout(200);
    check((await tab.locator(BAR_MENU).count()) === 0 && (await onMenuButton()), 'Shift+Tab: the same');

    step("The person's menu at 1280 px, and a real Tab press out of it");
    await tab.setViewportSize({ width: 1280, height: 800 });
    await open(tab, '/?lang=en');
    await tab.click(PERSON_BUTTON);
    await tab.waitForSelector(BAR_MENU);
    check(
      (await tab.locator(`${BAR_MENU} .anotoki-top-bar__menu-head`).innerText()).includes('mona@example.test') && (await tab.locator(`${BAR_MENU} .anotoki-top-bar__menu-role`).count()) === 0,
      'name and email at its head; no role pill for a person without one',
    );
    await shot(tab, '08-account-menu-1280', { clip: { x: 0, y: 0, width: 1280, height: 400 } });
    await tab.keyboard.press('End');
    await tab.keyboard.press('Tab');
    await tab.waitForTimeout(200);
    check(
      (await tab.locator(BAR_MENU).count()) === 0 && (await tab.evaluate(() => document.activeElement?.classList.contains('anotoki-top-bar__person-button'))),
      "Tab from the last item: closed, the focus on the person's button, never <body>",
    );

    // ── Slovak ─────────────────────────────────────────────────────────────────────────────────────────
    step('Slovak at 390 px: no key where a word should be');
    await tab.setViewportSize({ width: 390, height: 800 });
    const account = sql('SELECT id FROM accounts WHERE user_id = 7')[0].id;
    for (const path of ['/', '/import', '/builds/genshin', '/builds/genshin/hutao', `/accounts/${account}`, `/accounts/${account}/characters/hutao`]) {
      await open(tab, `${path}?lang=sk`);
      const shown = await keysShown(tab);
      check(shown.length === 0 && (await tab.evaluate(() => document.documentElement.lang)) === 'sk', `${path}: Slovak, no keys${shown.length ? ` - ${shown.join(', ')}` : ''}`);
      await shot(tab, `sk-390${path.replace(/[/]/g, '-') || '-home'}`, { fullPage: true });
    }

    // ── Feedback ───────────────────────────────────────────────────────────────────────────────────────
    step("Feedback from the foot of every page: a visitor writes in Slovak at 390 px - the family's form in a dialog, light and dark - and it is kept, with nothing of where it came from");
    {
      const writer = await newContext({ width: 390, height: 860 });
      const page = await writer.newPage();
      await open(page, '/import?lang=sk');
      check((await page.locator('anotoki-site-footer [data-open-feedback]').innerText()).trim() === 'Spätná väzba', 'the foot of the page: Spätná väzba');
      await page.click('anotoki-site-footer [data-open-feedback]');
      await page.waitForSelector(`${DIALOG} anotoki-feedback-form [data-message] textarea`, { timeout: 15000 });
      const heading = page.locator(`${DIALOG} h2`);
      // The language of its words is said on the dialog, round the heading.
      const headingLang = await heading.evaluate((element) => element.closest('[lang]')?.getAttribute('lang') ?? null);
      check((await heading.innerText()).trim().length > 0 && headingLang === 'sk', `the dialog's title, in Slovak: ${(await heading.innerText()).trim()} (lang ${headingLang})`);
      check((await page.locator(`${DIALOG} [data-anonymous]`).count()) === 0, 'nobody signed in: no "without my name"');
      const shown = await keysShown(page);
      check(shown.length === 0, `no key where a word should be${shown.length ? ` - ${shown.join(', ')}` : ''}`);
      const scroll = await page.evaluate(() => document.documentElement.scrollWidth);
      check(scroll <= 390, `390 px: no sideways scroll (${scroll})`);
      await shot(page, '22-feedback-form-390-light');
      await page.emulateMedia({ colorScheme: 'dark' });
      await shot(page, '22-feedback-form-390-dark');
      await page.fill('anotoki-feedback-form [data-message] textarea', 'Import sa zastaví pri druhom kroku.');
      // The form's buttons are in the dialog's footer (anotoki-lib 0.8: actionsOutside), outside the form itself.
      await page.click(`${DIALOG} [data-send]`);
      await page.waitForSelector('anotoki-feedback-form [data-thanks]', { timeout: 15000 });
      await shot(page, '22-feedback-sent-390-dark');
      const kept = sql('SELECT kind, message, language, user_id, contact_email, page FROM anotoki_feedback ORDER BY id DESC LIMIT 1')[0];
      check(
        kept?.message === 'Import sa zastaví pri druhom kroku.' && kept?.language === 'sk' && kept?.user_id === null && kept?.contact_email === null && kept?.page === '/import',
        `kept: ${JSON.stringify(kept)}`,
      );
      await page.click(`${DIALOG} [data-close]`);
      await page.waitForSelector(DIALOG, { state: 'detached' });
      await page.setViewportSize({ width: 1280, height: 900 });
      for (const scheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: scheme });
        await page.click('anotoki-site-footer [data-open-feedback]');
        await page.waitForSelector(`${DIALOG} anotoki-feedback-form [data-message] textarea`);
        await shot(page, `22-feedback-form-1280-${scheme}`);
        await page.keyboard.press('Escape');
        await page.waitForSelector(DIALOG, { state: 'detached' });
      }
      // Signed in: their account's address written in for an answer, and "send it without my name" offered.
      await open(tab, '/?lang=en');
      await tab.click('anotoki-site-footer [data-open-feedback]');
      await tab.waitForSelector(`${DIALOG} anotoki-feedback-form [data-anonymous]`, { timeout: 15000 });
      check((await tab.locator(`${DIALOG} [data-email] input`).inputValue()) === 'mona@example.test', "mona: her account's address written in, and Send without my name offered");
      await tab.keyboard.press('Escape');
      await tab.waitForSelector(DIALOG, { state: 'detached' });
      await writer.close();
    }

    // ── The account's preferences ──────────────────────────────────────────────────────────────────────
    step("The account's theme, dates and language (an IAM with its migration 015): the account's once signed in, the toggle and the menu save to it");
    {
      const keeper = await newContext({ width: 390, height: 800 });
      const kept = await keeper.newPage();
      /** The theme as the page has it: the switch's choice (light, dark or auto), and the colours drawn (<html data-theme>). */
      const theme = () =>
        kept.evaluate(
          () => `${document.querySelector('app-top-bar anotoki-theme-toggle [role=radio][aria-checked=true]')?.getAttribute('data-value')}/${document.documentElement.getAttribute('data-theme')}`,
        );
      /** What the stand-in IAM's account now says, once it says `done` (or after five seconds). */
      const account = async (done) => {
        let user = null;
        for (let tries = 0; tries < 50; tries++) {
          user = (await (await fetch(`${IAM}/_test/state`)).json()).user;
          if (done(user)) {
            break;
          }
          await kept.waitForTimeout(100);
        }
        return user;
      };
      await open(kept, '/');
      check((await theme()) === 'auto/light', `a visitor: this device's theme, as the device is set (${await theme()})`);
      await signIn(kept, PIA);
      check((await theme()) === 'dark/dark', `signed in: the account's dark theme, though this device had none (${await theme()})`);
      check((await kept.evaluate(() => localStorage.getItem('build-analyzer-theme'))) === 'dark', 'kept on this device for the next page load');

      await kept.click('anotoki-site-footer [data-whats-new]');
      // The dialog's frame comes before its releases: wait for their dates, not only the dialog.
      await kept.waitForSelector(`${DIALOG} .release-head .date`);
      const days = (await kept.locator(`${DIALOG} .release-head .date`).allInnerTexts()).map((day) => day.trim());
      check(days.length > 0 && days.every((day) => /^\d{4}-\d{2}-\d{2}$/.test(day)), `What's new: the dates in the account's order - ${days.slice(0, 3).join(', ')}`);
      await shot(kept, '08a-account-whats-new-390-dark');
      await kept.keyboard.press('Escape');
      await kept.waitForSelector(DIALOG, { state: 'detached' });

      await kept.click(`${THEME_SWITCH} [role=radio][data-value=light]`);
      // Drawn at the next render (the shell's ThemeService writes <html data-theme> from an effect), not after the save.
      await kept.waitForFunction(() => document.documentElement.getAttribute('data-theme') === 'light', null, { timeout: 2000 }).catch(() => {});
      check((await theme()) === 'light/light', `the switch: light at once (${await theme()})`);
      const lit = await account((user) => user?.preferences?.theme === 'light');
      check(lit?.preferences?.theme === 'light', '...and saved to the account, across origins (POST /api/oidc/preferences at the stand-in IAM)');

      await kept.click(LANGUAGE_BUTTON);
      await kept.click(`${LANGUAGE_MENU} [role=menuitemradio][lang=sk]`);
      await kept.waitForFunction(() => document.documentElement.lang === 'sk', null, { timeout: 10000 });
      const spoken = await account((user) => user?.locale === 'sk');
      check(spoken?.locale === 'sk', 'the language menu: Slovak on the page, and saved to the account');
      // What's new gave the focus back to the footer's button when it closed, which scrolled the page there.
      await kept.evaluate(() => window.scrollTo(0, 0));
      await shot(kept, '08b-account-preferences-390-sk', { fullPage: true });

      await kept.reload();
      await kept.waitForSelector(BAR, { timeout: 15000 });
      await kept.waitForLoadState('networkidle');
      check((await theme()) === 'light/light' && (await kept.evaluate(() => document.documentElement.lang)) === 'sk', 'after a reload: light and Slovak, as the account now says');

      // The IAM out of reach: the choice shows, the save fails, the account's theme comes back - and the switch
      // says so under itself (the family's note, role alert).
      await iamState({ down: true });
      await kept.click(`${THEME_SWITCH} [role=radio][data-value=dark]`);
      const note = (
        await kept
          .locator(`${THEME_SWITCH} .anotoki-theme-toggle__note`)
          .innerText({ timeout: 10000 })
          .catch(() => '')
      )
        .replace(/\s+/g, ' ')
        .trim();
      await shot(kept, '08c-account-theme-refused-390-sk');
      await iamState({ down: false });
      check(note.length > 0 && (await theme()) === 'light/light', `a save the IAM could not take: the account's theme is back, and the switch says "${note}"`);
      check((await account(() => true))?.preferences?.theme === 'light', 'the account still says light');
      await keeper.close();
    }

    // ── The admin panel ────────────────────────────────────────────────────────────────────────────────
    step('The admin panel: a person without a role, an editor, an admin - English, whatever the reader reads');
    await tab.setViewportSize({ width: 1280, height: 800 });
    await open(tab, '/admin?lang=sk');
    check((await tab.locator('h1').innerText()) === 'This part is for editors and admins', 'mona (no role): the card');
    check((await tab.locator(`${ADMIN_FRAME}, app-top-bar`).count()) === 0, "a page of its own: no frame, no bar, none of the panel's pages");

    const editor = await newContext();
    const ed = await editor.newPage();
    await open(ed, '/?lang=sk');
    await signIn(ed, EDDIE);
    await open(ed, '/admin');
    check((await ed.locator(FRAME_LABEL).allInnerTexts()).join('|') === 'Builds|Translations', "an editor's pages in the frame's column: Builds, Translations");
    check((await ed.evaluate(() => document.documentElement.lang)) === 'en', '<html lang="en"> in the admin panel');
    check((await ed.locator('app-top-bar, anotoki-language-switcher').count()) === 0, 'no top bar in the admin panel, and no language chooser');
    const editorColumn = (await ed.locator(`${FRAME_COLUMN} .anotoki-admin-frame__account`).innerText()).replace(/\s+/g, ' ').trim();
    check(
      editorColumn.includes('eddie') &&
        editorColumn.includes('Back to Build Analyzer') &&
        editorColumn.includes('Sign out') &&
        (await ed.locator(`${FRAME_COLUMN} a.anotoki-admin-frame__back`).getAttribute('href')) === '/',
      `the column: who is signed in, "Back to Build Analyzer" to /, Sign out (${editorColumn})`,
    );
    await shot(ed, '09-admin-editor-builds', { fullPage: true });
    await open(ed, '/admin/languages');
    check(new URL(ed.url()).pathname === '/admin/builds', 'Languages sends an editor to Builds');
    await open(ed, '/admin/people');
    check(new URL(ed.url()).pathname === '/admin/builds', 'People sends an editor to Builds');
    await open(ed, '/admin/migrations');
    check(new URL(ed.url()).pathname === '/admin/builds', 'Migrations sends an editor to Builds');
    await open(ed, '/admin/site-settings');
    check(new URL(ed.url()).pathname === '/admin/builds', 'Site settings sends an editor to Builds');
    const refused = await ed.evaluate(async () => {
      const token =
        JSON.parse(localStorage.getItem('anotoki-auth:build-analyzer') ?? '{}').access_token ?? JSON.parse(localStorage.getItem('anotoki-auth:build-analyzer') ?? '{}').tokens?.access_token;
      const people = await fetch('/api/admin/people', { headers: { Authorization: `Bearer ${token}` } });
      const migrations = await fetch('/api/admin/migrations', { headers: { Authorization: `Bearer ${token}` } });
      const settings = await fetch('/api/admin/site-settings', { headers: { Authorization: `Bearer ${token}` } });
      return [people.status, (await people.json()).code, migrations.status, settings.status, (await settings.json()).code];
    });
    check(
      refused[0] === 403 && refused[1] === 'admin_only' && refused[2] === 403 && refused[3] === 403 && refused[4] === 'admin_only',
      `the server refuses an editor ADMIN's routes, the site settings too (${refused.join(', ')})`,
    );

    // ── The Builds page: a game's builds out to a file and the same file back in ──────────────────────────
    step("The admin panel's Builds page: the builds exported, the same file imported - a preview that changes nothing, then the import, recorded by its editor - at 1280 and 390 px, light and dark");
    {
      await open(ed, '/admin/builds');
      await ed.waitForSelector('section.game .builds button.export', { timeout: 15000 });
      check((await ed.locator('h1').innerText()).trim() === 'Builds', 'the page: Builds');
      const tiles = (await ed.locator('section.game .builds .counts').innerText()).replace(/\s+/g, ' ');
      check(tiles.includes('Characters with builds') && tiles.includes('2') && tiles.includes('3'), `its counts: two characters, three builds (${tiles})`);
      // The catalog as the check's database left it, refreshed a moment ago: nothing asked its provider on the way (the
      // check never reaches the network) - no list's refresh failed.
      const lists = (await ed.locator('section.game .lists').innerText()).replace(/\s+/g, ' ');
      check(
        (await ed.locator('section.game .list-problems').count()) === 0 &&
          !lists.includes('never refreshed') &&
          sql("SELECT count(*) AS n FROM cache WHERE key LIKE 'catalog-state:%' AND value::jsonb->>'failed_at' IS NOT NULL")[0].n == 0,
        `the catalog: refreshed, never asked on the way (${lists})`,
      );
      await shot(ed, '25-admin-builds-1280-light', { fullPage: true });

      const [download] = await Promise.all([ed.waitForEvent('download'), ed.click('section.game .builds button.export')]);
      const saved = join(WORK, download.suggestedFilename());
      await download.saveAs(saved);
      check(/^builds-genshin-\d{4}-\d{2}-\d{2}\.json$/.test(download.suggestedFilename()), `the file's name: ${download.suggestedFilename()}`);
      const builds = JSON.parse(readFileSync(saved, 'utf8'));
      check(
        builds.format === 'build-analyzer.builds' &&
          builds.format_version === 3 &&
          builds.game === 'genshin' &&
          builds.builds.map((build) => `${build.character} ${build.role} C${build.min_rank}`).join('|') === 'hutao main_dps C0|hutao sub_dps C2|kaedeharakazuha support C0',
        `the file: Hu Tao's two builds and Kazuha's one as variations (${builds.builds.map((build) => `${build.role} C${build.min_rank}`).join(', ')})`,
      );
      const teams = builds.builds.map((build) => build.teams.map((team) => `${team.name}: ${team.members.map((member) => `${member.key} C${member.min_rank}`).join(' ')}`).join(', '));
      check(
        teams.join('|') === 'Vaporize: hutao C0 kaedeharakazuha C2 xingqiu C6||' &&
          builds.builds[0].teams[0].members[1].alternatives.map((alternative) => alternative.key).join('|') === 'traveleranemo',
        `the main build's team in it, Kazuha's stand-in too; the other two none (${teams.join(' | ')})`,
      );
      check(!/"(id|source|variant)"\s*:/.test(readFileSync(saved, 'utf8')), 'no id, no source, no variant in it');

      await ed.setInputFiles('section.game .builds input[type=file]', saved);
      await ed.waitForSelector('section.game .preview', { timeout: 15000 });
      const summary = (await ed.locator('section.game .preview .summary').innerText()).trim();
      check(summary.includes(', with 1 team, ') && summary.endsWith('0 new, 0 updated, 3 unchanged, 0 removed.'), `the preview: ${summary}`);
      check((await ed.locator('section.game .preview .no-changes').count()) === 1, 'it says nothing changes');
      const strangers = (await ed.locator('section.game .preview .teammates-unknown').allInnerTexts()).join(' ');
      check(strangers.endsWith(': Xingqiu.'), `the teammate the catalog lacks, named - no build skipped for him: ${strangers}`);
      await shot(ed, '25-admin-builds-preview-1280-light', { fullPage: true });
      await ed.click('section.game .preview button.confirm');
      await ed.waitForSelector('section.game .preview', { state: 'detached', timeout: 15000 });
      const recorded = sql('SELECT imported_by, summary FROM build_imports ORDER BY id DESC LIMIT 1')[0];
      check(recorded?.imported_by === EDDIE.id && JSON.parse(recorded.summary).unchanged === 3, `recorded, by the editor: ${JSON.stringify(recorded)}`);
      // The teammate the catalog lacks asks for no refresh here (the check's database has the lists refreshed a moment
      // ago): the catalog is the three characters the check began with, nothing asked of its provider.
      const catalog = sql('SELECT count(*) AS n FROM game_characters')[0].n;
      check(catalog == 3, `the catalog as the check's database made it, its provider never asked (${catalog} characters)`);
      const lines = await ed.locator('section.game .imports .import-list li').allInnerTexts();
      check(lines.length === 2 && lines[0].includes('by eddie'), `the recent imports, the newest first (${lines.map((line) => line.replace(/\s+/g, ' ')).join(' | ')})`);

      // A hand edit - an editor's save, made in the database here - of Kazuha's game version, and a file that changes the
      // same part: a question, the edit standing unless the file is chosen; what the file says kept beside it, so the
      // same file asks nothing again.
      const kazuhaBuild = "(SELECT id FROM game_characters WHERE key = 'kaedeharakazuha')";
      sql(`UPDATE builds SET game_version = '9.9', edited_at = now() WHERE game_character_id = ${kazuhaBuild} RETURNING id`);
      const changed = JSON.parse(readFileSync(saved, 'utf8'));
      changed.builds.find((build) => build.character === 'kaedeharakazuha').game_version = '9.8';
      const changedFile = join(WORK, 'builds-genshin-changed.json');
      writeFileSync(changedFile, JSON.stringify(changed));
      await ed.setInputFiles('section.game .builds input[type=file]', changedFile);
      await ed.waitForSelector('section.game .preview .questions', { timeout: 15000 });
      const questions = (await ed.locator('section.game .preview .question').allInnerTexts()).map((question) => question.replace(/\s+/g, ' ').trim());
      check(
        questions.length === 1 && questions[0].startsWith('Kaedehara Kazuha · Support') && questions[0].includes('You edited its game version, and the file changes it too.'),
        `one question, about Kazuha's build: ${questions.join(' | ')}`,
      );
      const standing = (await ed.locator('section.game .preview .question [role=radio][aria-checked=true]').innerText()).trim();
      check(standing === 'Keep your edit', `the edit stands unless the file is chosen (${standing})`);
      await shot(ed, '25-admin-builds-question-1280-light', { fullPage: true });
      await ed.click('section.game .preview button.confirm');
      await ed.waitForSelector('section.game .preview', { state: 'detached', timeout: 15000 });
      const kazuha = sql(`SELECT game_version, file_build::jsonb->>'game_version' AS file FROM builds WHERE game_character_id = ${kazuhaBuild}`)[0];
      check(kazuha?.game_version === '9.9' && kazuha?.file === '9.8', `the edit kept, what the file says beside it (${JSON.stringify(kazuha)})`);
      await ed.setInputFiles('section.game .builds input[type=file]', changedFile);
      await ed.waitForSelector('section.game .preview', { timeout: 15000 });
      const hand = (await ed.locator('section.game .preview .hand').innerText()).trim();
      check((await ed.locator('section.game .preview .questions').count()) === 0 && hand === 'Kept by hand: 1 build with an edit.', `the same file again asks nothing (${hand})`);
      await ed.click('section.game .preview button.cancel');
      await ed.waitForSelector('section.game .preview', { state: 'detached', timeout: 10000 });

      // The first export, older than the edit and the changed file: it changes Kazuha's game version back - a question
      // again, on a phone too.
      for (const scheme of ['light', 'dark']) {
        await ed.setViewportSize({ width: 390, height: 860 });
        await ed.emulateMedia({ colorScheme: scheme });
        await open(ed, '/admin/builds');
        await ed.waitForSelector('section.game .builds button.export', { timeout: 15000 });
        await ed.setInputFiles('section.game .builds input[type=file]', saved);
        await ed.waitForSelector('section.game .preview .questions', { timeout: 15000 });
        check((await ed.locator('section.game .preview .question').count()) === 1, `390 px, ${scheme}: the question about Kazuha's game version`);
        const scroll = await ed.evaluate(() => document.documentElement.scrollWidth);
        check(scroll <= 390, `390 px, ${scheme}, with the preview: no sideways scroll (${scroll})`);
        await shot(ed, `25-admin-builds-390-${scheme}`, { fullPage: true });
        await ed.click('section.game .preview button.cancel');
        await ed.waitForSelector('section.game .preview', { state: 'detached', timeout: 10000 });
      }
      await ed.setViewportSize({ width: 1280, height: 900 });
      await ed.emulateMedia({ colorScheme: 'light' });
    }

    // ── The builds editor: a character's builds changed by hand, the site's note in English and Slovak ─────────
    step('The builds editor: opened from the Builds page, a note written in English and Slovak, saved - and on the member page in both languages at 390 px');
    {
      const english = 'Keep her HP below half before her skill.';
      const slovak = 'Pred jej skillom drž HP pod polovicou.';
      await open(ed, '/admin/builds');
      await ed.waitForSelector('section.game .editor .editor-table tbody tr', { timeout: 15000 });
      // Every character of the catalog in a table, each row's cells as read - the name by itself: a picture, where
      // there is none, draws the initials.
      const tableRows = () =>
        ed
          .locator('section.game .editor-table tbody tr')
          .evaluateAll((rows) =>
            rows.map((row) => [...row.querySelectorAll('td')].map((cell, i) => (i === 0 ? cell.querySelector('.editor-name') : cell)?.textContent?.replace(/\s+/g, ' ').trim()).join(' | ')),
          );
      const everyone = await tableRows();
      check(
        everyone.length === 3 && everyone.includes('Hu Tao | Pyro | Polearm | 2 | Nothing yet'),
        `Edit builds: every character of the catalog in a table, with its builds (${everyone.join(' / ')})`,
      );
      // The table drawn anew after a filter: the page draws on its next turn (zoneless).
      const rowsCome = (count) => ed.waitForFunction((n) => document.querySelectorAll('section.game .editor-table tbody tr').length === n, count, { timeout: 5000 });
      // Kazuha's game version was edited above: the one character changed here.
      await ed.click('section.game .editor-changed input');
      await rowsCome(1);
      const changedHere = await tableRows();
      check(changedHere.join(' / ') === 'Kaedehara Kazuha | Anemo | Sword | 1 | 1 edited', `only those changed here: ${changedHere.join(' / ')}`);
      await ed.click('section.game .editor-changed input');
      await rowsCome(3);
      await shot(ed, '25-admin-builds-editor-table-1280-light', { fullPage: true });

      // Hu Tao, found by typing in the table's search, and opened by her name.
      await ed.fill('section.game .editor-find input', 'hu');
      await rowsCome(1);
      const found = await tableRows();
      check(found.join(' / ') === 'Hu Tao | Pyro | Polearm | 2 | Nothing yet', `the table narrowed to the words typed (${found.join(' / ')})`);
      await ed.click('section.game .editor-table a.editor-character');
      await ed.waitForURL('**/admin/builds/genshin/hutao', { timeout: 15000 });
      await ed.waitForSelector('.build-panel [data-path="notes.build.en"] textarea', { timeout: 15000 });
      check((await ed.locator('h1').innerText()).trim() === 'Builds of Hu Tao', 'the editor: Builds of Hu Tao');
      check(
        (await ed.locator(`${FRAME_LINK}[aria-current="page"] .anotoki-side-nav__label`).innerText()).trim() === 'Builds',
        "the column's Builds marked as the page's: the editor is the Builds page's",
      );
      const tabs = await ed.locator('.build-tabs .anotoki-tabs__label').allInnerTexts();
      check(tabs.join('|') === 'Main DPS|Sub DPS · C2+', `a tab a build, by its variation (${tabs.join(', ')})`);
      // A build of the file's: its name there changeable - renamed, it becomes one of the editor's own, said so, and back.
      const changeable = await ed.locator('.build-panel [data-path="role"] select').isEnabled();
      check(changeable && (await ed.locator('.build-panel .fixed-name').count()) === 1, "a build of the file's: its role, qualifier and constellation changeable, what it means said");
      await ed.fill('.build-panel [data-path="qualifier"] input', 'Melt');
      await ed.waitForSelector('.build-panel .renamed', { timeout: 5000 });
      const renamedTab = (await ed.locator('.build-tabs [role=tab][aria-selected=true] .anotoki-tabs__badge').innerText()).trim();
      check(renamedTab === 'Renamed', `renamed: one of the editor's own, its tab says so (${renamedTab})`);
      await shot(ed, '26-build-editor-renamed-1280-light', { fullPage: true });
      await ed.click('.build-panel button.undo-rename');
      await ed.waitForSelector('.build-panel .renamed', { state: 'detached', timeout: 5000 });
      check((await ed.locator('.save-bar').count()) === 0, "back to the file's name: the file's build again, nothing to save");

      // The note: the English, and the Slovak in its block of Slovak.
      await ed.fill('.build-panel [data-path="notes.build.en"] textarea', english);
      await ed.fill('.build-panel [data-path="notes.build.sk"] textarea', slovak);
      check((await ed.locator('.build-panel [data-path="notes.build.sk"]').getAttribute('lang')) === 'sk', 'the Slovak box in a block of Slovak');
      const unsaved = (await ed.locator('.build-tabs [role=tab][aria-selected=true] .anotoki-tabs__badge').innerText()).trim();
      check(unsaved === 'Unsaved' && (await ed.locator('.save-bar button.save').isEnabled()), `the tab says Unsaved, Save is there (${unsaved})`);
      await shot(ed, '26-build-editor-1280-light', { fullPage: true });
      await ed.click('.save-bar button.save');
      await ed.waitForSelector('.save-bar', { state: 'detached', timeout: 15000 });
      const stored = sql("SELECT site_notes, edited_at FROM builds WHERE role = 'main_dps' AND game_character_id = (SELECT id FROM game_characters WHERE key = 'hutao')")[0];
      check(
        JSON.stringify(JSON.parse(stored.site_notes)) === JSON.stringify({ build: { en: english, sk: slovak } }) && stored.edited_at !== null,
        `saved: the note in both languages, the build edited (${stored.site_notes})`,
      );
      const audited = sql("SELECT actor_id, target_id FROM anotoki_audit_log WHERE action = 'builds.edited' ORDER BY id DESC LIMIT 1")[0];
      check(audited?.actor_id == EDDIE.id && audited?.target_id === 'hutao', `in the audit log, by the editor (${JSON.stringify(audited)})`);

      for (const scheme of ['light', 'dark']) {
        await ed.setViewportSize({ width: 390, height: 860 });
        await ed.emulateMedia({ colorScheme: scheme });
        await open(ed, '/admin/builds/genshin/hutao');
        await ed.waitForSelector('.build-panel [data-path="notes.build.en"] textarea', { timeout: 15000 });
        const scroll = await ed.evaluate(() => document.documentElement.scrollWidth);
        check(scroll <= 390, `the editor, 390 px, ${scheme}: no sideways scroll (${scroll})`);
        await shot(ed, `26-build-editor-390-${scheme}`, { fullPage: true });
      }
      await ed.emulateMedia({ colorScheme: 'light' });

      // On the member page: the Slovak to whom reads Slovak, the English to the rest - and for an editor the way back.
      for (const [language, note, link] of [
        ['en', english, 'Edit builds'],
        ['sk', slovak, 'Uprav buildy'],
      ]) {
        await open(ed, `/builds/genshin/hutao?lang=${language}`);
        await ed.waitForSelector('.notes .anotoki-disclosure__button', { timeout: 15000 });
        await ed.click('.notes .anotoki-disclosure__button');
        const shown = (await ed.locator('.notes-text').innerText()).trim();
        const lang = await ed.locator('.notes-text').getAttribute('lang');
        check(shown === note && lang === null, `the member page, ${language}, 390 px: the note in the page's language (${shown})`);
        check(
          (await ed.locator('a.edit-builds').innerText()).trim() === link && (await ed.locator('a.edit-builds').getAttribute('href')) === '/admin/builds/genshin/hutao',
          `the member page, ${language}: an editor's way to the editor (${link})`,
        );
        const scroll = await ed.evaluate(() => document.documentElement.scrollWidth);
        const keys = await keysShown(ed);
        check(scroll <= 390 && keys.length === 0, `the member page, ${language}, 390 px: no sideways scroll (${scroll}), no key where a word should be${keys.length ? ` (${keys.join(', ')})` : ''}`);
        await shot(ed, `27-build-note-390-${language}`, { fullPage: true });
      }
      await open(ed, '/builds/genshin/hutao?lang=en');
      await ed.setViewportSize({ width: 1280, height: 900 });
      // Somebody with no role has no such way.
      await open(tab, '/builds/genshin/hutao');
      await tab.waitForSelector('.notes .anotoki-disclosure__button', { timeout: 15000 });
      check((await tab.locator('a.edit-builds').count()) === 0, 'mona (no role): no way to the editor');

      // The parts' forms, on Kazuha's build (Hu Tao's main build is what the account pages measure further on): a
      // weapon added - a polearm, offered once every type is asked for, Kazuha wielding a sword - and refined, his set
      // made two pieces of it and two of another, a substat's priority lowered, a target added.
      await ed.setViewportSize({ width: 1280, height: 900 });
      await open(ed, '/admin/builds/genshin/kaedeharakazuha');
      await ed.waitForSelector('[data-part="weapons"] button.add-weapon', { timeout: 15000 });
      check((await ed.locator('[data-part="weapons"] .none').innerText()).trim() === 'None listed.', 'his build lists no weapon yet');
      // Each pick by the keyboard - the name typed, the match the kit's combobox makes active taken with Enter - and
      // checked taken: a click can land where the list stood a frame before (seen: a weapon row left empty at Save).
      // The kit makes the first match active as the text is typed - among the options it has then: typed again once the
      // list shows the one wanted (the flow can type before a switch it pressed, such as "every weapon type", is drawn),
      // and Enter only once it is the active one.
      const pickByName = async (field, typed, name, taken) => {
        const state = () =>
          ed.evaluate((selector) => {
            const root = document.querySelector(selector);
            return JSON.stringify({
              text: root?.querySelector('input')?.value,
              shown: [...document.querySelectorAll('[role=option]')].map((option) => option.textContent?.trim()),
              active: document.querySelector('.anotoki-combobox__option--active')?.textContent?.trim() ?? null,
              everyType: document.querySelector('[data-part="weapons"] .every-type input')?.checked ?? null,
            });
          }, field);
        await ed.fill(`${field} input`, typed);
        await ed.locator('[role=option]', { hasText: name }).first().waitFor({ timeout: 10000 });
        await ed.fill(`${field} input`, typed);
        await ed
          .locator('.anotoki-combobox__option--active', { hasText: name })
          .first()
          .waitFor({ timeout: 5000 })
          .catch(async () => {
            throw new Error(`${name}, typed "${typed}" in ${field}: never the active option - ${await state()}`);
          });
        await ed.press(`${field} input`, 'Enter');
        await ed.waitForFunction(taken, name, { timeout: 5000 }).catch(async () => {
          throw new Error(`${name}, typed "${typed}" in ${field} and taken with Enter: not taken - ${await state()}`);
        });
      };
      await ed.click('[data-part="weapons"] button.add-weapon');
      await ed.click('[data-part="weapons"] .every-type input');
      await pickByName('[data-path="weapons[0].key"]', 'homa', 'Staff of Homa', (name) => document.querySelector('[data-path="weapons[0].key"] input')?.value === name);
      await ed.selectOption('[data-path="weapons[0].refinement"] select', '1');
      await ed.locator('[data-path="sets[0].pieces[0].count"] [role=radio]').first().click();
      await ed.click('[data-part="sets"] button.add-piece');
      await pickByName('[data-path="sets[0].pieces[1].keys"]', 'shimenawa', "Shimenawa's Reminiscence", (name) =>
        [...(document.querySelectorAll('[data-part="sets"] .piece')[1]?.querySelectorAll('.anotoki-chip-list__label') ?? [])].some((chip) => chip.textContent?.trim() === name),
      );
      await ed.selectOption('[data-path="substats[2].priority"] select', '3');
      await ed.click('[data-part="stat_targets"] button.add-target');
      await ed.selectOption('[data-path="stat_targets[2].stat"] select', 'eleMas');
      await ed.fill('[data-path="stat_targets[2].min"] input', '800');
      const unsavedParts = (await ed.locator('[data-part] .unsaved').count()) + (await ed.locator('app-part-card.variation .unsaved').count());
      check(unsavedParts === 4, `the four parts changed say so, each on its card (${unsavedParts})`);
      await shot(ed, '26-build-editor-forms-1280-light', { fullPage: true });
      await ed.click('.save-bar button.save');
      await ed.waitForSelector('.save-bar', { state: 'detached', timeout: 15000 }).catch(async (error) => {
        // Not saved: what the page says instead - its bar, the problems at fields and above the cards, its messages.
        const said = await ed.evaluate(() =>
          [...document.querySelectorAll('.save-bar .save-text, .save-error, .problems, .anotoki-field__error, anotoki-toast-host .anotoki-toast-host__message')].map(
            (element) => `${element.closest('[data-path]')?.getAttribute('data-path') ?? element.className}: ${element.textContent?.replace(/\s+/g, ' ').trim()}`,
          ),
        );
        throw new Error(`his forms' save: still not saved after 15 s - ${said.join(' | ')} (${error.message.split('\n')[0]})`);
      });
      const kazuhaRow = sql("SELECT weapons, sets, substats, stat_targets FROM builds WHERE game_character_id = (SELECT id FROM game_characters WHERE key = 'kaedeharakazuha')")[0];
      const weapons = JSON.parse(kazuhaRow.weapons).map((weapon) => `${weapon.key} R${weapon.refinement}`);
      const pieces = JSON.parse(kazuhaRow.sets)[0].pieces.map((piece) => `${piece.count}pc ${piece.keys.join('+')}`);
      const priorities = JSON.parse(kazuhaRow.substats).map((stat) => `${stat.stat} ${stat.priority}`);
      const targets = JSON.parse(kazuhaRow.stat_targets).map((target) => `${target.stat} ${target.min} C${target.from_rank}`);
      check(
        weapons.join('|') === 'staffofhoma R1' &&
          pieces.join('|') === '2pc crimsonwitchofflames|2pc shimenawasreminiscence' &&
          priorities.join('|') === 'critRate_ 1|critDMG_ 1|hp_ 3' &&
          targets.join('|') === 'enerRech_ 120 C0|enerRech_ 110 C1|eleMas 800 C0',
        `saved as the forms left it (${[weapons, pieces, priorities, targets].map((list) => list.join(', ')).join(' / ')})`,
      );
      // A change undone is none: the refinement to R2 and back, a target's minimum to 130% and back (its "%" typed
      // too) - and no Save bar left standing.
      await ed.selectOption('[data-path="weapons[0].refinement"] select', '2');
      await ed.waitForSelector('.save-bar', { timeout: 5000 });
      await ed.selectOption('[data-path="weapons[0].refinement"] select', '1');
      await ed.fill('[data-path="stat_targets[0].min"] input', '130%');
      await ed.fill('[data-path="stat_targets[0].min"] input', '120%');
      await ed.waitForSelector('.save-bar', { state: 'detached', timeout: 5000 });
      check((await ed.locator('[data-part] .unsaved').count()) === 0, 'a refinement and a minimum changed and changed back ("%" typed): nothing to save');
      // A bound typed that is no number: offered to save at once, said at its field once it is left.
      await ed.fill('[data-path="stat_targets[2].max"] input', '9x');
      await ed.waitForSelector('.save-bar', { timeout: 5000 });
      const errorBefore = await ed.locator('[data-path="stat_targets[2].max"] .anotoki-field__error').count();
      await ed.press('[data-path="stat_targets[2].max"] input', 'Tab');
      const boundError = (await ed.locator('[data-path="stat_targets[2].max"] .anotoki-field__error').innerText()).trim();
      check(errorBefore === 0 && boundError.startsWith('Write a number'), `a bound that is no number: said once its field is left, never while typing (${boundError})`);
      await ed.fill('[data-path="stat_targets[2].max"] input', '');
      await ed.waitForSelector('.save-bar', { state: 'detached', timeout: 5000 });

      // A team for his build: Hu Tao beside him at C1 as the main DPS - Traveler (Anemo) to stand in for her -, and the
      // site's note on it in English and Slovak; saved, the note filed under the team's members.
      const teamNote = { en: 'Swirl her Pyro: the damage bonus is the team.', sk: 'Rozvír jej Pyro: bonus k poškodeniu je celý tím.' };
      await ed.click('[data-part="teams"] button.add-team');
      await ed.fill('[data-path="teams[0].name"] input', 'Pyro Swirl');
      await pickByName('[data-path="teams[0].members[1].key"]', 'hu', 'Hu Tao', (name) => document.querySelector('[data-path="teams[0].members[1].key"] input')?.value === name);
      await ed.selectOption('[data-path="teams[0].members[1].role"] select', 'main_dps');
      await ed.selectOption('[data-path="teams[0].members[1].min_rank"] select', '1');
      await pickByName('[data-path="teams[0].members[1].alternatives"]', 'travel', 'Traveler (Anemo)', (name) =>
        [...(document.querySelectorAll('[data-part="teams"] .member')[1]?.querySelectorAll('.anotoki-chip-list__label') ?? [])].some((chip) => chip.textContent?.trim() === name),
      );
      await ed.fill('[data-path="notes.teams[0].en"] textarea', teamNote.en);
      await ed.fill('[data-path="notes.teams[0].sk"] textarea', teamNote.sk);
      check(
        (await ed.locator('[data-part="teams"] .unsaved').count()) === 1 && (await ed.locator('app-part-card.notes .unsaved').count()) === 0,
        "a team and its note: the teams' card says unsaved, the build's notes not",
      );
      await shot(ed, '28-build-teams-1280-light', { fullPage: true });
      await ed.click('.save-bar button.save');
      await ed.waitForSelector('.save-bar', { state: 'detached', timeout: 15000 });
      const teamRow = sql("SELECT teams, site_notes FROM builds WHERE game_character_id = (SELECT id FROM game_characters WHERE key = 'kaedeharakazuha')")[0];
      const savedTeams = JSON.parse(teamRow.teams ?? '[]');
      check(
        savedTeams.length === 1 &&
          savedTeams[0].name === 'Pyro Swirl' &&
          savedTeams[0].members.map((member) => `${member.key} ${member.role} C${member.min_rank} ${member.alternatives.map((one) => one.key).join('+')}`).join('|') ===
            'kaedeharakazuha support C0 |hutao main_dps C1 traveleranemo' &&
          JSON.stringify(JSON.parse(teamRow.site_notes ?? 'null')?.teams?.['hutao kaedeharakazuha']) === JSON.stringify(teamNote),
        `the team saved as its form left it, its note under its members (${teamRow.teams}; ${teamRow.site_notes})`,
      );
      // The team taken away again - asked first, in the kit's dialog -: its note waits below for a team of the same
      // members, named by them; Discard puts it all back.
      await ed.click('[data-part="teams"] .team-head button.remove');
      await ed.click(`${DIALOG} [data-answer="yes"]`);
      await ed.waitForSelector('[data-part="teams"] .waiting-group', { timeout: 5000 });
      const waitingShown = await ed.evaluate(() => ({
        name: document.querySelector('[data-part="teams"] .waiting-group')?.getAttribute('aria-label') ?? null,
        note: [...document.querySelectorAll('[data-part="teams"] .waiting-note')].map((note) => note.textContent.trim()),
        teams: document.querySelectorAll('[data-part="teams"] .team').length,
      }));
      check(
        waitingShown.teams === 0 && waitingShown.name === 'Note waiting for Kaedehara Kazuha, Hu Tao' && waitingShown.note.join('|') === `${teamNote.en}|${teamNote.sk}`,
        `the team removed, asked first: its note waits below, named by its members (${JSON.stringify(waitingShown)})`,
      );
      await shot(ed, '28-build-teams-waiting-1280-light', { fullPage: true });
      await ed.click('.save-bar button.discard');
      await ed.click(`${DIALOG} [data-answer="yes"]`);
      await ed.waitForSelector('.save-bar', { state: 'detached', timeout: 5000 });
      check((await ed.locator('[data-part="teams"] .team').count()) === 1 && (await ed.locator('[data-part="teams"] .waiting').count()) === 0, 'Discard: the team back as saved, nothing waiting');
      for (const scheme of ['light', 'dark']) {
        await ed.setViewportSize({ width: 390, height: 860 });
        await ed.emulateMedia({ colorScheme: scheme });
        await open(ed, '/admin/builds/genshin/kaedeharakazuha');
        await ed.waitForSelector('[data-path="weapons[0].key"] input', { timeout: 15000 });
        const scroll = await ed.evaluate(() => document.documentElement.scrollWidth);
        check(scroll <= 390, `his parts' forms, 390 px, ${scheme}: no sideways scroll (${scroll})`);
        await shot(ed, `26-build-editor-forms-390-${scheme}`, { fullPage: true });
      }
      await ed.emulateMedia({ colorScheme: 'light' });
      await open(ed, '/builds/genshin/kaedeharakazuha?lang=en');
      await ed.waitForSelector('app-build-detail .weapons li', { timeout: 15000 });
      const shownWeapon = (await ed.locator('app-build-detail .weapons .w-name').first().innerText()).replace(/\s+/g, ' ').trim();
      const shownSets = (await ed.locator('app-build-detail .sets .w-text > div:first-child').first().innerText()).replace(/\s+/g, ' ').trim();
      const shownTargets = (await ed.locator('app-build-detail .target .t-stat').allInnerTexts()).map((stat) => stat.replace(/\s+/g, ' ').trim());
      check(
        shownWeapon === 'Staff of Homa R1' && shownSets === "2pc Crimson Witch of Flames + 2pc Shimenawa's Reminiscence" && shownTargets.includes('Elemental Mastery'),
        `the member page: what was saved (${shownWeapon}; ${shownSets}; ${shownTargets.join(', ')})`,
      );
      await shot(ed, '27-build-parts-390-en', { fullPage: true });

      // His team on his builds page, in both languages: its members, and its note in the page's language.
      for (const [language, words] of [
        ['en', teamNote.en],
        ['sk', teamNote.sk],
      ]) {
        await open(ed, `/builds/genshin/kaedeharakazuha?lang=${language}`);
        await ed.waitForSelector('app-build-teams .team-note .anotoki-disclosure__button', { timeout: 15000 });
        await ed.click('app-build-teams .team-note .anotoki-disclosure__button');
        const shownTeam = await ed.evaluate(() => ({
          names: [...document.querySelectorAll('app-build-teams .team-name')].map((name) => name.textContent.trim()),
          members: [...document.querySelectorAll('app-build-teams .member .member-name')].map((name) => name.textContent.trim()),
          note: document.querySelector('app-build-teams .team-note-text')?.textContent?.trim() ?? null,
          lang: document.querySelector('app-build-teams .team-note-text')?.getAttribute('lang') ?? null,
        }));
        check(
          shownTeam.names.join('|') === 'Pyro Swirl' && shownTeam.members.join('|') === 'Kaedehara Kazuha|Hu Tao' && shownTeam.note === words && shownTeam.lang === null,
          `his builds page, ${language}, 390 px: the team, and its note in the page's language (${JSON.stringify(shownTeam)})`,
        );
        const scroll = await ed.evaluate(() => document.documentElement.scrollWidth);
        const keys = await keysShown(ed);
        check(scroll <= 390 && keys.length === 0, `his builds page, ${language}, 390 px: no sideways scroll (${scroll}), no key where a word should be${keys.length ? ` (${keys.join(', ')})` : ''}`);
        await shot(ed, `28-build-team-390-${language}`, { fullPage: true });
      }
      await ed.setViewportSize({ width: 1280, height: 900 });
    }

    const admin = await newContext();
    const ad = await admin.newPage();
    await open(ad, '/?lang=en');
    await signIn(ad, ADA);
    await ad.click(PERSON_BUTTON);
    await ad.waitForSelector(`${BAR_MENU} [role=menuitem]`);
    const adminItems = (await ad.locator(`${BAR_MENU} [role=menuitem]`).allInnerTexts()).map((item) => item.replace(/\s+/g, ' ').trim());
    check(
      adminItems.some((item) => item.endsWith('Admin panel')),
      `the person's menu leads into the "Admin panel" (${adminItems.join(' | ')})`,
    );
    await ad.keyboard.press('Escape');
    await ad.setViewportSize({ width: 1280, height: 900 });
    await open(ad, '/admin/people');
    // Each page by its own words (its label): a count beside one (Feedback's badge, and what it counts for the ear)
    // is not its name.
    const pages = await ad.locator(FRAME_LABEL).allInnerTexts();
    check(pages.join('|') === ADMIN_PAGES.join('|'), `an admin's pages in the frame's column: all ten, in the family's one shape - Site settings and the admin tools too (${pages.join(', ')})`);
    const headings = await ad.locator(`${FRAME_COLUMN} nav .anotoki-side-nav__heading`).evaluateAll((all) => all.map((heading) => heading.textContent.trim()));
    check(headings.join('|') === 'Localization|System', `under their headings (${headings.join(', ')})`);
    // The panel's name as its bar said it, at the top of the column: a link to its first page.
    const brand = (await ad.locator(`${FRAME_COLUMN} a.anotoki-admin-frame__brand`).innerText()).replace(/\s+/g, ' ').trim();
    check(
      brand === 'Analyzer · admin panel' && (await ad.locator(`${FRAME_COLUMN} a.anotoki-admin-frame__brand`).getAttribute('href')) === '/admin',
      `the column's name: "${brand}", a link to /admin`,
    );
    check((await ad.locator('app-top-bar').count()) === 0, 'no top bar in the admin panel');
    check((await ad.title()) === 'People · Admin panel · Build Analyzer', `the tab's title: ${await ad.title()}`);
    await ad.waitForSelector('anotoki-people-page [data-person]', { timeout: 15000 });
    const people = await ad.locator('main').innerText();
    check(people.includes('mona') && /visitor/i.test(people), "People (the family's page) lists mona, and the visitors apart");
    check((await ad.locator('[data-person="7"] [data-delete]').count()) === 1, "mona's row: Delete their data");
    check((await ad.locator('[data-person="1"] [data-you]').count()) === 1 || people.includes('you'), 'the reader\'s own row says "you"');
    await shot(ad, '10-admin-people', { fullPage: true });
    for (const [scheme, width] of [
      ['dark', 1280],
      ['light', 390],
      ['dark', 390],
    ]) {
      await ad.setViewportSize({ width, height: 900 });
      await ad.emulateMedia({ colorScheme: scheme });
      await open(ad, '/admin/people');
      await ad.waitForSelector('anotoki-people-page [data-person]', { timeout: 15000 });
      const scroll = await ad.evaluate(() => document.documentElement.scrollWidth);
      check(scroll <= width, `People, ${width} px, ${scheme}: no sideways scroll (${scroll})`);
      await shot(ad, `10-admin-people-${width}-${scheme}`, { fullPage: true });
    }
    await ad.emulateMedia({ colorScheme: 'light' });

    step(
      `The admin panel's frame at 390, ${FRAME_PHONE_MAX}, ${FRAME_PHONE_MAX + 1} and 1280 px for the ADMIN, light and dark - a phone's row with Menu and its drawer, then the column - and an editor's at 390 and 1280 px`,
    );
    for (const scheme of ['light', 'dark']) {
      for (const width of [390, FRAME_PHONE_MAX, FRAME_PHONE_MAX + 1, 1280]) {
        await ad.setViewportSize({ width, height: 760 });
        await ad.emulateMedia({ colorScheme: scheme });
        await open(ad, '/admin/migrations');
        const phone = width <= FRAME_PHONE_MAX;
        check(
          (await ad.locator(FRAME_MENU).count()) === (phone ? 1 : 0) && (await ad.locator(FRAME_LINK).count()) === (phone ? 0 : ADMIN_PAGES.length),
          `admin panel, ${width} px, ${scheme}: ${phone ? "a phone's row with Menu" : `the column with the ${ADMIN_PAGES.length} pages`}`,
        );
        const scroll = await ad.evaluate(() => document.documentElement.scrollWidth);
        check(scroll <= width, `admin panel, ${width} px, ${scheme}: no sideways scroll (${scroll})`);
        await shot(ad, `admin-frame-${width}-${scheme}`);
        if (phone) {
          await ad.click(FRAME_MENU);
          await ad.waitForSelector(`${FRAME_DRAWER} nav .anotoki-side-nav__label`, { timeout: 10000 });
          const drawer = (await ad.locator(FRAME_DRAWER).innerText()).replace(/\s+/g, ' ');
          check(
            drawer.includes('ada') && drawer.includes('Back to Build Analyzer') && drawer.includes('Sign out') && drawer.includes('Backups'),
            `admin panel, ${width} px, ${scheme}: the drawer holds the person, Back, Sign out and the pages`,
          );
          await shot(ad, `admin-frame-${width}-${scheme}-drawer`);
          await ad.keyboard.press('Escape');
          await ad.waitForSelector(FRAME_DRAWER, { state: 'detached', timeout: 5000 });
        }
      }
    }
    await ad.emulateMedia({ colorScheme: 'light' });
    for (const width of [390, 1280]) {
      await ed.setViewportSize({ width, height: 760 });
      await open(ed, '/admin/builds');
      if (width <= FRAME_PHONE_MAX) {
        await ed.click(FRAME_MENU);
        await ed.waitForSelector(`${FRAME_DRAWER} nav .anotoki-side-nav__label`, { timeout: 10000 });
      }
      const labels = await ed.locator(width <= FRAME_PHONE_MAX ? `${FRAME_DRAWER} nav .anotoki-side-nav__label` : FRAME_LABEL).allInnerTexts();
      check(labels.join('|') === 'Builds|Translations', `admin panel, an editor, ${width} px: Builds and Translations (${labels.join(', ')})`);
      await shot(ed, `admin-frame-editor-${width}`);
      if (width <= FRAME_PHONE_MAX) {
        await ed.keyboard.press('Escape');
        await ed.waitForSelector(FRAME_DRAWER, { state: 'detached', timeout: 5000 });
      }
    }

    // ── The site's words: the Translations and Languages pages (anotoki-lib's, drawn with its kit) ──────
    step("Translations (EDITOR and ADMIN) and Languages (ADMIN): the anotoki library's pages in the admin panel - a string reworded and read back, light and dark, 1280 and 390 px");
    {
      const box = (key, language = 'Slovak') => ad.locator(`textarea[aria-label="${language}: ${key}"]`);
      const bundle = async (code) => (await (await fetch(`${SITE}/api/translations/${code}`)).json()).values;
      await ad.setViewportSize({ width: 1280, height: 900 });
      await open(ad, '/admin/translations');
      await ad.waitForSelector('anotoki-translations-page textarea', { timeout: 15000 });
      check((await ad.locator('anotoki-translations-page h1').innerText()).trim() === 'Translations', "the page: Translations, in the admin panel's frame");
      check((await ad.title()) === 'Translations · Admin panel · Build Analyzer', `its title: ${await ad.title()}`);
      check((await ad.evaluate(() => document.documentElement.lang)) === 'en', '<html lang="en">');
      const coverage = (await ad.locator('.anotoki-translations-page__languages').innerText()).replace(/\s+/g, ' ').trim();
      check(/English/.test(coverage) && /Slovak/.test(coverage), `each language's coverage: ${coverage.slice(0, 140)}`);
      check((await box('anotoki.language.button').count()) === 1, "the library's words are in the grid too - the language button's name among them (moved there by 006)");
      check((await ad.locator('[data-languages-link]').count()) === 1, 'an admin is led on to Languages');
      await shot(ad, '17-admin-translations-1280-light');

      // A search, a string reworded, saved - and the Slovak pages read it at once.
      await ad.getByLabel('Find a key or a text').fill('topbar.import');
      await ad.waitForTimeout(400);
      const before = await box('topbar.import').inputValue();
      await box('topbar.import').fill('Importovať');
      await ad.click('[data-save]');
      await ad.waitForFunction(() => /saved/i.test(document.querySelector('[data-save-state]')?.textContent ?? ''), null, { timeout: 15000 });
      check((await bundle('sk'))['topbar.import'] === 'Importovať', 'saved: the Slovak bundle has the new words');
      const written = sql("SELECT value, updated_by FROM translations WHERE key_name = 'topbar.import' AND language_code = 'sk'")[0];
      check(written?.value === 'Importovať' && Number(written?.updated_by) === 1, `by whoever saved it: the token's person (${JSON.stringify(written)})`);
      await shot(ad, '17b-admin-translations-saved-1280-light');
      await box('topbar.import').fill(before);
      await ad.click('[data-save]');
      let back = null;
      for (let i = 0; i < 50 && back !== before; i++) {
        await ad.waitForTimeout(200);
        back = (await bundle('sk'))['topbar.import'];
      }
      check(back === before, `and back as it was (${back})`);
      await ad.getByLabel('Find a key or a text').fill('');

      for (const [scheme, width] of [
        ['dark', 1280],
        ['light', 390],
        ['dark', 390],
      ]) {
        await ad.setViewportSize({ width, height: 900 });
        await ad.emulateMedia({ colorScheme: scheme });
        await open(ad, '/admin/translations');
        await ad.waitForSelector('anotoki-translations-page textarea', { timeout: 15000 });
        const scroll = await ad.evaluate(() => document.documentElement.scrollWidth);
        check(scroll <= width, `Translations, ${width} px, ${scheme}: no sideways scroll (${scroll})`);
        if (scheme === 'dark') {
          check(await darkSurface(ad, 'body'), `Translations, ${width} px: in the site's dark colours`);
        }
        await shot(ad, `17-admin-translations-${width}-${scheme}`);
      }

      for (const [scheme, width] of [
        ['light', 1280],
        ['dark', 1280],
        ['light', 390],
        ['dark', 390],
      ]) {
        await ad.setViewportSize({ width, height: 900 });
        await ad.emulateMedia({ colorScheme: scheme });
        await open(ad, '/admin/languages');
        await ad.waitForSelector('anotoki-languages-page [data-language]', { timeout: 15000 });
        if (width === 1280 && scheme === 'light') {
          const rows = await ad
            .locator('[data-language]')
            .evaluateAll((list) => list.map((row) => `${row.dataset.language}: ${[...row.querySelectorAll('anotoki-badge')].map((badge) => badge.textContent.trim()).join(', ')}`));
          check(rows.join(' | ') === 'en: en, Fallback | sk: sk, Released with the site', `the languages: ${rows.join(' | ')}`);
          check((await ad.title()) === 'Languages · Admin panel · Build Analyzer', `its title: ${await ad.title()}`);
        }
        const scroll = await ad.evaluate(() => document.documentElement.scrollWidth);
        check(scroll <= width, `Languages, ${width} px, ${scheme}: no sideways scroll (${scroll})`);
        await shot(ad, `18-admin-languages-${width}-${scheme}`, { fullPage: true });
      }
      await ad.emulateMedia({ colorScheme: 'light' });

      // An editor rewords the strings too - the languages are an admin's.
      await open(ed, '/admin/translations');
      await ed.waitForSelector('anotoki-translations-page textarea', { timeout: 15000 });
      check((await ed.locator('[data-languages-link]').count()) === 0, 'an editor has the grid, and no way on to Languages');

      // A page in Slovak, dark, at 1280 and 390 px: the reader's words, the language button named by the library's.
      for (const width of [1280, 390]) {
        await tab.setViewportSize({ width, height: 800 });
        await tab.emulateMedia({ colorScheme: 'dark' });
        await open(tab, '/builds/genshin?lang=sk');
        const shown = await keysShown(tab);
        check(
          shown.length === 0 && (await tab.evaluate(() => document.documentElement.lang)) === 'sk',
          `/builds/genshin, ${width} px, dark: Slovak, no keys${shown.length ? ` - ${shown.join(', ')}` : ''}`,
        );
        await shot(tab, `sk-${width}-dark-builds`, { fullPage: true });
      }
      await tab.emulateMedia({ colorScheme: 'light' });
    }

    // ── The family's admin tools ───────────────────────────────────────────────────────────────────────
    step(
      "The anotoki family's admin tools (ADMIN): the error log, the audit log, feedback with its count in the frame's column, backups - light and dark, 1280 and 390 px; an editor has none of them",
    );
    {
      // Something worth seeing in the error log: a path nobody has (404).
      await fetch(`${SITE}/api/nothing-here`);
      const wide = 1280;
      await ad.setViewportSize({ width: wide, height: 900 });
      await ad.emulateMedia({ colorScheme: 'light' });
      await open(ad, '/admin/builds');
      // And an error a page meets, thrown outside Angular's own code (window's error event): the family's reporter
      // (@anotoki/lib/error-report) sends it scrubbed, through the app's HttpClient - so with ada's token - and the
      // error log writes it as the browser's, by her account.
      const reported = ad.waitForResponse((answer) => answer.url() === `${SITE}/api/client-errors` && answer.request().method() === 'POST', { timeout: 15000 });
      await ad.evaluate(
        (message) =>
          setTimeout(() => {
            throw new Error(message);
          }, 0),
        THROWN,
      );
      const report = await reported;
      check(report.status() === 204 && /^Bearer \S+$/.test((await report.request().allHeaders()).authorization ?? ''), `the page's error reported, with ada's token (${report.status()})`);
      const thrown = pageErrors().filter((line) => line.message === THROWN_SCRUBBED);
      check(
        thrown.length === 1 && thrown[0].user_id === 1 && thrown[0].path === '/admin/builds' && !JSON.stringify(pageErrors()).includes('@example.test'),
        `the error log has it as the browser's - scrubbed, by ada, on its page (${JSON.stringify(pageErrors().map((line) => [line.message, line.user_id, line.path]))})`,
      );
      await ad.waitForSelector(`${FRAME_COLUMN} a[href="/admin/feedback"] .anotoki-side-nav__badge`, { timeout: 15000 });
      const count = (await ad.locator(`${FRAME_COLUMN} a[href="/admin/feedback"] .anotoki-side-nav__badge`).innerText()).trim();
      // The family's side navigation (anotoki-lib 0.10.0's admin frame): the number seen beside Feedback, and what it
      // counts heard after the name - "Feedback, 1 new", never "Feedback 1".
      const heard = (await ad.locator(`${FRAME_COLUMN} a[href="/admin/feedback"] .anotoki-visually-hidden`).innerText()).replace(/\s+/g, ' ').trim();
      check(count === '1' && heard === ', 1 new', `the new feedback counted beside Feedback (seen ${count}, heard "Feedback${heard}")`);
      await shot(ad, '19-admin-frame-feedback-count', { clip: { x: 0, y: 0, width: 240, height: 760 } });

      const tools = [
        ['/admin/errors', 'anotoki-error-log-page', 'Error log'],
        ['/admin/audit', 'anotoki-audit-log-page', 'Audit log'],
        ['/admin/feedback', 'anotoki-feedback-page', 'Feedback'],
        ['/admin/backups', 'anotoki-backups-page', 'Backups'],
      ];
      for (const [path, element, title] of tools) {
        for (const [scheme, width] of [
          ['light', 1280],
          ['dark', 1280],
          ['light', 390],
          ['dark', 390],
        ]) {
          await ad.setViewportSize({ width, height: 900 });
          await ad.emulateMedia({ colorScheme: scheme });
          await open(ad, path);
          await ad.waitForSelector(`${element} h1`, { timeout: 15000 });
          await ad.waitForLoadState('networkidle');
          if (scheme === 'light' && width === 1280) {
            check((await ad.title()) === `${title} · Admin panel · Build Analyzer` && (await ad.evaluate(() => document.documentElement.lang)) === 'en', `${path}: "${await ad.title()}", in English`);
          }
          const scroll = await ad.evaluate(() => document.documentElement.scrollWidth);
          check(scroll <= width, `${path}, ${width} px, ${scheme}: no sideways scroll (${scroll})`);
          if (scheme === 'dark') {
            check(await darkSurface(ad, 'body'), `${path}, ${width} px: in the site's dark colours`);
          }
          await shot(ad, `19-admin${path.replace(/\//g, '-')}-${width}-${scheme}`, { fullPage: true });
        }
      }
      await ad.setViewportSize({ width: 1280, height: 900 });
      await ad.emulateMedia({ colorScheme: 'light' });

      await open(ad, '/admin/errors');
      await ad.waitForSelector('anotoki-error-log-page h1');
      await ad.waitForLoadState('networkidle');
      check((await ad.locator('anotoki-error-log-page').innerText()).includes('/api/nothing-here'), 'the error log: the 404 a moment ago, by its path');
      check((await ad.locator('anotoki-error-log-page').innerText()).includes(THROWN_SCRUBBED), "the error log: the page's error, scrubbed");
      await open(ad, '/admin/audit');
      await ad.waitForSelector('anotoki-audit-log-page h1');
      await ad.waitForLoadState('networkidle');
      const audit = await ad.locator('anotoki-audit-log-page').innerText();
      check(audit.includes('translations.saved') && audit.includes('ada'), 'the audit log: the string ada saved on the Translations page, by her name');

      // Feedback: the visitor's message; opened, it is read - and the count beside Feedback goes.
      await open(ad, '/admin/feedback');
      await ad.waitForSelector('anotoki-feedback-page h1');
      await ad.waitForLoadState('networkidle');
      check((await ad.locator('anotoki-feedback-page').innerText()).includes('Import sa zastaví pri druhom kroku.'), "the visitor's message is listed");
      await ad.getByText('Import sa zastaví pri druhom kroku.').first().click();
      await ad.waitForSelector('[data-viewer]', { timeout: 10000 });
      await shot(ad, '19-admin-feedback-opened');
      await ad.keyboard.press('Escape');
      await ad.setViewportSize({ width: wide, height: 900 });
      await ad.waitForFunction(
        () => document.querySelector('app-admin-root a[href="/admin/feedback"]') !== null && document.querySelector('app-admin-root a[href="/admin/feedback"] .anotoki-side-nav__badge') === null,
        null,
        { timeout: 10000 },
      );
      check(sql("SELECT status FROM anotoki_feedback WHERE message = 'Import sa zastaví pri druhom kroku.'")[0]?.status === 'read', 'opened: read, and no count beside Feedback any more');
      await ad.setViewportSize({ width: 1280, height: 900 });

      // Backups: Back up now asks first; a sign-in of more than ten minutes ago is no proof - Sign in again, and back.
      await open(ad, '/admin/backups');
      await ad.waitForSelector('anotoki-backups-page [data-create]:not([disabled])', { timeout: 15000 });
      const made = () => sql("SELECT count(*) AS n FROM anotoki_audit_log WHERE action = 'backup.created' AND actor_id = 1")[0].n;
      const before = Number(made());
      const backUp = async () => {
        await ad.click('[data-create]');
        await ad.waitForSelector('[data-guarded] [data-guarded-confirm]', { timeout: 10000 });
        await ad.click('[data-guarded] [data-guarded-confirm]');
        await ad.waitForSelector(':is([data-outcome], [data-sign-in-needed])', { timeout: 30000 });
      };
      await backUp();
      if ((await ad.locator('[data-sign-in-needed]').count()) > 0) {
        check(true, 'a sign-in older than ten minutes: "Sign in again first"');
        await shot(ad, '19-admin-backups-sign-in-again');
        await iamAs(ADA);
        await ad.click('[data-sign-in]');
        await ad.waitForURL((url) => url.href.startsWith(SITE) && url.pathname === '/admin/backups', { timeout: 20000 });
        await ad.waitForSelector('anotoki-backups-page [data-create]:not([disabled])', { timeout: 15000 });
        await backUp();
      }
      await ad.waitForLoadState('networkidle');
      check(Number(made()) === before + 1, 'Back up now: a backup made by the ADMIN, in the audit log');
      check((await ad.locator('[data-backups] [data-backup]').count()) >= 1, 'listed on the page');
      // Its maker by name (AdminContext's names: the site's copy of the IAM's), and sealed - restorable here. The
      // list is read again after the outcome shows, so it is waited for: the day's own backup, made by itself when
      // the check began, stands first until then.
      await ad.waitForFunction(() => (document.querySelector('[data-backups] [data-backup]')?.textContent ?? '').includes('ada'), null, { timeout: 10000 }).catch(() => {});
      check(
        (await ad.locator('[data-backups] [data-backup]').first().innerText()).includes('ada'),
        `listed with its maker's name (${(await ad.locator('[data-backups] [data-backup]').first().innerText()).replace(/\s+/g, ' ')})`,
      );
      await shot(ad, '19-admin-backups-made', { fullPage: true });

      // An editor has none of them: sent to Builds, and the server refuses them.
      for (const path of ['/admin/errors', '/admin/audit', '/admin/feedback', '/admin/backups']) {
        await open(ed, path);
        check(new URL(ed.url()).pathname === '/admin/builds', `${path} sends an editor to Builds`);
      }
      const edRefused = await ed.evaluate(async () => {
        const token = JSON.parse(localStorage.getItem('anotoki-auth:build-analyzer') ?? '{}').access_token;
        const answers = await Promise.all(
          ['/api/admin/errors', '/api/admin/audit', '/api/admin/feedback', '/api/admin/backups'].map((path) => fetch(path, { headers: { Authorization: `Bearer ${token}` } })),
        );
        return Promise.all(answers.map(async (answer) => `${answer.status} ${(await answer.json()).code}`));
      });
      check(
        edRefused.every((answer) => answer === '403 admin_only'),
        `the server refuses an editor every one of them (${edRefused.join(', ')})`,
      );
    }

    // ── The site settings (anotoki-lib 0.11.0) ─────────────────────────────────────────────────────────
    step("Site settings (ADMIN): the family's page in the admin panel - its three parts, at 1280 px light and 390 px dark, under System in the column and in the phone's menu");
    {
      /** The settings' own API with ada's token (what the page writes is pressed on the page; a row of Page access, and switching all off at the end, go straight). */
      const adminApi = (method, path, body = null) =>
        ad.evaluate(
          async ([method, path, body]) => {
            const stored = JSON.parse(localStorage.getItem('anotoki-auth:build-analyzer') ?? '{}');
            const token = stored.access_token ?? stored.tokens?.access_token;
            const answer = await fetch(path, {
              method,
              headers: { Authorization: `Bearer ${token}`, ...(body ? { 'Content-Type': 'application/json' } : {}) },
              body: body ? JSON.stringify(body) : undefined,
            });
            return { status: answer.status, body: await answer.json().catch(() => null) };
          },
          [method, path, body],
        );
      /** The page's cards, read once they are drawn. */
      const settingsPage = async () => {
        await open(ad, '/admin/site-settings');
        await ad.waitForSelector('anotoki-site-settings-page [data-card]', { timeout: 15000 });
        await ad.waitForLoadState('networkidle');
      };
      /** A part's dialog opened from its card: its switch on, a message in English and Slovak - shot, then saved. */
      const switchOn = async (part, words, name) => {
        const dialog = `anotoki-dialog[data-switches="${part}"]`;
        await ad.click(`anotoki-site-settings-page [data-card="${part}"]`);
        await ad.waitForSelector(`${dialog} dialog[open]`, { timeout: 10000 });
        // The kit's switch: its input is see-through under the track, so the press goes to its label, as a person's does.
        await ad.click(`${dialog} anotoki-switch[data-enabled] label`);
        check(await ad.isChecked(`${dialog} anotoki-switch[data-enabled] input[role=switch]`), `${part}: switched on`);
        await ad.fill(`${dialog} anotoki-textarea[data-language="en"] textarea`, words.en);
        await ad.fill(`${dialog} anotoki-textarea[data-language="sk"] textarea`, words.sk);
        await shot(ad, name);
        await ad.click(`${dialog} [data-save]`);
        await ad.waitForSelector(dialog, { state: 'detached', timeout: 15000 });
      };

      await ad.setViewportSize({ width: 1280, height: 900 });
      await ad.emulateMedia({ colorScheme: 'light' });
      await settingsPage();
      const cards = await ad.locator('anotoki-site-settings-page [data-card]').evaluateAll((all) => all.map((card) => card.getAttribute('data-card')));
      check(cards.join('|') === 'getting_in|announcement|page_access', `its three parts, a card each (${cards.join(', ')})`);
      check((await ad.title()) === 'Site settings · Admin panel · Build Analyzer', `its title: ${await ad.title()}`);
      const marked = (await ad.locator(`${FRAME_COLUMN} nav a[aria-current="page"] .anotoki-side-nav__label`).innerText()).trim();
      check(marked === 'Site settings', `the column marks it (${marked}), the first of System`);
      await shot(ad, '20-admin-site-settings-1280-light', { fullPage: true });
      await ad.setViewportSize({ width: 390, height: 844 });
      await ad.emulateMedia({ colorScheme: 'dark' });
      await settingsPage();
      const narrow = await ad.evaluate(() => document.documentElement.scrollWidth);
      check(narrow <= 390 && (await darkSurface(ad, 'body')), `390 px, dark: no sideways scroll (${narrow}), in the site's dark colours`);
      await shot(ad, '20-admin-site-settings-390-dark', { fullPage: true });
      await ad.click(FRAME_MENU);
      await ad.waitForSelector(`${FRAME_DRAWER} nav .anotoki-side-nav__label`, { timeout: 10000 });
      const drawerPages = await ad.locator(`${FRAME_DRAWER} nav .anotoki-side-nav__label`).allInnerTexts();
      const drawerHeadings = await ad.locator(`${FRAME_DRAWER} nav .anotoki-side-nav__heading`).evaluateAll((all) => all.map((heading) => heading.textContent.trim()));
      check(
        drawerPages.join('|') === ADMIN_PAGES.join('|') && drawerHeadings.join('|') === 'Localization|System',
        `the phone's menu: the same pages under the same headings (${drawerHeadings.join(', ')}: ${drawerPages.join(', ')})`,
      );
      await shot(ad, '20-admin-site-settings-390-dark-menu');
      await ad.keyboard.press('Escape');
      await ad.waitForSelector(FRAME_DRAWER, { state: 'detached', timeout: 5000 });

      try {
        step(
          "Site settings: an announcement written in English and Slovak and saved on the page - above everybody's pages, at 1280 px light in English and 390 px dark in Slovak; hidden, it stays hidden in that browser",
        );
        await ad.setViewportSize({ width: 1280, height: 900 });
        await ad.emulateMedia({ colorScheme: 'light' });
        await settingsPage();
        await switchOn('announcement', ANNOUNCED, '20-admin-site-settings-announcement');
        const told = await (await fetch(`${SITE}/api/site-settings`)).json();
        check(told.announcement?.message?.en === ANNOUNCED.en && told.announcement?.message?.sk === ANNOUNCED.sk, `saved: every page is told it (${JSON.stringify(told.announcement)})`);
        check(Number(sql("SELECT count(*) AS n FROM anotoki_audit_log WHERE action = 'site_settings.changed' AND actor_id = 1")[0].n) >= 1, 'in the audit log, by ada');
        for (const [language, scheme, width] of [
          ['en', 'light', 1280],
          ['sk', 'dark', 390],
        ]) {
          const context = await newContext({ width, scheme, height: width < 600 ? 844 : 900 });
          const page = await context.newPage();
          await open(page, `/builds/genshin?lang=${language}`);
          await page.waitForSelector('anotoki-announcement [data-announcement]', { timeout: 15000 });
          const bar = await page.evaluate(() => ({
            text: document.querySelector('anotoki-announcement .anotoki-announcement__message')?.textContent?.trim() ?? null,
            above:
              document.querySelector('anotoki-announcement [data-announcement]').getBoundingClientRect().bottom <=
              document.querySelector('app-top-bar .anotoki-top-bar__topbar').getBoundingClientRect().top + 1,
            scroll: document.documentElement.scrollWidth,
          }));
          const label = `${language}, ${scheme}, ${width} px`;
          check(
            bar.text === ANNOUNCED[language] && bar.above && bar.scroll <= width,
            `${label}: the announcement above the bar, in the reader's language, no sideways scroll (${JSON.stringify(bar)})`,
          );
          const keys = await keysShown(page);
          check(keys.length === 0, `${label}: no key where a word should be${keys.length ? ` - ${keys.join(', ')}` : ''}`);
          if (scheme === 'dark') {
            check(await darkSurface(page, 'body'), `${label}: the dark theme`);
          }
          await shot(page, `21-announcement-${language}-${width}-${scheme}`);
          if (language === 'sk') {
            await page.click('anotoki-announcement [data-hide]');
            await page.waitForSelector('anotoki-announcement [data-announcement]', { state: 'detached', timeout: 5000 });
            await open(page, `/?lang=${language}`);
            check((await page.locator('anotoki-announcement [data-announcement]').count()) === 0, `${label}: hidden, it stays hidden on the next page load in this browser`);
          }
          await context.close();
        }
        await adminApi('PUT', '/api/admin/site-settings', { settings: { announcement_enabled: false } });

        step(
          'Page access: the import kept for people signed in - a visitor at /import reads the dashboard, the bar offers no Import, its API refuses them (423 page_locked) and the sitemap leaves it out; the ADMIN reads it with a line saying so',
        );
        const added = await adminApi('POST', '/api/admin/site-settings/pages', { path: '/import', visibility: 'USER', blocked: false, api_paths: ['/api/imports'] });
        const row = added.body?.pages?.find((page) => page.path === '/import');
        check(added.status === 201 && row !== undefined, `a row: /import for people signed in, refusing /api/imports with it (${added.status})`);
        try {
          const context = await newContext();
          const page = await context.newPage();
          await open(page, '/import?lang=en');
          check(
            new URL(page.url()).pathname === '/' && (await page.locator('app-visitor-hero').count()) === 1,
            `a visitor at /import: the dashboard, as for an address nobody has (${new URL(page.url()).pathname})`,
          );
          const links = await page.locator(BAR_LINK).allInnerTexts();
          check(links.join('|') === 'Dashboard|Builds', `the bar offers no Import (${links.join(', ')})`);
          const locked = await fetch(`${SITE}/api/imports/file`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ content: GOOD, file_name: 'good.json' }) });
          check(locked.status === 423 && (await locked.json()).code === 'page_locked', `its API refuses a visitor: ${locked.status} page_locked`);
          const hiddenMap = await (await fetch(`${SITE}/sitemap.xml`)).text();
          check(!hiddenMap.includes('/import</loc>') && hiddenMap.includes('/builds/genshin</loc>'), 'the sitemap leaves /import out, and keeps the builds');
          await context.close();
          await ad.setViewportSize({ width: 1280, height: 900 });
          await open(ad, '/import?lang=en');
          check(
            new URL(ad.url()).pathname === '/import' && (await ad.locator('anotoki-page-notice [data-notice="limited"]').count()) === 1,
            'the ADMIN reads /import, with the line above it saying whom it is kept for',
          );
          await shot(ad, '21b-page-access-admin-notice');
        } finally {
          if (row) {
            await adminApi('DELETE', `/api/admin/site-settings/pages/${row.id}`);
          }
        }

        step(
          "Getting in: the site closed for maintenance on the page, with a message in English and Slovak - the closed sign at 1280 px light in English and 390 px dark in Slovak, the API's 503; the ADMIN uses the site as it is; signed out, an administrator signs in at /staff",
        );
        await settingsPage();
        await switchOn('getting_in', CLOSED, '22-admin-site-settings-maintenance');
        const closedStatus = await (await fetch(`${SITE}/api/site-status`)).json();
        check(
          closedStatus.state === 'maintenance' && closedStatus.maintenance_message?.sk === CLOSED.sk,
          `the site says it is closed, with the administrators' words (${JSON.stringify(closedStatus)})`,
        );
        const closed = await fetch(`${SITE}/api/builds/genshin`);
        check(
          closed.status === 503 && (await closed.json()).code === 'maintenance' && closed.headers.get('retry-after') === '300',
          'every other path answers anybody 503 maintenance, Retry-After 300',
        );
        check(
          (await fetch(`${SITE}/api/meta`)).status === 200 && (await fetch(`${SITE}/api/site-settings`)).status === 200,
          '/api/meta and the settings still answer: the pages start, and sign-in works',
        );
        for (const [language, scheme, width, path] of [
          ['en', 'light', 1280, '/'],
          ['sk', 'dark', 390, '/builds/genshin'],
        ]) {
          const context = await newContext({ width, scheme, height: width < 600 ? 844 : 900 });
          const page = await context.newPage();
          await openGated(page, `${path}?lang=${language}`);
          const sign = await page.evaluate(() => ({
            title: document.querySelector('anotoki-maintenance-page h1')?.textContent?.trim() ?? null,
            message: document.querySelector('anotoki-maintenance-page [data-maintenance-message]')?.textContent?.trim() ?? null,
            door: document.querySelectorAll('anotoki-maintenance-page [data-staff-sign-in]').length,
            bar: document.querySelectorAll('app-top-bar').length,
            scroll: document.documentElement.scrollWidth,
          }));
          const label = `${language}, ${scheme}, ${width} px`;
          check(
            sign.title === (language === 'sk' ? 'Hneď sme späť' : 'Back shortly') && sign.message === CLOSED[language] && sign.door === 0 && sign.bar === 0 && sign.scroll <= width,
            `${label}: the closed sign - its heading, the administrators' words in the reader's language, no door, no page behind it (${JSON.stringify(sign)})`,
          );
          if (scheme === 'dark') {
            check(await darkSurface(page, 'body'), `${label}: the dark theme`);
          }
          await shot(page, `22-maintenance-${language}-${width}-${scheme}`, { fullPage: true });
          await context.close();
        }
        await open(ad, '/builds/genshin?lang=en');
        check(
          (await ad.locator('anotoki-site-status').count()) === 0 && (await ad.locator('anotoki-page-notice [data-notice="maintenance"]').count()) === 1,
          'the ADMIN uses the site as it is, a line above the page saying it is closed',
        );
        await shot(ad, '22b-maintenance-admin-page');
        await open(ad, '/admin/builds');
        check((await ad.locator('app-admin-root anotoki-page-notice [data-notice="maintenance"]').count()) === 1, 'and in the admin panel, above its pages');
        await shot(ad, '22c-maintenance-admin-panel');
        const staff = await newContext();
        const door = await staff.newPage();
        await openGated(door, '/staff?lang=en');
        const lead = await door.evaluate(() => document.querySelector('anotoki-maintenance-page [data-staff-sign-in]')?.textContent?.trim() ?? null);
        check(new URL(door.url()).pathname === '/staff' && lead === 'Sign in', `/staff: the closed sign with Sign in for an administrator (${new URL(door.url()).pathname}, ${lead})`);
        await shot(door, '22d-maintenance-staff');
        await iamAs(ADA);
        await door.click('anotoki-maintenance-page [data-staff-sign-in]');
        await door.waitForURL((url) => url.href.startsWith(SITE) && !url.pathname.startsWith('/auth/callback'), { timeout: 15000 });
        await door.waitForSelector(PERSON_BUTTON, { timeout: 15000 });
        await door.waitForLoadState('networkidle');
        check(
          new URL(door.url()).pathname === '/' && (await door.locator('anotoki-page-notice [data-notice="maintenance"]').count()) === 1,
          `signed in there: the ADMIN on the dashboard, the site as it is (${new URL(door.url()).pathname})`,
        );
        await staff.close();
      } finally {
        const off = await adminApi('PUT', '/api/admin/site-settings', { settings: { maintenance_mode: false, announcement_enabled: false } });
        check(off.status === 200 && (await (await fetch(`${SITE}/api/site-status`)).json()).state === 'ready', `everything switched off again: the site open (${off.status})`);
      }

      step("robots.txt and sitemap.xml, answered by the site's PHP (anotoki-lib's Robots): what robots leave alone, and the public pages with every character's builds");
      const robotsAnswer = await fetch(`${SITE}/robots.txt`);
      const robotsText = await robotsAnswer.text();
      console.log(`  ---- /robots.txt (${robotsAnswer.status}, ${robotsAnswer.headers.get('content-type')})\n${robotsText.replace(/^/gm, '  | ')}`);
      check(
        robotsAnswer.status === 200 &&
          ['/admin', '/api', '/auth', '/accounts', '/sign-in', '/staff'].every((path) => robotsText.includes(`\nDisallow: ${path}\n`)) &&
          robotsText.trimEnd().endsWith('Sitemap: http://localhost:4600/sitemap.xml'),
        "robots.txt: the admin panel, the API, the sign-in's way back, a reader's accounts, the failed sign-in's screen and /staff left alone; the Sitemap line at the site's own address (a checkout's)",
      );
      const sitemapAnswer = await fetch(`${SITE}/sitemap.xml`);
      const sitemapText = await sitemapAnswer.text();
      console.log(`  ---- /sitemap.xml (${sitemapAnswer.status}, ${sitemapAnswer.headers.get('content-type')})\n${sitemapText.replace(/^/gm, '  | ')}`);
      const locs = [...sitemapText.matchAll(/<loc>([^<]+)<\/loc>/g)].map((match) => match[1].replace('http://localhost:4600', ''));
      check(
        sitemapAnswer.status === 200 &&
          locs.join('|') === '/terms|/privacy|/contact|/about|/support|/|/import|/builds/genshin|/builds/genshin/hutao|/builds/genshin/kaedeharakazuha' &&
          (sitemapText.match(/<lastmod>/g) ?? []).length === 2,
        `sitemap.xml: the legal pages, About, Support, the public pages, each character's builds with the day they changed (${locs.join(', ')})`,
      );
    }

    // ── The Migrations page, and the site while an update waits ────────────────────────────────────────
    step("Migrations (ADMIN): the family's page - every file applied, nothing waiting, a file's SQL");
    await ad.setViewportSize({ width: 1280, height: 900 });
    await open(ad, '/admin/migrations');
    await ad.waitForSelector('anotoki-migrations-page [data-applied]');
    check((await ad.locator('h1').innerText()) === 'Migrations', 'the page: Migrations');
    check((await ad.title()) === 'Migrations · Admin panel · Build Analyzer', `its title: ${await ad.title()}`);
    check((await ad.locator('[data-pending]').innerText()).includes('Nothing pending - the database is up to date with the files.'), 'nothing pending on a database the migrations built');
    // Four sets, in the order they run - the library's admin tools', the site's own, the library's translations and
    // people - each file named as its set's records name it: the site's by its name, the library's with its set.
    const appliedFiles = (await ad.locator('[data-applied] .anotoki-migrations-page__files code').allInnerTexts()).map((name) => name.trim());
    const sqlFiles = (folder) =>
      readdirSync(folder)
        .filter((file) => file.endsWith('.sql'))
        .sort();
    const library = (set, folder) => sqlFiles(join(backend, 'vendor/anotoki/lib/backend/migrations', folder)).map((file) => `${set}/${file}`);
    const migrationFiles = [...library('anotoki_admin', 'admin'), ...sqlFiles(join(backend, 'migrations')), ...library('anotoki_translations', 'translations'), ...library('anotoki_people', 'people')];
    check(
      appliedFiles.join('|') === migrationFiles.join('|'),
      `every file applied, in order - the admin tools' first, the translations' and the people's after the site's: ${appliedFiles.join(', ')}`,
    );
    check(await ad.locator('[data-apply]').isDisabled(), 'Apply pending cannot be pressed with nothing pending');
    // The site's 005 by its whole name: the library's translations set has a 005 of its own since 0.6.0.
    await ad.locator('[data-applied]').getByRole('button', { name: 'View 005_retire_not_set_up_page.sql', exact: true }).click();
    await ad.waitForSelector('anotoki-dialog[data-sql] pre');
    check((await ad.locator('anotoki-dialog[data-sql] pre').innerText()).includes('DELETE FROM translation_keys'), "View shows a file's SQL (005)");
    await shot(ad, '11-admin-migrations-file');
    await ad.click('anotoki-dialog[data-sql] [data-close]');
    await shot(ad, '11-admin-migrations-current', { fullPage: true });

    // 004 as if it had just been uploaded: its row forgotten (its schema stays, so applying it again fails -
    // which is how the page's "Stopped at" is seen without a broken file on disk). From now on the site is
    // closed to everybody but its ADMIN on the Migrations page.
    const forgot = sql("DELETE FROM migrations WHERE filename = '004_visitors_and_family_bar.sql' RETURNING filename");
    const guests = await newContext();
    const guest = await guests.newPage();
    try {
      check(forgot.length === 1, '004 made pending for the check');
      const status = await (await fetch(`${SITE}/api/site-status`)).json();
      check(status.state === 'update_pending' && status.installed === false, `the site says an update waits (${JSON.stringify(status)})`);
      const closed = await fetch(`${SITE}/api/builds/genshin`);
      check(closed.status === 503 && (await closed.json()).code === 'update_pending' && closed.headers.get('retry-after') === '60', 'every other path answers 503 update_pending, Retry-After 60');

      step('An update waits: a visitor and a member read that the site is being updated - in their language, no error, nothing to press but Sign in');
      let apiPaths = apiAsked(guest);
      await openGated(guest, '/?lang=en');
      let gate = await gatePage(guest);
      check(gate.title === 'The site is being updated' && gate.text.includes('It will be back in a few minutes. This page reloads by itself.') && gate.role === 'status', `a visitor: "${gate.text}"`);
      check(gate.buttons.join('|') === 'Sign in' && gate.links.length === 0, `only a quiet Sign in (${gate.buttons.join(', ')}; links: ${gate.links.join(', ') || 'none'})`);
      check(!/update_pending|503|setup|migration|error/i.test(gate.page), 'no error, no code, no setup or Migrations button');
      let paths = apiPaths();
      check(paths.includes('/api/site-status') && paths.every((path) => OPEN_WHILE_WAITING.test(path)), `no dashboard made behind it: the API asked for ${paths.join(', ')} only`);
      await shot(guest, '12a-update-waiting-visitor', { fullPage: true });
      await guest.setViewportSize({ width: 390, height: 800 });
      await openGated(guest, '/import?lang=sk');
      gate = await gatePage(guest);
      check(gate.title === 'Stránku práve aktualizujeme' && gate.buttons.join('|') === 'Prihlásiť sa', `in Slovak: "${gate.text}"`);
      check((await guest.evaluate(() => document.documentElement.scrollWidth)) <= 390, '390 px: no sideways scroll');
      await shot(guest, '12b-update-waiting-visitor-sk-390', { fullPage: true });
      await guest.emulateMedia({ colorScheme: 'dark' });
      check(await darkSurface(guest, 'anotoki-site-status section'), "dark: the page is in the site's dark colours (the --anotoki-* properties)");
      await shot(guest, '12e-update-waiting-visitor-sk-390-dark', { fullPage: true });

      apiPaths = apiAsked(tab);
      await openGated(tab, '/');
      gate = await gatePage(tab);
      check(gate.title === 'Stránku práve aktualizujeme' && gate.buttons.length === 0 && gate.links.length === 0, `mona, signed in: the same, with no button ("${gate.text}")`);
      check(!/update_pending|503|setup|migration/i.test(gate.page), 'and no error, code, setup or Migrations');
      paths = apiPaths();
      check(
        paths.every((path) => OPEN_WHILE_WAITING.test(path)),
        `no dashboard of hers made behind it: the API asked for ${paths.join(', ')} only`,
      );
      await shot(tab, '12c-update-waiting-member', { fullPage: true });

      step('An update waits: the ADMIN reads that a database update is waiting, and reaches the Migrations page - the one page that shows');
      apiPaths = apiAsked(ad);
      await openGated(ad, '/admin/builds');
      gate = await gatePage(ad);
      check(gate.title === 'A database update is waiting' && gate.links.join('|') === 'Open Migrations -> /admin/migrations', `the ADMIN: "${gate.text}" (${gate.links.join(', ')})`);
      paths = apiPaths();
      check(
        paths.every((path) => OPEN_WHILE_WAITING.test(path)),
        `no Builds page made behind it: the API asked for ${paths.join(', ')} only`,
      );
      await shot(ad, '12d-update-waiting-admin', { fullPage: true });
      await ad.click('anotoki-site-status a');
      await ad.waitForURL('**/admin/migrations');
      await ad.waitForSelector('[data-pending] .anotoki-migrations-page__files');
      // Pending is one list in the order the files run (anotoki-lib 0.4), each named as its set's records name it.
      const pendingFiles = (await ad.locator('[data-pending] .anotoki-migrations-page__files li').allInnerTexts()).map((line) => line.replace(/\s+/g, ' ').trim());
      check(pendingFiles.join('|') === '004_visitors_and_family_bar.sql pending', `Migrations: 004 pending (${pendingFiles.join(', ')})`);
      check((await ad.locator(ADMIN_FRAME).count()) === 1, "in the admin panel's frame");
      const waitingPages = await ad.locator(FRAME_LABEL).allInnerTexts();
      check(waitingPages.join('|') === 'Migrations', `its column links Migrations alone while the update waits (${waitingPages.join(', ')})`);
      await shot(ad, '13-admin-migrations-pending', { fullPage: true });

      step("An update waits: a signed-out ADMIN presses Sign in on the waiting page and comes back to it - now the ADMIN's");
      const comeback = await newContext();
      const returning = await comeback.newPage();
      await openGated(returning, '/?lang=en');
      await iamAs(ADA);
      await returning.click('anotoki-site-status button');
      await returning.waitForURL((url) => url.href.startsWith(SITE) && !url.pathname.startsWith('/auth/callback'), { timeout: 15000 });
      await returning.waitForFunction(() => document.querySelector('anotoki-site-status h1')?.textContent?.includes('A database update is waiting'), null, { timeout: 15000 });
      check(new URL(returning.url()).pathname === '/', `back on the page Sign in was pressed on (${new URL(returning.url()).pathname})`);
      await returning.click('anotoki-site-status a');
      await returning.waitForSelector('[data-pending] .anotoki-migrations-page__files', { timeout: 15000 });
      check(new URL(returning.url()).pathname === '/admin/migrations', 'and on to the Migrations page');
      await comeback.close();

      step('An update waits: Apply pending asks first - and says where it stopped');
      await ad.click('[data-apply]');
      await ad.waitForSelector('anotoki-dialog[data-confirm] dialog[open] h2');
      const asked = (await ad.locator('[data-confirm] h2').innerText()).trim();
      check(asked === 'Apply 1 pending migration?', `it asks first: ${asked}`);
      check((await ad.evaluate(() => document.activeElement?.hasAttribute('data-confirm-cancel'))) === true, 'Cancel has the focus: an Apply is never one keypress away');
      await shot(ad, '14-admin-migrations-confirm');
      await ad.click('[data-confirm-apply]');
      await ad.waitForSelector('[data-result]', { timeout: 30000 });
      const outcome = await ad.locator('[data-result]').innerText();
      check(
        outcome.includes('Stopped at 004_visitors_and_family_bar.sql') && outcome.includes('Nothing was applied.') && outcome.includes('rolled back as a whole') && /already exists/.test(outcome),
        `the failure, named: ${outcome.replace(/\s+/g, ' ').slice(0, 160)}`,
      );
      check((await ad.locator('[data-result]').getAttribute('role')) === 'alert', 'said aloud: role alert');
      check((await ad.locator('[data-pending] .anotoki-migrations-page__files li').count()) === 1, 'still pending after the failed apply');
      await shot(ad, '15-admin-migrations-stopped', { fullPage: true });
      await ad.setViewportSize({ width: 390, height: 800 });
      await shot(ad, '16-admin-migrations-stopped-390', { fullPage: true });
      const wide = await ad.evaluate(() => document.documentElement.scrollWidth);
      check(wide <= 390, `390 px: no sideways scroll (${wide})`);
      await ad.emulateMedia({ colorScheme: 'dark' });
      check(await darkSurface(ad, '[data-pending]'), "dark: the Migrations page is in the site's dark colours");
      await shot(ad, '16b-admin-migrations-stopped-390-dark', { fullPage: true });
      await ad.emulateMedia({ colorScheme: 'light' });
    } finally {
      sql("INSERT INTO migrations (filename) VALUES ('004_visitors_and_family_bar.sql') ON CONFLICT DO NOTHING RETURNING filename");
    }

    step('The update applied: the waiting pages reload by themselves, and the site is back');
    // The visitor's page asks again every 30 seconds, and starts afresh once the site is ready.
    await guest.waitForSelector(BAR, { timeout: 45000 });
    check((await guest.locator('anotoki-site-status').count()) === 0, "the visitor's page came back by itself");
    await guests.close();
    await ad.setViewportSize({ width: 1280, height: 900 });
    await open(ad, '/admin/migrations');
    await ad.waitForSelector('anotoki-migrations-page [data-pending]');
    check((await ad.locator('[data-pending]').innerText()).includes('Nothing pending'), 'the database as it was: nothing pending');
    await open(tab, '/');
    check((await tab.locator('anotoki-site-status').count()) === 0, "mona's dashboard again");

    // ── The served path ────────────────────────────────────────────────────────────────────────────────
    step('Served by router.php: X-Visitor-Key passes, and a sweep runs after an answer');
    const made = await fetch(`${SITE}/api/imports/file`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ content: GOOD, file_name: 'good.json' }) });
    const servedKey = made.headers.get('x-visitor-key');
    check(made.status === 201 && /^[A-Za-z0-9_-]{43}$/.test(servedKey ?? ''), 'an import with no sign-in answers its key in X-Visitor-Key');
    const mine = await fetch(`${SITE}/api/accounts`, { headers: { 'X-Visitor-Key': servedKey } });
    check(mine.status === 200 && (await mine.json()).length === 1, "the key sent back in X-Visitor-Key opens that visitor's accounts");
    // The twins' second visitor (its key was never kept) - a month and a half unseen.
    sql("UPDATE users SET last_seen_at = now() - interval '45 days' WHERE id < 0 AND visitor_key_hash <> ?", [createHash('sha256').update(servedKey).digest('hex')]);
    const before = sql('SELECT count(*) AS n FROM users WHERE id < 0')[0].n;
    sql("DELETE FROM cache WHERE key LIKE '%visitors:swept%'");
    await fetch(`${SITE}/api/accounts`, { headers: { 'X-Visitor-Key': servedKey } });
    await new Promise((done) => setTimeout(done, 500));
    const after = sql('SELECT count(*) AS n FROM users WHERE id < 0')[0].n;
    check(Number(after) === 1 && Number(before) > 1, `the visitors unseen for 45 days went after that answer (${before} -> ${after})`);
    const logged = readFileSync(join(WORK, 'config', 'storage', 'logs', 'app.log'), 'utf8');
    check(logged.includes('Visitors unseen for 30 days deleted') && !logged.includes(servedKey) && !logged.includes(key), 'the log says so, and holds no key');

    // ── Signing in from a visitor's page waits for the save on its way ─────────────────────────────────
    step("Sign in pressed while a visitor's first import is on its way: it waits for the key, which then moves");
    const hurry = await newContext();
    const rushed = await hurry.newPage();
    await open(rushed, '/import?lang=en');
    await rushed.locator('button.choice.method').nth(1).click();
    await rushed.getByRole('button', { name: "I'm ready" }).click();
    await rushed.setInputFiles('input[type=file]', goodFile);
    // The import's answer is held back a moment, as a slow server's would be.
    await rushed.route('**/api/imports/file', async (route) => {
      await new Promise((done) => setTimeout(done, 1500));
      await route.continue();
    });
    const rushedAnswer = rushed.waitForResponse((response) => response.url().endsWith('/api/imports/file'));
    await rushed.getByRole('button', { name: 'Import file' }).click();
    await iamAs(SAM);
    await rushed.click(SIGN_IN);
    const rushedKey = (await rushedAnswer).headers()['x-visitor-key'] ?? '';
    await rushed.waitForURL((url) => url.href.startsWith(SITE) && !url.pathname.startsWith('/auth/callback'), { timeout: 15000 });
    await rushed.waitForSelector(PERSON_BUTTON, { timeout: 15000 });
    await rushed.waitForLoadState('networkidle');
    await rushed.waitForTimeout(500);
    check(/^[A-Za-z0-9_-]{43}$/.test(rushedKey), 'the import made a visitor and answered its key');
    check(sql('SELECT count(*) AS n FROM accounts WHERE user_id = 10')[0].n == 1, "after the sign-in the account is sam's: the key was kept before the page left, and moved");
    check(sql('SELECT count(*) AS n FROM users WHERE visitor_key_hash = ?', [createHash('sha256').update(rushedKey).digest('hex')])[0].n == 0, 'that visitor row is gone');
    await hurry.close();

    // ── A refused sign-in while visiting, and the admin panel ──────────────────────────────────────────
    step("A sign-in the IAM refuses while visiting (MEMBER taken away): the failure's screen says why - light and dark, 1280 and 390 px; Back goes on visiting; /admin is sign-in only");
    await iamState({ deny: true });
    const turned = await newContext();
    const away = await turned.newPage();
    /** The failure's screen as a person reads it. */
    const failureScreen = () =>
      away.evaluate(() => {
        const screen = document.querySelector('anotoki-sign-in-failure');
        const words = (element) => (element?.innerText ?? '').replace(/\s+/g, ' ').trim();
        return {
          title: words(screen?.querySelector('h1')),
          message: words(screen?.querySelector('[role=alert]')),
          buttons: [...(screen?.querySelectorAll('button') ?? [])].map(words),
          robots: document.querySelector('meta[name=robots]')?.getAttribute('content') ?? null,
          lang: screen?.querySelector('section')?.getAttribute('lang') ?? null,
        };
      });
    await open(away, '/builds/genshin?lang=en');
    await away.click(SIGN_IN);
    await away.waitForURL((url) => url.href.startsWith(SITE) && url.pathname === '/sign-in', { timeout: 15000 });
    await away.waitForSelector('anotoki-sign-in-failure [role=alert]');
    let seen = await failureScreen();
    check(
      seen.title === 'Signing in did not work' && seen.message.startsWith('The sign-in was not completed.') && seen.buttons.join('|') === 'Try again|Back',
      `back from the IAM: the failure's screen says why ("${seen.title}: ${seen.message}" - ${seen.buttons.join(', ')})`,
    );
    check(seen.robots === 'noindex', `search engines are asked not to index it (${seen.robots})`);
    check((await away.locator(BAR).count()) === 1 && (await away.title()) === 'Sign in · Build Analyzer', `in the site's frame, under its bar ("${await away.title()}")`);
    await shot(away, '17-visitor-sign-in-refused-1280-light', { fullPage: true });
    for (const [scheme, width] of [
      ['dark', 1280],
      ['light', 390],
      ['dark', 390],
    ]) {
      await away.setViewportSize({ width, height: 800 });
      await away.emulateMedia({ colorScheme: scheme });
      await away.waitForTimeout(200);
      const scroll = await away.evaluate(() => document.documentElement.scrollWidth);
      check(scroll <= width, `the failure's screen, ${width} px, ${scheme}: no sideways scroll (${scroll})`);
      if (scheme === 'dark') {
        check(await darkSurface(away, '.anotoki-sign-in-failure__panel'), `the failure's screen, ${width} px: in the site's dark colours`);
      }
      await shot(away, `17-visitor-sign-in-refused-${width}-${scheme}`, { fullPage: true });
    }
    await away.setViewportSize({ width: 1280, height: 800 });
    await away.emulateMedia({ colorScheme: 'light' });
    await away.click('anotoki-sign-in-failure [data-back]');
    await away.waitForURL((url) => url.pathname === '/builds/genshin', { timeout: 10000 });
    await away.waitForSelector(SIGN_IN, { timeout: 10000 });
    check(new URL(away.url()).pathname === '/builds/genshin', 'Back: on to the page the sign-in started on, visiting');
    await open(away, '/?lang=sk');
    check(new URL(away.url()).pathname === '/' && (await away.locator('app-visitor-hero').count()) === 1, "said once: the next page is the visitor's again");
    // In Slovak: the screen reads the site's own words, moved under the library's keys by 009.
    await away.click(SIGN_IN);
    await away.waitForURL((url) => url.href.startsWith(SITE) && url.pathname === '/sign-in', { timeout: 15000 });
    await away.waitForSelector('anotoki-sign-in-failure [role=alert]');
    seen = await failureScreen();
    check(
      seen.lang === 'sk' && seen.message.startsWith('Prihlásenie sa nedokončilo.') && seen.buttons.join('|') === 'Skúsiť znova|Späť',
      `in Slovak: "${seen.title}: ${seen.message}" (${seen.buttons.join(', ')})`,
    );
    await shot(away, '17-visitor-sign-in-refused-sk-1280-light', { fullPage: true });
    await away.goto(`${SITE}/admin`);
    await away.waitForURL((url) => url.href.startsWith(SITE) && url.pathname === '/sign-in', { timeout: 15000 });
    await away.waitForSelector('anotoki-sign-in-failure [role=alert]');
    check((await failureScreen()).message.length > 0, "a visitor at /admin is sent to sign in; refused, the failure's screen says so");
    check((await away.locator(BAR_LINK, { hasText: 'Translations' }).count()) === 0 && (await away.locator(ADMIN_FRAME).count()) === 0, 'and nothing of the panel shows');
    await iamState({ deny: false });
    await turned.close();

    // ── Sign-in required ───────────────────────────────────────────────────────────────────────────────
    step('The IAM says sign-in is required: every page asks for it; the key held is kept and moves at sign-in');
    const late = await newContext();
    const lateTab = await late.newPage();
    await open(lateTab, '/import?lang=en');
    await lateTab.locator('button.choice.method').nth(1).click();
    await lateTab.getByRole('button', { name: "I'm ready" }).click();
    await lateTab.setInputFiles('input[type=file]', goodFile);
    const lateAnswer = lateTab.waitForResponse((response) => response.url().endsWith('/api/imports/file'));
    await lateTab.getByRole('button', { name: 'Import file' }).click();
    const lateKey = (await lateAnswer).headers()['x-visitor-key'] ?? '';
    await lateTab.waitForTimeout(300);
    check(/^[A-Za-z0-9_-]{43}$/.test(lateKey), 'a visitor with a key, while visitors are welcome');

    await iamState({ login_required: true });
    forgetSwitch();
    const meta = await (await fetch(`${SITE}/api/meta`)).json();
    check(meta.auth?.login_required === true, `/api/meta relays it (auth.login_required: ${meta.auth?.login_required})`);
    const standing = await fetch(`${SITE}/api/site-status`);
    const standingBody = await standing.json();
    check(standing.status === 200 && standingBody.state === 'ready', `the site's status still answers anybody (${standing.status} ${JSON.stringify(standingBody)}): never read as "not available"`);
    const keyRefused = await fetch(`${SITE}/api/accounts`, { headers: { 'X-Visitor-Key': lateKey } });
    check(keyRefused.status === 401 && (await keyRefused.json()).code === 'sign_in_required', 'the key opens nothing now (401 sign_in_required)');

    // Turned away first: the sign-in page says so, and the key stays.
    await iamState({ deny: true });
    await lateTab.goto(`${SITE}/builds/genshin`);
    await lateTab.waitForURL((url) => url.href.startsWith(SITE) && url.pathname === '/sign-in', { timeout: 15000 });
    await lateTab.waitForSelector('anotoki-sign-in-failure [role=alert]');
    check(
      (await lateTab.locator('anotoki-sign-in-failure [role=alert]').innerText()).trim().startsWith('The sign-in was not completed.'),
      "a page load went to the IAM by itself, and came back refused to the failure's screen",
    );
    check((await lateTab.evaluate(() => localStorage.getItem('build-analyzer-visitor'))) === lateKey, 'the key is kept while sign-in is required');
    await shot(lateTab, '18-required-refused', { fullPage: true });
    await iamState({ deny: false });

    // Then let in: back on the page asked for, and what the visitor imported is the person's.
    await iamAs(RITA);
    await lateTab.click('anotoki-sign-in-failure [data-retry]');
    await lateTab.waitForURL((url) => url.href.startsWith(SITE) && url.pathname === '/builds/genshin', { timeout: 15000 });
    await lateTab.waitForSelector(PERSON_BUTTON, { timeout: 15000 });
    await lateTab.waitForLoadState('networkidle');
    await lateTab.waitForTimeout(500);
    check(sql('SELECT count(*) AS n FROM accounts WHERE user_id = 11')[0].n == 1, "signed in, back on /builds/genshin: the visitor's account is rita's (the move works while sign-in is required)");
    check((await lateTab.evaluate(() => localStorage.getItem('build-analyzer-visitor'))) === null, 'and the key is gone');
    await shot(lateTab, '19-required-signed-in', { fullPage: true });
    await late.close();

    const stranger = await newContext();
    const strangerTab = await stranger.newPage();
    await iamState({ deny: true });
    await strangerTab.goto(`${SITE}/`);
    await strangerTab.waitForURL((url) => url.href.startsWith(SITE) && url.pathname === '/sign-in', { timeout: 15000 });
    check((await strangerTab.locator('app-visitor-hero').count()) === 0, "somebody new never sees the visitors' dashboard");
    await iamState({ deny: false });
    await stranger.close();

    // What the site keeps is said before anybody signs in: the legal pages are anybody's, also now.
    const reader = await newContext();
    const readerTab = await reader.newPage();
    await iamState({ deny: true });
    await open(readerTab, '/privacy?lang=en');
    check(
      new URL(readerTab.url()).pathname === '/privacy' && (await readerTab.locator('anotoki-privacy-page h1').innerText()).trim() === 'Privacy policy',
      `while sign-in is required, /privacy is read without going to the IAM (${new URL(readerTab.url()).pathname})`,
    );
    check((await readerTab.locator(SIGN_IN).count()) === 1, "with the bar's Sign in, nobody signed in");
    // No message can be sent now by somebody signed out (the server refuses it): no Feedback at the foot, nor on Contact.
    check(
      (await readerTab.locator('anotoki-site-footer [data-open-feedback]').count()) === 0 && (await readerTab.locator('anotoki-site-footer [data-whats-new]').count()) === 1,
      "the footer offers no Feedback to somebody signed out now - What's new all the same",
    );
    await shot(readerTab, '18b-required-privacy', { fullPage: true });
    await open(readerTab, '/contact?lang=en');
    await readerTab.waitForSelector('anotoki-contact-page h1', { timeout: 15000 });
    check(
      (await readerTab.locator('anotoki-contact-page [data-open-feedback]').count()) === 0 && (await readerTab.locator('anotoki-contact-page [data-section="email"]').count()) === 1,
      'and Contact gives the address alone',
    );
    await iamState({ deny: false });
    await reader.close();

    step('Sign-in no longer required: visitors are welcome again');
    await iamState({ login_required: false });
    forgetSwitch();
    const again = await newContext();
    const againTab = await again.newPage();
    await open(againTab, '/?lang=en');
    check(new URL(againTab.url()).pathname === '/' && (await againTab.locator('app-visitor-hero').count()) === 1, "the dashboard's hero, no sign-in asked for");
    await again.close();

    // ── Not set up ─────────────────────────────────────────────────────────────────────────────────────
    step('Not set up: never installed - "not set up yet" and the setup page; installed once - "not available", never the setup page');
    // The settings folder loses auth.local.php (the site reads its settings on every request), and later gains
    // storage/installed.lock, which setup.php writes when it finishes on a real host.
    const settings = join(WORK, 'config');
    const lock = join(settings, 'storage', 'installed.lock');
    renameSync(join(settings, 'auth.local.php'), join(settings, 'auth.local.php.away'));
    try {
      const fresh = await newContext();
      const freshTab = await fresh.newPage();
      await openGated(freshTab, '/?lang=en');
      let gate = await gatePage(freshTab);
      check(
        gate.title === 'This site is not set up yet' && gate.links.join('|') === 'Open the setup page -> /setup.php' && gate.buttons.join('|') === 'Try again',
        `never installed: "${gate.text}" (${gate.links.join(', ')})`,
      );
      const answer = await (await fetch(`${SITE}/api/meta`)).json();
      check(answer.code === 'not_set_up' && answer.installed === false && answer.setup === '/setup.php', `the API: 503 not_set_up with the setup page (${JSON.stringify(answer)})`);
      await shot(freshTab, '20-not-set-up-never-installed', { fullPage: true });

      writeFileSync(lock, JSON.stringify({ installed_at: new Date().toISOString(), site: SITE }));
      await openGated(freshTab, '/?lang=en');
      gate = await gatePage(freshTab);
      check(gate.title === 'The site is not available right now' && gate.links.length === 0 && !/setup/i.test(gate.page), `installed once: "${gate.text}" - no setup page`);
      const installed = await (await fetch(`${SITE}/api/meta`)).json();
      check(installed.code === 'not_set_up' && installed.installed === true && !('setup' in installed), `the API: 503 not_set_up, installed, no setup page (${JSON.stringify(installed)})`);
      await shot(freshTab, '21-not-set-up-installed', { fullPage: true });
      await fresh.close();
    } finally {
      rmSync(lock, { force: true });
      renameSync(join(settings, 'auth.local.php.away'), join(settings, 'auth.local.php'));
    }
    const back = await (await fetch(`${SITE}/api/site-status`)).json();
    check(back.state === 'ready', `set up again: ${JSON.stringify(back)}`);

    // ── The legal pages ────────────────────────────────────────────────────────────────────────────────
    step("Terms, Privacy and Contact: the family's pages with this site's facts, from the footer, at 1280 and 390 px, light and dark, English and Slovak");
    {
      const legal = await newContext();
      const tab = await legal.newPage();
      await open(tab, '/?lang=en');
      const links = await tab.locator('anotoki-site-footer anotoki-legal-links a').evaluateAll((all) => all.map((link) => `${link.textContent.trim()} -> ${link.getAttribute('href')}`));
      check(links.join('|') === 'About -> /about|Support -> /support|Terms -> /terms|Privacy -> /privacy|Contact -> /contact', `the footer's links: ${links.join(', ')}`);
      check((await tab.locator('anotoki-site-footer [data-fan]').innerText()).includes('not affiliated with or endorsed by HoYoverse'), 'and the fan clause under them');
      await tab.click('anotoki-site-footer anotoki-legal-links a[href="/privacy"]');
      await tab.waitForSelector('anotoki-privacy-page h1', { timeout: 15000 });
      check(
        new URL(tab.url()).pathname === '/privacy' && (await tab.title()) === 'Privacy policy · Build Analyzer',
        `the footer's Privacy opens the page in the site's frame, titled "${await tab.title()}"`,
      );
      check((await tab.locator('anotoki-site-footer anotoki-legal-links a[aria-current="page"]').innerText()).trim() === 'Privacy', 'the open page marked in the footer');
      // The Contact page's form: the family's feedback form in the kit's dialog.
      await open(tab, '/contact?lang=en');
      await tab.click('anotoki-contact-page [data-open-feedback]');
      await tab.waitForSelector(`${DIALOG} dialog[open] anotoki-feedback-form`, { timeout: 10000 });
      check(true, "Contact's Send feedback opens the family's form in a dialog");
      await tab.keyboard.press('Escape');
      await legal.close();

      for (const [language, scheme, width] of [
        ['en', 'light', 1280],
        ['en', 'dark', 390],
        ['sk', 'dark', 1280],
        ['sk', 'light', 390],
      ]) {
        const context = await newContext({ width, scheme, height: width < 600 ? 844 : 900 });
        const page = await context.newPage();
        for (const name of ['terms', 'privacy', 'contact']) {
          await open(page, `/${name}?lang=${language}`);
          await page.waitForSelector(`anotoki-${name}-page h1`, { timeout: 15000 });
          const facts = await page.evaluate(
            (name) => ({
              title: document.querySelector(`anotoki-${name}-page h1`)?.textContent?.trim(),
              lang: document.documentElement.lang,
              scroll: document.documentElement.scrollWidth,
              width: window.innerWidth,
              fan: document.querySelector('[data-fan]')?.textContent?.trim() ?? null,
              kept: document.querySelectorAll('[data-kept]').length,
              stored: [...document.querySelectorAll('[data-stored] code')].map((code) => code.textContent.trim()),
              recipients: [...document.querySelectorAll('[data-recipient] .anotoki-legal-page__item-name')].map((item) => item.textContent.trim()),
              accountPrivacy: document.querySelector('[data-account-privacy]')?.getAttribute('href') ?? null,
            }),
            name,
          );
          const label = `${name}, ${language}, ${scheme}, ${width} px`;
          check(facts.lang === language && facts.title && facts.scroll <= facts.width, `${label}: "${facts.title}", <html lang="${facts.lang}">, no sideways scroll (${facts.scroll}/${facts.width})`);
          const keys = await keysShown(page);
          check(keys.length === 0, `${label}: no key where a word should be${keys.length ? ` - ${keys.join(', ')}` : ''}`);
          if (name !== 'privacy') {
            check(facts.fan !== null && facts.fan.includes('Genshin Impact') && !facts.fan.includes('Star Rail'), `${label}: the library's fan clause, naming Genshin Impact - the one game here`);
          } else {
            check(
              facts.kept === 12 &&
                facts.stored.includes('build-analyzer-visitor') &&
                facts.stored.includes('build-analyzer-announcement') &&
                facts.recipients.length === 3 &&
                facts.recipients[0] === 'WebSupport s.r.o.' &&
                facts.accountPrivacy === `${IAM}/privacy`,
              `${label}: the site's seven parts (the site settings' among them) with visitors, feedback and the three logs (${facts.kept}), its storage with the hidden announcement (${facts.stored.length}), ${facts.recipients.join(', ')}, the account's own Privacy page ${facts.accountPrivacy}`,
            );
          }
          if (scheme === 'dark') {
            check(await darkSurface(page, 'body'), `${label}: the dark theme`);
          }
          await shot(page, `23-legal-${name}-${language}-${width}-${scheme}`, { fullPage: name === 'privacy' });
        }
        await context.close();
      }
    }

    // ── The kit's newer pieces ────────────────────────────────────────────────────────────────────────
    step(
      "The kit's newer pieces in place of the site's own (anotoki-lib 0.6.0): the account's element filter with its marks, its link cards and score bars, its import history; a build's tab with its badge; the import guide's stepper and disclosures; the builds' link cards - at 1280 and 390 px, light and dark",
    );
    {
      const monaAccount = sql('SELECT id FROM accounts WHERE user_id = 7')[0].id;
      const kit = await newContext();
      const page = await kit.newPage();
      await open(page, '/?lang=en');
      await signIn(page, MONA);
      /** WCAG's contrast of two colours the page computed ("rgb(...)", or a token's "#rrggbb"). */
      const contrastOf = (page, selector) =>
        page.evaluate((selector) => {
          const rgb = (colour) => {
            const hex = /^#([0-9a-f]{6})$/i.exec(colour.trim());
            return hex ? [0, 2, 4].map((at) => parseInt(hex[1].slice(at, at + 2), 16)) : (colour.match(/[\d.]+/g) ?? []).slice(0, 3).map(Number);
          };
          const luminance = (colour) => {
            const [r, g, b] = rgb(colour).map((value) => {
              const channel = value / 255;
              return channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
            });
            return 0.2126 * r + 0.7152 * g + 0.0722 * b;
          };
          const ratio = (a, b) => {
            const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);
            return (light + 0.05) / (dark + 0.05);
          };
          const root = getComputedStyle(document.documentElement);
          // A mark stands on the control's ground (surface-2) and, chosen, on its segment's (surface).
          const grounds = [root.getPropertyValue('--anotoki-surface-2'), root.getPropertyValue('--anotoki-surface')];
          return [...document.querySelectorAll(selector)].map((mark) => {
            const colour = getComputedStyle(mark).backgroundColor;
            return { value: mark.closest('[role=radio]')?.getAttribute('data-value'), lowest: Math.min(...grounds.map((ground) => ratio(colour, ground))) };
          });
        }, selector);
      /** The teams of the build shown: their heading, each team's name, each member's name, link, marks and stand-ins. */
      const teamShown = (page) =>
        page.evaluate(() => ({
          heading: document.querySelector('app-build-teams h3')?.textContent?.trim() ?? null,
          names: [...document.querySelectorAll('app-build-teams .team-name')].map((name) => name.textContent.trim()),
          members: [...document.querySelectorAll('app-build-teams .team:first-child .member')].map((member) => ({
            name: member.querySelector('.member-name')?.textContent?.trim() ?? null,
            href: member.querySelector('a.member-name')?.getAttribute('href') ?? null,
            marks: [...member.querySelectorAll('.member-facts anotoki-badge')].map((badge) => badge.textContent.trim()),
            below: member.querySelector('.member-below')?.textContent?.trim() ?? null,
            // The check on the portrait of a teammate the account has, and what a screen reader hears of it.
            checked: member.querySelector('.portrait .owned-check') !== null,
            heard: member.querySelector('.member-head .visually-hidden')?.textContent?.trim() ?? null,
            // As written: the template adds no blank of its own beside the words.
            alternatives: member.querySelector('.alternatives')?.textContent ?? null,
            standIns: [...member.querySelectorAll('.alternatives a')].map((link) => link.getAttribute('href')),
            standInChecks: member.querySelectorAll('.alternatives .owned-check').length,
            // Nothing wider than its column (a name breaks between its words, never inside one).
            spills: member.scrollWidth > member.clientWidth + 1,
          })),
          // The team side by side: every member at the top of one row.
          rows: new Set([...document.querySelectorAll('app-build-teams .team:first-child .member')].map((member) => Math.round(member.getBoundingClientRect().top))).size,
        }));

      for (const [language, scheme, width] of [
        ['en', 'light', 1280],
        ['sk', 'dark', 390],
        ['en', 'dark', 1280],
        ['sk', 'light', 390],
      ]) {
        await page.setViewportSize({ width, height: width < 600 ? 844 : 900 });
        await page.emulateMedia({ colorScheme: scheme });
        const label = `${language}, ${scheme}, ${width} px`;
        const fits = async (where) => {
          const scroll = await page.evaluate(() => document.documentElement.scrollWidth);
          const keys = await keysShown(page);
          check(scroll <= width && keys.length === 0, `${where}, ${label}: no sideways scroll (${scroll}), no key where a word should be${keys.length ? ` - ${keys.join(', ')}` : ''}`);
          if (scheme === 'dark') {
            check(await darkSurface(page, 'body'), `${where}, ${label}: the dark theme`);
          }
        };

        // The account: its element filter (the kit's segmented control, each element's mark), its characters as
        // link cards with the kit's progress bars, and its import history in the kit's disclosure.
        await open(page, `/accounts/${monaAccount}?lang=${language}`);
        await page.waitForSelector('.char-card', { timeout: 15000 });
        const account = await page.evaluate(() => ({
          group: document.querySelector('.element [role=radiogroup]')?.getAttribute('aria-label') ?? null,
          radios: document.querySelectorAll('.element [role=radio]').length,
          cards: [...document.querySelectorAll('a.char-card')].map((card) => card.classList.contains('anotoki-link-card') && /^\/accounts\/\d+\/characters\//.test(card.getAttribute('href') ?? '')),
          bars: [...document.querySelectorAll('.char-card [role=progressbar]')].map((bar) => bar.getAttribute('aria-valuetext')),
        }));
        check(account.group !== null && account.radios >= 2, `the account, ${label}: the element filter is the kit's radio group "${account.group}" (${account.radios} choices)`);
        check(account.cards.length > 0 && account.cards.every(Boolean), `the account, ${label}: every character is one link card to its page (${account.cards.length})`);
        check(
          account.bars.length > 0 && account.bars.every((text) => text !== null),
          `the account, ${label}: the score bars are the kit's progress bars, each with its words (${account.bars.slice(0, 3).join(', ')}...)`,
        );
        const marks = await contrastOf(page, '.element .anotoki-segmented__mark');
        const faint = marks.filter((mark) => mark.lowest < 3);
        check(
          marks.length === account.radios - 1 && faint.length === 0,
          `the account, ${label}: every element's mark 3:1 on the control (lowest ${Math.min(...marks.map((mark) => mark.lowest)).toFixed(2)}${faint.length ? `; FAINT ${faint.map((mark) => `${mark.value} ${mark.lowest.toFixed(2)}`).join(', ')}` : ''})`,
        );
        const history = page.locator('.history .anotoki-disclosure__button');
        check(
          (await history.getAttribute('aria-expanded')) === 'false' && (await page.locator('.history-table tr').count()) === 0,
          `the account, ${label}: the import history closed, its table not made`,
        );
        await history.click();
        await page.waitForSelector('.history-table tr', { timeout: 5000 });
        check((await history.getAttribute('aria-expanded')) === 'true', `the account, ${label}: opened, the history's runs (${await page.locator('.history-table tr').count()})`);
        await fits('the account');
        // From the top: the bar stays put over a page scrolled to the history, and would stand over the filters.
        await page.evaluate(() => window.scrollTo(0, 0));
        await shot(page, `24-kit-account-${language}-${width}-${scheme}`, { fullPage: true });

        // A character: a tab a build, named by its variation - Hu Tao (C1) has a main DPS build and a Sub DPS one that
        // needs C2 -, its score as the kit's badge, the closest build marked and the one she does not reach locked; the
        // stat target of her constellation marked "C1+"; the score bars.
        await open(page, `/accounts/${monaAccount}/characters/hutao?lang=${language}`);
        await page.waitForSelector('[role=tab]', { timeout: 15000 });
        const tabs = await page.evaluate(() =>
          [...document.querySelectorAll('[role=tab]')].map((tab) => ({
            label: tab.querySelector('.anotoki-tabs__label')?.textContent?.trim() ?? null,
            badge: tab.querySelector('.anotoki-tabs__badge')?.textContent?.trim() ?? null,
            tone: tab.querySelector('.anotoki-tabs__badge')?.getAttribute('data-tone') ?? null,
            icon: tab.querySelector('anotoki-icon') !== null,
            heard: tab.querySelector('.anotoki-visually-hidden')?.textContent?.trim() ?? null,
          })),
        );
        const variations = language === 'sk' ? ['Hlavný DPS', 'Vedľajší DPS · C2+'] : ['Main DPS', 'Sub DPS · C2+'];
        check(
          tabs.length === 2 &&
            tabs.map((tab) => tab.label).join('|') === variations.join('|') &&
            /^\d+$/.test(tabs[0].badge ?? '') &&
            tabs[0].tone === 'primary' &&
            tabs[0].icon &&
            (tabs[0].heard ?? '').includes(tabs[0].badge) &&
            tabs[1].tone === 'neutral' &&
            tabs[1].icon &&
            (tabs[1].heard ?? '').includes('C2'),
          `the character, ${label}: a tab a build by its variation, its score as the kit's badge, the closest build marked, the one needing C2 locked (${JSON.stringify(tabs)})`,
        );
        const targetMarks = await page.locator('.build-card .t-rank').allInnerTexts();
        check(targetMarks.join('|') === 'C1+', `the character, ${label}: the stat target of her constellation, marked C1+ (${targetMarks.join(', ')})`);
        check((await page.locator('app-score-bar [role=progressbar]').count()) === 5, `the character, ${label}: the five score bars, the kit's progress bars`);
        // The main build's team (tests/tools/browser-db.php): Hu Tao herself, no link; Kazuha, whom the account has below
        // the C2 the team asks - marked so, and a link to the account's own analysis of him -, and Traveler (Anemo), his
        // stand-in, whom it has too - marked, a link to its analysis of her; Xingqiu, whom the catalog does not know, by
        // the file's name alone.
        const teamWords =
          language === 'sk'
            ? { heading: 'Tímy', below: 'potrebuje C2', or: 'alebo Traveler (Anemo)', owned: 'Máš' }
            : { heading: 'Teams', below: 'needs C2', or: 'or Traveler (Anemo)', owned: 'Owned' };
        const team = await teamShown(page);
        check(
          team.heading === teamWords.heading &&
            team.names.join('|') === 'Vaporize' &&
            team.members.map((member) => member.name).join('|') === 'Hu Tao|Kaedehara Kazuha|Xingqiu' &&
            team.members.map((member) => member.href ?? '-').join('|') === `-|/accounts/${monaAccount}/characters/kaedeharakazuha|-` &&
            team.members[1].marks.join('|') === 'C2+' &&
            team.members[1].below === teamWords.below &&
            team.members.map((member) => member.checked).join('|') === 'false|true|false' &&
            team.members[1].heard === teamWords.owned &&
            team.members[1].alternatives === `${teamWords.or} ${teamWords.owned}` &&
            team.members[1].standIns.join('|') === `/accounts/${monaAccount}/characters/traveleranemo` &&
            team.members[1].standInChecks === 1 &&
            team.members[2].marks.join('|') === 'C6+' &&
            team.rows === 1 &&
            team.members.every((member) => !member.spills),
          `the character, ${label}: the main build's team side by side - Kazuha hers (a check on his portrait, heard "${teamWords.owned}") below the C2 it asks, a link to her analysis of him, Traveler (Anemo) his stand-in, hers too; Xingqiu by his name alone; nothing wider than its column (${JSON.stringify(team)})`,
        );
        await fits('the character');
        await shot(page, `24-kit-character-${language}-${width}-${scheme}`, { fullPage: true });

        // The build she is graded by: above the build in hand "Use this build" - the closest match's mark beside it -;
        // chosen, the build a person and a green badge on its tab, "Selected" and "Choose automatically" above it, a
        // toast; the account page then names it on her card; chosen automatically again, as it was.
        const choiceWords =
          language === 'sk'
            ? {
                use: 'Použiť tento build',
                auto: 'Vyberať automaticky',
                chosen: 'Vybraný',
                closest: 'Najbližšie k tomu, čo máš teraz nasadené',
                line: 'Vybraný build: Vedľajší DPS · C2+',
                best: 'Najlepší build: Hlavný DPS',
              }
            : {
                use: 'Use this build',
                auto: 'Choose automatically',
                chosen: 'Selected',
                closest: 'Closest match to your current setup',
                line: 'Selected build: Sub DPS · C2+',
                best: 'Best build: Main DPS',
              };
        const choice = () => page.evaluate(() => [...document.querySelectorAll('.choice > *')].map((element) => element.textContent.replace(/\s+/g, ' ').trim()));
        const choiceShown = (words) =>
          page
            .waitForFunction((words) => [...document.querySelectorAll('.choice > *')].map((element) => element.textContent.replace(/\s+/g, ' ').trim()).join('|') === words.join('|'), words, {
              timeout: 10000,
            })
            .then(
              () => true,
              () => false,
            );
        check(
          (await choice()).join('|') === `${choiceWords.use}|${choiceWords.closest}`,
          `the character, ${label}: "${choiceWords.use}" above the closest match, its mark beside it (${(await choice()).join(', ')})`,
        );
        await page.locator('[role=tab]').nth(1).click();
        check(await choiceShown([choiceWords.use]), `the character, ${label}: the build needing C2 in hand, "${choiceWords.use}" alone (${(await choice()).join(', ')})`);
        await page.locator('.choice button').click();
        const chosenNow = await choiceShown([choiceWords.chosen, choiceWords.auto]);
        const toast = await page
          .locator('.anotoki-toast-host__message')
          .last()
          .textContent({ timeout: 5000 })
          .catch(() => null);
        const chosenTabs = await page.evaluate(() =>
          [...document.querySelectorAll('[role=tab]')].map((tab) => ({
            tone: tab.querySelector('.anotoki-tabs__badge')?.getAttribute('data-tone') ?? null,
            heard: tab.querySelector('.anotoki-visually-hidden')?.textContent?.trim() ?? null,
          })),
        );
        check(
          chosenNow && (toast ?? '').includes('Hu Tao') && chosenTabs[1]?.tone === 'success' && (chosenTabs[1]?.heard ?? '').includes(choiceWords.chosen) && chosenTabs[0]?.tone === 'primary',
          `the character, ${label}: chosen - "${choiceWords.chosen}" and "${choiceWords.auto}" above it, its tab green and heard so, the closest match's still marked, a toast "${toast}" (${JSON.stringify(chosenTabs)})`,
        );
        await fits('the character, a build chosen');
        await shot(page, `25-chosen-character-${language}-${width}-${scheme}`, { fullPage: true });

        await open(page, `/accounts/${monaAccount}?lang=${language}`);
        await page.waitForSelector('.char-card', { timeout: 15000 });
        const line = (await page.locator('a.char-card[href$="/characters/hutao"] .best-line').textContent())?.replace(/\s+/g, ' ').trim();
        check(line === choiceWords.line, `the account, ${label}: Hu Tao's card names the build chosen - "${line}"`);
        await fits('the account, a build chosen');
        await shot(page, `25-chosen-account-${language}-${width}-${scheme}`, { fullPage: true });

        await open(page, `/accounts/${monaAccount}/characters/hutao?lang=${language}`);
        await page.waitForSelector('[role=tab]', { timeout: 15000 });
        const openedOn = await page.locator('[role=tab][aria-selected=true] .anotoki-tabs__label').textContent();
        check(openedOn?.trim() === variations[1], `the character, ${label}: the page opens on the build chosen (${openedOn})`);
        await page.locator('.choice button').click();
        check(await choiceShown([choiceWords.use]), `the character, ${label}: chosen automatically again - "${choiceWords.use}" (${(await choice()).join(', ')})`);
        await open(page, `/accounts/${monaAccount}?lang=${language}`);
        await page.waitForSelector('.char-card', { timeout: 15000 });
        const again = (await page.locator('a.char-card[href$="/characters/hutao"] .best-line').textContent())?.replace(/\s+/g, ' ').trim() ?? '';
        check(again.startsWith(choiceWords.best), `the account, ${label}: the card names the closest match again - "${again}"`);

        // The same team on the builds' own page, which is anybody's: nothing marked as anybody's, Kazuha a link to his builds.
        await open(page, `/builds/genshin/hutao?lang=${language}`);
        await page.waitForSelector('app-build-teams .member', { timeout: 15000 });
        const publicTeam = await teamShown(page);
        check(
          publicTeam.heading === teamWords.heading &&
            publicTeam.members.map((member) => member.href ?? '-').join('|') === '-|/builds/genshin/kaedeharakazuha|-' &&
            publicTeam.members[1].marks.join('|') === 'C2+' &&
            publicTeam.members.every((member) => !member.checked && member.heard === null) &&
            publicTeam.members[1].alternatives === teamWords.or &&
            publicTeam.members[1].standIns.length === 0 &&
            publicTeam.rows === 1,
          `the builds' page, ${label}: the team, nothing marked as anybody's, Kazuha a link to his builds (${JSON.stringify(publicTeam)})`,
        );
        // The stat targets in three tiers (the check's database keeps Hu Tao's and her weapons' stats): the line naming
        // the build's first weapon and set, the three tiers' names on a tiered row with a value each, the stated
        // Energy Recharge as it was stated, and nothing of anybody's on the builds' own page.
        const tierWords =
          language === 'sk'
            ? { basis: 'Vypočítané pre Staff of Homa so setom 4pc Crimson Witch of Flames.', names: 'Základná|Stredná|Pokročilá' }
            : { basis: 'Worked out for Staff of Homa with 4pc Crimson Witch of Flames.', names: 'Basic|Intermediate|Advanced' };
        const basisLine = (await page.locator('.targets-basis').first().textContent())?.replace(/\s+/g, ' ').trim() ?? '';
        const tiered = page.locator('.target.tiered').first();
        const tierNames = (await tiered.locator('dt').allTextContents()).map((name) => name.trim()).join('|');
        const tierValues = (await tiered.locator('dd').allTextContents()).map((value) => value.trim());
        const stated = (await page.locator('.target:not(.tiered) .t-goal').allTextContents()).map((goal) => goal.replace(/\s+/g, ' ').trim());
        check(
          basisLine === tierWords.basis &&
            tierNames === tierWords.names &&
            tierValues.length === 3 &&
            tierValues.every((value) => /^[\d,.]+%?$/.test(value)) &&
            stated.join('|') === '120.0%+' &&
            (await page.locator('.t-current').count()) === 0,
          `the builds' page, ${label}: the stat targets in three tiers for the build's first weapon and set, the stated one as stated, nothing of anybody's (${basisLine}; ${tierNames}; ${tierValues.join(', ')}; ${stated.join('|')})`,
        );
        await fits("the builds' page");
        await shot(page, `24-kit-build-page-${language}-${width}-${scheme}`, { fullPage: true });

        // Kazuha's own team (made in the builds editor): Hu Tao, whom the account has at the C1 it asks - a check, a
        // link to its analysis of her -, and Traveler (Anemo), its stand-in for her, checked too.
        await open(page, `/accounts/${monaAccount}/characters/kaedeharakazuha?lang=${language}`);
        await page.waitForSelector('app-build-teams .member', { timeout: 15000 });
        const hisTeam = await teamShown(page);
        check(
          hisTeam.names.join('|') === 'Pyro Swirl' &&
            hisTeam.members.map((member) => member.name).join('|') === 'Kaedehara Kazuha|Hu Tao' &&
            hisTeam.members.map((member) => member.href ?? '-').join('|') === `-|/accounts/${monaAccount}/characters/hutao` &&
            hisTeam.members[1].marks.join('|') === 'C1+' &&
            hisTeam.members[1].below === null &&
            hisTeam.members.map((member) => member.checked).join('|') === 'false|true' &&
            hisTeam.members[1].standInChecks === 1,
          `his character page, ${label}: the team made in the editor, Hu Tao checked at the C1 it asks, Traveler (Anemo) her stand-in, checked (${JSON.stringify(hisTeam)})`,
        );
        await fits('his character page');

        // The import guide: the kit's stepper, the way in done and a way back to it; the trouble list in the kit's
        // disclosure; in English on to the file's step and its paste box, in the kit's disclosure too.
        await open(page, `/import?lang=${language}`);
        await page.locator('button.choice.method').nth(1).click();
        // The guide's step is drawn a moment after the choice (the page draws on its next turn, zoneless).
        const settled = (condition, argument) =>
          page.waitForFunction(condition, argument, { timeout: 10000 }).then(
            () => true,
            () => false,
          );
        await settled(() => document.querySelectorAll('.anotoki-stepper__step--done').length === 1);
        const steps = await page.evaluate(() => ({
          name: document.querySelector('.anotoki-stepper__steps')?.getAttribute('aria-label') ?? null,
          done: document.querySelectorAll('.anotoki-stepper__step--done .anotoki-stepper__button').length,
          current: document.querySelector('.anotoki-stepper__step[aria-current=step] .anotoki-stepper__label')?.textContent?.trim() ?? null,
        }));
        check(
          steps.name !== null && steps.done === 1 && steps.current !== null,
          `the import guide, ${label}: the kit's stepper "${steps.name}", the way in done with a way back, the one in hand "${steps.current}"`,
        );
        /** A disclosure of the kit opened, its panel shown (the attribute and the panel follow on the page's next turn). */
        const opened = (selector) =>
          settled((selector) => {
            const button = document.querySelector(`${selector} .anotoki-disclosure__button`);
            const panel = document.querySelector(`${selector} .anotoki-disclosure__panel`);
            return button?.getAttribute('aria-expanded') === 'true' && panel !== null && !panel.hidden;
          }, selector);
        await page.click('.trouble .anotoki-disclosure__button');
        check(
          (await opened('.trouble')) && (await page.locator('.trouble li').count()) > 0,
          `the import guide, ${label}: what to do when it goes wrong, opened (${await page.locator('.trouble li').count()} points)`,
        );
        await fits('the import guide');
        await shot(page, `24-kit-import-guide-${language}-${width}-${scheme}`, { fullPage: true });
        if (language === 'en') {
          await page.getByRole('button', { name: "I'm ready" }).click();
          await page.click('.paste .anotoki-disclosure__button');
          check((await opened('.paste')) && (await page.locator('.paste textarea').count()) === 1, `the import's file step, ${label}: the paste box in the kit's disclosure, opened`);
          await shot(page, `24-kit-import-file-${language}-${width}-${scheme}`, { fullPage: true });
        }

        // The builds: each character one link card; the filters - the kit's segmented controls and selects, offering
        // what the characters have - narrow them, the address follows, and Reset brings them all back.
        await open(page, `/builds/genshin?lang=${language}`);
        await page.waitForSelector('a.card', { timeout: 15000 });
        const builds = await page.evaluate(() => [...document.querySelectorAll('a.card')].map((card) => card.classList.contains('anotoki-link-card')));
        check(builds.length === 2 && builds.every(Boolean), `the builds, ${label}: every character one link card (${builds.length})`);
        const filters = await page.evaluate(() => ({
          groups: [...document.querySelectorAll('.filters [role=radiogroup]')].map((group) => group.getAttribute('aria-label')),
          selects: [...document.querySelectorAll('.filters select')].map((select) => [...select.options].map((option) => option.textContent.trim()).join('|')),
          count: document.querySelector('.count')?.textContent?.trim() ?? null,
        }));
        const words =
          language === 'sk'
            ? { groups: 'Element|Weapon|Vzácnosť', selects: ['Akýkoľvek región|Inazuma|Liyue', 'Akákoľvek verzia|1.6|1.3'], all: 'Zobrazené: 2 z 2', one: 'Zobrazené: 1 z 2' }
            : { groups: 'Element|Weapon|Rarity', selects: ['Any region|Inazuma|Liyue', 'Any version|1.6|1.3'], all: 'Showing 2 of 2', one: 'Showing 1 of 2' };
        check(
          filters.groups.join('|') === words.groups && filters.selects.join(' / ') === words.selects.join(' / ') && filters.count === words.all,
          `the builds' filters, ${label}: element, weapon type and rarity; the regions and the versions there are, the newest first; how many show (${JSON.stringify(filters)})`,
        );
        await fits('the builds');
        if (width < 600) {
          // On a phone each control has the whole width.
          const widths = await page.evaluate(() => [...document.querySelectorAll('.filters > *')].map((control) => Math.round(control.getBoundingClientRect().width)));
          check(widths.length === 7 && new Set(widths).size === 1, `the builds' filters, ${label}: each control the whole width (${widths.join(', ')})`);
          await shot(page, `24-kit-builds-${language}-${width}-${scheme}`, { fullPage: true });
        }
        await page.click('.filters [data-value="anemo"]');
        await page.waitForFunction(() => document.querySelectorAll('a.card').length === 1, null, { timeout: 10000 });
        const left = await page.locator('a.card .name').allInnerTexts();
        check(
          left.join('|') === 'Kaedehara Kazuha' && new URL(page.url()).searchParams.get('element') === 'anemo' && (await page.locator('.count').innerText()).trim() === words.one,
          `the builds, ${label}: Anemo leaves Kazuha, said, and the address has it (${left.join(', ')}; ${page.url()})`,
        );
        await fits('the builds, filtered');
        if (width < 600) {
          await shot(page, `24-kit-builds-filtered-${language}-${width}-${scheme}`, { fullPage: true });
        }
        await page.click('.filters button.reset');
        await page.waitForFunction(() => document.querySelectorAll('a.card').length === 2, null, { timeout: 10000 });
        check(!new URL(page.url()).searchParams.has('element'), `the builds, ${label}: Reset brings both back, the address without the filter (${page.url()})`);
      }

      // The builds' filters in English as a phone shows them (the tour above has English at 1280 px alone), opened from a
      // filtered address: a link to a filtered list opens it filtered.
      await page.setViewportSize({ width: 390, height: 844 });
      await page.emulateMedia({ colorScheme: 'light' });
      await open(page, '/builds/genshin?lang=en&rarity=5');
      await page.waitForSelector('a.card', { timeout: 15000 });
      const phone = await page.evaluate(() => ({
        chosen: document.querySelector('[role=radiogroup][aria-label="Rarity"] [aria-checked=true]')?.textContent?.trim() ?? null,
        count: document.querySelector('.count')?.textContent?.trim() ?? null,
        scroll: document.documentElement.scrollWidth,
      }));
      const phoneKeys = await keysShown(page);
      check(
        phone.chosen === '5 stars' && phone.count === 'Showing 2 of 2' && phone.scroll <= 390 && phoneKeys.length === 0,
        `the builds, en, light, 390 px: opened with "5 stars" chosen from the address, no sideways scroll, no key (${JSON.stringify(phone)}${phoneKeys.length ? ` - ${phoneKeys.join(', ')}` : ''})`,
      );
      await shot(page, '24-kit-builds-en-390-light', { fullPage: true });
      await kit.close();
    }

    // ── The pages' own errors, over the whole check ───────────────────────────────────────────────────
    step("The errors the pages met, over every tour (the family's reporter writes each into the error log): none but the one thrown on purpose");
    const met = pageErrors().filter((line) => line.message !== THROWN_SCRUBBED);
    check(met.length === 0, `no page met an error${met.length ? `: ${JSON.stringify(met.map((line) => [line.type, line.message, line.path]))}` : ''}`);
  }
} catch (error) {
  failures.push(String(error?.stack ?? error));
  console.error(error);
} finally {
  await browser.close();
}

console.log(failures.length === 0 ? '\nThe browser check passed.' : `\n${failures.length} FAILED:\n- ${failures.join('\n- ')}`);
process.exit(failures.length === 0 ? 0 : 1);
