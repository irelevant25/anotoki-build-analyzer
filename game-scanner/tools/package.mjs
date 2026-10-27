import { execFileSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// npm run package -- [--node=<node.exe> | --node-version=22.x.y] [--url=<release download address>] [--npm="npx -y npm@11"]
// Builds the release in dist/:
// - scanner-win-x64.zip: node.exe (the official build, signed by the OpenJS Foundation) with its LICENSE, and
//   app/ - this program with its production modules (npm ci), less what it never loads (the OCR engine's other
//   builds, the legacy language data);
// - scanner.ps1: the script players paste into PowerShell (from tools/scanner.ps1), with the zip's address
//   (--url + /scanner-win-x64.zip) and SHA-256 written in.
// The zip is unpacked and its node.exe runs the self-test (src/selftest.js) before anything is written to dist/.
// Windows only: it packs and unpacks with Windows' own tar.exe.

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const option = (name) => args.find((a) => a.startsWith(`--${name}=`))?.slice(name.length + 3);
const version = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8')).version;
const dist = path.join(root, 'dist');
const ZIP = 'scanner-win-x64.zip';
const url = (option('url') ?? 'https://github.com/irelevant25/anotoki-build-analyzer/releases/download/scanner').replace(/\/$/, '');
const tar = path.join(process.env.SystemRoot ?? 'C:\\Windows', 'System32', 'tar.exe');
const npm = (option('npm') ?? 'npm').split(' ');

const run = (file, fileArgs, options = {}) => execFileSync(file, fileArgs, { stdio: 'inherit', shell: process.platform === 'win32' && !file.endsWith('.exe'), ...options });
const sha256 = (file) => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');

const stage = fs.mkdtempSync(path.join(os.tmpdir(), 'scanner-package-'));
try {
  console.log(`Building the scanner ${version} in ${stage}`);
  const app = path.join(stage, 'app');
  fs.mkdirSync(app, { recursive: true });
  for (const item of ['package.json', 'package-lock.json', 'src', 'data']) fs.cpSync(path.join(root, item), path.join(app, item), { recursive: true });
  run(npm[0], [...npm.slice(1), 'ci', '--omit=dev', '--ignore-scripts', '--no-audit', '--no-fund'], { cwd: app });
  prune(app);

  await addNode(stage);
  fs.mkdirSync(dist, { recursive: true });
  const zip = path.join(dist, ZIP);
  fs.rmSync(zip, { force: true });
  run(tar, ['-a', '-c', '-f', zip, '-C', stage, 'node.exe', 'LICENSE', 'app']);
  selfTest(zip);

  const hash = sha256(zip);
  const script = fs
    .readFileSync(path.join(root, 'tools', 'scanner.ps1'), 'utf8')
    .replaceAll('{{VERSION}}', version)
    .replaceAll('{{ZIP_URL}}', `${url}/${ZIP}`)
    .replaceAll('{{ZIP_SHA256}}', hash.toUpperCase())
    .replaceAll('{{ZIP_MB}}', String(Math.ceil(fs.statSync(zip).size / 1048576)));
  if (/[^\x00-\x7f]/.test(script)) throw new Error('tools/scanner.ps1 must stay ASCII (Windows PowerShell reads a download as Latin-1)');
  fs.writeFileSync(path.join(dist, 'scanner.ps1'), script);
  console.log(`\n${zip}: ${(fs.statSync(zip).size / 1048576).toFixed(1)} MB, SHA-256 ${hash}`);
  console.log(`${path.join(dist, 'scanner.ps1')}: downloads ${url}/${ZIP}`);
} finally {
  fs.rmSync(stage, { recursive: true, force: true });
}

// What the program never loads: in Node tesseract.js loads an engine build's .js, which reads its .wasm (which
// build depends on the computer, so all stay) - never the .wasm.js copies (the same engines for browsers); and
// the language data it reads is eng/4.0.0_best_int.
function prune(app) {
  const core = path.join(app, 'node_modules', 'tesseract.js-core');
  for (const file of fs.readdirSync(core)) if (file.endsWith('.wasm.js')) fs.rmSync(path.join(core, file));
  fs.rmSync(path.join(app, 'node_modules', '@tesseract.js-data', 'eng', '4.0.0'), { recursive: true, force: true });
}

// node.exe and its LICENSE: copied from --node (and the LICENSE beside it, or the one of that version), or
// downloaded from nodejs.org for --node-version (checked against the release's SHASUMS256.txt).
async function addNode(stage) {
  const given = option('node');
  if (given) {
    fs.copyFileSync(given, path.join(stage, 'node.exe'));
    const nodeVersion = execFileSync(given, ['--version']).toString().trim();
    const license = path.join(path.dirname(given), 'LICENSE');
    if (fs.existsSync(license)) fs.copyFileSync(license, path.join(stage, 'LICENSE'));
    else fs.writeFileSync(path.join(stage, 'LICENSE'), await download(`https://raw.githubusercontent.com/nodejs/node/${nodeVersion}/LICENSE`));
    console.log(`node.exe ${nodeVersion} from ${given}`);
    return;
  }
  const nodeVersion = option('node-version');
  if (!nodeVersion) throw new Error('Give --node=<path to node.exe> or --node-version=<x.y.z>.');
  const name = `node-v${nodeVersion}-win-x64`;
  const base = `https://nodejs.org/dist/v${nodeVersion}`;
  const sums = (await download(`${base}/SHASUMS256.txt`)).toString();
  const expected = sums
    .split('\n')
    .find((line) => line.endsWith(`  ${name}.zip`))
    ?.split(' ')[0];
  if (!expected) throw new Error(`${name}.zip is not in ${base}/SHASUMS256.txt`);
  const zip = path.join(stage, `${name}.zip`);
  fs.writeFileSync(zip, await download(`${base}/${name}.zip`));
  if (sha256(zip) !== expected) throw new Error(`${name}.zip does not match its SHA-256`);
  run(tar, ['-x', '-f', zip, '-C', stage, `${name}/node.exe`, `${name}/LICENSE`]);
  for (const file of ['node.exe', 'LICENSE']) fs.renameSync(path.join(stage, name, file), path.join(stage, file));
  fs.rmSync(path.join(stage, name), { recursive: true });
  fs.rmSync(zip);
  console.log(`node.exe ${nodeVersion} from ${base}`);
}

async function download(address) {
  const response = await fetch(address);
  if (!response.ok) throw new Error(`${address}: HTTP ${response.status}`);
  return Buffer.from(await response.arrayBuffer());
}

// Unpacks the zip as the PowerShell script does and runs the self-test with its own node.exe.
function selfTest(zip) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'scanner-selftest-'));
  try {
    run(tar, ['-x', '-f', zip, '-C', dir]);
    run(path.join(dir, 'node.exe'), [path.join(dir, 'app', 'src', 'selftest.js')], { cwd: dir });
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
}
