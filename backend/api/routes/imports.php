<?php

use App\App;
use App\Auth\Role;
use App\Models\Account;
use App\Models\User;
use App\Presenters\AccountPresenter;
use App\Repositories\Accounts;
use App\Services\Import\ImportException;
use App\Services\Import\ImportService;
use App\Support\Neutral;
use App\Web\HttpError;
use App\Web\Input;
use App\Web\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Routing\RouteCollectorProxy;

/**
 * Imports into the signed-in person's own accounts; account_id may only name one of theirs.
 *
 *   POST /api/imports/showcase  {game, uid, account_id?}                     the Character Showcase by UID
 *                                                                            (10 a minute per person)
 *   POST /api/imports/file      {content | file, game?, account_id?, file_name?}  an inventory export
 *
 * A problem the person can fix is answered 422 {message, code, params}: the English sentence, and what
 * happened as a kind of ImportKinds::ERRORS with its parameters, for the wizard to word.
 */
return function (Slim\App $slim, App $app): void {
    $refused = fn (ImportException $e): HttpError => new HttpError(422, (string) Neutral::text($e->getMessage()), [
        'code' => $e->kind,
        'params' => AccountPresenter::params($e->params),
    ]);

    /** The account an import may target: one of the user's, or none. Anyone else's reads as "does not exist". */
    $target = function (Input $input, User $user) use ($app): ?Account {
        $id = $input->integer('account_id');
        if ($id === null || $input->failed('account_id')) {
            return null;
        }
        $account = $app->get(Accounts::class)->findOwned($user->id, $id, withCounts: false);
        if ($account === null) {
            $input->fail('account_id', 'The selected account does not exist.');
        }

        return $account;
    };

    $slim->group('/api/imports', function (RouteCollectorProxy $group) use ($app, $target, $refused) {
        $group->post('/showcase', function (Request $request, Response $response) use ($app, $target, $refused): Response {
            $user = currentUser($request);
            $input = Input::of($request);
            $game = $input->game('game', required: true);
            $uid = $input->string('uid', required: true, regex: '/^\d{9,10}$/', regexMessage: 'A UID is 9 or 10 digits long.');
            $account = $target($input, $user);
            $input->valid();

            try {
                $run = $app->get(ImportService::class)->fromEnka($game, $uid, $user, $account);
            } catch (ImportException $e) {
                throw $refused($e);
            }

            return Json::send($response, AccountPresenter::importRun($run), 201);
        })->add(throttle($app, 'showcase', (int) $app->config->get('showcase.per_minute', 10)));

        $group->post('/file', function (Request $request, Response $response) use ($app, $target, $refused): Response {
            $user = currentUser($request);
            $input = Input::of($request);
            $game = $input->game('game');
            $account = $target($input, $user);

            $file = $request->getUploadedFiles()['file'] ?? null;
            $file = $file instanceof UploadedFileInterface && $file->getError() === UPLOAD_ERR_OK ? $file : null;
            if ($file !== null && $file->getSize() > 20480 * 1024) {
                $input->fail('file', 'The file field must not be greater than 20480 kilobytes.');
            }
            $content = $file === null ? $input->string('content') : null;
            if ($file === null && $content === null && ! $input->failed('content')) {
                $input->fail('content', 'The content field is required when file is not present.');
            }
            $fileName = $input->string('file_name', max: 255);
            $input->valid();

            if ($file !== null) {
                $content = (string) $file->getStream();
                $fileName = $file->getClientFilename();
            }

            try {
                $run = $app->get(ImportService::class)->fromFile((string) $content, $user, $game, $account, $fileName);
            } catch (ImportException $e) {
                throw $refused($e);
            }

            return Json::send($response, AccountPresenter::importRun($run), 201);
        });
    })->add(requireRole($app, Role::MEMBER));
};
