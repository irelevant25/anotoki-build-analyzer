import { GAMES } from '../../../shared/format';
import { en, isTranslationKey } from '../../../shared/i18n/en';
import { GameKey } from '../../../api/models';
import { GUIDES, ImportMethodKey, METHODS } from './import-guides';
import { SCANNER_COMMAND, SCANNER_GUIDE } from './scanner';

const WAYS: ImportMethodKey[] = ['showcase', 'file'];

/** Every key the two tables hold, with where it stands. */
function keysOfTheGuides(): { key: string; where: string }[] {
  const found: { key: string; where: string }[] = [];
  for (const game of GAMES) {
    for (const method of METHODS[game]) {
      const at = `METHODS.${game}.${method.key}`;
      found.push({ key: method.title, where: `${at}.title` }, { key: method.summary, where: `${at}.summary` });
      method.pros.forEach((key, index) => found.push({ key, where: `${at}.pros[${index}]` }));
      method.cons.forEach((key, index) => found.push({ key, where: `${at}.cons[${index}]` }));
    }
    for (const way of WAYS) {
      const guide = GUIDES[game][way];
      const at = `GUIDES.${game}.${way}`;
      found.push({ key: guide.intro, where: `${at}.intro` });
      if (guide.fileHint) {
        found.push({ key: guide.fileHint, where: `${at}.fileHint` });
      }
      guide.steps.forEach((step, index) => {
        found.push({ key: step.title, where: `${at}.steps[${index}].title` }, { key: step.body, where: `${at}.steps[${index}].body` });
        if (step.tip) {
          found.push({ key: step.tip, where: `${at}.steps[${index}].tip` });
        }
        if (step.command) {
          found.push({ key: step.command.label, where: `${at}.steps[${index}].command` });
        }
        if (step.link) {
          found.push({ key: step.link.label, where: `${at}.steps[${index}].link` });
        }
      });
      guide.troubleshooting.forEach((key, index) => found.push({ key, where: `${at}.troubleshooting[${index}]` }));
    }
  }
  return found;
}

describe('the import guides (METHODS and GUIDES)', () => {
  const strings = en as Record<string, string>;

  it('keep their shape: every game the site offers, both ways in, the steps, tips and hints they had', () => {
    for (const game of GAMES) {
      expect(METHODS[game].map((method) => method.key)).toEqual(['showcase', 'file']);
      expect(METHODS[game].map((method) => method.icon)).toEqual(['user', 'layers']);
      expect(Object.keys(GUIDES[game])).toEqual(['showcase', 'file']);
    }
    const shape = (game: GameKey, way: ImportMethodKey) => {
      const guide = GUIDES[game][way];
      const method = METHODS[game].find((one) => one.key === way)!;
      return {
        pros: method.pros.length,
        cons: method.cons.length,
        steps: guide.steps.length,
        tips: guide.steps.filter((step) => step.tip).length,
        troubleshooting: guide.troubleshooting.length,
        fileHint: !!guide.fileHint,
        commands: guide.steps.filter((step) => step.command).length,
        links: guide.steps.filter((step) => step.link).length,
      };
    };
    expect(shape('genshin', 'showcase')).toEqual({ pros: 3, cons: 2, steps: 5, tips: 1, troubleshooting: 3, fileHint: false, commands: 0, links: 0 });
    expect(shape('genshin', 'file')).toEqual({ pros: 3, cons: 2, steps: 4, tips: 2, troubleshooting: 3, fileHint: true, commands: 1, links: 1 });
    expect(GUIDES.genshin.showcase.steps.map((step) => step.icon)).toEqual(['user', 'columns', 'eye', 'clock', 'key']);
  });

  it('hold keys and nothing else: each one a key of the site, each written once, none left over', () => {
    const found = keysOfTheGuides();
    expect(found).toHaveLength(46);
    expect(found.filter(({ key }) => !isTranslationKey(key)).map(({ where }) => where)).toEqual([]);
    // A list is tracked by its keys, so no key may stand twice.
    expect(new Set(found.map(({ key }) => key)).size).toBe(found.length);
    // Every string of the guides is shown somewhere: no key of the namespace is left without a place.
    const used = new Set(found.map(({ key }) => key));
    expect(Object.keys(strings).filter((key) => key.startsWith('importGuide.') && !used.has(key))).toEqual([]);
  });

  it('name each key after where it stands: importGuide.<game>.<method>.<what>', () => {
    const misplaced = keysOfTheGuides().filter(({ key, where }) => {
      const [, game, way, rest] = /^(?:METHODS|GUIDES)\.(\w+)\.(\w+)\.(.+)$/.exec(where)!;
      const tail = rest
        .replace(/^pros\[(\d+)\]$/, (_, index: string) => `pro${Number(index) + 1}`)
        .replace(/^cons\[(\d+)\]$/, (_, index: string) => `con${Number(index) + 1}`)
        .replace(/^steps\[(\d+)\]\./, (_, index: string) => `step${Number(index) + 1}.`)
        .replace(/^troubleshooting\[(\d+)\]$/, (_, index: string) => `trouble${Number(index) + 1}`);
      return key !== `importGuide.${game}.${way}.${tail}`;
    });
    expect(misplaced).toEqual([]);
  });

  it('say in English what they said before they were keys', () => {
    expect(strings[METHODS.genshin[0].title]).toBe('Character Showcase (UID)');
    expect(strings[METHODS.genshin[1].cons[1]]).toBe('Totals are worked out from the gear, not read from the game (a UID import adds the exact ones)');
    expect(strings[GUIDES.genshin.showcase.steps[0].body]).toBe('In the game press Esc to open the Paimon menu, click your avatar at the top left and choose "Edit Profile".');
    // Reworded on purpose: the file comes from the project's own scanner now (migration 030), which runs as the game does.
    expect(strings[GUIDES.genshin.file.steps[0].tip!]).toBe("The game runs as administrator, so the scanner must as well: without it, Windows blocks the scanner's clicks and key presses.");
    expect(strings[GUIDES.genshin.file.fileHint!]).toBe('A .json file in the GOOD format');
  });

  it("hold no web address in a string: the one place they link to, and the line they show, are the code's - the scanner's", () => {
    const guideStrings = keysOfTheGuides().map(({ key }) => strings[key]);
    expect(guideStrings.filter((text) => /https?:|www\.|\.(?:com|gg|net|org|io|moe)\b/i.test(text))).toEqual([]);
    const steps = GAMES.flatMap((game) => WAYS.flatMap((way) => GUIDES[game][way].steps));
    expect(steps.filter((step) => step.command || step.link)).toEqual([GUIDES.genshin.file.steps[0]]);
    expect([GUIDES.genshin.file.steps[0].command?.text, GUIDES.genshin.file.steps[0].link?.href]).toEqual([SCANNER_COMMAND, SCANNER_GUIDE]);
    // The project's own repository, nothing else: the release the workflow publishes, and the scanner's README.
    expect(SCANNER_COMMAND).toBe('irm https://github.com/irelevant25/anotoki-build-analyzer/releases/download/scanner/scanner.ps1 | iex');
    expect(SCANNER_GUIDE.startsWith('https://github.com/irelevant25/anotoki-build-analyzer/')).toBe(true);
  });
});
