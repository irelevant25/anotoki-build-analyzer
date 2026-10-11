<?php

use Anotoki\Lib\Admin\AdminContext;
use Anotoki\Lib\Auth\Http\Identify;
use Anotoki\Lib\Feedback\Feedback;
use Anotoki\Lib\Feedback\Http\FeedbackRoutes;
use App\App;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The visitors' feedback form (the anotoki library's: Anotoki\Lib\Feedback) - opened from the foot of every
 * page, by anybody while the site is open to everybody (optionalSignIn(): while the IAM requires sign-in here,
 * 401 sign_in_required for nobody):
 *
 *   POST /api/feedback {kind: bug | idea | other, section?, subject?, message, email?, page?, language?,
 *                       anonymous?, website}   -> 201 {ok: true}
 *
 * Who sent it comes from the token alone, never the body: a signed-in person's id, unless they send it "without
 * my name" (anonymous: no id, no address). The reply address is only ever the one the sender left in the form's
 * field - the page writes the account's address in, and whoever clears it sends none: the token's address is
 * never handed to the library, which would fill an empty field from it (their choice, the owner's decision of
 * 2026-10-11). Nothing of where a message came from is kept with it - no network address, no hash of one (the
 * owner's decision); its page, language and browser are. The limits (5 an hour and 20 a day
 * from one address bucket, 300 a day from everybody: 429 too_many with retry_after) count apart, a day, in
 * storage/cache. The robots' field `website` filled: the same answer, and nothing kept. No mail is sent: the
 * admin panel counts what is new. The sections are App\Admin\AdminTools::FEEDBACK_SECTIONS. 503
 * feature_unavailable until the library's anotoki_admin/002 is applied.
 */
return function (Slim\App $slim, App $app): void {
    FeedbackRoutes::public($slim, '/api/feedback', $app->get(Feedback::class), $app->get(AdminContext::class), static function (Request $request): ?array {
        $person = Identify::person($request);

        // The id alone: an address here would be kept for a sender who cleared the field.
        return $person === null ? null : ['id' => $person->sub];
    })->add(optionalSignIn($app));
};
