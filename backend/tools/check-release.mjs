// Runs tools/check-release.php - the built app names no other site, service or tool - with a PHP 8.4+
// ($BUILD_ANALYZER_PHP, else php8, else php), from any shell: anotoki-deploy runs the frontend's build through the
// system's (composer.json, extra["anotoki-deploy"].frontendBuild: "npm run build && node ../backend/tools/check-release.mjs").
import { spawnSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const backend = join(dirname(fileURLToPath(import.meta.url)), '..');
const app = join(backend, '..', 'frontend', 'dist', 'frontend', 'browser');

const php = [process.env.BUILD_ANALYZER_PHP, 'php8', 'php'].filter(Boolean).find((binary) => {
  const answer = spawnSync(binary, ['-r', 'echo PHP_VERSION_ID;'], { encoding: 'utf8' });
  return answer.status === 0 && Number(answer.stdout.trim()) >= 80400;
});
if (!php) {
  console.error('check-release: no PHP 8.4+ found - set BUILD_ANALYZER_PHP to one.');
  process.exit(1);
}
const result = spawnSync(php, [join(backend, 'tools', 'check-release.php'), app], { stdio: 'inherit' });
process.exit(result.status ?? 1);
