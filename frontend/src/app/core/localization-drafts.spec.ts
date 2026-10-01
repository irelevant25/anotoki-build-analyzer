import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AuthMode, AuthService } from './auth';
import { AnotokiUser } from './iam/anotoki-auth';
import { LOCALIZATION_DRAFTS_KEY, LanguageDraft, LocalizationDrafts } from './localization-drafts';

const person = (sub: string, name: string): AnotokiUser => ({ sub, preferred_username: name, email: `${name}@example.test`, email_verified: true, locale: null, roles: ['ADMIN'] });
const ANNA = person('1', 'anna');
const BEN = person('2', 'ben');

const STRINGS = { 'home.title': { sk: 'Povedz nám svoj názor' }, 'common.close': { en: 'Shut', sk: 'Zatvoriť' } };
const CZECH: LanguageDraft = { name: 'Czech', native_name: 'Česky', sort_order: '7' };

describe('LocalizationDrafts', () => {
  const mode = signal<AuthMode>('iam');
  const user = signal<AnotokiUser | null>(ANNA);
  /** As the sign-in tells it: in this tab or another, signed in, out, or as somebody else - heard as the page next draws. */
  const signedInAs = (who: AnotokiUser | null) => {
    user.set(who);
    TestBed.tick();
  };

  /** The service as a page load makes it: new, with whatever the tab has kept. */
  function load(): LocalizationDrafts {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [{ provide: AuthService, useValue: { mode, user } }],
    });
    return TestBed.inject(LocalizationDrafts);
  }

  const stored = () => sessionStorage.getItem(LOCALIZATION_DRAFTS_KEY);
  /** What the tab keeps is written when the page next draws: here, now. */
  const drawn = () => TestBed.tick();

  function closing(): Event {
    const event = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event);
    return event;
  }

  beforeEach(() => {
    sessionStorage.clear();
    mode.set('iam');
    user.set(ANNA);
  });

  afterEach(() => {
    vi.restoreAllMocks();
    TestBed.resetTestingModule();
    sessionStorage.clear();
  });

  it('counts the changed cells and the changed rows', () => {
    const drafts = load();
    expect(drafts.count()).toBe(0);

    drafts.strings.set(STRINGS);
    expect(drafts.count()).toBe(3);

    drafts.languages.set({ cs: CZECH });
    expect(drafts.count()).toBe(4);

    drafts.clear();
    expect(drafts.count()).toBe(0);
    expect(drafts.strings()).toEqual({});
    expect(drafts.languages()).toEqual({});
  });

  describe('whose they are', () => {
    it('keeps them through a sign-out - the session ending, another tab, this one - and for the same person signing in again', () => {
      const drafts = load();
      drafts.strings.set(STRINGS);
      drafts.languages.set({ cs: CZECH });

      signedInAs(null);
      expect(drafts.strings()).toEqual(STRINGS);
      expect(drafts.languages()).toEqual({ cs: CZECH });

      signedInAs(ANNA);
      expect(drafts.count()).toBe(4);
    });

    it('lets go of them only when somebody else signs in', () => {
      const drafts = load();
      drafts.strings.set(STRINGS);
      drafts.languages.set({ cs: CZECH });
      drawn();
      expect(stored()).not.toBeNull();

      signedInAs(null);
      signedInAs(BEN);
      expect(drafts.count()).toBe(0);
      drawn();
      expect(stored()).toBeNull();

      // And what Ben types is Ben's: Anna coming back does not get it.
      drafts.strings.set({ 'home.title': { sk: 'Benov názor' } });
      signedInAs(ANNA);
      expect(drafts.strings()).toEqual({});
    });

    it("keeps them for this computer's owner with sign-in off, through a page load", () => {
      mode.set('none');
      user.set(null);
      const drafts = load();
      drafts.strings.set(STRINGS);
      drawn();

      expect(JSON.parse(stored()!).owner).toBe('local');
      expect(load().strings()).toEqual(STRINGS);
      // Somebody is always there: closing the tab asks.
      expect(closing().defaultPrevented).toBe(true);
    });

    it('takes the first person to sign in as the owner, with nothing to let go of', () => {
      user.set(null);
      const drafts = load();

      signedInAs(ANNA);
      drafts.strings.set(STRINGS);
      drawn();

      expect(JSON.parse(stored()!).owner).toBe('1');
      signedInAs(BEN);
      expect(drafts.count()).toBe(0);
    });
  });

  describe('kept for the tab', () => {
    it('mirrors them to sessionStorage with their owner, and takes them away from there when there are none', () => {
      const drafts = load();
      drawn();
      expect(stored()).toBeNull();

      drafts.strings.set(STRINGS);
      drafts.languages.set({ cs: CZECH });
      drawn();
      expect(JSON.parse(stored()!)).toEqual({ owner: '1', strings: STRINGS, languages: { cs: CZECH } });

      // Typed back, one by one, to what is saved: none left.
      drafts.strings.set({});
      drafts.languages.set({});
      drawn();
      expect(stored()).toBeNull();
    });

    it('takes them out of the tab the moment they are given up - a sign-out leaves the page before it draws again', () => {
      const drafts = load();
      drafts.strings.set(STRINGS);
      drawn();
      expect(stored()).not.toBeNull();

      drafts.clear();

      expect(stored()).toBeNull();
      // So whoever loads the page next - the same person, signed in again - starts with none.
      expect(load().count()).toBe(0);
    });

    it('finds them again after the round trip to the sign-in: a new page load, the same person', () => {
      const before = load();
      before.strings.set(STRINGS);
      before.languages.set({ cs: CZECH });
      drawn();

      const after = load();
      expect(after).not.toBe(before);
      expect(after.strings()).toEqual(STRINGS);
      expect(after.languages()).toEqual({ cs: CZECH });
      expect(after.count()).toBe(4);
    });

    it('keeps them while nobody is signed in - the page loaded again after the session ended - for the person who comes back', () => {
      const before = load();
      before.strings.set(STRINGS);
      drawn();

      user.set(null);
      const signedOut = load();
      expect(signedOut.strings()).toEqual(STRINGS);
      drawn();
      expect(JSON.parse(stored()!).owner).toBe('1');

      signedInAs(ANNA);
      expect(signedOut.strings()).toEqual(STRINGS);

      // Somebody else coming instead gets none of it.
      user.set(null);
      const again = load();
      expect(again.strings()).toEqual(STRINGS);
      signedInAs(BEN);
      expect(again.strings()).toEqual({});
    });

    it("gives nobody another person's: a page load as somebody else starts with none, and what was kept is removed", () => {
      const before = load();
      before.strings.set(STRINGS);
      drawn();

      user.set(BEN);
      const after = load();
      expect(after.count()).toBe(0);
      expect(stored()).toBeNull();
    });

    it.each([
      ['not JSON', '{"owner": "1", "strings": {'],
      ['a text', '"drafts"'],
      ['null', 'null'],
      ['a list', '[]'],
      ['no owner', JSON.stringify({ strings: STRINGS, languages: {} })],
      ['an owner that is not a text', JSON.stringify({ owner: 1, strings: STRINGS, languages: {} })],
      ['an empty owner', JSON.stringify({ owner: '', strings: STRINGS, languages: {} })],
      ['no strings', JSON.stringify({ owner: '1', languages: {} })],
      ['strings that are a list', JSON.stringify({ owner: '1', strings: [], languages: {} })],
      ['a key whose cells are a text', JSON.stringify({ owner: '1', strings: { 'home.title': 'Názor' }, languages: {} })],
      ['a cell that is not a text', JSON.stringify({ owner: '1', strings: { 'home.title': { sk: 7 } }, languages: {} })],
      ['no languages', JSON.stringify({ owner: '1', strings: STRINGS })],
      ['a language that is a text', JSON.stringify({ owner: '1', strings: {}, languages: { cs: 'Czech' } })],
      ['a language without its order', JSON.stringify({ owner: '1', strings: {}, languages: { cs: { name: 'Czech', native_name: 'Česky' } } })],
      ['an order that is a number', JSON.stringify({ owner: '1', strings: {}, languages: { cs: { name: 'Czech', native_name: 'Česky', sort_order: 7 } } })],
    ])('takes a broken value for no drafts, and removes it: %s', (_, value) => {
      sessionStorage.setItem(LOCALIZATION_DRAFTS_KEY, value);

      const drafts = load();

      expect(drafts.count()).toBe(0);
      expect(drafts.strings()).toEqual({});
      expect(drafts.languages()).toEqual({});
      expect(stored()).toBeNull();
    });

    it('reads a key named like something every object has as a key and nothing more', () => {
      sessionStorage.setItem(LOCALIZATION_DRAFTS_KEY, '{"owner": "1", "strings": {"__proto__": {"sk": "x"}}, "languages": {}}');

      const drafts = load();

      expect(Object.keys(drafts.strings())).toEqual(['__proto__']);
      expect(drafts.count()).toBe(1);
      expect(({} as Record<string, unknown>)['sk']).toBeUndefined();
    });

    it('works in memory when the tab keeps nothing: storage that refuses to be read or written breaks no page', () => {
      vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
        throw new DOMException('The operation is insecure.', 'SecurityError');
      });
      vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new DOMException('The quota has been exceeded.', 'QuotaExceededError');
      });
      vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
        throw new DOMException('The operation is insecure.', 'SecurityError');
      });

      const drafts = load();
      drafts.strings.set(STRINGS);
      expect(() => drawn()).not.toThrow();
      expect(drafts.count()).toBe(3);

      signedInAs(null);
      expect(drafts.strings()).toEqual(STRINGS);
      expect(() => drafts.clear()).not.toThrow();
      expect(() => drawn()).not.toThrow();
    });
  });

  describe('closing the tab', () => {
    it('has the browser ask while something is not saved - whichever page is showing - and not otherwise', () => {
      const drafts = load();
      expect(closing().defaultPrevented).toBe(false);

      drafts.languages.set({ cs: CZECH });
      expect(closing().defaultPrevented).toBe(true);

      drafts.clear();
      drafts.strings.set({ 'home.title': { sk: 'Názor' } });
      expect(closing().defaultPrevented).toBe(true);

      drafts.clear();
      expect(closing().defaultPrevented).toBe(false);
    });

    it('does not have the browser ask while nobody is signed in and the tab holds the drafts: the way on is the sign-in, which they survive', () => {
      const drafts = load();
      drafts.strings.set({ 'home.title': { sk: 'Názor' } });
      expect(closing().defaultPrevented).toBe(true);

      // The session ends over the page: the drafts stay, and the only way on is to sign in - a page load.
      signedInAs(null);
      expect(drafts.count()).toBe(1);
      expect(closing().defaultPrevented).toBe(false);
      // Not asked because they are kept - and they are: the same moment, in the tab's storage.
      expect(JSON.parse(stored()!)).toEqual({ owner: '1', strings: { 'home.title': { sk: 'Názor' } }, languages: {} });

      // The page loaded again, still signed out: the drafts are here, and still nothing is asked.
      const back = load();
      expect(back.count()).toBe(1);
      expect(closing().defaultPrevented).toBe(false);
      // Signed in again, a closed tab would lose them: asked as before.
      signedInAs(ANNA);
      expect(closing().defaultPrevented).toBe(true);
    });

    it('still has it ask, signed out, where the tab could not keep them: a page load would lose what is only in memory', () => {
      const drafts = load();
      drafts.languages.set({ cs: CZECH });
      signedInAs(null);
      expect(closing().defaultPrevented).toBe(false);

      const full = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new DOMException('The quota has been exceeded.', 'QuotaExceededError');
      });
      expect(closing().defaultPrevented).toBe(true);
      full.mockRestore();
      expect(closing().defaultPrevented).toBe(false);

      // Storage that is not there at all keeps nothing either.
      vi.stubGlobal('sessionStorage', undefined);
      try {
        expect(closing().defaultPrevented).toBe(true);
      } finally {
        vi.unstubAllGlobals();
      }

      // And with nothing typed there is nothing to ask about, kept or not.
      drafts.clear();
      expect(closing().defaultPrevented).toBe(false);
    });

    it('has it ask while somebody is signed in, kept for the tab or not: a closed tab loses the drafts', () => {
      const drafts = load();
      drafts.strings.set(STRINGS);

      expect(closing().defaultPrevented).toBe(true);
      // They were written - which is no reason to let a signed-in person close the tab unasked.
      expect(JSON.parse(stored()!).strings).toEqual(STRINGS);

      vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new DOMException('The quota has been exceeded.', 'QuotaExceededError');
      });
      expect(closing().defaultPrevented).toBe(true);
    });

    it('keeps what was typed that very moment: the way to the sign-in is an unload too', () => {
      const drafts = load();
      drafts.strings.set(STRINGS);
      // Not drawn since: nothing is in the tab's storage yet.
      expect(stored()).toBeNull();

      closing();
      expect(JSON.parse(stored()!).strings).toEqual(STRINGS);

      // A page that goes without asking (a phone's browser) says so too.
      drafts.strings.set({ 'home.title': { sk: 'Názor' } });
      window.dispatchEvent(new Event('pagehide'));
      expect(JSON.parse(stored()!).strings).toEqual({ 'home.title': { sk: 'Názor' } });
    });

    it('listens once, and no longer than it lives', () => {
      const first = load();
      first.strings.set(STRINGS);
      sessionStorage.clear();

      // A new page load, as somebody with nothing typed: the one before asks nothing any more.
      user.set(BEN);
      load();

      expect(closing().defaultPrevented).toBe(false);
    });
  });
});
