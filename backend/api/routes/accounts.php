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
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/**
 * The game accounts of the one this request's data belongs to (requireOwner(): the signed-in person, any
 * role or none, or this browser's visitor). An {account} id only ever finds one of theirs: somebody else's
 * account - another person's, another visitor's - answers 404, exactly like one that does not exist.
 *
 *   GET    /api/accounts                              list, with counts
 *   GET    /api/accounts/{id}                         overview: every character with its best build
 *   PATCH  /api/accounts/{id}  {nickname}             rename
 *   DELETE /api/accounts/{id}                         delete, with everything imported into it
 *   GET    /api/accounts/{id}/imports                 import history (50 newest)
 *   GET    /api/accounts/{id}/characters/{key}?build= full analysis of one character, its builds, and owned_teammates:
 *                                                     which of the builds' teammates the account has, with each one's
 *                                                     constellation ({key: rank}; the character itself left out)
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
                // The teammates the player has: only of these teams, never the character itself.
                $teammates = array_values(array_diff(BuildPresenter::teammateKeys($builds), [$owned->character->key]));

                return Json::send(
                    $response,
                    ['account' => AccountPresenter::summary($account)] +
                        $detail + [
                            'builds' => $app->get(BuildPresenter::class)->collection($builds),
                            // An object, {} when there is none.
                            'owned_teammates' => (object) $app->get(Accounts::class)->characterRanks($account->id, $teammates),
                        ],
                );
            });
        })
        ->add(requireOwner($app));
};
