<?php

use App\App;
use App\Models\Account;
use App\Presenters\AccountPresenter;
use App\Presenters\BuildPresenter;
use App\Repositories\Accounts;
use App\Repositories\Builds;
use App\Repositories\ImportRuns;
use App\Services\Analysis\CharacterAnalyzer;
use App\Web\HttpError;
use App\Web\Input;
use App\Web\Json;
use App\Web\ValidationError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/**
 * The game accounts of the one this request's data belongs to (requireOwner(): the signed-in person, any
 * role or none, or this browser's visitor). An {account} id only ever finds one of theirs: somebody else's
 * account - another person's, another visitor's - answers 404, exactly like one that does not exist.
 *
 *   GET    /api/accounts                              list, with counts
 *   GET    /api/accounts/{id}                         overview: every character with the build it is judged by - the one
 *                                                     the player chose, else its best (the closest match)
 *   PATCH  /api/accounts/{id}  {nickname}             rename
 *   DELETE /api/accounts/{id}                         delete, with everything imported into it
 *   GET    /api/accounts/{id}/imports                 import history (50 newest)
 *   GET    /api/accounts/{id}/characters/{key}?build= full analysis of one character, its builds, and owned_teammates:
 *                                                     which of the builds' teammates the account has, with each one's
 *                                                     constellation ({key: rank}; the character itself left out; a
 *                                                     Traveler of another element than the account's null - unknown)
 *   PUT    /api/accounts/{id}/characters/{key}/build  {build_id}: the build the character is judged by - one of its
 *                                                     own builds, reached or not -, or null for the closest match;
 *                                                     answers {chosen_build_id}. Another build: 422 invalid
 *   PUT    /api/accounts/{id}/characters/{key}/favourite  {favourite}: the character starred - one the player is
 *                                                     building, listed first on the account page - or not; answers
 *                                                     {favourite}. Anything but true or false: 422 invalid
 */
return function (Slim\App $slim, App $app): void {
    $own = function (Request $request, array $args) use ($app): Account {
        return $app->get(Accounts::class)->findOwned(currentUser($request)->id, (int) $args['account']) ?? throw HttpError::notFound();
    };

    $slim
        ->group('/api/accounts', function (RouteCollectorProxy $group) use ($app, $own) {
            $group->get('', function (Request $request, Response $response) use ($app): Response {
                $accounts = $app->get(Accounts::class)->owned(currentUser($request)->id);

                return Json::send($response, array_map(fn(Account $account) => AccountPresenter::summary($account), $accounts));
            });

            $group->get('/{account:[0-9]+}', function (Request $request, Response $response, array $args) use ($app, $own): Response {
                $account = $own($request, $args);

                return Json::send(
                    $response,
                    AccountPresenter::summary($account) + [
                        'characters' => $app->get(CharacterAnalyzer::class)->overview($account),
                    ],
                );
            });

            $group->patch('/{account:[0-9]+}', function (Request $request, Response $response, array $args) use ($app, $own): Response {
                $account = $own($request, $args);
                $input = Input::of($request);
                $nickname = null;
                if ($input->has('nickname')) {
                    $nickname = $input->string('nickname', required: true, max: 100);
                }
                $input->valid();

                if ($nickname !== null) {
                    $app->get(Accounts::class)->rename($account, $nickname);
                }

                return Json::send($response, AccountPresenter::summary($app->get(Accounts::class)->withCounts($account)));
            });

            $group->delete('/{account:[0-9]+}', function (Request $request, Response $response, array $args) use ($app, $own): Response {
                $app->get(Accounts::class)->delete($own($request, $args));

                return $response->withStatus(204);
            });

            $group->get('/{account:[0-9]+}/imports', function (Request $request, Response $response, array $args) use ($app, $own): Response {
                $account = $own($request, $args);
                $runs = $app->get(ImportRuns::class)->forAccount($account->id);

                return Json::send($response, array_map(fn($run) => AccountPresenter::importRun($run), $runs));
            });

            $group->get('/{account:[0-9]+}/characters/{key}', function (Request $request, Response $response, array $args) use ($app, $own): Response {
                $account = $own($request, $args);
                $owned = $app->get(Accounts::class)->character($account->id, (string) $args['key']) ?? throw HttpError::notFound();

                $build = $request->getQueryParams()['build'] ?? null;
                $buildId = is_string($build) && ctype_digit($build) ? (int) $build : null;
                $detail = $app->get(CharacterAnalyzer::class)->detail($account, $owned, $buildId ?: null);

                $buildIds = array_map(fn($evaluation) => $evaluation['build']['id'], $detail['evaluations']);
                $builds = $app->get(Builds::class)->byIds($buildIds);
                // The teammates the player has, with each one's constellation: only of these teams, never the character
                // itself. The Traveler is one character in several elements: the account's, in whichever element it was
                // imported, makes every Traveler of the teams its own - its constellation in another element unknown, null
                // (the owner's word of 2026-10-22).
                $accounts = $app->get(Accounts::class);
                $teammates = array_values(array_diff(BuildPresenter::teammateKeys($builds), [$owned->character->key]));
                $ranks = $accounts->characterRanks($account->id, $teammates);
                $travelers = array_values(array_filter($teammates, fn(string $key) => $account->game->isTraveler($key) && !array_key_exists($key, $ranks)));
                if ($travelers !== [] && array_filter($accounts->characterKeys($account->id), $account->game->isTraveler(...)) !== []) {
                    $ranks += array_fill_keys($travelers, null);
                }

                return Json::send(
                    $response,
                    ['account' => AccountPresenter::summary($account)] +
                        $detail + [
                            'builds' => $app->get(BuildPresenter::class)->collection($builds),
                            // An object, {} when there is none.
                            'owned_teammates' => (object) $ranks,
                        ],
                );
            });

            $group->put('/{account:[0-9]+}/characters/{key}/build', function (Request $request, Response $response, array $args) use ($app, $own): Response {
                $account = $own($request, $args);
                $accounts = $app->get(Accounts::class);
                $owned = $accounts->character($account->id, (string) $args['key']) ?? throw HttpError::notFound();

                $input = Input::of($request);
                // A whole number, or null - "choose automatically"; anything else, an empty text too, is no build.
                $buildId = null;
                if (!$input->has('build_id')) {
                    $input->fail('build_id', 'The build id field is required; null chooses automatically.');
                } elseif ($input->data['build_id'] !== null) {
                    $buildId = $input->integer('build_id', required: true);
                }
                $input->valid();

                if (!$accounts->chooseBuild($owned, $buildId)) {
                    throw new ValidationError(['build_id' => ["That is not one of this character's builds."]]);
                }

                return Json::send($response, ['chosen_build_id' => $buildId]);
            });

            $group->put('/{account:[0-9]+}/characters/{key}/favourite', function (Request $request, Response $response, array $args) use ($app, $own): Response {
                $account = $own($request, $args);
                $accounts = $app->get(Accounts::class);
                $owned = $accounts->character($account->id, (string) $args['key']) ?? throw HttpError::notFound();

                $input = Input::of($request);
                $favourite = $input->boolean('favourite', required: true);
                $input->valid();

                $accounts->markFavourite($owned, $favourite);

                return Json::send($response, ['favourite' => $owned->favourite]);
            });
        })
        ->add(requireOwner($app));
};
