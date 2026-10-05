import { Injectable, InjectionToken, computed, inject, signal } from '@angular/core';
import { AnotokiAuth, AnotokiAuthOptions, AnotokiUser, createAuth } from './iam/anotoki-auth';
// The shape only: this service never injects the translation service (which reads this one).
import type { Wording } from './i18n/translation';
import { AuthConfig } from './models';
import { SavesInFlight } from './saves-in-flight';

/** Makes the IAM client (the vendored anotoki-auth SDK); tests provide a fake. */
export const AUTH_CLIENT_FACTORY = new InjectionToken<(options: AnotokiAuthOptions) => AnotokiAuth>('AUTH_CLIENT_FACTORY', {
  providedIn: 'root',
  factory: () => createAuth,
});

/** This site's roles, from the IAM: EDITOR runs the syncs and rewords the site's strings; ADMIN does that and more. */
export const ROLE_ADMIN = 'ADMIN';
export const ROLE_EDITOR = 'EDITOR';

/**
 * Who is signed in, as signals, around the anotoki IAM's browser SDK (core/iam/anotoki-auth.mjs, vendored).
 * The IAM is the only way to sign in. The SDK keeps the tokens in localStorage (shared by every tab) and
 * refreshes them; this service only mirrors its state for the UI, the guards and the HTTP interceptor. The
 * server checks every token itself.
 *
 * Somebody who is not signed in uses the site all the same, as a visitor (core/visitor-key.ts) - a service
 * this one never injects: it reads this one, and the move after a sign-in is started there - unless the IAM
 * says this site requires a sign-in (`loginRequired`): then every page asks for one (siteSignInGuard).
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly createClient = inject(AUTH_CLIENT_FACTORY);
  private readonly saves = inject(SavesInFlight);
  private client: AnotokiAuth | null = null;
  /** The sign-in this page load came back from did not complete, and no page has said so yet. */
  private refusedOnReturn = false;

  /** Whether people can sign in: the server said where its IAM is (false before that, or when it could not be asked). */
  readonly available = signal(false);
  /**
   * Whether the IAM's "Sign-in required" switch is on for this site, as the server relays it in /api/meta
   * (`auth.login_required`), read once a page load. True until the server has said otherwise - and when it
   * could not be asked, or said nothing: the pages then ask for a sign-in, and the sign-in page says why
   * it cannot happen. While it is true nobody uses the site as a visitor: the visitor key is kept but not
   * sent (core/visitor-key.ts), and the next sign-in moves what it holds into the account.
   */
  readonly loginRequired = signal(true);
  readonly user = signal<AnotokiUser | null>(null);
  /**
   * Why the last sign-in did not complete (refused at the IAM, or the answer could not be used): a key
   * with its params, for the page to word - `error.key | translate: error.params`. It comes from the
   * SDK's error code alone; the IAM's and the SDK's own sentences are never shown.
   */
  readonly lastError = signal<Wording | null>(null);
  /** The IAM's account page (profile, password, sessions), once the IAM client exists. */
  readonly accountUrl = signal<string | null>(null);

  readonly signedIn = computed(() => this.user() !== null);
  /** The token's roles for this site, upper case; MEMBER, which every account holds, changes nothing (an ordinary person). */
  readonly roles = computed(() => (this.user()?.roles ?? []).map((role) => String(role).toUpperCase()));
  readonly isAdmin = computed(() => this.roles().includes(ROLE_ADMIN));
  /** May use the admin panel: an editor or an admin. The server decides for itself; this only hides. */
  readonly isStaff = computed(() => this.isAdmin() || this.roles().includes(ROLE_EDITOR));
  /**
   * The signed-in person's name: the user name, else the email address - or null (nobody is signed in, or
   * the account has neither), and the template words what it shows then from `user().sub`.
   */
  readonly displayName = computed(() => {
    const user = this.user();
    return user ? (user.preferred_username ?? user.email ?? null) : null;
  });

  /**
   * Once, from the app initializer, before the first navigation: finishes a sign-in callback (the SDK
   * then puts the address the person started from back into the address bar) or restores the session.
   */
  async init(config: AuthConfig | null): Promise<void> {
    this.loginRequired.set(config?.login_required !== false);
    if (!config) {
      this.available.set(false);
      return;
    }
    if (!config.issuer || !config.client_id) {
      this.available.set(false);
      this.lastError.set({ key: 'signIn.notConfigured', params: {} });
      return;
    }

    const origin = window.location.origin;
    const client = this.createClient({
      issuer: config.issuer,
      clientId: config.client_id,
      redirectUri: `${origin}/auth/callback`,
      postLogoutRedirectUri: `${origin}/`,
    });
    this.client = client;
    this.available.set(true);
    this.accountUrl.set(client.accountUrl());
    // Signed in, out or refreshed - here or in another tab.
    client.onChange(() => this.user.set(client.isAuthenticated() ? client.user() : null));

    try {
      const { error } = await client.init();
      if (error) {
        this.lastError.set(signInProblem(error.code));
        this.refusedOnReturn = true;
      }
    } catch {
      // The IAM could not be reached; a stored session stays usable until its tokens run out.
    }
    this.user.set(client.isAuthenticated() ? client.user() : null);
  }

  /**
   * Goes to the IAM's sign-in page; the callback brings the person back to returnTo.
   *
   * `uiLocales` is the language the IAM's pages should open in. The caller says it - this service knows
   * nothing of the site's words - and only when the language is known (TranslationService.signInLanguage():
   * a bundle is in memory); left out, the IAM chooses for itself.
   *
   * Answers true when the browser is on its way to the IAM. False when the sign-in could not even start:
   * there is no IAM to go to, or the browser refuses the site's storage, where the SDK keeps the sign-in in
   * progress (`storage_unavailable`) - then `lastError` says why, and a Sign in button says it in a snack
   * bar (signInOrSayWhy(), core/sign-in.ts) instead of doing nothing at all.
   *
   * It leaves only once the saves still on their way have their answers (SavesInFlight, SAVES_WAIT_MS at
   * most): a visitor's first import brings the visitor key, which the move after the sign-in needs.
   */
  async login(returnTo?: string, options: { anotherAccount?: boolean; uiLocales?: string | null } = {}): Promise<boolean> {
    this.lastError.set(null);
    if (!this.client) {
      return false;
    }
    await this.saves.settled();
    try {
      await this.client.login({
        returnTo: returnTo ?? currentPath(),
        prompt: options.anotherAccount ? 'login' : undefined,
        ...(options.uiLocales ? { uiLocales: options.uiLocales } : {}),
      });
      return true;
    } catch (error) {
      this.lastError.set(signInProblem((error as { code?: unknown } | null)?.code));
      return false;
    }
  }

  /**
   * Once a page load: whether the sign-in it came back from was refused or failed (`lastError` says why -
   * an `access_denied` is also how the IAM turns away a person whose MEMBER role was taken) and no page has
   * said so yet. siteSignInGuard then shows the sign-in page, visiting or not, so it is never swallowed.
   */
  takeRefusalOnReturn(): boolean {
    const refused = this.refusedOnReturn && !this.signedIn();
    this.refusedOnReturn = false;
    return refused;
  }

  /** Signs out everywhere (the IAM ends its session) and comes back to the start page. */
  logout(): Promise<void> {
    return this.client?.logout() ?? Promise.resolve();
  }

  /** A valid access token for the API, refreshed when it is about to expire; null when signed out. */
  accessToken(): Promise<string | null> {
    return this.client ? this.client.getAccessToken() : Promise.resolve(null);
  }

  /** After the API refused the current token: a different, fresh one, or null (signed out). */
  refreshedToken(): Promise<string | null> {
    return this.client ? this.client.refresh() : Promise.resolve(null);
  }
}

