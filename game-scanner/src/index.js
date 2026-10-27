import { execFile, execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { uIOhook, UiohookKey } from 'uiohook-napi';
import { fetchNames } from './catalog.js';
import { SETTINGS } from './config.js';
import { createOcr } from './good/ocr.js';
import { closeLog, log, logToConsole, openLogFile } from './log.js';
import { REAL_IO } from './scanner.js';
import { Session } from './session.js';
import { createConsoleUi } from './ui.js';

// npm start [-- --keep-images]
// The program (session.js). The PowerShell script that downloads it runs it with --portable --work=<folder>:
// the scans and the log go into that folder, the GOOD file into Downloads, and all of it is deleted at the end.
// --keep-images also writes every screenshot (to read them again with `npm run good -- --fresh`).

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const option = (name) => args.find((a) => a.startsWith(`--${name}=`))?.slice(name.length + 3);
const portable = args.includes('--portable');
const work = path.resolve(option('work') ?? root);
const downloads = path.join(os.homedir(), 'Downloads');
const version = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8')).version;

openLogFile(path.join(work, 'logs'));
logToConsole(false); // the session talks to the player; the log goes to its file
log.debug(`Version ${version}, ${portable ? 'portable' : 'development'} run, work folder ${work}`);

// Ctrl+S, from anywhere (the game has the focus). Holding the keys repeats keydown events, so S has to be
// let go before the next press counts.
function createHotkey() {
  const listeners = new Set();
  let sHeld = false;
  uIOhook.on('keydown', (e) => {
    if (e.keycode !== UiohookKey.S || sHeld) return;
    sHeld = true;
    if (e.ctrlKey && !e.altKey && !e.shiftKey && !e.metaKey) for (const fn of [...listeners]) fn();
  });
  uIOhook.on('keyup', (e) => {
    if (e.keycode === UiohookKey.S) sHeld = false;
  });
  uIOhook.start();
  return {
    label: SETTINGS.hotkeyLabel,
    on(fn) {
      listeners.add(fn);
      return () => listeners.delete(fn);
    },
  };
}

function isAdmin() {
  if (process.platform !== 'win32') return true;
  try {
    execFileSync('net', ['session'], { stdio: 'ignore' });
    return true;
  } catch {
    return false;
  }
}

function run(file, fileArgs, options = {}) {
  return new Promise((resolve, reject) => execFile(file, fileArgs, { windowsHide: true, ...options }, (error) => (error ? reject(error) : resolve())));
}

// The text onto the clipboard: PowerShell's Set-Clipboard (any characters), else clip.exe.
async function copyToClipboard(text) {
  const file = path.join(work, 'clipboard.json');
  fs.writeFileSync(file, text);
  try {
    await run('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', 'Set-Clipboard -Value ([IO.File]::ReadAllText($env:ANOTOKI_CLIPBOARD))'], {
      env: { ...process.env, ANOTOKI_CLIPBOARD: file },
    });
    return true;
  } catch (e) {
    log.warn(`Set-Clipboard failed: ${e.message}`);
    return new Promise((resolve) => {
      const clip = spawn('clip.exe', [], { windowsHide: true });
      clip.on('error', () => resolve(false));
      clip.on('close', (code) => resolve(code === 0));
      clip.stdin.end(text);
    });
  } finally {
    fs.rmSync(file, { force: true });
  }
}

// The page in the default browser (explorer hands it over; it answers 1 even when that worked).
const openUrl = (url) => run('explorer.exe', [url]).catch(() => {});

const ui = createConsoleUi();
const hotkey = createHotkey();
const session = new Session({
  ui,
  io: REAL_IO,
  hotkey,
  createOcr,
  fetchNames: () => fetchNames(),
  isAdmin,
  clipboard: copyToClipboard,
  openUrl,
  paths: portable ? { scans: path.join(work, 'scans'), output: fs.existsSync(downloads) ? downloads : work, work } : { scans: path.join(root, 'scans'), output: path.join(root, 'exports') },
  portable,
  keepImages: args.includes('--keep-images'),
  version,
  closeLog,
});

// Ctrl+C: out at once (in a portable run the GOOD file goes too; the script deletes the rest).
const quit = () => {
  if (portable && session.goodFile) fs.rmSync(session.goodFile, { force: true });
  uIOhook.stop();
  ui.close();
  setTimeout(() => process.exit(1), 300);
};
process.on('SIGINT', quit);
process.on('SIGTERM', quit);

let code = 1;
try {
  code = await session.run();
} catch (e) {
  log.error(`The program crashed: ${e.stack || e}`);
  ui.error(`Something went wrong: ${e.message}`);
  if (!portable) ui.print(`The log file says more: ${log.file}`);
  await ui.waitKey('Press any key to exit.').catch(() => {});
}
uIOhook.stop();
ui.close();
process.exit(code);
