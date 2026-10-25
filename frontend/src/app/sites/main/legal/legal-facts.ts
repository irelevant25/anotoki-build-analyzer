import { inject } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { AnotokiAuth } from '@anotoki/lib/auth';
import { ANOTOKI_LEGAL_CONFIG, AnotokiLegalConfig, DEFAULT_LEGAL_PATHS, LegalFacts } from '@anotoki/lib/legal';
import { CHANGELOG_SEEN_KEY } from '../../../shared/changelog';
import { feedbackOffered } from '../../../shared/feedback';
import { BRAND } from '../../../shared/i18n/title-strategy';
import { TranslationService } from '../../../shared/i18n/translation';
import { LANGUAGE_STORAGE } from '../../../shared/i18n/translations.config';
import { MetaStore } from '../../../shared/meta-store';
import { THEME_KEY } from '../../../shared/shell';
import { SIGN_IN_KIND_STORAGE_KEY } from '../../../shared/sign-in-kind';
import { ANNOUNCEMENT_KEY } from '../../../shared/site-settings';
import { VISITOR_STORAGE_KEY } from '../../../shared/visitor-key';

/**
 * The day this site's facts last changed: the pages show the later of it and the day the library's own words last
 * did. Move it with any change to what the site keeps or for how long - and the strings that say so (`legal.*`,
 * migration 011; the site settings' part, 025).
 */
export const SITE_LEGAL_UPDATED = '2026-10-25';

/**
 * The anotoki account's cookie for every anotoki site (the IAM's hint_cookie_name, set under the IAM's parent
 * domain). This site reads it: a visitor signed in at the IAM is signed in here with one silent hop (the auth
 * module's hint, on - app.config.ts).
 */
export const SIGN_IN_HINT_COOKIE = 'anotoki_signed_in';

/** The hosting company, named by the owner on 2026-10-18: the servers and the databases (and the mail, which this site sends none of). */
export const HOSTING = { name: 'WebSupport s.r.o.', url: 'https://www.websupport.sk' } as const;

/**
 * The legal pages' configuration: the app's (shared/legal.ts - the page's language, the database's words) with the
 * facts the pages read. Their frame provides it (sites/main/legal/legal-layout), so the facts - this code and its strings'
 * keys - load with the pages, never with the first page.
 *
 * The frame is made for each page apart (each a route of its own, app.routes.ts), and knows which it frames: on the
 * Contact page the feedback form is offered only while a message can be sent (shared/feedback.ts - never to somebody
 * signed out while the IAM requires signing in here, whose message the server refuses), the address alone
 * otherwise; the Privacy page says to everybody what a message holds and how long it is kept.
 */
export function legalPagesConfig(): AnotokiLegalConfig {
  const root = inject(ANOTOKI_LEGAL_CONFIG, { skipSelf: true });
  const i18n = inject(TranslationService);
  const auth = inject(AnotokiAuth);
  const meta = inject(MetaStore);
  const contact = `/${inject(ActivatedRoute).snapshot.routeConfig?.path ?? ''}` === DEFAULT_LEGAL_PATHS.contact;
  const form = contact ? feedbackOffered() : () => true;
  return { ...root, facts: () => siteLegalFacts(i18n, auth, meta.visitorDays(), form()) };
}

