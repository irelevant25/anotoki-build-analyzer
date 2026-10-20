<?php

declare(strict_types=1);

/**
 * The repository names no site the builds come from (docs/decisions.md, 2026-10-20): every file git tracks - and
 * every file `git add -A` would add - read for the words of the owner's config/neutral.local.php: its `names` on
 * word boundaries (as App\Support\Neutral matches them) and its `tree_words` (typos, page parts, labels that would
 * give a site away) anywhere. It prints where it found one - never the file's list - and fails on any.
 *
 *   php tools/check-names.php            the working tree
 *   php tools/check-names.php --staged   what is staged (the owner's untracked .git/hooks/pre-commit runs this)
 *
 * Without the file there is nothing to look for, which it says. Binary files and files over 5 MB are skipped.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Config;

$staged = in_array('--staged', array_slice($argv, 1), true);
$root = dirname(__DIR__, 2);

$file = Config::readOptionalFile('neutral.local.php', dirname(__DIR__));
$words = static function (mixed $list): array {
    $clean = [];
    foreach (is_array($list) ? $list : [] as $word) {
        if (is_string($word) && trim($word) !== '') {
            $clean[] = strtolower(trim($word));
        }
    }

    return array_values(array_unique($clean));
};
$names = $words(is_array($file) ? $file['names'] ?? [] : []);
$treeWords = $words(is_array($file) ? $file['tree_words'] ?? [] : []);
if ($names === [] && $treeWords === []) {
    echo "check-names: config/neutral.local.php names nothing - nothing to look for.\n";
    exit(0);
}

usort($names, fn(string $a, string $b) => strlen($b) <=> strlen($a));
$namesPattern = $names === [] ? null : '~(?<![\w.-])(?:' . implode('|', array_map(fn(string $n) => preg_quote($n, '~'), $names)) . ')(?![\w-])~i';

$git = static function (string $arguments) use ($root): array {
    $output = shell_exec('git -C ' . escapeshellarg($root) . ' ' . $arguments);
    if (!is_string($output)) {
        fwrite(STDERR, "check-names: git {$arguments} gave nothing.\n");
        exit(1);
    }

    return array_values(array_filter(explode("\0", $output), fn(string $path) => $path !== ''));
};

$paths = $staged ? $git('diff --cached --name-only -z --diff-filter=ACMR') : array_values(array_unique([...$git('ls-files -z'), ...$git('ls-files -z --others --exclude-standard')]));
sort($paths);

$found = 0;
foreach ($paths as $path) {
    if ($staged) {
        $content = shell_exec('git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg(':' . $path));
    } else {
        $full = $root . '/' . $path;
        $content = is_file($full) && filesize($full) <= 5_000_000 ? file_get_contents($full) : null;
    }
    if (!is_string($content) || strlen($content) > 5_000_000 || str_contains(substr($content, 0, 8000), "\0")) {
        continue;
    }
    // The path itself counts too (a folder or file named after a site).
    foreach (['(path)' => $path, ...explode("\n", $content)] as $line => $text) {
        $hits = [];
        if ($namesPattern !== null && preg_match_all($namesPattern, $text, $matches) > 0) {
            $hits = array_map('strtolower', $matches[0]);
        }
        $lower = strtolower($text);
        foreach ($treeWords as $word) {
            if (str_contains($lower, $word)) {
                $hits[] = $word;
            }
        }
        foreach (array_unique($hits) as $hit) {
            $where = $line === '(path)' ? $path : $path . ':' . ((int) $line + 1);
            echo "{$where}: found \"{$hit}\"\n";
            $found++;
        }
    }
}

if ($found > 0) {
    fwrite(STDERR, "check-names: {$found} place(s) name what the repository must not (see above).\n");
    exit(1);
}
echo 'check-names: ' . count($paths) . " files, none names a site the builds come from.\n";
