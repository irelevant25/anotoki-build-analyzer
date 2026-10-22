import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { Api } from '../core/api';
import { AuthService } from '../core/auth';
import { en } from '../core/i18n/en';
import { provideKit } from '../core/kit';
import { MetaStore } from '../core/meta-store';
import { BuildTeam, GameMeta, Meta, TeamMember, Teammate } from '../core/models';
import { BuildTeams } from './build-teams';

/** The informal Slovak of a build's teams: the game's names and notation stay as they are. */
const SLOVAK: Record<string, string> = {
  'buildDetail.teams': 'Tímy',
  'buildDetail.teamAlternatives': 'alebo {names}',
  'buildDetail.teamNotes': 'Poznámky k tímu {team}',
  'buildDetail.owned': 'Máš',
  'buildDetail.notes': 'Poznámky',
  'character.needsRank': 'potrebuje {rank}',
  'builds.role.main_dps': 'Hlavný DPS',
  'builds.role.sub_dps': 'Vedľajší DPS',
  'builds.role.support': 'Podpora',
  'builds.role.unknown': 'Build',
};

const GAME: GameMeta = {
  key: 'genshin',
  label: 'Genshin Impact',
  slots: [],
  weapon_label: 'Weapon',
  item_label: 'Artifact',
  rank_label: 'Constellation',
  refinement_prefix: 'R',
  rank_prefix: 'C',
  elements: [],
  weapon_types: [],
  stats: {},
  talents: [],
  roles: ['main_dps', 'sub_dps', 'support'],
} as unknown as GameMeta;

const META: Meta = { games: [GAME], version: '1.0.0', auth: { issuer: '', client_id: '' } } as unknown as Meta;

function teammate(key: string, name: string, fields: Partial<Teammate> = {}): Teammate {
  return { key, name, known: true, icon_url: null, rarity: 5, element: 'anemo', has_builds: false, ...fields };
}

function member(key: string, name: string, fields: Partial<TeamMember> = {}): TeamMember {
  return { ...teammate(key, name), role: null, min_rank: 0, own: false, alternatives: [], ...fields };
}

/**
 * Hu Tao's Vaporize team: herself; Kazuha, with builds, whom the team asks at C2 and Traveler (Anemo) or Sucrose
 * can stand in for; Xingqiu, whom the catalog does not know.
 */
function vaporize(fields: Partial<BuildTeam> = {}): BuildTeam {
  return {
    name: 'Vaporize',
    members: [
      member('hutao', 'Hu Tao', { own: true, role: 'main_dps', has_builds: true }),
      member('kaedeharakazuha', 'Kaedehara Kazuha', {
        role: 'support',
        min_rank: 2,
        has_builds: true,
        alternatives: [teammate('traveleranemo', 'Traveler (Anemo)'), teammate('sucrose', 'Sucrose', { has_builds: true })],
      }),
      member('xingqiu', 'Xingqiu', { role: 'sub_dps', known: false, rarity: null, element: null }),
    ],
    note: 'Swirl the Pyro onto the rain.',
    ...fields,
  };
}

