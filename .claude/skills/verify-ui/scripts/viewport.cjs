#!/usr/bin/env node
// True-viewport screenshot (phone widths) with the installed Edge through playwright-core. screenshot.sh
// cannot do this: headless Edge keeps a minimum window width of about 500 px and only crops narrower captures.
//
//   npm i --prefix "$TMPDIR/pw" playwright-core            # once, outside the repository
//   NODE_PATH="$TMPDIR/pw/node_modules" node .claude/skills/verify-ui/scripts/viewport.cjs URL OUT.png [WIDTH=390] [HEIGHT=844]
//
// Takes a full-page screenshot in dark mode and prints the page's scroll width (larger than WIDTH means a
// horizontal page scroll) and any console errors. Uses a throwaway browser context, never the user's profile.
// The context has seen the current version, so "What's new" does not open over the page.
const { chromium } = require('playwright-core');

const [, , url, out, width = '390', height = '844'] = process.argv;
if (!url || !out) {
  console.error('usage: viewport.cjs URL OUT.png [WIDTH] [HEIGHT]');
  process.exit(2);
}

/** The newest version in the app's changelog.json, or null. */
async function currentVersion() {
  try {
    const entries = await (await fetch(new URL('/changelog.json', url))).json();
    return entries[0]?.version ?? null;
  } catch {
    return null;
  }
}

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const context = await browser.newContext({ viewport: { width: +width, height: +height }, colorScheme: 'dark' });
  const version = await currentVersion();
  if (version) {
    await context.addInitScript((seen) => localStorage.setItem('build-analyzer-version-seen', seen), version);
  }
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => message.type() === 'error' && errors.push(message.text()));

  await page.goto(url, { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
  await page.screenshot({ path: out, fullPage: true });
  await browser.close();

  console.log(`saved ${out}: viewport ${width} px, page scroll width ${scrollWidth} px${scrollWidth > +width ? ' (HORIZONTAL SCROLL)' : ''}`);
  for (const error of errors) {
    console.log(`console error: ${error}`);
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
