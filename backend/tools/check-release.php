<?php

declare(strict_types=1);

/**
 * The built app names no other website, service or tool (docs/architecture.md: the UI is neutral): every file at
 * the top of the app's folder (html, js, css, json, txt) through App\Support\Neutral::namesIn(). The backend's own
 * code may name them - it is never served. Run by tools/check-release.mjs after the frontend's build, which
 * anotoki-deploy runs (composer.json, extra["anotoki-deploy"].frontendBuild); a release that names one is refused.
 *
 * One exception, the project's own game scanner (the owner's request of 2026-10-27, docs/decisions.md): the import
 * guide shows its PowerShell line and links to its README, both on the project's GitHub repository. The addresses
 * the two constants of frontend/src/app/sites/main/import/scanner.ts hold - each on that repository (REPOSITORY),
 * or the check stops - are taken out of every file before it is read; any other address or name of GitHub is
 * refused as before.
 *
 *   php tools/check-release.php <the app's folder>
 */

require __DIR__ . '/../vendor/autoload.php';

const SCANNER_FILE = __DIR__ . '/../../frontend/src/app/sites/main/import/scanner.ts';
const REPOSITORY = 'https://github.com/irelevant25/anotoki-build-analyzer/';

$app = $argv[1] ?? '';
if ($app === '' || !is_file($app . '/index.html')) {
    fwrite(STDERR, "check-release: no built app at '{$app}'.\n");
    exit(1);
}

preg_match_all("~export const SCANNER_(?:COMMAND|GUIDE)\s*=\s*'([^'\\\\]*)';~", is_file(SCANNER_FILE) ? (string) file_get_contents(SCANNER_FILE) : '', $constants);
preg_match_all('~https://[^\s\'"]+~', implode("\n", $constants[1]), $addresses);
$scanner = $addresses[0];
if (count($constants[1]) !== 2 || count($scanner) !== 2 || array_filter($scanner, fn(string $address) => !str_starts_with($address, REPOSITORY)) !== []) {
    fwrite(STDERR, "check-release: frontend/src/app/sites/main/import/scanner.ts must hold the scanner's two addresses, each on " . REPOSITORY . "\n");
    exit(1);
}

$problems = [];
foreach (scandir($app) ?: [] as $name) {
    if (preg_match('/\.(html|js|css|json|txt)$/', $name) === 1 && is_file("{$app}/{$name}")) {
        $names = App\Support\Neutral::namesIn(str_replace($scanner, '', (string) file_get_contents("{$app}/{$name}")));
        if ($names !== []) {
            $problems[] = "{$name} names " . implode(', ', $names);
        }
    }
}
if ($problems !== []) {
    fwrite(STDERR, "check-release: the app names what it must not:\n  " . implode("\n  ", $problems) . "\n");
    exit(1);
}
echo "check-release: the app names no other site, service or tool.\n";
