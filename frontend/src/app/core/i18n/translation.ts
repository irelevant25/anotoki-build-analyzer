import { DOCUMENT, Injectable, Injector, computed, effect, inject, signal, untracked } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { Api } from '../api';
import { AuthService } from '../auth';
import { MetaStore } from '../meta-store';
import { SiteLanguage, TranslationBundle } from '../models';
import { PluralKey, TranslationKey, en } from './en';

/**
 * What everything falls back to: the server merges English underneath every bundle, the English pages
 * are English whatever the person reads, and the compiled dictionary (en.ts) is English.
 */
export const FALLBACK_LANGUAGE = 'en';

/**
 * The menu's choice (localStorage) and the `?lang=` of this tab (sessionStorage): the same name in two
 * stores with two lifetimes.
 */
const LANGUAGE_KEY = 'build-analyzer-language';
/** The last bundle, so a returning reader starts in their own language while the fresh one is on its way. */
const CACHE_KEY = 'build-analyzer-language-cache';

/** A language code as the database keeps it - the same expression as the server's check. */
const LANGUAGE_CODE = /^[a-z]{2}(-[a-z]{2})?$/;
/** A placeholder in a string - the same expression as the server's check. */
const PLACEHOLDER = /\{([A-Za-z][A-Za-z0-9_]*)\}/g;

/**
 * At the browser step only: a language nobody offers that reads another far more easily than English.
 * Czech readers read Slovak. An exact match always wins - this counts only while no `cs` is on offer.
 */
const BROWSER_ALIASES: Record<string, string> = { cs: 'sk' };

/** What `Intl` is given: British English writes dates the way the rest of Europe reads them. */
const LOCALES: Record<string, string> = { en: 'en-GB', sk: 'sk-SK' };

/**
 * The pages that are English whatever the person reads: the admin's Sources page and the owner's two.
 * The address itself, or anything under it.
 */
const ENGLISH_PATHS = ['/sources', '/translations', '/languages'];

/** How long a start with a cached bundle waits for the fresh one (a 304, usually). */
export const CACHE_WAIT_MS = 1000;
/** How long a first visit waits for its words before the page starts on the compiled English. */
export const FIRST_WAIT_MS = 3000;

/** What fills a string's {placeholders}. */
export type TranslationParams = Record<string, string | number | null | undefined>;

/**
 * Something to say, as state holds it: a key and what fills it - never the sentence, which is worded
 * where it is shown (`wording.key | translate: wording.params`, or the errorText pipe) and so follows the
 * language.
 */
export interface Wording {
  key: TranslationKey;
  params?: TranslationParams;
}

/**
 * A sentence as the page shows it, with the `lang` its element carries: null for the site's own words
 * (the page's language), `en` for English that was stored as data - shown where there is no key to word
 * it with - while the page is in another language. `<span [attr.lang]="worded.lang">{{ worded.text }}</span>`.
 */
export interface Worded {
  text: string;
  lang: 'en' | null;
}

/** A language somebody may want, and whether it is the browser that said so (only there do aliases count). */
export interface LanguageCandidate {
  code: string;
  browser: boolean;
}

/** Where a wish for a language can come from, strongest first. */
export interface LanguageSources {
  /** `?lang=`, kept for this tab. */
  tab?: string | null;
  /** What the menu remembered on this device. */
  chosen?: string | null;
  /** The `locale` claim of the IAM's token (only where people sign in). */
  token?: string | null;
  /** `navigator.languages`. */
  browser?: readonly (string | null | undefined)[];
}

/** The code, lower-cased, when it has the shape of one - anything else is nothing: never stored, never sent. */
export function languageCode(raw: unknown): string | null {
  const code = typeof raw === 'string' ? raw.toLowerCase() : '';
  return LANGUAGE_CODE.test(code) ? code : null;
}

/**
 * Every language the reader may want, in order: `?lang=` / this tab, the menu's remembered choice, the
 * token's `locale`, each browser tag and then its first subtag (`sk-SK` also asks for `sk`), and English.
 */
