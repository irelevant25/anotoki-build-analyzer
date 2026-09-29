import { Injectable, InjectionToken, computed, inject, signal } from '@angular/core';
import { AnotokiAuth, AnotokiAuthError, AnotokiAuthOptions, AnotokiUser, createAuth } from './iam/anotoki-auth';
import { AuthConfig } from './models';

/** Makes the IAM client (the vendored anotoki-auth SDK); tests provide a fake. */
export const AUTH_CLIENT_FACTORY = new InjectionToken<(options: AnotokiAuthOptions) => AnotokiAuth>('AUTH_CLIENT_FACTORY', {
  providedIn: 'root',
  factory: () => createAuth,
});

export const ROLE_ADMIN = 'ADMIN';
export const ROLE_MEMBER = 'MEMBER';

/**
 * "iam": people sign in at the anotoki IAM. "none": sign-in is off (a copy on the owner's computer) and
 * everything is allowed. "unavailable": the server could not be asked yet; pages show their own errors.
 */
export type AuthMode = 'iam' | 'none' | 'unavailable';

/**
 * Who is signed in, as signals, around the anotoki IAM's browser SDK (core/iam/anotoki-auth.mjs, vendored).
 * The SDK keeps the tokens in localStorage (shared by every tab) and refreshes them; this service only
 * mirrors its state for the UI, the guards and the HTTP interceptor. The server checks every token itself.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly createClient = inject(AUTH_CLIENT_FACTORY);
  private client: AnotokiAuth | null = null;

  readonly mode = signal<AuthMode>('unavailable');
  readonly user = signal<AnotokiUser | null>(null);
  /** Why the last sign-in did not complete (refused at the IAM, or the answer could not be used). */
  readonly lastError = signal<string | null>(null);
  /** The IAM's account page (profile, password, sessions), once the IAM client exists. */
  readonly accountUrl = signal<string | null>(null);

  readonly usesSignIn = computed(() => this.mode() === 'iam');
  readonly signedIn = computed(() => this.mode() === 'none' || this.user() !== null);
  readonly roles = computed(() => (this.mode() === 'none' ? [ROLE_ADMIN, ROLE_MEMBER] : (this.user()?.roles ?? [])));
  readonly isAdmin = computed(() => this.roles().includes(ROLE_ADMIN));
  /** May use the app: a member or an admin (everyone, with sign-in off). */
  readonly isMember = computed(() => this.isAdmin() || this.roles().includes(ROLE_MEMBER));
  readonly displayName = computed(() => {
    const user = this.user();
    return user ? (user.preferred_username ?? user.email ?? `user ${user.sub}`) : null;
  });

  /**
   * Once, from the app initializer, before the first navigation: finishes a sign-in callback (the SDK
   * then puts the address the person started from back into the address bar) or restores the session.
   */
  async init(config: AuthConfig | null): Promise<void> {
    if (!config) {
      this.mode.set('unavailable');
      return;
    }
    if (config.mode === 'none') {
      this.mode.set('none');
      return;
    }
    if (!config.issuer || !config.client_id) {
      this.mode.set('unavailable');
      this.lastError.set('Sign-in is not configured on this server.');
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
    this.mode.set('iam');
    this.accountUrl.set(client.accountUrl());
    // Signed in, out or refreshed - here or in another tab.
    client.onChange(() => this.user.set(client.isAuthenticated() ? client.user() : null));

    try {
      const { error } = await client.init();
      if (error) {
        this.lastError.set(describe(error));
      }
    } catch {
      // The IAM could not be reached; a stored session stays usable until its tokens run out.
    }
    this.user.set(client.isAuthenticated() ? client.user() : null);
  }

  /** Goes to the IAM's sign-in page; the callback brings the person back to returnTo. */
  login(returnTo?: string, options: { anotherAccount?: boolean } = {}): Promise<void> {
    this.lastError.set(null);
    return this.client?.login({ returnTo: returnTo ?? currentPath(), prompt: options.anotherAccount ? 'login' : undefined }) ?? Promise.resolve();
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

function describe(error: AnotokiAuthError): string {
  switch (error.code) {
    case 'access_denied':
      // The IAM turned the account away ("This account cannot use Build Analyzer", "Confirm your email
      // address first") or the person went back from its page ("The sign-in was cancelled"). Its own
      // description says which, and is meant to be shown as it is - nothing is decided on it.
      return error.message ? `${error.message.replace(/\.$/, '')}.` : 'The sign-in was cancelled.';
    case 'state_mismatch':
      return 'That sign-in did not start here, or took too long. Please sign in again.';
    default:
      return `The sign-in did not complete: ${error.message}`;
  }
}
