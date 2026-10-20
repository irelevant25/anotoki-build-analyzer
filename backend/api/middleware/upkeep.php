<?php

use Anotoki\Lib\Backups\Backups;
use Anotoki\Lib\Migrations\Http\SiteState;
use Anotoki\Lib\People\Http\PeopleUpkeep;
use Anotoki\Lib\People\People;
use App\App;
use App\Cache\Prune;
use App\Services\Catalog\CatalogUpkeep;
use App\Support\Log;
use App\Web\AfterResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * What ordinary traffic carries, after an answer (App\Web\AfterResponse) - the host has no cron:
 *
 * - the scrub of the accounts the IAM deleted (the anotoki library's PeopleUpkeep: at most once an hour, claimed
 *   with a stamp in storage/cache/iam, only while the site is ready - never in the middle of an update):
 *   thirty days after its owner asked, an account the IAM deleted is nobody's here (App\People\SitePeople);
 * - the day's backup (the library's backups: the first request of a UTC day that finds none made, while
 *   nothing waits to be applied; 14 kept);
 * - the day's tidying up (App\Cache\Prune: what has expired in the cache table, the downloaded files older than
 *   three days - the first request of a UTC day, while the site is ready);
 * - a stale catalog list refreshed (App\Services\Catalog\CatalogUpkeep: one at a time, a look at most every five
 *   minutes, while the site is ready).
 *
 * config/app.php `upkeep` switches each off (the tests do, but where they mean to try it). None ever fails the
 * answer: a failure is a line in the log.
 */
function upkeepAfterAnswer(App $app, SiteState $state): Closure
{
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app, $state): ResponseInterface {
        $response = $handler->handle($request);

        try {
            if ($app->config->get('upkeep.people', true)) {
                (new PeopleUpkeep($app->get(People::class), static fn(): bool => $state->state() === SiteState::READY, static fn(callable $work) => AfterResponse::add($work)))->soon();
            }
            if ($app->config->get('upkeep.daily_backup', true)) {
                $app->get(Backups::class)->dailyIfDue();
            }
            // Only while the site is ready: it reads the database, which a site not set up may not even have.
            if ($app->config->get('upkeep.prune', true) && $state->state() === SiteState::READY) {
                $app->get(Prune::class)->dailyIfDue();
            }
            if ($app->config->get('upkeep.catalog', true) && $state->state() === SiteState::READY) {
                $app->get(CatalogUpkeep::class)->soon();
            }
        } catch (Throwable $e) {
            Log::exception($e, 'The upkeep after an answer');
        }

        return $response;
    };
}
