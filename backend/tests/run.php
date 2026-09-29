<?php

/**
 * php tests/run.php [filter] - every tests/*.test.php, each in a process of its own.
 *
 * A process each because each file makes its own throwaway database and builds the app once. The exit
 * status is non-zero when any file fails. A filter runs only the files whose name contains it
 * ("php tests/run.php sync").
 */

$filter = $argv[1] ?? '';
$files = array_values(array_filter(glob(__DIR__.'/*.test.php') ?: [], fn (string $file) => $filter === '' || str_contains(basename($file), $filter)));
sort($files);
$failed = [];
$counts = [];

foreach ($files as $file) {
    echo "\n== ".basename($file)."\n";
    $output = [];
    $handle = popen(escapeshellarg(PHP_BINARY).' '.escapeshellarg($file).' 2>&1', 'r');
    while (($line = fgets($handle)) !== false) {
        echo $line;
        if (preg_match('/^(\d+) tests, /', $line, $m)) {
            $counts[] = (int) $m[1];
        }
    }
    if (pclose($handle) !== 0) {
        $failed[] = basename($file);
    }
}

echo "\n".array_sum($counts).' tests in '.count($files).' files. '
    .($failed === [] ? "Every test file passed.\n" : 'Failed: '.implode(', ', $failed)."\n");
exit($failed === [] ? 0 : 1);
