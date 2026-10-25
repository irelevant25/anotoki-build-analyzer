<?php

use Anotoki\Lib\Admin\AdminContext;
use Anotoki\Lib\Audit\AuditLog;
use Anotoki\Lib\SiteSettings\Http\SiteSettingsRoutes;
use Anotoki\Lib\SiteSettings\SiteSettings;
use App\App;
use App\Auth\Role;

/**
 * The site settings (anotoki-lib 0.11.0: the library's routes, on this site's settings - App\Web\SitePages, all three
 * parts: Getting in, the announcement, Page access):
 *
 *   GET    /api/site-settings              public, no token read: what every page is told before anybody signs in -
 *                                          {maintenance, announcement: null | {level, message: {en, sk, ...}},
 *                                          pages: [{path, visibility, blocked}]} (never a row's API paths). A reserved
 *                                          path: it answers while the site is closed, and no row may claim it.
 *
 * For the site's ADMIN alone (an EDITOR is refused, 403 admin_only; nobody 401 sign_in_required) - the Site settings
 * page (/admin/site-settings, the library's):
 *
 *   GET    /api/admin/site-settings        the page's whole: parts, settings (each with when and by whom it last
 *                                          changed), pages (each with its API paths), levels, visibilities, reserved,
 *                                          limits
 *   PUT    /api/admin/site-settings        {settings: {name: value}} - audited site_settings.changed
 *   POST   /api/admin/site-settings/pages  {path, visibility?, blocked?, api_paths?} -> 201 - audited page_access.added
 *                                          | 409 page_exists
 *   PUT    /api/admin/site-settings/pages  {pages: [{id, visibility?, blocked?, api_paths?}]} - audited
 *                                          page_access.changed, an entry a row
 *   DELETE /api/admin/site-settings/pages/{id}  - audited page_access.removed | 404 not_found
 *
 * A refusal says why (422 invalid_setting, invalid_page, invalid_api_path, ...: a reserved page or API path - either
 * way round - is never taken); 503 feature_unavailable while the library's anotoki_admin/003 waits - but the site
 * gate keeps these routes closed then anyway (update_pending). Who changed what is the token's person (the
 * AdminContext), named on the page by this site's record of them; the People page's delete leaves what they changed
 * by nobody (App\People\SitePeople::deleteData()). This site's own check answers first, in its words; the library's
 * group asks the context's ADMIN check again.
 */
return function (Slim\App $slim, App $app): void {
    $settings = $app->get(SiteSettings::class);

    SiteSettingsRoutes::public($slim, $settings);
    SiteSettingsRoutes::admin($slim, $settings, $app->get(AdminContext::class), $app->get(AuditLog::class))->add(requireRole($app, Role::ADMIN));
};
