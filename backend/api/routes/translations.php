<?php

use Anotoki\Lib\Audit\AuditLog;
use Anotoki\Lib\Support\JsonResponse;
use Anotoki\Lib\Support\Refusal;
use Anotoki\Lib\Translations\Http\TranslationsRoutes;
use Anotoki\Lib\Translations\Translations;
use App\App;
use App\Auth\Role;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The site's own words: the strings a page reads, and the admin pages that keep the languages and reword the
 * strings - the anotoki family's routes (anotoki/lib: TranslationsRoutes, on the library's Translations with this
 * site's own rules, App\Services\Translations\SiteTranslations), in the family's one wire format (the IAM's:
 * snake_case), behind this site's own checks. A {code} that is not a language code is 404 before any query.
 *
 *   GET    /api/translations/{code}               public, no token read: {language, languages: [{code, name,
 *                                                 native_name}], values, english?} - the language's strings
 *                                                 with English underneath; ETag, and 304 for a tag held
 *
 * For editors (EDITOR or ADMIN) - the languages as they are, and rewording the strings:
 *
 *   GET    /api/admin/languages                   {languages: [{code, name, native_name, enabled, sort_order,
 *                                                 seeded, strings}]}, hidden ones too
 *   GET    /api/admin/translations                {languages, keys: [{name, description, values: {code: text}}]}
 *   PUT    /api/admin/translations                {values: {key: {code: text}}}, the changed ones -> the grid
 *                                                 as it is now; 422 with a code, and the key and language
 *   GET    /api/admin/translations/{code}/export  that language's own strings as a file, {key: text}
 *   PUT    /api/admin/translations/{code}/import  {key: text} -> the grid as it is now
 *
 * For admins - adding, renaming, switching and deleting a language:
 *
 *   POST   /api/admin/languages                   {code, name, native_name, enabled?} -> 201 {language};
 *                                                 409 language_exists, 422 too_many_languages, invalid_language
 *   PUT    /api/admin/languages/{code}            {name?, native_name?, enabled?, sort_order?} -> {language};
 *                                                 409 fallback_language when switching English off
 *   DELETE /api/admin/languages/{code}            -> {strings}: how many strings went with it;
 *                                                 409 fallback_language, seeded_language
 *
 * The role checks are this site's (api/middleware/auth.php), on each route group: 401 sign_in_required, 403
 * editor_only / admin_only - before anything else is looked at. Who saved a string is the token's person,
 * never anything a body says. A refusal is this site's error body, {message, code, ...what it is about: key,
 * language, placeholder, keys, field, found}, never kept by a cache. Every write that changed something is in
 * the audit log (the library's: translations.saved, translations.imported, language.created, language.updated,
 * language.deleted), by that person.
 *
 * The writes take JSON and nothing else, read by the library from the request itself: another type of body -
 * a form, which any page open in the browser can send without asking first - is 415 unsupported_media_type;
 * more than 1 MiB is 413 too_large; nothing at all 422 empty_body (what PHP makes of a body larger than its
 * post_max_size); a body that is not JSON 400 invalid_json.
 *
 * Between an upload and "Apply pending" on the Migrations page the tables may not be there. The site gate then
 * answers every route here 503 update_pending but the strings of a language (an open path: the pages need
 * their words while an update waits) - and whatever reaches a route here with the tables missing, or with no
 * English string at all, answers 503 translations_unavailable with Retry-After: 60 - never a 500, and never
 * not_set_up - and the app reads its compiled English.
 */
return function (Slim\App $slim, App $app): void {
    $translations = fn (): Translations => $app->get(Translations::class);
    $error = fn (Response $response, Refusal $refusal): Response => JsonResponse::write(
        $response,
        $refusal->status,
        ['message' => $refusal->message, 'code' => $refusal->code] + $refusal->extra,
    );
    $actor = fn (Request $request): int => currentUser($request)->id;
    $audit = fn (Request $request, string $action, ?string $type, ?string $id, array $details) => $app->get(AuditLog::class)
        ->record($request, currentUser($request)->id, $action, $type, $id, $details);

    TranslationsRoutes::bundle($slim, $translations, $error);
    TranslationsRoutes::strings($slim, '/api/admin', $translations, $actor, $error, $audit)->add(requireRole($app, ...Role::STAFF));
    TranslationsRoutes::languages($slim, '/api/admin', $translations, $error, $audit)->add(requireRole($app, Role::ADMIN));
};