/**
 * This site's facts, in the page's language - read as a page is drawn, so a change of language redraws them. Each
 * must stay true of the code:
 *
 * - the record of a person: users (migrations 002, 007) - the anotoki account's id (the IAM's sub) and the name from
 *   the latest token (preferred_username, the library's people module: App\People\SitePeople), created_at,
 *   last_seen_at (at most every five minutes), updated_at; no address; deleted whole only by an ADMIN on the People
 *   page (SitePeople::deleteData() - the next sign-in makes an empty one again); its name the IAM's placeholder
 *   once the account is deleted (the library's scrub, users.anonymized_at);
 * - the game accounts: accounts (UID, nickname - the game's or the person's own -, level, world level, signature,
 *   meta: region, achievements, platform), account_characters, account_weapons, account_items and import_runs
 *   (source, status, the file's name, the summary and its warnings, the error) - never deleted by age; a person
 *   deletes an account (DELETE /api/accounts/{id}: its characters, gear and imports go by the foreign keys), an
 *   import replaces what it brings (App\Services\Import\AccountImporter), an ADMIN's delete on the People page takes
 *   all of it; when the account is deleted it stays, the row it hangs on nobody's (the owner's rule: the data
 *   stays);
 * - the editors' and administrators' traces: build_imports.imported_by (migration 016: who imported a builds file,
 *   never a name - the Builds page names them by their row, which the scrub makes the IAM's placeholder; the People
 *   delete sets it NULL by the foreign key), translations.updated_by (the People delete forgets it:
 *   SiteTranslations::forgetWriter());
 * - the site settings' changers (anotoki-lib 0.11.0, the library's anotoki_admin/003): anotoki_site_settings.updated_by
 *   and anotoki_page_access.updated_by - who changed a switch or a page's row last (the IAM's sub, never a name: the
 *   Site settings page names them by their row, which the scrub makes the IAM's placeholder), and when; until it is
 *   changed again; the People delete sets it NULL (App\People\SitePeople::deleteData()) - migration 025's legal.settings.*;
 * - the limits: the cache table's counts (rate:{name}:user:{id} or :ip:{keyed hash}, a minute; visitors:new:{keyed
 *   hash}, an hour - App\Web\ClientAddress::countedKey()) and the library's feedback and error-report counts (a day
 *   of keyed hashes, in storage/cache); the cache table's expired rows go with the day's tidying up (App\Cache\Prune,
 *   after the first answer of a UTC day: api/middleware/upkeep.php), the library's at its next count;
 * - the showcase answers: the cache table's showcase:{game}:{uid} (App\Services\Import\Enka\EnkaClient - the
 *   service's whole answer, kept for the ttl it sends, at least 60 seconds), pruned with the rest;
 * - the site's own log: storage/logs/app.log (App\Support\Log - rotated past 5 MB, one old file kept);
 * - visitors (migration 004, while the IAM requires no sign-in): users with a negative id, the key's SHA-256, created
 *   and last seen; their game accounts and imports under that id; moved into the account at a sign-in
 *   (App\Auth\Visitors::move() - never into an account with data here after a silent sign-in: `held`), swept
 *   `visitors.keep_days` after the last visit (config/app.php; the days come from /api/meta);
 * - feedback (the library's, App\Admin\AdminTools::feedback(): the library's keep - spam 30 days, resolved 365,
 *   pruned at most daily after a new message; new and read ones until deleted): the kind, the section, the
 *   subject, the page as a path, the language, the browser, the sender's id unless "without my name", the reply
 *   address the form sends (the account's written in for somebody signed in: shared/feedback.ts); the reply address
 *   and browser forgotten by the scrub and by the People delete;
 * - the error log: the library's words and its 30 days (AdminTools::errorLog() sets no `days`); never an address, a
 *   keyed hash of its bucket;
 * - the audit log: builds imported from a file or edited by hand (builds.imported, builds.edited - who saved which
 *   character's builds; no name is kept with a build), the catalog refreshed, the strings and languages, the site
 *   settings changed (site_settings.changed, page_access.added, .changed, .removed), a person's data deleted,
 *   migrations applied, the tools' own - editors' too, so in this site's words;
 *   no address (AdminTools::auditLog() sets no recordAddress), never pruned;
 * - backups: the library's, plain (sealed, not encrypted), its keeping by count - 14 daily (made after an answer
 *   on a UTC day the site is used), 5 before an update, 5 before a restore, by hand until deleted
 *   (AdminTools::backups() sets none of its own) -, downloadable by an ADMIN: the library's words, 0.6.2's, but the
 *   first sentence, which said "every day";
 * - this browser's storage: the sign-in's (the library's auth module, `anotoki-auth:{client id}`; its hint marker
 *   `anotoki-auth:{client id}:hint`, which no sign-out removes; its session items while a sign-in is under way), the
 *   IAM's hint cookie (read here), how the present sign-in began (SIGN_IN_KIND_STORAGE_KEY), the theme (THEME_KEY),
 *   the language and its cached strings (LANGUAGE_STORAGE; a `?lang=` held for the tab under its language name in
 *   sessionStorage), the visitor's key (VISITOR_STORAGE_KEY), What's new seen (CHANGELOG_SEEN_KEY), the admin
 *   pages' unsaved words (LANGUAGE_STORAGE.drafts), the mark of the announcement its reader hid (ANNOUNCEMENT_KEY:
 *   the library's preset - a mark of its words and kind, never the words);
 * - counting visits: the family's Umami (src/index.html: umami.anotoki.eu, the query and the fragment left out) -
 *   the library's words;
 * - who else handles data: the hosting company (HOSTING), Cloudflare, and the public showcase service a UID import
 *   asks (config/app.php `showcase`) - described, never named: the site names no other website, service or tool.
 *
 * This site takes feedback: the Contact page opens the family's form in the kit's dialog (configured in
 * shared/feedback.ts) - but where `feedbackForm` is false, which only the Contact page's frame says, while no message
 * can be sent (legalPagesConfig()). Every address the pages link to is the code's - the anotoki account's pages from
 * the IAM's address the site signs in with (/api/meta's `auth`), the hosting company's here, the operator and
 * Cloudflare in the library -, never a string's: a rewording on the Translations page can never change where a link
 * goes (the 0.6.0 review). So are the cookie's and the stored items' names.
 */
