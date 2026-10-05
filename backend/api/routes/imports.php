<?php

use App\App;
use App\Auth\Visitors;
use App\Enums\Game;
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
 * Imports into the own accounts of the one this request's data belongs to (requireOwner(): the signed-in
 * person, any role or none, or this browser's visitor); account_id may only name one of theirs.
 *
 *   POST /api/imports/showcase  {game, uid, account_id?}                     the Character Showcase by UID
 *                                                                            (10 a minute per person)
 *   POST /api/imports/file      {content | file, game?, account_id?, file_name?}  an inventory export
 *                                                                            (20 a minute per person)
 *
 * Sent with neither a token nor a visitor key, an import that goes through makes a visitor for this browser
 * (makeVisitor(), after the throttles): its answer carries the visitor's key in X-Visitor-Key, the one time
 * the key leaves the server. A visitor's imports are counted per address, and all visitors' showcase imports
 * together too (config/app.php showcase, imports, visitors); a visitor keeps at most visitors.max_accounts
 * game accounts and imports files of at most visitors.file_max_kb - past either, 422 visitor_limit
 * {limit: accounts | file_size, max}, which says that signing in lifts it.
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

    /**
     * A visitor's import that would add a game account past visitors.max_accounts: refused. Into one of its
     * accounts (account_id, or the showcase's UID it already has) it may always import. This is the early
     * check, before any download or parsing; AccountImporter counts again under the owner's lock, so two
     * imports at once cannot both add the last account.
     */
    $roomForAccount = function (User $user, ?Account $account, ?Game $game = null, ?string $uid = null) use ($app): void {
        if (! $user->isVisitor() || $account !== null) {
            return;
        }
        $accounts = $app->get(Accounts::class);
        if ($game !== null && $uid !== null && $accounts->findOwnedByUid($user->id, $game, $uid) !== null) {
            return;
        }
        $max = (int) $app->config->get('visitors.max_accounts', 5);
        if ($accounts->countOwned($user->id) >= $max) {
            throw Visitors::accountLimit($max);
        }
    };

    /** A visitor's file past visitors.file_max_kb: refused. */
    $smallEnough = function (User $user, int $bytes) use ($app): void {
        $maxKb = (int) $app->config->get('visitors.file_max_kb', 5120);
        if ($user->isVisitor() && $bytes > $maxKb * 1024) {
            throw new HttpError(422, "Without signing in, a file can be up to {$maxKb} KB. Sign in to import a larger one.", [
                'code' => 'visitor_limit',
                'params' => ['limit' => 'file_size', 'max' => $maxKb],
            ]);
        }
    };

    $slim->group('/api/imports', function (RouteCollectorProxy $group) use ($app, $target, $refused, $roomForAccount, $smallEnough) {
        $group->post('/showcase', function (Request $request, Response $response) use ($app, $target, $refused, $roomForAccount): Response {
            $user = currentUser($request);
            $input = Input::of($request);
            $game = $input->game('game', required: true);
            $uid = $input->string('uid', required: true, regex: '/^\d{9,10}$/', regexMessage: 'A UID is 9 or 10 digits long.');
            $account = $target($input, $user);
            $input->valid();
            $roomForAccount($user, $account, $game, $uid);

            try {
                $run = $app->get(ImportService::class)->fromEnka($game, $uid, $user, $account);
            } catch (ImportException $e) {
                throw $refused($e);
            }

            return Json::send($response, AccountPresenter::importRun($run), 201);
        })->add(makeVisitor($app))->add(throttle($app, 'showcase', 'showcase.per_minute', 'visitors.showcase_per_minute_all'));

        $group->post('/file', function (Request $request, Response $response) use ($app, $target, $refused, $roomForAccount, $smallEnough): Response {
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

            $smallEnough($user, $file !== null ? (int) $file->getSize() : strlen((string) $content));
            $roomForAccount($user, $account);
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
        })->add(makeVisitor($app))->add(throttle($app, 'file', 'imports.file_per_minute'));
    })->add(requireOwner($app, mayMakeVisitor: true));
};
