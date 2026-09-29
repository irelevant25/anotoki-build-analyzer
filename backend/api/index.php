<?php

/**
 * The build analyzer's front controller.
 *
 * Under Apache, api/.htaccess sends every /api/ path here; in development, router.php does the same for
 * PHP's built-in server. The application itself (every route and middleware) is put together by
 * buildAnalyzerApi() in app.php, which the tests call with a configuration of their own.
 */

// The hosting prints PHP's warnings into the page (display_errors is on there), which puts the server's paths
// in front of an answer's JSON and breaks it for the app. Served by a web server, they go to the error log
// only; the command line, the tests and the development server keep showing them.
if (! in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
    ini_set('display_errors', '0');
}

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/app.php';

use App\App;
use App\Config;
use App\Support\Log;

// The tests build the app themselves and hand it requests; nothing is served while they load this file.
if (! defined('BUILD_ANALYZER_NO_RUN')) {
    $config = Config::load();
    Log::to($config->storage('logs/app.log'));

    buildAnalyzerApi(new App($config, web: true))->run();
}