export function languageCandidates(sources: LanguageSources): LanguageCandidate[] {
  const candidates: LanguageCandidate[] = [];
  const add = (raw: unknown, browser: boolean): void => {
    const code = languageCode(raw);
    if (code && !candidates.some((candidate) => candidate.code === code && candidate.browser === browser)) {
      candidates.push({ code, browser });
    }
  };
  add(sources.tab, false);
  add(sources.chosen, false);
  add(sources.token, false);
  for (const tag of sources.browser ?? []) {
    add(tag, true);
    add(typeof tag === 'string' ? tag.split('-')[0] : null, true);
  }
  add(FALLBACK_LANGUAGE, false);
  return candidates;
}

/** The first candidate that is on offer - or, for a browser's wish, whose alias is - else English. */
export function pickLanguage(candidates: readonly LanguageCandidate[], offered: readonly string[]): string {
  for (const candidate of candidates) {
    if (offered.includes(candidate.code)) {
      return candidate.code;
    }
    if (candidate.browser && Object.hasOwn(BROWSER_ALIASES, candidate.code) && offered.includes(BROWSER_ALIASES[candidate.code])) {
      return BROWSER_ALIASES[candidate.code];
    }
  }
  return FALLBACK_LANGUAGE;
}

/** Whether an address is one of the English pages: `/sources`, `/translations`, `/languages`, or under one. */
export function isEnglishPath(url: string): boolean {
  const path = url.split(/[?#]/)[0];
  return ENGLISH_PATHS.some((english) => path === english || path.startsWith(`${english}/`));
}

/** The compiled English, as a map - so `has('constructor')` is false, as it is for a bundle. */
const COMPILED: ReadonlyMap<string, string> = new Map(Object.entries(en));

/**
 * The site's own words: which language the pages are read in, and the strings for it. They live in the
 * database (the Translations page edits them) and arrive as one bundle - GET /api/translations/{code}.
 *
 * Only what a member reads is translated. The Sources page and the owner's Translations and Languages
 * pages are English whatever is chosen: `effectiveLanguage` says `en` while the address is one of them,
 * and everything that words a string goes through it - so the toolbar they share is English there too.
 *
 * With a bundle in memory, the bundle is what is shown: a key it has no string for comes back as itself,
 * on the page, and warns once - the owner wants to see which key is missing. The compiled English (en.ts)
 * is read only while there is no bundle at all: between an upload and Apply on the Sources page the
 * database has no strings yet and the route answers 503, and the pages must be readable all the same.
 *
 * Game terms are not in here at all: they reach a sentence as a {placeholder} (core/game-terms.ts, or
 * what /api/meta sends), and game values keep the game's notation (MetaStore.formatStat()).
 *
 * Keys are never built from the address, from the IAM's answer or from anything a person typed - run-time
 * keys come from closed sets, through a table with a fallback (error-text.ts, recommendation-words.ts,
 * import-words.ts; dynamic-keys.ts lists them). And state holds keys, kinds and errors, never a worded
 * sentence: wording happens in a template (the pipes) or inside a computed() / effect() that calls t(),
 * which reads signals and so runs again when the language changes.
 *
 * This service never injects the Router and starts no request while it is being built: the Router builds
 * the title strategy, the strategy needs this service, and asking for the Router here would close the
 * circle (NG0200 at start-up). The strategy says where the page is instead - noteAddress(). The API and
 * the metadata are asked for when they are first needed, not in the constructor.
 *
 * Every load - the start, a choice, reload(), asking again - takes the next turn, and an answer is used
 * only while its turn is still the last one: the load begun last wins, however late an earlier answer comes.
 */
@Injectable({ providedIn: 'root' })
export class TranslationService {
  private readonly _injector = inject(Injector);
  private readonly _auth = inject(AuthService);
  private readonly _document = inject(DOCUMENT);

  private readonly _language = signal(FALLBACK_LANGUAGE);
  private readonly _languages = signal<SiteLanguage[]>([]);
  /** The chosen language's strings, English merged underneath. Null: no bundle in memory. */
  private readonly _values = signal<ReadonlyMap<string, string> | null>(null);
  /** English itself, as the bundle has it - what the English pages read. */
  private readonly _english = signal<ReadonlyMap<string, string> | null>(null);
  private readonly _englishOnly = signal(false);

  /**
   * The person's language. A plain signal: it changes only once the bundle of that language is in memory
   * (init(), setLanguage()), never by itself when a token or the address changes - a page is never in a
   * language it has no strings for. `en` while there is no bundle.
   */
  readonly language = this._language.asReadonly();
  /** The languages on offer, in the order the owner set. Empty while no bundle has arrived. */
  readonly languages = this._languages.asReadonly();
  /** A bundle is in memory - from the cache or from the server. False: the pages read the compiled English. */
  readonly ready = computed(() => this._values() !== null);
  /** The address is one of the English pages. */
  readonly englishOnly = this._englishOnly.asReadonly();
  /** The language actually on the page: English on the English pages, the person's everywhere else. */
  readonly effectiveLanguage = computed(() => (this._englishOnly() ? FALLBACK_LANGUAGE : this._language()));
  /** What `Intl` is given for dates and numbers in the language on the page. */
  readonly locale = computed(() => {
    const code = this.effectiveLanguage();
    return Object.hasOwn(LOCALES, code) ? LOCALES[code] : code;
  });
  /**
   * The `lang` of an element whose whole content is stored English - a changelog entry, a source's note,
   * an import error kept from before the strings existed: `en` while the page is in another language,
   * nothing (null removes the attribute) while it is English anyway.
   */
  readonly storedEnglishLang = computed<'en' | null>(() => (this.effectiveLanguage() === FALLBACK_LANGUAGE ? null : FALLBACK_LANGUAGE));
  /**
   * What signing in hands to the IAM as `uiLocales`: the person's language - but only when a bundle is in
   * memory. With none, `language` is an `en` nobody chose, and saying it would override the IAM's own choice.
   */
  readonly signInLanguage = computed<string | undefined>(() => (this.ready() ? this._language() : undefined));

  /** The strings of the language on the page. Maps, so `has('constructor')` is false. */
  private readonly _strings = computed<ReadonlyMap<string, string>>(() => (this._englishOnly() ? this._english() : this._values()) ?? COMPILED);

  /** Keys already complained about, so one missing string is one warning. */
  private readonly _warned = new Set<string>();
  /** Counts the loads begun: an answer that arrives after a later load began is dropped. */
  private _turn = 0;
  /** The loads still out. While there is one, nothing asks again: never two in flight for the same thing. */
  private _out = 0;
  /** init() has decided the language at least once: only then is there something to ask again for. */
  private _started = false;
  /**
   * What is in force came from the server since this page was loaded. False with no bundle at all, and
   * with the cached one whose refresh has not come (or failed): then the next navigation asks again.
   */
  private _fresh = false;
  /** The first navigation of a visit ends a moment after init() asked: it does not ask a second time. */
  private _firstNavigation = false;
  /**
   * The two wishes that are kept in storage, held here as well for as long as the page lives: `?lang=` of
   * this tab, and what the menu was told. A browser whose storage refuses writes still gets both, until
   * it reloads.
   */
  private _tab: string | null = null;
  private _chosen: string | null = null;
  /** The token's `locale` as last seen: only a changed one is adopted. */
  private _seenLocale: string | null = null;

  constructor() {
    // Honest for a screen reader: the English pages say `en` whatever the person reads.
    effect(() => {
      this._document.documentElement.lang = this.effectiveLanguage();
    });

    // The token's `locale` is used, not stored: a change made on the IAM's account page arrives with the
    // next token. It reads only the token - what it then does is untracked, so putting a bundle in force
    // does not run it again. Nothing is asked for before init() has run.
    effect(() => {
      const locale = this._tokenLocale();
      untracked(() => this._followToken(locale));
    });
  }

  // ── Reading ────────────────────────────────────────────────────────────────

  /**
   * The string for a key in the language on the page, its {placeholders} filled in one pass: a
   * parameter's own text is never scanned again, and a placeholder with no parameter stays as written.
   *
   * With a bundle in memory, a key it has no string for comes back as itself - on purpose visible - and
   * warns once. With no bundle it is the compiled English.
   */
  t(key: TranslationKey, params?: TranslationParams): string {
    return this._word(key, params);
  }

  /**
   * One of a plural family - `key.one`, `key.few`, `key.other` - by the rules of the language on the page
   * (Slovak: 1 -> one, 2-4 -> few, 0 and 5+ -> other). Inside a family the number is always `{count}`,
   * whatever the code calls it - written the way the language writes numbers (number()).
   *
   * A family is for a Slovak noun that follows the number (postava, kus, build). A count beside a game
   * term is not a family: it is written "term: {count}".
   */
  plural(key: PluralKey, count: number, params?: TranslationParams): string {
    const form = `${key}.${pluralRules(this.effectiveLanguage()).select(count)}`;
    return this._word(this._strings().has(form) ? form : `${key}.other`, { ...params, count: this.number(count) });
  }

  /**
   * A number of the site's own - a count - the way the language on the page writes it: 11,523 in English,
   * 11 523 in Slovak. For a number that goes into t() as a placeholder; plural() does it for `{count}`.
   *
   * Never for a game value: a stat, a level or a percent keeps the game's notation (MetaStore.formatStat()).
   */
  number(value: number): string {
    return numberFormat(this.locale()).format(value);
  }

  /**
   * A sentence cut at one placeholder, for markup inside it:
   * `{{ before }}<strong>{{ name }}</strong>{{ after }}`. The stored string is cut first and each half
   * filled after, so nothing a parameter holds can move the cut.
   *
   * The third answer says whether the cut was made. It is not when the key has no string, and not when
   * the string leaves the placeholder out - which a language other than English may: then the whole
   * sentence (or the key) is the first half, and the page must not draw the marked-up value at all, or it
   * would be glued onto a sentence that does not name it.
   */
  around(key: TranslationKey, placeholder: string, params?: TranslationParams): [before: string, after: string, found: boolean] {
    const value = this._strings().get(key);
    if (value === undefined) {
      return [this._missing(key), '', false];
    }
    const mark = `{${placeholder}}`;
    const at = value.indexOf(mark);
    return at < 0 ? [fill(value, params), '', false] : [fill(value.slice(0, at), params), fill(value.slice(at + mark.length), params), true];
  }

  /** Whether a key has a string in what the page reads now (the bundle, or the compiled English). */
  has(key: string): boolean {
    return this._strings().has(key);
  }

  // ── Choosing ───────────────────────────────────────────────────────────────

  /**
   * The menu's choice: loads that language's bundle, then switches and remembers it on this device.
   * Never rejects - false when the strings could not be had, and the page stays as it is.
   *
   * `remember: false` is for a language that was not chosen here (the token's `locale`): used, not
   * stored - only the menu writes the choice.
   *
   * The last choice wins: a choice takes its turn even when it needs no request (the language already on
   * the page), so a slower, earlier choice still on its way is dropped when it comes - and that one
   * answers false.
   */
  async setLanguage(code: string, remember = true): Promise<boolean> {
    const wanted = languageCode(code);
    if (!wanted) {
      return false;
    }
    const turn = ++this._turn;
    if (wanted !== this._language() || !this.ready()) {
      const bundle = await this._counted(this._request(wanted));
      if (turn !== this._turn) {
        return false;
      }
      if (!bundle || bundle.language !== wanted) {
        // Switched off a moment ago: the server answered in English. The page stays in its language; the
        // menu stops offering the one that went.
        if (bundle?.languages.some((language) => language.code === this._language())) {
          this._languages.set(bundle.languages);
        }
        return false;
      }
      this._use(bundle);
    }
    if (remember) {
      // In memory too: storage may refuse the write, and the choice must hold for this page all the same.
      this._chosen = wanted;
      writeStorage(LANGUAGE_KEY, wanted);
      // A choice made here is stronger than the `?lang=` this tab came with.
      this._tab = null;
      writeTab(LANGUAGE_KEY, null);
    }
    return true;
  }

  /**
   * The strings again, from the server: for whoever has just changed them, or the languages on offer (the
   * Translations and Languages pages after a save), and for the Sources page after an Apply that applied
   * something - the strings may only now exist. Asks for the bundle of the language the person reads (the
   * first wish while there is none) and settles against the list that comes with it - so a language
   * hidden or offered a moment ago is followed - then puts the answer in force and in the cache. Never
   * rejects; when the server cannot be had, the page stays as it is - and, being known to be behind, asks
   * again at the next navigation.
   *
   * Not init(): that one is the start - it takes `?lang=` out of the address and starts from the cache.
   */
  async reload(): Promise<void> {
    try {
      // What is in memory is no longer known to be what the server has.
      this._fresh = false;
      await this._again();
    } catch (error) {
      console.error('[i18n] the strings could not be read again', error);
    }
  }

  /**
   * Where the page is, said by the title strategy after every navigation: the English pages are English.
   *
   * A navigation is also when a page that started without its strings asks for them once more: while no
   * bundle is in memory (the route answered 503 before Apply, or the request failed - the page reads the
   * compiled English), or while the cached one is in force because its refresh failed. One load a
   * navigation, and none while another is out - so such a page gets its words by itself at the next
   * click, and a server that stays down is asked no more often than somebody clicks. Not at the first
   * navigation of a visit, which ends a moment after init() asked.
   */
  noteAddress(url: string): void {
    this._place(url);
    const first = this._firstNavigation;
    this._firstNavigation = false;
    if (this._started && !first && !this._fresh && this._out === 0 && this.canAsk()) {
      void this._again().catch((error: unknown) => console.error('[i18n] the strings could not be asked for again', error));
    }
  }

  private _place(url: string): void {
    this._englishOnly.set(isEnglishPath(url));
  }

  // ── Starting ───────────────────────────────────────────────────────────────

  /**
   * Once as the site starts, after the sign-in (which, back from the IAM, has put the address back):
   * decides the language and gets its strings before the first page is drawn. Never rejects.
   *
   * While the server cannot be asked (`MetaStore.loaded()` is false: not set up, or out of reach) nothing
   * is requested: the cached bundle is taken when there is one - in whatever language it is in - and the
   * pages read the compiled English otherwise.
   *
   * With a cache the fresh bundle is asked for all the same, and the start waits a second for it; with
   * none it waits three, then starts on the compiled English - and the bundle is put in force when it
   * arrives.
   */
  async init(): Promise<void> {
    try {
      await this._start();
    } catch (error) {
      console.error('[i18n] the language could not be set up', error);
    }
  }

  private async _start(): Promise<void> {
    const location = this._document.location;
    this._place(location?.pathname ?? '/');
    this._takeAddressLanguage();
    this._seenLocale = this._tokenLocale();
    this._started = true;

    // Whether the server can be asked decides what a cache is worth. While it cannot, nobody is signed in
    // and nothing can be asked: the language the cache is in is the best there is - a reader whose
    // language comes from the token would otherwise lose their words exactly when they are all there is.
    const running = this.canAsk();
    const candidates = this._candidates();
    const cached = this._cachedBundle(candidates, running);
    if (cached) {
      this._use(cached, false);
    }
    if (!running) {
      return;
    }

    const turn = ++this._turn;
    const first = cached ? cached.language : candidates[0].code;
    this._firstNavigation = true;
    // With a cache the page may start from it, but not at once: a release adds strings, and a returning
    // reader would see their keys. The fresh bundle is a 304 most of the time - worth a second's wait.
    // Without one the wait is longer, and its end is the compiled English rather than keys. Either way
    // the answer replaces what the page started on whenever it comes.
    await Promise.race([this._counted(this._refresh(turn, candidates, first)), wait(cached ? CACHE_WAIT_MS : FIRST_WAIT_MS)]);
  }

  /**
   * The strings once more, for the wishes as they are now: what reload() does, and what a navigation does
   * for a page that has none from the server. It asks first for the language in force - its bundle names
   * the languages on offer now - and takes the next turn, so it is the load that counts.
   */
  private _again(): Promise<boolean> {
    const turn = ++this._turn;
    const candidates = this._candidates();
    return this._counted(this._refresh(turn, candidates, this.ready() ? this._language() : candidates[0].code));
  }

  /**
   * One load, put in force when it came and is still the last one begun. False: the server could not be
   * had - or a later load has taken over.
   */
  private async _refresh(turn: number, candidates: readonly LanguageCandidate[], first: string): Promise<boolean> {
    const bundle = await this._load(candidates, first);
    if (!bundle || turn !== this._turn) {
      return false;
    }
    this._use(bundle);
    return true;
  }

  /** Counted while it is out, whichever way it ends. */
  private async _counted<T>(work: Promise<T>): Promise<T> {
    this._out++;
    try {
      return await work;
    } finally {
      this._out--;
    }
  }

  /**
   * The bundle for the language the candidates settle on. The list of offered languages arrives with the
   * bundle itself, so: ask for `first`, settle against the list that came - whatever answered - and fetch
   * that bundle if it is another language than the one in hand. One request when `first` is what the
   * wishes settle on (the usual case: the server answers a regional code like `sk-sk` with its base
   * language); two at most - on a first visit whose first wish is not on offer, or when the offered
   * languages have changed since the cached bundle was made (a language hidden, offered again, or added:
   * the reader is moved to it now, not one visit late).
   *
   * When the second request fails the first answer is what there is: a page in the database's English.
   */
  private async _load(candidates: readonly LanguageCandidate[], first: string): Promise<TranslationBundle | null> {
    const answer = await this._request(first);
    if (!answer) {
      return null;
    }
    const settled = pickLanguage(
      candidates,
      answer.languages.map((language) => language.code),
    );
    return settled === answer.language ? answer : ((await this._request(settled)) ?? answer);
  }

  /** One bundle from the server, checked - or null: out of reach, refused, or not what a bundle is. */
  private async _request(code: string): Promise<TranslationBundle | null> {
    try {
      return bundleFrom(await this.fetchBundle(code));
    } catch {
      // Offline, a 503 before Apply, the API down. The cache - or the compiled English - is what the page
      // shows then; it is not worth stopping the site over.
      return null;
    }
  }

  /** GET /api/translations/{code}, through Api. On its own so the tests' stand-in answers without HTTP. */
  protected fetchBundle(code: string): Promise<unknown> {
    return firstValueFrom(this._injector.get(Api).translations(code));
  }

  /**
   * Whether the server can be asked at all: /api/meta has answered. False while it is not set up or out
   * of reach. On its own so the tests' stand-in needs no metadata.
   */
  protected canAsk(): boolean {
    return this._injector.get(MetaStore).loaded();
  }

  /**
   * Puts a bundle in force as the one a spec runs with (the tests' stand-in): it counts as the server's -
   * nothing asks for it again - and is kept nowhere.
   */
  protected use(bundle: TranslationBundle): void {
    this._use(bundle, false);
    this._fresh = true;
  }

  /**
   * Puts a checked bundle in force - strings first, then the language they are in. The server's is kept
   * for the next visit; the kept one (`keep: false`) is only in force until the server's comes.
   */
  private _use(bundle: TranslationBundle, keep = true): void {
    const values = new Map(Object.entries(bundle.values));
    const english = bundle.language === FALLBACK_LANGUAGE ? values : new Map(Object.entries(bundle.english ?? {}));
    this._values.set(values);
    // A bundle that came without its English (none has, from this server) leaves the English pages the compiled one.
    this._english.set(english.size ? english : COMPILED);
    this._languages.set(bundle.languages);
    this._language.set(bundle.language);
    this._fresh = keep;
    if (keep) {
      writeStorage(CACHE_KEY, JSON.stringify(bundle));
    }
  }

  private _candidates(): LanguageCandidate[] {
    const navigator = this._document.defaultView?.navigator;
    return languageCandidates({
      tab: this._tabWish(),
      chosen: this._chosenWish(),
      token: this._tokenLocale(),
      browser: navigator?.languages?.length ? navigator.languages : [navigator?.language],
    });
  }

  /** `?lang=` of this tab: what this page took from its address, else what the tab kept. */
  private _tabWish(): string | null {
    return this._tab ?? readTab(LANGUAGE_KEY);
  }

  /** The menu's choice: what was chosen on this page, else what the device kept. */
  private _chosenWish(): string | null {
    return this._chosen ?? readStorage(LANGUAGE_KEY);
  }

  /**
   * `?lang=` holds for this tab: kept in sessionStorage - it survives the round trip to the IAM and the
   * navigation inside the app - and taken out of the address before the router reads it. Never written to
   * localStorage: a link somebody followed is not a choice they made. Kept in memory too, because the
   * parameter is gone from the address either way: where sessionStorage refuses the write, the link still
   * works for this page.
   */
  private _takeAddressLanguage(): void {
    const view = this._document.defaultView;
    const location = this._document.location;
    const parts = (location?.search ?? '').replace(/^\?/, '').split('&');
    const given = parts.filter((part) => part.split('=')[0] === 'lang');
    if (!view || !location || !given.length) {
      return;
    }
    let value = '';
    try {
      value = decodeURIComponent(given[0].split('=')[1] ?? '');
    } catch {
      // Not an address anybody wrote by hand; not a language either.
    }
    const code = languageCode(value);
    if (code) {
      this._tab = code;
      writeTab(LANGUAGE_KEY, code);
    }
    // The other parameters stay byte for byte as they came.
    const rest = parts.filter((part) => part !== '' && part.split('=')[0] !== 'lang').join('&');
    try {
      view.history.replaceState(view.history.state, '', location.pathname + (rest ? `?${rest}` : '') + location.hash);
    } catch {
      // A browser that refuses: the parameter stays, and does no harm.
    }
  }

  /**
   * A changed `locale` of the token: while nothing is stored here - no choice, no `?lang=` - it is adopted
   * when it comes (somebody signs in; the language was changed on the IAM's account page). Used, never
   * stored.
   */
  private _followToken(locale: string | null): void {
    if (!this._started || locale === this._seenLocale) {
      return;
    }
    this._seenLocale = locale;
    const stored = languageCode(this._tabWish()) ?? languageCode(this._chosenWish());
    if (locale && !stored && locale !== this._language() && this._languages().some((language) => language.code === locale)) {
      void this.setLanguage(locale, false);
    }
  }

  /** The token's `locale` - only where people sign in at the IAM; with sign-in off there is no token. */
  private _tokenLocale(): string | null {
    return this._auth.mode() === 'iam' ? languageCode(this._auth.user()?.locale) : null;
  }

  /**
   * The cached bundle, only when it is one - it parses, has the shape, and (`settle`) is in the language
   * the candidates settle on now. Anything else is removed: a corrupt or outdated cache is no cache.
   *
   * Without `settle` - the server cannot be asked - the language is not asked about: the wishes are not
   * all known then (nobody is signed in, so the token says nothing), and a cache in another language is
   * still the reader's own words from their last visit. It is used, and left where it is.
   */
  private _cachedBundle(candidates: readonly LanguageCandidate[], settle: boolean): TranslationBundle | null {
    const raw = readStorage(CACHE_KEY);
    if (raw === null) {
      return null;
    }
    let bundle: TranslationBundle | null = null;
    try {
      bundle = bundleFrom(JSON.parse(raw));
    } catch {
      bundle = null;
    }
    if (
      bundle &&
      (!settle ||
        bundle.language ===
          pickLanguage(
            candidates,
            bundle.languages.map((language) => language.code),
          ))
    ) {
      return bundle;
    }
    writeStorage(CACHE_KEY, null);
    return null;
  }

  private _word(key: string, params?: TranslationParams): string {
    const value = this._strings().get(key);
    return value === undefined ? this._missing(key) : fill(value, params);
  }

  private _missing(key: string): string {
    if (!this._warned.has(key)) {
      this._warned.add(key);
      console.warn(`[i18n] no string for '${key}'`);
    }
    return key;
  }
}

const PLURAL_RULES = new Map<string, Intl.PluralRules>();
const NUMBER_FORMATS = new Map<string, Intl.NumberFormat>();

/** The plural rules of a language: only ever asked with a code that passed the pattern. */
function pluralRules(language: string): Intl.PluralRules {
  let rules = PLURAL_RULES.get(language);
  if (!rules) {
    rules = new Intl.PluralRules(language);
    PLURAL_RULES.set(language, rules);
  }
  return rules;
}

function numberFormat(locale: string): Intl.NumberFormat {
  let format = NUMBER_FORMATS.get(locale);
  if (!format) {
    format = new Intl.NumberFormat(locale);
    NUMBER_FORMATS.set(locale, format);
  }
  return format;
}

function fill(value: string, params?: TranslationParams): string {
  if (!params) {
    return value;
  }
  return value.replace(PLACEHOLDER, (whole, name: string) => {
    const given = Object.hasOwn(params, name) ? params[name] : undefined;
    return given === undefined || given === null ? whole : String(given);
  });
}

function wait(milliseconds: number): Promise<void> {
  return new Promise((done) => setTimeout(done, milliseconds));
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** A flat {key: text} map, copied - or null when it is anything else. */
function stringsFrom(raw: unknown): Record<string, string> | null {
  if (!isPlainObject(raw)) {
    return null;
  }
  const strings: Record<string, string> = {};
  for (const [key, value] of Object.entries(raw)) {
    if (typeof value !== 'string') {
      return null;
    }
    strings[key] = value;
  }
  return strings;
}

/**
 * A bundle, from the server or from the cache, only when it is one: a language code, the offered languages
 * (strings, and the bundle's own among them), a flat map of strings that is not empty - and English beside
 * it for every other language. A bundle with no strings is no bundle: it would put a key where every word
 * should be, and the compiled English is better than that.
 */
function bundleFrom(raw: unknown): TranslationBundle | null {
  if (!isPlainObject(raw) || !Array.isArray(raw['languages'])) {
    return null;
  }
  const language = raw['language'];
  if (typeof language !== 'string' || !LANGUAGE_CODE.test(language)) {
    return null;
  }
  const languages: SiteLanguage[] = [];
  for (const entry of raw['languages']) {
    if (!isPlainObject(entry)) {
      return null;
    }
    const { code, name, native_name } = entry;
    if (typeof code !== 'string' || !LANGUAGE_CODE.test(code) || typeof name !== 'string' || typeof native_name !== 'string') {
      return null;
    }
    languages.push({ code, name, native_name });
  }
  const values = stringsFrom(raw['values']);
  if (!values || !Object.keys(values).length || !languages.some((entry) => entry.code === language)) {
    return null;
  }
  if (language === FALLBACK_LANGUAGE) {
    return { language, languages, values };
  }
  const english = stringsFrom(raw['english']);
  return english ? { language, languages, values, english } : null;
}

/** localStorage that never throws: it can be missing or refuse writes (a private window, a full quota). */
function readStorage(key: string): string | null {
  try {
    return globalThis.localStorage?.getItem(key) ?? null;
  } catch {
    return null;
  }
}

function writeStorage(key: string, value: string | null): void {
  try {
    if (value === null) {
      globalThis.localStorage?.removeItem(key);
    } else {
      globalThis.localStorage?.setItem(key, value);
    }
  } catch {
    // Nothing to do: the value just will not survive a reload.
  }
}

/** The same for sessionStorage. */
function readTab(key: string): string | null {
  try {
    return globalThis.sessionStorage?.getItem(key) ?? null;
  } catch {
    return null;
  }
}

function writeTab(key: string, value: string | null): void {
  try {
    if (value === null) {
      globalThis.sessionStorage?.removeItem(key);
    } else {
      globalThis.sessionStorage?.setItem(key, value);
    }
  } catch {
    // Nothing to do: the language just will not hold for the tab.
  }
}
