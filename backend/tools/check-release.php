<?php

declare(strict_types=1);

/**
 * The built app names no other website, service or tool (docs/architecture.md: the UI is neutral): every file at
 * the top of the app's folder (html, js, css, json, txt) through App\Support\Neutral::namesIn(). The backend's own
 * code may name them - it is never served. Run by tools/check-release.mjs after the frontend's build, which
 * anotoki-deploy runs (composer.json, extra["anotoki-deploy"].frontendBuild); a release that names one is refused.
 *
 *   php tools/check-release.php <the app's folder>
 */

require __DIR__ . '/../vendor/autoload.php';

$app = $argv[1] ?? '';
if ($app === '' || !is_file($app . '/index.html')) {
    fwrite(STDERR, "check-release: no built app at '{$app}'.\n");
    exit(1);
}

$problems = [];
foreach (scandir($app) ?: [] as $name) {
    if (preg_match('/\.(html|js|css|json|txt)$/', $name) === 1 && is_file("{$app}/{$name}")) {
        $names = App\Support\Neutral::namesIn((string) file_get_contents("{$app}/{$name}"));
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