function currentPath(): string {
  return window.location.pathname + window.location.search + window.location.hash;
}

/** An OAuth error code, or one of the SDK's own: a short word, nothing a sentence could hide in. */
const FAILURE_CODE = /^[A-Za-z0-9_.-]{1,40}$/;

/**
 * What to say of a sign-in that came back without one - from the error's code alone, never from its
 * message (the IAM's or the SDK's English sentence):
 *
 *   access_denied    the IAM turned the account away, or the person went back from its page: one
 *                    sentence that is true either way
 *   state_mismatch   the sign-in did not start here, or took too long
 *   storage_unavailable  the SDK's own: the browser refuses the site's storage, so the sign-in never started
 *   anything else    `signIn.failed` with the code itself as {code} - when it looks like a code; it came
 *                    through the address, so anything else is "unknown"
 */
export function signInProblem(code: unknown): Wording {
  switch (code) {
    case 'access_denied':
      return { key: 'signIn.notCompleted', params: {} };
    case 'state_mismatch':
      return { key: 'signIn.stateMismatch', params: {} };
    case 'storage_unavailable':
      return { key: 'signIn.storageUnavailable', params: {} };
    default:
      return { key: 'signIn.failed', params: { code: typeof code === 'string' && FAILURE_CODE.test(code) ? code : 'unknown' } };
  }
}