export function siteLegalFacts(i18n: TranslationService, auth: AnotokiAuth, visitorDays: number, feedbackForm = true): LegalFacts {
  const iam = auth.issuer().replace(/\/+$/, '');
  const client = auth.clientId() || 'build-analyzer';
  // Each key written whole: the key scan (php translations.php --status) reads the keys a page asks for as they are written.
  return {
    site: BRAND,
    about: i18n.t('legal.about'),
    updated: SITE_LEGAL_UPDATED,
    account: { deleteUrl: auth.accountUrl(), privacyUrl: iam ? `${iam}/privacy` : null },
    data: [
      {
        name: i18n.t('legal.record.name'),
        what: i18n.t('legal.record.what'),
        why: i18n.t('legal.record.why'),
        kept: i18n.t('legal.record.kept'),
      },
      {
        name: i18n.t('legal.accounts.name'),
        what: i18n.t('legal.accounts.what'),
        why: i18n.t('legal.accounts.why'),
        kept: i18n.t('legal.accounts.kept'),
      },
      {
        name: i18n.t('legal.staff.name'),
        what: i18n.t('legal.staff.what'),
        why: i18n.t('legal.staff.why'),
        kept: i18n.t('legal.staff.kept'),
      },
      {
        name: i18n.t('legal.settings.name'),
        what: i18n.t('legal.settings.what'),
        why: i18n.t('legal.settings.why'),
        kept: i18n.t('legal.settings.kept'),
      },
      {
        name: i18n.t('legal.limits.name'),
        what: i18n.t('legal.limits.what'),
        why: i18n.t('legal.limits.why'),
        kept: i18n.t('legal.limits.kept'),
      },
      {
        name: i18n.t('legal.showcase.name'),
        what: i18n.t('legal.showcase.what'),
        why: i18n.t('legal.showcase.why'),
        kept: i18n.t('legal.showcase.kept'),
      },
      {
        name: i18n.t('legal.log.name'),
        what: i18n.t('legal.log.what'),
        why: i18n.t('legal.log.why'),
        kept: i18n.t('legal.log.kept'),
      },
    ],
    visitors: {
      what: i18n.t('legal.visitors.what'),
      why: i18n.t('legal.visitors.why'),
      kept: i18n.t('legal.visitors.kept', { days: visitorDays }),
    },
    // What a message holds and how long it is kept; false, the Contact page gives the address alone.
    feedback: feedbackForm ? { what: i18n.t('legal.feedback.what'), kept: i18n.t('legal.feedback.kept') } : false,
    // The editors' and administrators' changes - the library's words say the administrators' alone.
    auditLog: { what: i18n.t('legal.auditLog.what') },
    // One on each day the site is used - the library's first sentence says "every day"; its keeping (by count) as it is.
    backups: { what: i18n.t('legal.backups.what') },
    storage: [
      {
        preset: 'signIn',
        name: `anotoki-auth:${client}`,
        purpose: i18n.t('legal.storage.signIn.purpose'),
      },
      {
        name: `anotoki-auth:${client}:hint`,
        kind: 'local',
        purpose: i18n.t('legal.storage.hintTried.purpose'),
        kept: i18n.t('legal.storage.hintTried.kept'),
      },
      {
        name: `anotoki-auth:${client}:login:…, anotoki-auth:discovery:…`,
        kind: 'session',
        purpose: i18n.t('legal.storage.signInUnderWay.purpose'),
        kept: i18n.t('legal.storage.signInUnderWay.kept'),
      },
      {
        name: SIGN_IN_KIND_STORAGE_KEY,
        kind: 'local',
        purpose: i18n.t('legal.storage.signInKind.purpose'),
        kept: i18n.t('legal.storage.signInKind.kept'),
      },
      { preset: 'signInHint', name: SIGN_IN_HINT_COOKIE },
      { preset: 'theme', name: THEME_KEY },
      { preset: 'language', name: `${LANGUAGE_STORAGE.language}, ${LANGUAGE_STORAGE.cache}` },
      {
        name: LANGUAGE_STORAGE.language,
        kind: 'session',
        purpose: i18n.t('legal.storage.tabLanguage.purpose'),
        kept: i18n.t('legal.storage.untilTabClosed'),
      },
      {
        preset: 'visitorKey',
        name: VISITOR_STORAGE_KEY,
        kept: i18n.t('legal.storage.visitorKey.kept'),
      },
      {
        name: CHANGELOG_SEEN_KEY,
        kind: 'local',
        purpose: i18n.t('legal.storage.whatsNew.purpose'),
        kept: i18n.t('legal.storage.whatsNew.kept'),
      },
      {
        name: LANGUAGE_STORAGE.drafts,
        kind: 'session',
        purpose: i18n.t('legal.storage.wordDrafts.purpose'),
        kept: i18n.t('legal.storage.untilTabClosed'),
      },
      // The announcement bar's mark of the announcement its reader hid (shared/site-settings.ts): the library's words.
      { preset: 'announcement', name: ANNOUNCEMENT_KEY },
    ],
    processors: [
      { preset: 'hosting', name: HOSTING.name, url: HOSTING.url },
      { preset: 'cloudflare' },
      // Asked from the server alone, with a UID somebody typed: the visitor's browser never reaches it.
      { name: i18n.t('legal.showcaseService.name'), role: i18n.t('legal.showcaseService.role') },
    ],
    fanDisclaimer: 'hoyoverse',
  };
}
