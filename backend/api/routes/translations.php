<?php

use App\App;
use App\Auth\Role;
use App\Presenters\TranslationPresenter;
use App\Repositories\Translations;
use App\Services\Translations\TranslationCheck;
use App\Services\Translations\TranslationEditor;
use App\Web\HttpError;
use App\Web\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/**
 * The site's own words: the strings a page reads, and the owner's pages that keep the languages and reword
 * the strings (App\Services\Translations). A {code} that is not a language code is 404 before any query.
 *
 *   GET    /api/translations/{code}               public, no token read: {language, languages: [{code, name,
 *                                                 native_name}], values, english?} - the language's strings
 *                                                 with English underneath; ETag, and 304 for a tag held
 *
 * For admins (in auth mode "none": the owner, from this computer's own pages):
 *
 *   GET    /api/admin/languages                   {languages: [{code, name, native_name, enabled, sort_order,
 *                                                 seeded, strings}]}, hidden ones too
 *   POST   /api/admin/languages                   {code, name, native_name, enabled?} -> 201 {language};
 *                                                 409 language_exists, 422 too_many_languages, invalid_language
 *   PUT    /api/admin/languages/{code}            {name?, native_name?, enabled?, sort_order?} -> {language};
 *                                                 409 fallback_language when switching English off
 *   DELETE /api/admin/languages/{code}            -> {strings}: how many strings went with it;
 *                                                 409 fallback_language, seeded_language
 *   GET    /api/admin/translations                {languages, keys: [{name, description, values: {code: text}}]}
 *   PUT    /api/admin/translations                {values: {key: {code: text}}}, the changed ones -> the grid
 *                                                 as it is now; 422 with a code, and the key and language
 *   GET    /api/admin/translations/{code}/export  that language's own strings as a file, {key: text}
 *   PUT    /api/admin/translations/{code}/import  {key: text} -> the grid as it is now
 *
 * The writes take JSON only: a body of another type (a form) is 422 invalid_language on the language routes
 * and invalid_value on the string routes, as a malformed body is.
 *
 * Between an upload and "Apply" on the Sources page the tables are not there: every route here then answers
 * 503 translations_unavailable - never a 500, and never not_set_up - and the app reads its compiled English.
 */
