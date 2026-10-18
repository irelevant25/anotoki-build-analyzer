<?php

use App\App;
use App\Services\Transfer\AccountsFile;
use App\Services\Transfer\AccountsFileException;
use App\Services\Transfer\AccountsTransfer;
use App\Web\HttpError;
use App\Web\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/**
 * A signed-in person's own data as a file, out and back (App\Services\Transfer: AccountsFile is the file,
 * AccountsTransfer the rules). For a signed-in person only (requireSignIn): nobody and a browser's visitor
 * are 401 sign_in_required, and it is always the person's own data - nothing names another's.
 *
 *   GET  /api/me/export                  the file: every game account of theirs with its characters,
 *                                        weapons, artifacts / relics and import history (compact JSON,
 *                                        Content-Disposition attachment build-analyzer-accounts-YYYY-MM-DD.json)
 *   POST /api/me/import[?dry_run=1]      the file as the request's body, as it was saved ->
 *                                        {dry_run, added, replaced, kept, skipped, accounts: [{game, uid,
 *                                        nickname, outcome: added | replaced | kept, skipped}]}; a dry run
 *                                        says what the import would do and changes nothing
 *                                        (5 a minute per person, dry runs included: me.import_per_minute)
 *
 * A file that is not read is 422 {message, code, params} (AccountsFileException's kinds: no_file,
 * file_too_big {max_mb}, not_a_data_file, newer_version {version}, bad_entry {path}, too_many_accounts
 * {max}), and nothing was written; an export too large for one file is 422 export_too_big
 * {max_mb, max_accounts}. The body's size (config/app.php me.file_max_mb) is checked before it is read as
 * an accounts file - not before PHP takes it: by then PHP has the whole body, and Slim's body parser (app
 * level) has decoded one sent as application/json, for anybody's request. What bounds a body is the host's
 * post_max_size (past it, the body arrives empty: no_file). The export never writes more than
 * me.file_max_mb, so what it writes always imports.
 */
return function (Slim\App $slim, App $app): void {
    $refused = fn(AccountsFileException $e): HttpError => new HttpError(422, $e->getMessage(), [
        'code' => $e->kind,
        'params' => $e->params === [] ? new stdClass() : $e->params,
    ]);

    $slim
        ->group('/api/me', function (RouteCollectorProxy $group) use ($app, $refused) {
            $group->get('/export', function (Request $request, Response $response) use ($app, $refused): Response {
                try {
                    $file = $app->get(AccountsTransfer::class)->export(currentUser($request));
                } catch (AccountsFileException $e) {
                    throw $refused($e);
                }
                $response->getBody()->write($file['body']);

                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"');
            });

            $group
                ->post('/import', function (Request $request, Response $response) use ($app, $refused): Response {
                    // An import that has started finishes, even when the tab is closed while it runs.
                    ignore_user_abort(true);
                    $transfer = $app->get(AccountsTransfer::class);
                    $dryRun = in_array(strtolower((string) ($request->getQueryParams()['dry_run'] ?? '')), ['1', 'true', 'yes'], true);

                    try {
                        $body = (string) $request->getBody();
                        if ($body === '') {
                            // What PHP makes of a body larger than its post_max_size: it arrives as nothing.
                            throw new AccountsFileException('no_file', [], 'No file arrived. A file larger than this server accepts arrives empty.');
                        }
                        if (strlen($body) > $transfer->maxBytes()) {
                            throw new AccountsFileException(
                                'file_too_big',
                                ['max_mb' => $transfer->maxMb()],
                                "The file is larger than {$transfer->maxMb()} MB, more than an export of this site writes.",
                            );
                        }
                        // Sent as application/json, the body parser has decoded it already (once is enough for a file of
                        // megabytes); anything else - or a byte order mark, which some editors save - is decoded here.
                        $parsed = $request->getParsedBody();
                        $file = is_array($parsed) ? $parsed : json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body, true, 64);
                        unset($body, $parsed);
                        $file = AccountsFile::read($file);
                        $result = $transfer->import(currentUser($request), $file, $dryRun);
                    } catch (AccountsFileException $e) {
                        throw $refused($e);
                    }

                    return Json::send($response, $result);
                })
                ->add(throttle($app, 'me-import', 'me.import_per_minute'));
        })
        ->add(requireSignIn($app));
};
