import { Injectable, computed, inject } from '@angular/core';
import { AnotokiAuth, AnotokiPreferenceChanges, AnotokiPreferences, AnotokiSavedPreferences } from '@anotoki/lib/auth';

/**
 * Who is signed in, as this site's pages ask it - the anotoki family's auth module (@anotoki/lib/auth:
 * AnotokiAuth, set up in app.config.ts) seen through the names the pages have always used. The module is the
 * sign-in: the IAM's client, the tokens (in localStorage under the same keys as the IAM's SDK kept them, so
 * nobody was signed out by the move), the callback, following the IAM, the sign-in hint's automatic sign-in of
 * a visitor, the interceptor and the guards. This adds nothing of its own. A theme or a language the account
 * refused is said by the family's bar itself (its theme switch's and language chooser's notes).
 *
 * Somebody who is not signed in uses the site all the same, as a visitor (core/visitor-key.ts) - unless the IAM
 * says this site requires a sign-in (`loginRequired`): then every page asks for one (core/auth.guards.ts).
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly auth = inject(AnotokiAuth);

  /** Whether a sign-in can be offered: the server named an IAM, and the IAM knows this site. */
  readonly available = computed(() => this.auth.signInAvailable());
  /**
   * Whether the IAM's "Sign-in required" switch is on for this site, as the server relays it in /api/meta - true
   * until the server has said otherwise, and when it could not be asked (it fails closed). While it is true
   * nobody uses the site as a visitor: the visitor key is kept but not sent, and the next sign-in moves what it
   * holds into the account.
   */
  readonly loginRequired = this.auth.loginRequired;
  /** The person the page is shown to, with this site's roles - null for nobody and for a visitor. */
  readonly user = this.auth.user;
  readonly signedIn = this.auth.signedIn;
  /**
   * The page is shown to a visitor: nobody signed in, while the IAM lets visitors in (and the page was never shown
   * to a person). A request that does not say whom it is for goes as the visitor's then - their key when there is
   * one, never a token; to somebody signed out otherwise it would go as a person's with no token, and not be sent.
   */
  readonly visiting = computed(() => this.auth.state() === 'visitor');
  /**
   * The token's roles for this site, upper case: EDITOR runs the syncs and rewords the site's strings; ADMIN does
   * that and more. MEMBER, which every account holds, changes nothing (an ordinary person).
   */
  readonly roles = this.auth.roles;
  readonly isAdmin = this.auth.isAdmin;
  /** May use the admin panel: an editor or an admin. The server decides for itself; this only hides. */
  readonly isStaff = this.auth.isStaff;
  /**
   * The signed-in person's name: the user name, else the email address - or null (nobody is signed in, or the
   * account has neither), and the template words what it shows then from `user().sub`.
   */
  readonly displayName = computed(() => this.auth.displayName() || null);
  /**
   * The account's theme, date order and clock: one choice every anotoki site follows - this one on every page,
   * the admin panel included - while somebody is signed in. Null while nobody is, and while the IAM keeps none:
   * the site then keeps its own settings, and saves nothing to the account. The account's language is
   * `user().locale`, beside it.
   */
  readonly preferences = computed<AnotokiPreferences | null>(() => this.auth.preferences());
  /**
   * A sign-in that came back without one, or could not start (its code, never the IAM's sentence): the
   * failure's screen says it (/sign-in, the library's <anotoki-sign-in-failure>).
   */
  readonly failure = this.auth.signInFailure;
  /** The IAM's account page (profile, password, sessions), once the sign-in is set up. */
  readonly accountUrl = computed(() => (this.auth.state() === 'starting' || this.auth.state() === 'unavailable' ? null : this.auth.accountUrl() || null));

  /**
   * Off to the IAM's sign-in, back to `returnTo` (this page by default) - the IAM's pages in the language read
   * here, once the saves on their way have their answers (a visitor's first import brings the key the move
   * after the sign-in needs). True when the browser is on its way; false when it could not start (`failure`
   * says why).
   */
  login(returnTo?: string): Promise<boolean> {
    return this.auth.signIn(returnTo ? { returnTo } : {});
  }

  /** Signs out everywhere (the IAM ends its session) and comes back to the start page. */
  async logout(): Promise<void> {
    await this.auth.signOut();
  }

  /**
   * Saves what the person chose with this site's own controls - the theme, the language - to their account, for
   * every anotoki site. Only while `preferences()` is not null. Rejects with an error whose `code` says why.
   */
  savePreferences(changes: AnotokiPreferenceChanges): Promise<AnotokiSavedPreferences> {
    return this.auth.savePreferences(changes);
  }
}
