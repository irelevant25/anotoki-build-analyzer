import fs from 'node:fs';
import path from 'node:path';

// Console gets info and above; the log file gets everything (debug included), one file per program run.

const LEVELS = { debug: 10, info: 20, warn: 30, error: 40 };

let stream = null;
let filePath = null;
let sink = null;
let toConsole = true;

// The program talks to the player itself (session.js): its log goes to the file alone.
export function logToConsole(on) {
  toConsole = on;
}

// Tests: hand every log line to fn(level, line) instead of the console (null switches back).
export function redirectLog(fn) {
  sink = fn;
}

export function openLogFile(dir) {
  fs.mkdirSync(dir, { recursive: true });
  filePath = path.join(dir, `scanner-${stamp()}.log`);
  stream = fs.createWriteStream(filePath, { flags: 'a' });
  return filePath;
}

// Closes the log file (before its folder is deleted: Windows keeps an open file).
export function closeLog() {
  const open = stream;
  stream = null;
  return new Promise((resolve) => (open ? open.end(resolve) : resolve()));
}

export function stamp(date = new Date()) {
  const p = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${p(date.getMonth() + 1)}-${p(date.getDate())}_${p(date.getHours())}-${p(date.getMinutes())}-${p(date.getSeconds())}`;
}

function write(level, message, data) {
  const now = new Date();
  const time = `${now.toTimeString().slice(0, 8)}.${String(now.getMilliseconds()).padStart(3, '0')}`;
  const extra = data === undefined ? '' : ' ' + (typeof data === 'string' ? data : JSON.stringify(data));
  const line = `${time} ${level.toUpperCase().padEnd(5)} ${message}${extra}`;
  stream?.write(line + '\n');
  if (sink) sink(level, line);
  else if (toConsole && LEVELS[level] >= LEVELS.info) (level === 'error' ? console.error : console.log)(line);
}

export const log = {
  debug: (m, d) => write('debug', m, d),
  info: (m, d) => write('info', m, d),
  warn: (m, d) => write('warn', m, d),
  error: (m, d) => write('error', m, d),
  get file() {
    return filePath;
  },
};
