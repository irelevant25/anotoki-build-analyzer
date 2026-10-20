<?php

declare(strict_types=1);

/**
 * The repository names no site the builds come from (docs/decisions.md, 2026-10-20): every file git tracks - and
 * every file `git add -A` would add - read for the words of the owner's config/neutral.local.php: its `names` on
 * word boundaries (as App\Support\Neutral matches them; in a file's path however the path joins their words) and its
 * `tree_words` (typos, page parts, labels that would give a site away) anywhere. It prints where it found one -
 * never the file's list - and fails on any.
 *
 *   php tools/check-names.php                  the working tree
 *   php tools/check-names.php --staged         what is staged (the owner's untracked .git/hooks/pre-commit runs this)
 *   php tools/check-names.php --message=<file> a commit message, git's comment lines aside (the owner's untracked
 *                                              .git/hooks/commit-msg runs this)
 *
 * Without the file there is nothing to look for, which it says. A binary file or one over 5 MB is not read - its
 * path is.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Config;

$staged = in_array('--staged', array_slice($argv, 1), true);
$message = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--message=')) {
        $message = substr($argument, strlen('--message='));
    }
}
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

// git's own exit status says whether it worked: an empty list (no untracked file, nothing staged) is a list.
$git = static function (string $arguments) use ($root): array {
    exec('git -C ' . escapeshellarg($root) . ' ' . $arguments, $lines, $status);
    if ($status !== 0) {
        fwrite(STDERR, "check-names: git {$arguments} failed (exit {$status}).\n");
        exit(1);
    }

    return array_values(array_filter(explode("\0", implode("\n", $lines)), fn(string $path) => $path !== ''));
};

if ($message !== null) {
    // git's own lines ("# Please enter the commit message ...") are no part of the message.
    $lines = explode("\n", str_replace("\r\n", "\n", is_file($message) ? (string) file_get_contents($message) : ''));
    $messageText = implode("\n", array_map(fn(string $line) => str_starts_with($line, '#') ? '' : $line, $lines));
    $paths = ['the commit message'];
} else {
    $paths = $staged ? $git('diff --cached --name-only -z --diff-filter=ACMR') : array_values(array_unique([...$git('ls-files -z'), ...$git('ls-files -z --others --exclude-standard')]));
    sort($paths);
}

// A path is read squashed - its letters and digits alone -, so a name is found however a path joins its words
// ("a-name.php", "a_name/", "aName.txt"), where a word boundary would miss it.
$squash = static fn(string $text): string => (string) preg_replace('~[^a-z0-9]+~', '', strtolower($text));
$squashedNames = [];
foreach ($names as $name) {
    if ($squash($name) !== '') {
        $squashedNames[$squash($name)] = $name;
    }
}

$found = 0;
$report = static function (string $where, array $hits) use (&$found): void {
    foreach (array_unique($hits) as $hit) {
        echo "{$where}: found \"{$hit}\"\n";
        $found++;
    }
};
foreach ($paths as $path) {
    if ($message === null) {
        // The path itself counts too (a folder or file named after a site), whatever the file holds - a binary one too.
        $hits = [];
        foreach ($squashedNames as $squashed => $name) {
            if (str_contains($squash($path), $squashed)) {
                $hits[] = $name;
            }
        }
        foreach ($treeWords as $word) {
            if (str_contains(strtolower($path), $word)) {
                $hits[] = $word;
            }
        }
        $report($path, $hits);
    }

    if ($message !== null) {
        $content = $messageText;
    } elseif ($staged) {
        $content = shell_exec('git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg(':' . $path));
    } else {
        $full = $root . '/' . $path;
        $content = is_file($full) && filesize($full) <= 5_000_000 ? file_get_contents($full) : null;
    }
    if (!is_string($content) || strlen($content) > 5_000_000 || str_contains(substr($content, 0, 8000), "\0")) {
        continue;
    }
    foreach (explode("\n", $content) as $line => $text) {
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
        $report($path . ':' . ($line + 1), $hits);
    }
}

if ($found > 0) {
    fwrite(STDERR, "check-names: {$found} place(s) name what the repository must not (see above).\n");
    exit(1);
}
echo $message !== null ? "check-names: the commit message names no site the builds come from.\n" : 'check-names: ' . count($paths) . " files, none names a site the builds come from.\n";