describe('BuildTeams: the teams a build is played in - its teammates linked, what the player has marked', () => {
  let fixture: ComponentFixture<BuildTeams>;
  let host: HTMLElement;
  let warn: ReturnType<typeof vi.spyOn>;

  const text = (selector: string) => [...host.querySelectorAll<HTMLElement>(selector)].map((element) => element.textContent?.replace(/\s+/g, ' ').trim() ?? '');
  const hrefs = (selector: string) => [...host.querySelectorAll<HTMLAnchorElement>(selector)].map((element) => element.getAttribute('href'));

  async function show(
    language: 'en' | 'sk' | Record<string, string>,
    teams: BuildTeam[],
    inputs: { buildMinRank?: number; owned?: Record<string, number | null>; accountId?: number } = {},
  ): Promise<void> {
    TestBed.configureTestingModule({
      providers: [
        typeof language === 'object'
          ? provideTestTranslations({ ...en, ...language })
          : language === 'en'
            ? provideTestTranslations(en)
            : provideTestTranslations(SLOVAK, { language: 'sk', english: en }),
        provideKit(),
        provideRouter([]),
        { provide: Api, useValue: { meta: () => of(META) } },
        { provide: AuthService, useValue: { user: () => null, signedIn: () => false, preferences: () => null } },
      ],
    });
    await TestBed.inject(MetaStore).load();
    fixture = TestBed.createComponent(BuildTeams);
    fixture.componentRef.setInput('teams', teams);
    fixture.componentRef.setInput('game', 'genshin');
    fixture.componentRef.setInput('buildId', 11);
    fixture.componentRef.setInput('buildMinRank', inputs.buildMinRank ?? 0);
    fixture.componentRef.setInput('owned', inputs.owned ?? null);
    fixture.componentRef.setInput('accountId', inputs.accountId ?? null);
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  beforeEach(() => {
    warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
  });

  afterEach(() => vi.restoreAllMocks());

  it('lists each team by its name, its members in the team\'s order: the role each plays, the constellation the team asks ("C2+")', async () => {
    await show('en', [vaporize(), vaporize({ name: 'Melt', members: [member('hutao', 'Hu Tao', { own: true }), member('bennett', 'Bennett')], note: null })]);

    expect(text('h3')).toEqual(['Teams']);
    expect(text('.team-name')).toEqual(['Vaporize', 'Melt']);
    expect(text('.team:first-child .member-name')).toEqual(['Hu Tao', 'Kaedehara Kazuha', 'Xingqiu']);
    expect(text('.team:first-child .member-role')).toEqual(['Main DPS', 'Support', 'Sub DPS']);
    expect(text('.member-rank')).toEqual(['C2+']);
    // Its team's name in the title: one team's notes told from another's, and from the build's own.
    expect(text('.team-note .anotoki-disclosure__button')).toEqual(['Notes on Vaporize']);
    expect(host.querySelectorAll('.team-note')).toHaveLength(1);
    // Lists drawn without markers keep being lists for every screen reader.
    expect([...host.querySelectorAll('ol.teams, ul.members')].map((list) => list.getAttribute('role'))).toEqual(['list', 'list', 'list']);
    expect(warn).not.toHaveBeenCalled();
  });

  it("links a teammate to its builds where it has some - never the build's own member -, and shows one the catalog lacks by name alone", async () => {
    await show('en', [vaporize()]);

    expect(hrefs('a.member-name')).toEqual(['/builds/genshin/kaedeharakazuha']);
    expect(host.querySelector('.member.own a')).toBeNull();
    // No picture: the avatar's initial stands in (hidden from a screen reader, like every avatar here).
    expect(text('.member:last-child .member-name')).toEqual(['Xingqiu']);
    expect(host.querySelector('.member:last-child a')).toBeNull();
  });

  it('names who can take a member\'s place - "or Traveler (Anemo) / Sucrose" -, each a link where it has builds', async () => {
    await show('en', [vaporize()]);

    expect(text('.alternatives')).toEqual(['or Traveler (Anemo) / Sucrose']);
    expect(hrefs('.alternatives a')).toEqual(['/builds/genshin/sucrose']);
  });

  it('on a player\'s own page: a check on a teammate the account has - a member or one who can stand in -, heard "Owned"; "needs C2" below the constellation the team asks; a link to the player\'s own analysis of it', async () => {
    await show('en', [vaporize()], { owned: { kaedeharakazuha: 1, traveleranemo: 0 }, accountId: 3 });

    // The check on Kazuha's portrait, hidden from a screen reader, which hears "Owned" after his name instead.
    const checked = [...host.querySelectorAll('.member')].map((member) => member.querySelector('.portrait .owned-check') !== null);
    expect(checked).toEqual([false, true, false]);
    expect(host.querySelector('.portrait .owned-check')?.getAttribute('aria-hidden')).toBe('true');
    expect(text('.member-head .visually-hidden')).toEqual(['Owned']);
    expect(host.querySelector('anotoki-badge.member-owned')).toBeNull();
    expect(text('.member-below')).toEqual(['needs C2']);
    expect(hrefs('a.member-name')).toEqual(['/accounts/3/characters/kaedeharakazuha']);
    expect(hrefs('.alternatives a')).toEqual(['/accounts/3/characters/traveleranemo', '/builds/genshin/sucrose']);
    // The stand-in the account has checked too - a blank of its own before the check, so the words never run together.
    expect(host.querySelector('.alternatives')?.textContent).toBe('or Traveler (Anemo) Owned / Sucrose');
    expect(host.querySelectorAll('.alternatives .owned-check')).toHaveLength(1);

    TestBed.resetTestingModule();
    await show('en', [vaporize()], { owned: { kaedeharakazuha: 2 }, accountId: 3 });
    expect(host.querySelectorAll('.portrait .owned-check')).toHaveLength(1);
    expect(text('.member-below')).toEqual([]);
  });

  it('a Traveler of another element than the account\'s: checked, its constellation unknown - never "needs" -, a link to its builds, as the account has no analysis of it', async () => {
    const geo = vaporize({
      name: 'Geo',
      members: [member('hutao', 'Hu Tao', { own: true }), member('travelergeo', 'Traveler (Geo)', { min_rank: 6, has_builds: true })],
      note: null,
    });
    await show('en', [geo], { owned: { travelergeo: null }, accountId: 3 });

    expect(host.querySelectorAll('.portrait .owned-check')).toHaveLength(1);
    expect(text('.member-head .visually-hidden')).toEqual(['Owned']);
    expect(text('.member-rank')).toEqual(['C6+']);
    expect(text('.member-below')).toEqual([]);
    expect(hrefs('a.member-name')).toEqual(['/builds/genshin/travelergeo']);
  });

  it("marks the build's own member with the constellation a team asks only above what the build itself needs", async () => {
    const herC2 = vaporize({ members: [member('hutao', 'Hu Tao', { own: true, min_rank: 2 }), member('bennett', 'Bennett')] });
    await show('en', [herC2]);
    expect(text('.member.own .member-rank')).toEqual(['C2+']);

    TestBed.resetTestingModule();
    await show('en', [herC2], { buildMinRank: 2 });
    expect(text('.member.own .member-rank')).toEqual([]);
  });

  it("reads in Slovak - the heading, the roles, the stand-ins' line and the marks -, the team's name and note marked as stored English", async () => {
    await show('sk', [vaporize()], { owned: { kaedeharakazuha: 1 }, accountId: 3 });

    expect(text('h3')).toEqual(['Tímy']);
    expect(text('.team:first-child .member-role')).toEqual(['Hlavný DPS', 'Podpora', 'Vedľajší DPS']);
    expect(text('.alternatives')).toEqual(['alebo Traveler (Anemo) / Sucrose']);
    expect(text('.team-note .anotoki-disclosure__button')).toEqual(['Poznámky k tímu Vaporize']);
    expect(text('.member-head .visually-hidden')).toEqual(['Máš']);
    expect(text('.member-below')).toEqual(['potrebuje C2']);
    expect(host.querySelector('.team-name')?.getAttribute('lang')).toBe('en');
    expect(warn).not.toHaveBeenCalled();
  });

  it("keeps a reworded stand-ins' line as it is written: the template adds no blank beside its words", async () => {
    await show({ 'buildDetail.teamAlternatives': '({names})' }, [vaporize()]);

    expect(host.querySelector('.alternatives')?.textContent).toBe('(Traveler (Anemo) / Sucrose)');
  });

  it("labels each team's members by its heading, and hides the avatars a member's name already says", async () => {
    await show('en', [vaporize(), vaporize({ name: 'Second', members: [member('hutao', 'Hu Tao', { own: true }), member('bennett', 'Bennett')] })]);

    const headings = [...host.querySelectorAll('.team-name')].map((heading) => heading.id);
    expect(headings).toEqual(['team-11-0', 'team-11-1']);
    expect([...host.querySelectorAll('.members')].map((list) => list.getAttribute('aria-labelledby'))).toEqual(headings);
    expect([...host.querySelectorAll('app-avatar')].every((avatar) => avatar.getAttribute('aria-hidden') === 'true')).toBe(true);
  });
});
