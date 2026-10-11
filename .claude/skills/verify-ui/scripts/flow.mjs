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

/** The bar's breakpoints, as top-bar.ts has them: the member pages', and the admin panel's (a longer row). */
const TOP_BAR_TS = readFileSync(join(repo, 'frontend/src/app/layouts/top-bar/top-bar.ts'), 'utf8');
const PHONE_BAR_MAX = Number(/export const PHONE_BAR_MAX = (\d+);/.exec(TOP_BAR_TS)[1]);
const ADMIN_PHONE_BAR_MAX = Number(/export const ADMIN_PHONE_BAR_MAX = (\d+);/.exec(TOP_BAR_TS)[1]);
/** Every key of the site's words: none of them may show where a word should be. */
const KEYS = readdirSync(join(repo, 'frontend/src/app/core/i18n/en')).flatMap((file) => Object.keys(JSON.parse(readFileSync(join(repo, 'frontend/src/app/core/i18n/en', file), 'utf8'))));

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

/** A GOOD file (the tests' own sample): one Genshin account, no UID. */
const GOOD = JSON.stringify({
  format: 'GOOD',
  version: 3,
  source: 'Some Exporter',
  characters: [{ key: 'HuTao', level: 90, constellation: 1, ascension: 6, talent: { auto: 10, skill: 9, burst: 8 } }],
  weapons: [{ key: 'StaffOfHoma', level: 90, ascension: 6, refinement: 1, location: 'HuTao', lock: true }],
  artifacts: [
    {
      setKey: 'CrimsonWitchOfFlames', slotKey: 'flower', level: 20, rarity: 5, mainStatKey: 'hp', location: 'HuTao', lock: true,
      substats: [{ key: 'critRate_', value: 10.5 }, { key: 'critDMG_', value: 14.0 }],
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

/** Opens a page of the site and waits for the bar. */
async function open(page, path) {
  await page.goto(SITE + path);
  await page.waitForSelector('app-top-bar .topbar', { timeout: 15000 });
  await page.waitForLoadState('networkidle');
}

/** Opens a page of the site while it is not ready: the site gate's page (@anotoki/lib) stands in its place. */
async function openGated(page, path) {
  await page.goto(SITE + path);
  await page.waitForSelector('anotoki-site-status h1', { timeout: 15000 });
  await page.waitForLoadState('networkidle');
}

/** Whether an element's own background is a dark one (the theme followed into the family's pages). */
async function darkSurface(page, selector) {
  return page.evaluate((selector) => {
    const element = document.querySelector(selector);
    const [r, g, b] = (getComputedStyle(element).backgroundColor.match(/[\d.]+/g) ?? ['255', '255', '255']).map(Number);
    return (r + g + b) / 3 < 80;
  }, selector);
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

/** What a page may ask the API for while the gate's page stands in its place: the open paths and nothing else. */
const OPEN_WHILE_WAITING = /^\/api\/(meta|site-status|translations\/[a-z-]+)$/;

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
  if (await page.locator('.menu-button').count()) {
    await page.click('.menu-button');
    await page.click('#topbar-menu [role=menuitem]:last-child');
  } else {
    await page.click('.sign-in');
  }
  await page.waitForURL((url) => url.href.startsWith(SITE) && !url.pathname.startsWith('/auth/callback'), { timeout: 15000 });
  await page.waitForSelector('.user-button, .menu-button', { timeout: 15000 });
  await page.waitForFunction(() => !!localStorage.getItem('anotoki-auth:build-analyzer'));
  await page.waitForLoadState('networkidle');
}

/** The bar's pieces: where each is, whether any two overlap, whether any leaves the screen, and the page's scroll width. */
async function barLayout(page) {
  return page.evaluate(() => {
    const pick = (selector) => [...document.querySelectorAll(selector)].filter((element) => element.getBoundingClientRect().width > 0);
    const pieces = [
      ...pick('app-top-bar app-brand'),
      ...pick('app-top-bar .topbar-link'),
      ...pick('app-top-bar .topbar-widget'),
      ...pick('app-top-bar app-language-switcher'),
      ...pick('app-top-bar app-theme-toggle'),
      ...pick('app-top-bar .user-button'),
      ...pick('app-top-bar .sign-in'),
      ...pick('app-top-bar .menu-button'),
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
    const name = document.querySelector('app-top-bar .user-name');
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
  check(layout.overlaps.length === 0 && layout.outside.length === 0 && layout.scroll <= layout.width, `${label}: the bar fits - ${describeLayout(layout)}${layout.overlaps.length ? ` OVERLAP ${layout.overlaps}` : ''}${layout.outside.length ? ` OUTSIDE ${layout.outside}` : ''}, scroll ${layout.scroll}`);
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
    const inner = document.querySelector('app-top-bar .topbar-inner');
    const style = getComputedStyle(inner);
    const gap = parseFloat(style.columnGap);
    const actions = inner.querySelector('.topbar-actions');
    const actionGap = parseFloat(getComputedStyle(actions).columnGap);
    const visible = [...actions.children].filter((child) => child.getBoundingClientRect().width > 0);
    let actionWidth = 0;
    for (const child of visible) {
      const button = child.querySelector?.('.user-button');
      if (button) {
        const name = button.querySelector('.user-name');
        // The round button alone: what it is without the name (and its gap), with the narrower right padding.
        actionWidth += button.getBoundingClientRect().width - name.getBoundingClientRect().width - parseFloat(getComputedStyle(button).columnGap) - (0.6 - 0.4) * 16;
      } else {
        actionWidth += child.getBoundingClientRect().width;
      }
    }
    actionWidth += actionGap * (visible.length - 1);
    const brand = inner.querySelector('app-brand').getBoundingClientRect().width;
    const nav = inner.querySelector('.topbar-nav')?.getBoundingClientRect().width ?? 0;
    return Math.ceil(parseFloat(style.paddingLeft) + parseFloat(style.paddingRight) + brand + gap + nav + gap + actionWidth);
  });
}

try {
  if (MEASURE) {
    step('What the row needs (the person\'s name aside), at 1600 px');
    for (const language of ['en', 'sk']) {
      const context = await newContext({ width: 1600 });
      const page = await context.newPage();
      await open(page, `/?lang=${language}`);
      console.log(`  ${language}, signed out: ${await rowNeeds(page)} px`);
      await signIn(page, ADA);
      const staff = await rowNeeds(page);
      console.log(`  ${language}, an admin: ${staff} px; with the sync spinner (40 px and a gap): ${staff + 40 + 9.6} px`);
      // The admin panel reads English whichever language was chosen: measured from both.
      await open(page, '/admin/sources');
      const area = await rowNeeds(page);
      console.log(`  the admin panel (English, every section), an admin who chose ${language}: ${area} px; with the sync spinner: ${area + 40 + 9.6} px`);
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
    check((await page.locator('.topbar-link').allInnerTexts()).join('|') === 'Dashboard|Import|Builds', 'the bar links Dashboard, Import, Builds');
    check((await page.locator('app-top-bar .sign-in').innerText()).includes('Sign in'), 'Sign in in the bar');
    check((await page.locator('app-footer').innerText()).includes(`Version ${version}`), `the footer says Version ${version}`);
    await page.click('app-footer .whats-new');
    check(await page.locator('mat-dialog-container').isVisible(), "the footer's What's new opens the notes");
    await shot(page, '01-nobody-whats-new');
    await page.keyboard.press('Escape');
    await shot(page, '02-nobody-dashboard-1280-light', { fullPage: true });

    // ── A visitor's first import ───────────────────────────────────────────────────────────────────────
    step("A visitor: the first import, its key, its notice");
    const visitor = await newContext();
    const tab = await visitor.newPage();
    await open(tab, '/import?lang=en');
    check((await tab.locator('app-visitor-notice').count()) === 1, 'the notice is on the import page before the first import');
    await tab.locator('button.choice').first().click();
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
    check((await second.locator('.account-grid .account-main').count()) === 1, 'it sees the same account (the key is the browser\'s)');
    await second.close();

    step('Two tabs that import first, one after the other: the first key stays');
    const twins = await newContext();
    const [one, two] = [await twins.newPage(), await twins.newPage()];
    for (const page of [one, two]) {
      await open(page, '/import?lang=en');
    }
    const keys = [];
    for (const page of [one, two]) {
      await page.locator('button.choice').first().click();
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
    step('Signing in moves what the visitor imported into the person\'s account');
    await signIn(tab, MONA);
    const snack = await tab.locator('mat-snack-bar-container').innerText({ timeout: 10000 }).catch(() => '');
    check(snack.includes('now in your anotoki account'), `the snack bar says so: "${snack.replace(/\s+/g, ' ').trim()}"`);
    check((await tab.evaluate(() => localStorage.getItem('build-analyzer-visitor'))) === null, 'the key is gone from the browser');
    check(sql('SELECT count(*) AS n FROM users WHERE visitor_key_hash = ?', [hash])[0].n == 0, 'the visitor row is gone');
    check(sql('SELECT count(*) AS n FROM accounts WHERE user_id = 7')[0].n == 1, 'the account is mona\'s now');
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
          check((await tab.locator('.menu-button').count()) === (width <= PHONE_BAR_MAX ? 1 : 0), `${width} px: ${width <= PHONE_BAR_MAX ? "a phone's bar" : "a desktop's bar"}`);
          if (width === 1280 && scheme === 'light') {
            // Label in Name (WCAG 2.5.3): the code the button shows is in its accessible name - "click SK".
            const chooser = tab.locator('app-top-bar app-language-switcher .switcher');
            const shown = (await chooser.innerText()).trim();
            const name = await chooser.getAttribute('aria-label');
            check(shown === language.toUpperCase() && name === (language === 'sk' ? 'Jazyk: Slovenčina (SK)' : 'Language: English (EN)'), `${language}: the language button shows ${shown}, and its name says it too - ${name}`);
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
    await tab.click('.menu-button');
    await tab.waitForSelector('#topbar-menu');
    // Each item's words, without its icons' ligature names.
    const items = await tab.evaluate(() =>
      [...document.querySelectorAll('#topbar-menu [role=menuitem]')].map((item) => {
        const copy = item.cloneNode(true);
        copy.querySelectorAll('mat-icon').forEach((icon) => icon.remove());
        return copy.textContent.replace(/\s+/g, ' ').trim();
      }),
    );
    check(items.join('|') === 'Prehľad|Import|Buildy|Tvoj anotoki účet|Odhlásiť sa', `the menu: ${items.join(' | ')}`);
    check(await tab.evaluate(() => document.activeElement?.getAttribute('role') === 'menuitem'), 'the focus is on its first item');
    await shot(tab, '07-phone-menu-390-sk', { fullPage: false });
    await tab.keyboard.press('ArrowDown');
    await tab.keyboard.press('Tab');
    await tab.waitForTimeout(200);
    check((await tab.locator('#topbar-menu').count()) === 0, 'Tab closed it');
    check(await tab.evaluate(() => document.activeElement?.classList.contains('menu-button')), `the focus is on the menu button (${await tab.evaluate(() => document.activeElement?.className || document.activeElement?.tagName)})`);
    await tab.keyboard.press('Enter');
    await tab.waitForSelector('#topbar-menu');
    await tab.keyboard.press('Shift+Tab');
    await tab.waitForTimeout(200);
    check(await tab.evaluate(() => document.activeElement?.classList.contains('menu-button')), 'Shift+Tab: the same');

    step('The person\'s menu at 1280 px, and a real Tab press out of it');
    await tab.setViewportSize({ width: 1280, height: 800 });
    await open(tab, '/?lang=en');
    await tab.click('.user-button');
    await tab.waitForSelector('#user-menu');
    check((await tab.locator('#user-menu .menu-head').innerText()).includes('mona@example.test'), 'name and email at its head; no role pill for a person without one');
    await shot(tab, '08-account-menu-1280', { clip: { x: 0, y: 0, width: 1280, height: 400 } });
    await tab.keyboard.press('End');
    await tab.keyboard.press('Tab');
    await tab.waitForTimeout(200);
    check((await tab.locator('#user-menu').count()) === 0 && (await tab.evaluate(() => document.activeElement?.classList.contains('user-button'))), 'Tab from the last item: closed, the focus on the person\'s button, never <body>');

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

    // ── The account's preferences ──────────────────────────────────────────────────────────────────────
    step("The account's theme, dates and language (an IAM with its migration 015): the account's once signed in, the toggle and the menu save to it");
    {
      const keeper = await newContext({ width: 390, height: 800 });
      const kept = await keeper.newPage();
      const theme = () => kept.evaluate(() => (document.documentElement.classList.contains('theme-dark') ? 'dark' : document.documentElement.classList.contains('theme-light') ? 'light' : 'system'));
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
      check((await theme()) === 'system', "a visitor: this device's theme, as the device is set");
      await signIn(kept, PIA);
      check((await theme()) === 'dark', "signed in: the account's dark theme, though this device had none");
      check((await kept.evaluate(() => localStorage.getItem('build-analyzer-theme'))) === 'dark', 'kept on this device for the next page load');

      await kept.click('app-footer .whats-new');
      // The dialog's frame comes before its releases: wait for their dates, not only the container.
      await kept.waitForSelector('mat-dialog-container .release-head .muted');
      const days = (await kept.locator('mat-dialog-container .release-head .muted').allInnerTexts()).map((day) => day.trim());
      check(days.length > 0 && days.every((day) => /^\d{4}-\d{2}-\d{2}$/.test(day)), `What's new: the dates in the account's order - ${days.slice(0, 3).join(', ')}`);
      await shot(kept, '08a-account-whats-new-390-dark');
      await kept.keyboard.press('Escape');
      await kept.waitForSelector('mat-dialog-container', { state: 'detached' });

      await kept.click('app-theme-toggle [role=radio] >> nth=0');
      check((await theme()) === 'light', 'the toggle: light at once');
      const lit = await account((user) => user?.preferences?.theme === 'light');
      check(lit?.preferences?.theme === 'light', "...and saved to the account, across origins (POST /api/oidc/preferences at the stand-in IAM)");

      await kept.click('app-language-switcher .switcher');
      await kept.click('#language-menu [role=menuitemradio][lang=sk]');
      await kept.waitForFunction(() => document.documentElement.lang === 'sk', null, { timeout: 10000 });
      const spoken = await account((user) => user?.locale === 'sk');
      check(spoken?.locale === 'sk', 'the language menu: Slovak on the page, and saved to the account');
      // What's new gave the focus back to the footer's button when it closed, which scrolled the page there.
      await kept.evaluate(() => window.scrollTo(0, 0));
      await shot(kept, '08b-account-preferences-390-sk', { fullPage: true });

      await kept.reload();
      await kept.waitForSelector('app-top-bar .topbar', { timeout: 15000 });
      await kept.waitForLoadState('networkidle');
      check((await theme()) === 'light' && (await kept.evaluate(() => document.documentElement.lang)) === 'sk', 'after a reload: light and Slovak, as the account now says');

      // The IAM out of reach: the choice shows, the save fails, the account's theme comes back - and a snack bar says why.
      await iamState({ down: true });
      await kept.click('app-theme-toggle [role=radio] >> nth=1');
      const snack = (await kept.locator('mat-snack-bar-container').innerText({ timeout: 10000 }).catch(() => '')).replace(/\s+/g, ' ').trim();
      await iamState({ down: false });
      check(snack.length > 0 && (await theme()) === 'light', `a save the IAM could not take: the account's theme is back, and the snack bar says "${snack}"`);
      check((await account(() => true))?.preferences?.theme === 'light', 'the account still says light');
      await keeper.close();
    }

    // ── The admin panel ────────────────────────────────────────────────────────────────────────────────
    step('The admin panel: a person without a role, an editor, an admin - English, whatever the reader reads');
    await tab.setViewportSize({ width: 1280, height: 800 });
    await open(tab, '/admin?lang=sk');
    check((await tab.locator('h1').innerText()) === 'This part is for editors and admins', 'mona (no role): the card');
    check((await tab.locator('.topbar-link').count()) === 0, 'no admin sections in her bar');

    const editor = await newContext();
    const ed = await editor.newPage();
    await open(ed, '/?lang=sk');
    await signIn(ed, EDDIE);
    await open(ed, '/admin');
    check((await ed.locator('.topbar-link').allInnerTexts()).join('|') === 'Sources|Translations', 'an editor\'s sections: Sources, Translations');
    check((await ed.evaluate(() => document.documentElement.lang)) === 'en', '<html lang="en"> in the admin panel');
    check((await ed.locator('app-language-switcher').count()) === 0, 'no language chooser in the admin panel');
    await ed.click('.user-button');
    check((await ed.locator('#user-menu').innerText()).includes('Editor') && (await ed.locator('#user-menu').innerText()).includes('Back to Build Analyzer'), 'the role pill "Editor", "Back to Build Analyzer"');
    await shot(ed, '09-admin-editor-sources', { fullPage: true });
    await ed.keyboard.press('Escape');
    await open(ed, '/admin/languages');
    check(new URL(ed.url()).pathname === '/admin/sources', 'Languages sends an editor to Sources');
    await open(ed, '/admin/people');
    check(new URL(ed.url()).pathname === '/admin/sources', 'People sends an editor to Sources');
    await open(ed, '/admin/migrations');
    check(new URL(ed.url()).pathname === '/admin/sources', 'Migrations sends an editor to Sources');
    const refused = await ed.evaluate(async () => {
      const token = JSON.parse(localStorage.getItem('anotoki-auth:build-analyzer') ?? '{}').access_token ?? JSON.parse(localStorage.getItem('anotoki-auth:build-analyzer') ?? '{}').tokens?.access_token;
      const people = await fetch('/api/admin/people', { headers: { Authorization: `Bearer ${token}` } });
      const migrations = await fetch('/api/admin/migrations', { headers: { Authorization: `Bearer ${token}` } });
      return [people.status, (await people.json()).code, migrations.status];
    });
    check(refused[0] === 403 && refused[1] === 'admin_only' && refused[2] === 403, `the server refuses an editor ADMIN's routes (${refused.join(', ')})`);

    const admin = await newContext();
    const ad = await admin.newPage();
    await open(ad, '/?lang=en');
    await signIn(ad, ADA);
    await ad.click('.user-button');
    await ad.waitForSelector('#user-menu [role=menuitem]');
    const adminItems = (await ad.locator('#user-menu [role=menuitem]').allInnerTexts()).map((item) => item.replace(/\s+/g, ' ').trim());
    check(adminItems.some((item) => item.endsWith('Admin panel')), `the person's menu leads into the "Admin panel" (${adminItems.join(' | ')})`);
    await ad.keyboard.press('Escape');
    await open(ad, '/admin/people');
    check((await ad.locator('.topbar-link').allInnerTexts()).join('|') === 'Sources|Translations|Languages|People|Migrations', "an admin's sections: all five");
    // As the IAM's: "anotoki admin panel" beside the mark, the app's name in the link's accessible name.
    check((await ad.locator('app-top-bar app-brand .wordmark').innerText()).replace(/\s+/g, ' ').trim() === 'anotoki admin panel', 'the brand says "anotoki admin panel"');
    check((await ad.locator('app-top-bar app-brand a').getAttribute('aria-label')) === 'anotoki Build Analyzer: the admin panel', 'its link is named "anotoki Build Analyzer: the admin panel"');
    check((await ad.title()) === 'People · Admin panel · Build Analyzer', `the tab's title: ${await ad.title()}`);
    const people = await ad.locator('main').innerText();
    check(people.includes('mona') && /visitor/i.test(people), 'People lists mona, and the visitors apart');
    await shot(ad, '10-admin-people', { fullPage: true });

    step(`The admin panel's bar at 390, ${ADMIN_PHONE_BAR_MAX}, ${ADMIN_PHONE_BAR_MAX + 1}, 1024 and 1280 px, light and dark`);
    for (const scheme of ['light', 'dark']) {
      for (const width of [390, ADMIN_PHONE_BAR_MAX, ADMIN_PHONE_BAR_MAX + 1, 1024, 1280]) {
        await ad.setViewportSize({ width, height: 760 });
        await ad.emulateMedia({ colorScheme: scheme });
        await open(ad, '/admin/migrations');
        const layout = await checkBar(ad, `admin panel, ${width} px, ${scheme}`);
        check((await ad.locator('.menu-button').count()) === (width <= ADMIN_PHONE_BAR_MAX ? 1 : 0), `admin panel, ${width} px: ${width <= ADMIN_PHONE_BAR_MAX ? "a phone's bar" : "a desktop's bar"}`);
        if (width === 1280) {
          check(layout.nameCut === false, `admin panel, 1280 px: the person's name shows, whole (${layout.nameCut})`);
        }
        await shot(ad, `admin-bar-${width}-${scheme}`, { clip: { x: 0, y: 0, width, height: 120 } });
      }
    }
    await ad.emulateMedia({ colorScheme: 'light' });

    // ── The site's words: the Translations and Languages pages (anotoki-lib's, drawn with its kit) ──────
    step("Translations (EDITOR and ADMIN) and Languages (ADMIN): the anotoki library's pages in the admin panel - a string reworded and read back, light and dark, 1280 and 390 px");
    {
      const box = (key, language = 'Slovak') => ad.locator(`textarea[aria-label="${language}: ${key}"]`);
      const bundle = async (code) => (await (await fetch(`${SITE}/api/translations/${code}`)).json()).values;
      await ad.setViewportSize({ width: 1280, height: 900 });
      await open(ad, '/admin/translations');
      await ad.waitForSelector('anotoki-translations-page textarea', { timeout: 15000 });
      check((await ad.locator('anotoki-translations-page h1').innerText()).trim() === 'Translations', 'the page: Translations, in the admin panel\'s frame');
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

      for (const [scheme, width] of [['dark', 1280], ['light', 390], ['dark', 390]]) {
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

      for (const [scheme, width] of [['light', 1280], ['dark', 1280], ['light', 390], ['dark', 390]]) {
        await ad.setViewportSize({ width, height: 900 });
        await ad.emulateMedia({ colorScheme: scheme });
        await open(ad, '/admin/languages');
        await ad.waitForSelector('anotoki-languages-page [data-language]', { timeout: 15000 });
        if (width === 1280 && scheme === 'light') {
          const rows = await ad.locator('[data-language]').evaluateAll((list) => list.map((row) => `${row.dataset.language}: ${[...row.querySelectorAll('anotoki-badge')].map((badge) => badge.textContent.trim()).join(', ')}`));
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
        check(shown.length === 0 && (await tab.evaluate(() => document.documentElement.lang)) === 'sk', `/builds/genshin, ${width} px, dark: Slovak, no keys${shown.length ? ` - ${shown.join(', ')}` : ''}`);
        await shot(tab, `sk-${width}-dark-builds`, { fullPage: true });
      }
      await tab.emulateMedia({ colorScheme: 'light' });
    }

    // ── The Migrations page, and the site while an update waits ────────────────────────────────────────
    step("Migrations (ADMIN): the family's page - every file applied, nothing waiting, a file's SQL");
    await ad.setViewportSize({ width: 1280, height: 900 });
    await open(ad, '/admin/migrations');
    await ad.waitForSelector('anotoki-migrations-page [data-applied]');
    check((await ad.locator('h1').innerText()) === 'Migrations', 'the page: Migrations');
    check((await ad.title()) === 'Migrations · Admin panel · Build Analyzer', `its title: ${await ad.title()}`);
    check((await ad.locator('[data-pending]').innerText()).includes('Nothing pending - the database is up to date with the files.'), 'nothing pending on a database the migrations built');
    // Two sets - the site's own, then the anotoki library's translations - so each file is named with its set.
    const appliedFiles = await ad.locator('[data-applied] .anotoki-migrations-page__files code').allInnerTexts();
    const sqlFiles = (folder) => readdirSync(folder).filter((file) => file.endsWith('.sql')).sort();
    const migrationFiles = [
      ...sqlFiles(join(backend, 'migrations')).map((file) => `build_analyzer/${file}`),
      ...sqlFiles(join(backend, 'vendor/anotoki/lib/php/migrations/translations')).map((file) => `anotoki_translations/${file}`),
    ];
    check(appliedFiles.join('|') === migrationFiles.join('|'), `every file applied, in order - the library's last: ${appliedFiles.join(', ')}`);
    check(await ad.locator('[data-apply]').isDisabled(), 'Apply pending cannot be pressed with nothing pending');
    await ad.locator('[data-applied] li', { hasText: '005_' }).getByRole('button', { name: /^View/ }).click();
    await ad.waitForSelector('anotoki-dialog[data-sql] pre');
    check((await ad.locator('anotoki-dialog[data-sql] pre').innerText()).includes("DELETE FROM translation_keys"), 'View shows a file\'s SQL (005)');
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
      check(paths.every((path) => OPEN_WHILE_WAITING.test(path)), `no dashboard of hers made behind it: the API asked for ${paths.join(', ')} only`);
      await shot(tab, '12c-update-waiting-member', { fullPage: true });

      step('An update waits: the ADMIN reads that a database update is waiting, and reaches the Migrations page - the one page that shows');
      apiPaths = apiAsked(ad);
      await openGated(ad, '/admin/sources');
      gate = await gatePage(ad);
      check(gate.title === 'A database update is waiting' && gate.links.join('|') === 'Open Migrations -> /admin/migrations', `the ADMIN: "${gate.text}" (${gate.links.join(', ')})`);
      paths = apiPaths();
      check(paths.every((path) => OPEN_WHILE_WAITING.test(path)), `no Sources page made behind it: the API asked for ${paths.join(', ')} only`);
      await shot(ad, '12d-update-waiting-admin', { fullPage: true });
      await ad.click('anotoki-site-status a');
      await ad.waitForURL('**/admin/migrations');
      await ad.waitForSelector('[data-pending] .anotoki-migrations-page__files');
      // With two sets the Pending card heads each set's files with the set (migrations/<set>/), the file by its name.
      const pendingSets = await ad.locator('[data-pending] .anotoki-migrations-page__set').allInnerTexts();
      const pendingFiles = (await ad.locator('[data-pending] .anotoki-migrations-page__files li').allInnerTexts()).map((line) => line.replace(/\s+/g, ' ').trim());
      check(pendingSets.join('|') === 'migrations/build_analyzer/' && pendingFiles.join('|') === '004_visitors_and_family_bar.sql pending', `Migrations: 004 pending, under its set (${pendingSets.join(', ')} ${pendingFiles.join(', ')})`);
      check((await ad.locator('app-top-bar .topbar').count()) === 1, 'in the admin panel\'s frame');
      await shot(ad, '13-admin-migrations-pending', { fullPage: true });

      step('An update waits: a signed-out ADMIN presses Sign in on the waiting page and comes back to it - now the ADMIN\'s');
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
      check(outcome.includes('Stopped at build_analyzer/004_visitors_and_family_bar.sql') && outcome.includes('Nothing was applied.') && outcome.includes('rolled back as a whole') && /already exists/.test(outcome), `the failure, named: ${outcome.replace(/\s+/g, ' ').slice(0, 160)}`);
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
    await guest.waitForSelector('app-top-bar .topbar', { timeout: 45000 });
    check((await guest.locator('anotoki-site-status').count()) === 0, 'the visitor\'s page came back by itself');
    await guests.close();
    await ad.setViewportSize({ width: 1280, height: 900 });
    await open(ad, '/admin/migrations');
    await ad.waitForSelector('anotoki-migrations-page [data-pending]');
    check((await ad.locator('[data-pending]').innerText()).includes('Nothing pending'), 'the database as it was: nothing pending');
    await open(tab, '/');
    check((await tab.locator('anotoki-site-status').count()) === 0, 'mona\'s dashboard again');

    // ── The served path ────────────────────────────────────────────────────────────────────────────────
    step('Served by router.php: X-Visitor-Key passes, and a sweep runs after an answer');
    const made = await fetch(`${SITE}/api/imports/file`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ content: GOOD, file_name: 'good.json' }) });
    const servedKey = made.headers.get('x-visitor-key');
    check(made.status === 201 && /^[A-Za-z0-9_-]{43}$/.test(servedKey ?? ''), 'an import with no sign-in answers its key in X-Visitor-Key');
    const mine = await fetch(`${SITE}/api/accounts`, { headers: { 'X-Visitor-Key': servedKey } });
    check(mine.status === 200 && (await mine.json()).length === 1, 'the key sent back in X-Visitor-Key opens that visitor\'s accounts');
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
    step('Sign in pressed while a visitor\'s first import is on its way: it waits for the key, which then moves');
    const hurry = await newContext();
    const rushed = await hurry.newPage();
    await open(rushed, '/import?lang=en');
    await rushed.locator('button.choice').first().click();
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
    await rushed.click('.sign-in');
    const rushedKey = (await rushedAnswer).headers()['x-visitor-key'] ?? '';
    await rushed.waitForURL((url) => url.href.startsWith(SITE) && !url.pathname.startsWith('/auth/callback'), { timeout: 15000 });
    await rushed.waitForSelector('.user-button', { timeout: 15000 });
    await rushed.waitForLoadState('networkidle');
    await rushed.waitForTimeout(500);
    check(/^[A-Za-z0-9_-]{43}$/.test(rushedKey), 'the import made a visitor and answered its key');
    check(sql('SELECT count(*) AS n FROM accounts WHERE user_id = 10')[0].n == 1, 'after the sign-in the account is sam\'s: the key was kept before the page left, and moved');
    check(sql('SELECT count(*) AS n FROM users WHERE visitor_key_hash = ?', [createHash('sha256').update(rushedKey).digest('hex')])[0].n == 0, 'that visitor row is gone');
    await hurry.close();

    // ── A refused sign-in while visiting, and the admin panel ──────────────────────────────────────────
    step('A sign-in the IAM refuses while visiting (MEMBER taken away) is said; visiting goes on; /admin is sign-in only');
    await iamState({ deny: true });
    const turned = await newContext();
    const away = await turned.newPage();
    await open(away, '/builds/genshin?lang=en');
    await away.click('.sign-in');
    await away.waitForURL((url) => url.href.startsWith(SITE) && url.pathname === '/sign-in', { timeout: 15000 });
    await away.waitForSelector('.notice[role=alert]');
    const said = await away.locator('.notice[role=alert]').innerText();
    check(said.includes('The sign-in was not completed'), `back from the IAM: the sign-in page says so ("${said.slice(0, 60)}...")`);
    check((new URL(away.url()).searchParams.get('returnTo') ?? '').startsWith('/builds/genshin'), 'with the page asked for as returnTo');
    await shot(away, '17-visitor-sign-in-refused', { fullPage: true });
    await away.locator('app-top-bar .topbar-link', { hasText: 'Builds' }).click();
    await away.waitForURL('**/builds/genshin');
    await away.waitForSelector('app-builds-page, .page', { timeout: 10000 });
    check(new URL(away.url()).pathname === '/builds/genshin', 'said once: the visitor goes on to the builds');
    await away.goto(`${SITE}/admin`);
    await away.waitForURL((url) => url.href.startsWith(SITE) && url.pathname.startsWith('/admin'), { timeout: 15000 });
    await away.waitForSelector('.no-access h1');
    check((await away.locator('.no-access h1').innerText()) === 'You are not signed in', 'a visitor at /admin is sent to sign in; refused, the panel\'s card says so');
    check((await away.locator('.topbar-link').count()) === 0, 'and no section of the panel shows');
    await iamState({ deny: false });
    await turned.close();

    // ── Sign-in required ───────────────────────────────────────────────────────────────────────────────
    step('The IAM says sign-in is required: every page asks for it; the key held is kept and moves at sign-in');
    const late = await newContext();
    const lateTab = await late.newPage();
    await open(lateTab, '/import?lang=en');
    await lateTab.locator('button.choice').first().click();
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
    await lateTab.waitForSelector('.notice[role=alert]');
    check(new URL(lateTab.url()).searchParams.get('returnTo') === '/builds/genshin', 'a page load went to the IAM by itself, and came back refused to the sign-in page');
    check((await lateTab.evaluate(() => localStorage.getItem('build-analyzer-visitor'))) === lateKey, 'the key is kept while sign-in is required');
    await shot(lateTab, '18-required-refused', { fullPage: true });
    await iamState({ deny: false });

    // Then let in: back on the page asked for, and what the visitor imported is the person's.
    await iamAs(RITA);
    await lateTab.click('main button');
    await lateTab.waitForURL((url) => url.href.startsWith(SITE) && url.pathname === '/builds/genshin', { timeout: 15000 });
    await lateTab.waitForSelector('.user-button', { timeout: 15000 });
    await lateTab.waitForLoadState('networkidle');
    await lateTab.waitForTimeout(500);
    check(sql('SELECT count(*) AS n FROM accounts WHERE user_id = 11')[0].n == 1, 'signed in, back on /builds/genshin: the visitor\'s account is rita\'s (the move works while sign-in is required)');
    check((await lateTab.evaluate(() => localStorage.getItem('build-analyzer-visitor'))) === null, 'and the key is gone');
    await shot(lateTab, '19-required-signed-in', { fullPage: true });
    await late.close();

    const stranger = await newContext();
    const strangerTab = await stranger.newPage();
    await iamState({ deny: true });
    await strangerTab.goto(`${SITE}/`);
    await strangerTab.waitForURL((url) => url.href.startsWith(SITE) && url.pathname === '/sign-in', { timeout: 15000 });
    check((await strangerTab.locator('app-visitor-hero').count()) === 0, 'somebody new never sees the visitors\' dashboard');
    await iamState({ deny: false });
    await stranger.close();

    step('Sign-in no longer required: visitors are welcome again');
    await iamState({ login_required: false });
    forgetSwitch();
    const again = await newContext();
    const againTab = await again.newPage();
    await open(againTab, '/?lang=en');
    check(new URL(againTab.url()).pathname === '/' && (await againTab.locator('app-visitor-hero').count()) === 1, 'the dashboard\'s hero, no sign-in asked for');
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
      check(gate.title === 'This site is not set up yet' && gate.links.join('|') === 'Open the setup page -> /setup.php' && gate.buttons.join('|') === 'Try again',
        `never installed: "${gate.text}" (${gate.links.join(', ')})`);
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
  }
} catch (error) {
  failures.push(String(error?.stack ?? error));
  console.error(error);
} finally {
  await browser.close();
}

console.log(failures.length === 0 ? '\nThe browser check passed.' : `\n${failures.length} FAILED:\n- ${failures.join('\n- ')}`);
process.exit(failures.length === 0 ? 0 : 1);