return function (Slim\App $slim, App $app): void {
    $unavailable = fn (): HttpError => new HttpError(
        503,
        'The site\'s words are not in the database yet. An admin applies the waiting database update on the Sources page.',
        ['code' => 'translations_unavailable'],
        ['Retry-After' => '60'],
    );

    /** The strings are there to be read and written - or the 503. */
    $ready = function () use ($app, $unavailable): void {
        try {
            $available = $app->get(Translations::class)->available();
        } catch (PDOException) {
            $available = false;
        }
        if (! $available) {
            throw $unavailable();
        }
    };

    /** A {code} of the address: a language code, or there is no such thing - before any query. */
    $code = function (array $args): string {
        $code = (string) ($args['code'] ?? '');
        if (! TranslationCheck::codeOk($code)) {
            throw TranslationEditor::languageNotFound();
        }

        return $code;
    };

    /**
     * Every write here takes JSON and nothing else: a body of another type - a form, which any page open in
     * the browser can send without asking first - is refused as a malformed one is, with the route's code.
     * A request with no Content-Type has no body to read (a DELETE, a PUT whose body PHP dropped).
     */
    $jsonOnly = function (Request $request, string $code): void {
        $type = $request->getHeaderLine('Content-Type');
        if ($type !== '' && strtolower(trim(explode(';', $type)[0])) !== 'application/json') {
            throw TranslationCheck::invalid($code, 'Send it as JSON, with Content-Type: application/json.');
        }
    };

    /** A save's or an import's body, measured before anything in it is looked at - and JSON. */
    $measured = function (Request $request) use ($jsonOnly): mixed {
        $jsonOnly($request, 'invalid_value');
        $bytes = strlen((string) $request->getBody());
        if ($bytes === 0) {
            // What PHP makes of a body larger than its post_max_size: it arrives as nothing.
            throw TranslationCheck::invalid('empty_body', 'Nothing arrived. A body larger than this server accepts arrives empty.');
        }
        if ($bytes > TranslationCheck::BODY_LIMIT) {
            throw TranslationCheck::invalid('too_large', sprintf('That is more than %d MB, far more than every string of a language takes.', TranslationCheck::BODY_LIMIT / 1048576));
        }

        return $request->getParsedBody();
    };

    // No role check, on purpose: the words are the same for everybody, so a token - even one that would not
    // check out - is not looked at, and a page whose sign-in has run out still reads in its language.
    $slim->get('/api/translations/{code}', function (Request $request, Response $response, array $args) use ($app, $code, $ready, $unavailable): Response {
        $language = $code($args);

        try {
            $ready();
            $body = json_encode($app->get(TranslationPresenter::class)->bundle($language), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (PDOException) {
            // A database that cannot be asked must not log a failure on every page load: the app has its
            // compiled English to read.
            throw $unavailable();
        }

        // The strings change when somebody saves one, or with a release: the browser asks every time
        // (no-cache) and is told "the same as you have" in a few bytes. Both headers are set before that
        // answer, so it carries them too - without them it would go out as no-store.
        $etag = TranslationPresenter::etag($body);
        $response = $response->withHeader('ETag', $etag)->withHeader('Cache-Control', 'no-cache');
        if (TranslationPresenter::notModified($request->getHeaderLine('If-None-Match'), $etag)) {
            return $response->withStatus(304);
        }
        $response->getBody()->write($body);

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    });

    $slim->group('/api/admin', function (RouteCollectorProxy $group) use ($app, $code, $ready, $measured, $jsonOnly) {
        $group->get('/languages', function (Request $request, Response $response) use ($app, $ready): Response {
            $ready();

            return Json::send($response, ['languages' => $app->get(TranslationPresenter::class)->languages()]);
        });

        $group->post('/languages', function (Request $request, Response $response) use ($app, $ready, $jsonOnly): Response {
            $ready();
            $jsonOnly($request, 'invalid_language');
            $language = $app->get(TranslationEditor::class)->createLanguage($request->getParsedBody());

            return Json::send($response, ['language' => TranslationPresenter::language($language)], 201);
        });

        $group->put('/languages/{code}', function (Request $request, Response $response, array $args) use ($app, $code, $ready, $jsonOnly): Response {
            $language = $code($args);
            $ready();
            $jsonOnly($request, 'invalid_language');
            $language = $app->get(TranslationEditor::class)->changeLanguage($language, $request->getParsedBody());

            return Json::send($response, ['language' => TranslationPresenter::language($language)]);
        });

        $group->delete('/languages/{code}', function (Request $request, Response $response, array $args) use ($app, $code, $ready, $jsonOnly): Response {
            $language = $code($args);
            $ready();
            $jsonOnly($request, 'invalid_language');

            return Json::send($response, ['strings' => $app->get(TranslationEditor::class)->deleteLanguage($language)]);
        });

        $group->get('/translations', function (Request $request, Response $response) use ($app, $ready): Response {
            $ready();

            return Json::send($response, $app->get(TranslationPresenter::class)->grid());
        });

        // Who saved a string is the request's person - the token's - and nothing a body says.
        $group->put('/translations', function (Request $request, Response $response) use ($app, $ready, $measured): Response {
            $ready();
            $body = $measured($request);
            $app->get(TranslationEditor::class)->save(is_array($body) ? ($body['values'] ?? null) : null, currentUser($request)->id);

            return Json::send($response, $app->get(TranslationPresenter::class)->grid());
        });

        $group->get('/translations/{code}/export', function (Request $request, Response $response, array $args) use ($app, $code, $ready): Response {
            $language = $code($args);
            $ready();
            $strings = $app->get(TranslationEditor::class)->export($language);

            return Json::download($response, TranslationPresenter::map($strings), "translations-{$language}.json");
        });

        $group->put('/translations/{code}/import', function (Request $request, Response $response, array $args) use ($app, $code, $ready, $measured): Response {
            $language = $code($args);
            $ready();
            $app->get(TranslationEditor::class)->import($language, $measured($request), currentUser($request)->id);

            return Json::send($response, $app->get(TranslationPresenter::class)->grid());
        });
    })->add(requireRole($app, Role::ADMIN));
};
