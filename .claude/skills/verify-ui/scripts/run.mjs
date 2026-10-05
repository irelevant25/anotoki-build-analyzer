// The whole browser check in one go, from the repository's root:
//
//   node .claude/skills/verify-ui/scripts/run.mjs [--no-build] [--keep] [--measure]
//   SITE_PORT=8593 IAM_PORT=8596 node .claude/skills/verify-ui/scripts/run.mjs ...   # other throwaway ports
//
// It builds the frontend (unless --no-build), makes a throwaway database (build_analyzer_test_browser, from
// the migrations, with a small catalog and one build - backend/tests/tools/browser-db.php), starts the
// stand-in IAM (backend/tests/tools/fake-iam.php) on 8597 and the site on 8590 - or on IAM_PORT and
// SITE_PORT, each in 8590-8599, when another check holds those (router.php: the built app
// and the API on one origin, as on the hosting, with BUILD_ANALYZER_TEST_CONFIG pointing at the throwaway
// settings), drives headless Edge through playwright-core (flow.mjs), and then stops all of it and drops the
// database - also when the check fails. --keep leaves the work folder (settings, logs, screenshots, the
// downloaded export) behind to look at; the screenshots are there while it runs. --measure only prints
// what the top bar's row needs, in English and in Slovak (how PHONE_BAR_MAX was chosen).
//
// playwright-core is installed once into <temp>/pw (never into the repository): `npx -y npm@11 install
// --prefix "<temp>/pw" playwright-core` - this script does it when it is missing. Nothing here touches the
// owner's database, settings, servers (4200-4700, 8000-8500), browser profile or the real IAM.

import { execFileSync, spawn } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repo = resolve(here, '../../../..');
const backend = join(repo, 'backend');
const frontend = join(repo, 'frontend');
const args = new Set(process.argv.slice(2));
const windows = process.platform === 'win32';

/** A throwaway port from the environment, or the default: never the owner's 4200-4700 or 8000-8500. */
function port(name, fallback) {
  const value = Number(process.env[name] ?? fallback);
  if (!Number.isInteger(value) || value < 8590 || value > 8599) {
    throw new Error(`${name} must be a throwaway port, 8590-8599 (got ${process.env[name]}).`);
  }
  return value;
}

const SITE = port('SITE_PORT', 8590);
const IAM = port('IAM_PORT', 8597);
if (SITE === IAM) {
  throw new Error('SITE_PORT and IAM_PORT must differ.');
}
const php = process.env.PHP ?? 'php';
const pw = process.env.PW_DIR ?? join(tmpdir(), 'pw');

const work = mkdtempSync(join(tmpdir(), 'build-analyzer-browser-'));
const config = join(work, 'config');
const children = [];

function run(command, commandArgs, options = {}) {
  // npm and npx are .cmd files on Windows: batch files, which only a shell runs.
  return execFileSync(command, commandArgs, { stdio: 'inherit', shell: windows && ['npm', 'npx'].includes(command), ...options });
}

function start(command, commandArgs, options = {}) {
  const log = join(work, `${options.name}.log`);
  const child = spawn(command, commandArgs, { stdio: ['ignore', 'ignore', 'pipe'], ...options });
  child.stderr.on('data', (chunk) => writeFileSync(log, chunk, { flag: 'a' }));
  children.push(child);
  return child;
}

async function waitForUrl(url, what) {
  for (let i = 0; i < 100; i++) {
    try {
      const response = await fetch(url);
      if (response.status < 500) {
        return;
      }
    } catch {
      // not up yet
    }
    await new Promise((done) => setTimeout(done, 150));
  }
  throw new Error(`${what} did not start (${url}).`);
}

async function portFree(port) {
  try {
    await fetch(`http://127.0.0.1:${port}/`, { signal: AbortSignal.timeout(500) });
    return false;
  } catch {
    return true;
  }
}

function stopAll() {
  for (const child of children) {
    if (child.exitCode === null) {
      // On Windows a process's children go only with /T.
      if (windows) {
        try {
          execFileSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], { stdio: 'ignore' });
        } catch {
          // already gone
        }
      } else {
        child.kill('SIGTERM');
      }
    }
  }
}

let code = 1;
try {
  for (const port of [SITE, IAM]) {
    if (!(await portFree(port))) {
      throw new Error(`Port ${port} is in use: another check, or a server of somebody else's - look before stopping anything.`);
    }
  }
  if (!existsSync(join(pw, 'node_modules', 'playwright-core'))) {
    mkdirSync(pw, { recursive: true });
    run('npx', ['-y', 'npm@11', 'install', '--prefix', pw, 'playwright-core']);
  }
  if (!args.has('--no-build')) {
    run('npx', ['ng', 'build'], { cwd: frontend });
  }

  // The throwaway database and the settings that point at it and at the stand-in IAM.
  run(php, ['tests/tools/browser-db.php', 'make', config, `http://127.0.0.1:${IAM}`], { cwd: backend });
  const iamDir = join(work, 'fake-iam');
  mkdirSync(iamDir, { recursive: true });
  run(php, ['-r', 'require "src/Support/Iam/anotoki_iam.php"; file_put_contents($argv[1] . "/key.json", json_encode(anotoki_iam_test_keypair("browser-check")));', iamDir], { cwd: backend });

  start(php, ['-S', `127.0.0.1:${IAM}`, 'tests/tools/fake-iam.php'], { name: 'fake-iam', cwd: backend, env: { ...process.env, FAKE_IAM_DIR: iamDir, FAKE_IAM_ISSUER: `http://127.0.0.1:${IAM}` } });
  start(php, ['-S', `127.0.0.1:${SITE}`, 'router.php'], { name: 'site', cwd: backend, env: { ...process.env, BUILD_ANALYZER_TEST_CONFIG: config } });
  await waitForUrl(`http://127.0.0.1:${IAM}/.well-known/openid-configuration`, 'The stand-in IAM');
  await waitForUrl(`http://127.0.0.1:${SITE}/api/meta`, 'The site');

  const shots = join(work, 'shots');
  mkdirSync(shots, { recursive: true });
  console.log(`Screenshots: ${shots}\n`);
  const flow = spawn(process.execPath, [join(here, 'flow.mjs'), `http://127.0.0.1:${SITE}`, `http://127.0.0.1:${IAM}`, work, ...(args.has('--measure') ? ['--measure'] : [])], {
    stdio: 'inherit',
    env: { ...process.env, PW_DIR: pw, PHP: php },
  });
  code = await new Promise((done) => flow.on('exit', (exitCode) => done(exitCode ?? 1)));
} catch (error) {
  console.error(error instanceof Error ? error.message : error);
} finally {
  stopAll();
  try {
    execFileSync(php, ['tests/tools/browser-db.php', 'drop', config], { cwd: backend, stdio: 'ignore' });
  } catch {
    console.error(`Could not drop the throwaway database: php tests/tools/browser-db.php drop ${config}`);
  }
  for (const port of [SITE, IAM]) {
    if (!(await portFree(port))) {
      console.error(`Port ${port} still answers after the check - look at what holds it.`);
    }
  }
  if (args.has('--keep')) {
    writeFileSync(join(work, 'README.txt'), 'Left by the browser check (--keep). Delete this folder when done: it holds a database login.\n');
    console.log(`\nKept: ${work}`);
  } else {
    await new Promise((done) => setTimeout(done, 800));
    rmSync(work, { recursive: true, force: true, maxRetries: 5, retryDelay: 300 });
  }
}
process.exit(code);
