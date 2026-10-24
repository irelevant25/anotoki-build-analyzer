import { Injectable, inject } from '@angular/core';
import { AnotokiAuthClient, AnotokiAuthEnvironment, AnotokiAuthOptions, AnotokiInitResult, createAuthClient } from '@anotoki/lib/auth';
import { readStorage, writeStorage } from './storage';

/** Where this browser keeps how its present sign-in began (localStorage: every tab of the site shares it). */
export const SIGN_IN_KIND_STORAGE_KEY = 'build-analyzer-sign-in';

/**
 * How the sign-in this browser holds now began: `silent` - the IAM's sign-in hint's silent hop, nobody's
 * click - or `clicked` - every other way in (Sign in, the IAM's own page when this site requires signing in,
 * Try again). The move of a visitor's data after a sign-in asks the server with it (VisitorKey): the owner's
 * decision of 2026-10-11 - an account that already has data here never takes in a browser's visitor data
 * without its person's click.
 *
 * Every session in this browser begins with a callback, and the auth module's client says of each whether it
 * answered a silent sign-in (its init()'s `silent`): noted here at that page load, kept for the page loads
 * after it - the session they restore is the same. No note (a session from before this was kept): clicked,
 * as every sign-in was before the hint.
 */
@Injectable({ providedIn: 'root' })
export class SignInKind {
  /** The page load's own note, when storage cannot keep one. */
  private noted: 'silent' | 'clicked' | null = null;

  /** Whether the present sign-in was the hint's silent hop. */
  silent(): boolean {
    return (this.noted ?? readStorage(SIGN_IN_KIND_STORAGE_KEY)) === 'silent';
  }

  /** What the client's init() said: a callback that signed somebody in notes how. */
  note(result: AnotokiInitResult): void {
    if (result.returnTo === null || result.error !== null) {
      return;
    }
    this.noted = result.silent ? 'silent' : 'clicked';
    writeStorage(SIGN_IN_KIND_STORAGE_KEY, this.noted);
  }
}

/**
 * The auth module's client (createAuthClient, or a spec's) with its init()'s answer noted by SignInKind: the one
 * place the site learns whether a callback answered a silent sign-in. Provided as ANOTOKI_AUTH_CLIENT_FACTORY
 * (app.config.ts).
 */
export function clientNotingSignInKind(
  kind: SignInKind,
  make: (options: AnotokiAuthOptions, environment: AnotokiAuthEnvironment) => AnotokiAuthClient = createAuthClient,
): (options: AnotokiAuthOptions, environment: AnotokiAuthEnvironment) => AnotokiAuthClient {
  return (options, environment) => {
    const client = make(options, environment);
    const init = client.init.bind(client);
    client.init = async () => {
      const result = await init();
      kind.note(result);
      return result;
    };
    return client;
  };
}

/** For app.config.ts: the factory, with the root's SignInKind. */
export function signInKindClientFactory(): (options: AnotokiAuthOptions, environment: AnotokiAuthEnvironment) => AnotokiAuthClient {
  return clientNotingSignInKind(inject(SignInKind));
}
