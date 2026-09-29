<?php

/**
 * The build analyzer's front controller.
 *
 * Under Apache, api/.htaccess sends every /api/ path here; in development, router.php does the same for
 * PHP's built-in server. The application itself (every route and middleware) is put together by
 * buildAnalyzerApi() in app.php, which the tests call with a configuration of their own.
 */

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
