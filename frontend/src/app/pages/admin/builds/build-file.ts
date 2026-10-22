import { GAME_LABELS } from '../../../core/format';
import { DateChoices, OwnDateParts, chosenMoment } from '../../../core/i18n/date-patterns';
import { BuildChange, BuildImportEntry, BuildImportPlan, GameKey } from '../../../core/models';

/**
 * A game's builds file (backend: Services/Builds/BuildFile): format "build-analyzer.builds", version 3 - the builds
 * of one game, each a variation of its character's (its role, a short qualifier telling two builds of one role
 * apart, the constellation it needs), with the teams it is played in. The server checks every build of it; the
 * Builds page checks only enough to say plainly what a chosen file is not before sending it, and words what the
 * server says importing it does. English, as the whole admin panel: its words are written here.
 */

export const BUILD_FILE_FORMAT = 'build-analyzer.builds';
export const BUILD_FILE_VERSION = 3;
/**
 * The largest file the server takes (BuildFile::MAX_BYTES), checked before the file is read: 8 MB is a common default
 * of PHP's post_max_size, and a server sent more can answer with a warning page instead of the API's JSON. A game's
 * builds take about 1 MB (1.2 MB with their teams, 2026-10-22).
 */
export const BUILD_FILE_MAX_BYTES = 8 * 1024 * 1024;

/** What the page says of a file larger than BUILD_FILE_MAX_BYTES, before reading it. */
export const BUILD_FILE_TOO_LARGE = `This file is larger than ${BUILD_FILE_MAX_BYTES / 1048576} MB: more than the server accepts, and far more than a game's builds take. Is it the right file?`;

/** A chosen file is a builds file of the game - or what it is not, in a sentence. */
export type BuildFileCheck = { ok: true } | { ok: false; message: string };

/**
 * Whether a file's text is a builds file of `game` that this installation reads. What the file says is repeated only
 * where it has the form the app writes itself (a version's number, a game's key): a message never shows other text
 * of it, which could name a site.
 */
export function checkBuildFile(text: string, game: GameKey): BuildFileCheck {
  let data: unknown;
  try {
    data = JSON.parse(text);
  } catch {
    return problem('This is not a builds file: it is not JSON.');
  }
  if (!isObject(data) || data['format'] !== BUILD_FILE_FORMAT) {
    return problem('This is not a builds file exported by the build analyzer.');
  }

  const version = data['format_version'];
  if (version === 1) {
    return problem(
      `This builds file has format version 1, from before builds had variations. This installation reads version ${BUILD_FILE_VERSION}: a game's builds, each a variation of its character's.`,
    );
  }
  if (version === 2) {
    return problem(
      `This builds file has format version 2, from before builds had their teams. This installation reads version ${BUILD_FILE_VERSION}: make the file again, or export it again from this page.`,
    );
  }
  if (version !== BUILD_FILE_VERSION) {
    return problem(
      typeof version === 'number' && Number.isInteger(version) && version > BUILD_FILE_VERSION
        ? `This builds file has format version ${version}, and this installation reads version ${BUILD_FILE_VERSION}. Update this installation, then import again.`
        : 'This builds file has no format version this installation reads.',
    );
  }

  const theirs = data['game'];
  if (theirs !== game) {
    return problem(isGame(theirs) ? `This file holds ${GAME_LABELS[theirs]} builds, not ${GAME_LABELS[game]} builds.` : 'This builds file names no game this installation knows.');
  }
  if (!Array.isArray(data['builds'])) {
    return problem('This builds file holds no list of builds.');
  }
  return { ok: true };
}

/** What a build is for, in the admin panel's English (the server's Game::roles()). */
const ROLE_NAMES = new Map<string, string>([
  ['main_dps', 'Main DPS'],
  ['sub_dps', 'Sub DPS'],
  ['support', 'Support'],
]);

/** A build's role: "Main DPS", "Sub DPS", "Support" - one a newer server added shows as it is. */
export function roleName(role: string): string {
  return ROLE_NAMES.get(role) ?? role;
}

/**
 * A build's variation: its role, its qualifier, the constellation it needs - "Main DPS", "Main DPS · Melt",
 * "Support · C4+". `rankPrefix` is the game's (MetaStore: rank_prefix).
 */
export function variationName(variation: Pick<BuildChange, 'role' | 'qualifier' | 'min_rank'>, rankPrefix: string): string {
  const parts = [roleName(variation.role), variation.qualifier.trim()];
  if (variation.min_rank > 0) {
    parts.push(`${rankPrefix}${variation.min_rank}+`);
  }
  return parts.filter((part) => part !== '').join(' · ');
}

/** A build an import changes: its character and its variation - "Hu Tao · Main DPS · Melt · C2+". */
export function changeName(change: BuildChange, rankPrefix: string): string {
  return `${change.character.name} · ${variationName(change, rankPrefix)}`;
}

/** What an import does to a build, said after it. */
export const CHANGE_WORDS: Record<BuildChange['change'], string> = {
  created: 'new',
  updated: 'changed',
  removed: 'removed',
};

/** How many builds an import creates, updates and removes (the answer lists the first `changes_listed` of them). */
export function changeCount(plan: Pick<BuildImportPlan, 'created' | 'updated' | 'removed'>): number {
  return plan.created + plan.updated + plan.removed;
}

/**
 * What importing a file does, from the dry run: "The file's 250 builds of 110 characters, made 20 October 2026 at
 * 10:00: 2 new, 1 updated, 240 unchanged, 3 removed." With characters skipped, the builds counted are those of the
 * characters this installation knows. `dates` is the account's date order and clock (AuthService.preferences()).
 */
export function planSummary(plan: BuildImportPlan, dates: DateChoices | null = null): string {
  const known = plan.skipped.length > 0 ? ' this installation knows' : '';
  const teams = plan.teams > 0 ? `, with ${count(plan.teams, 'team')}` : '';
  return (
    `The file's ${count(plan.builds, 'build')} of ${count(plan.characters, 'character')}${known}${teams}, made ${momentText(plan.exported_at, dates)}: ` +
    `${plan.created} new, ${plan.updated} updated, ${plan.unchanged} unchanged, ${plan.removed} removed.`
  );
}

/**
 * The teammates the file's teams name that this installation's catalog lacks - none skips a build: the page shows
 * such a teammate by its name, without a picture or a link, until the catalog has it. Null when there is none.
 */
export function unknownTeammates(plan: Pick<BuildImportPlan, 'teammates_unknown' | 'teammates_unknown_total'>): string | null {
  const names = plan.teammates_unknown.map((teammate) => teammate.name);
  if (names.length === 0) {
    return null;
  }
  const more = plan.teammates_unknown_total - names.length;
  return `Teammates not in this installation's catalog, shown by their names alone until a refresh brings them: ${names.join(', ')}${more > 0 ? ` and ${more} more` : ''}.`;
}

/** Over the characters an import skips: "2 characters skipped". */
export function skippedHeading(plan: Pick<BuildImportPlan, 'skipped'>): string {
  return `${count(plan.skipped.length, 'character')} skipped`;
}

/** What an import did: the counts, and the characters this installation's catalog lacks, named. */
export function importSummary(result: BuildImportPlan): string {
  const message = `Imported: ${result.created} new, ${result.updated} updated, ${result.unchanged} unchanged, ${result.removed} removed.`;
  if (result.skipped.length === 0) {
    return message;
  }
  const names = result.skipped.slice(0, 5).map((character) => character.name);
  const more = result.skipped.length - names.length;
  return `${message} Skipped ${count(result.skipped.length, 'character')} the catalog lacks (${names.join(', ')}${more > 0 ? ` and ${more} more` : ''}): refresh the catalog, then import again.`;
}

/**
 * One of the recent imports, after when it was made: the file's date, what it did, who imported it - "file made 20
 * October 2026 at 10:00 · 3 new, 1 updated, 0 removed, 2 skipped · by Mona". The import of a person since deleted is
 * nobody's.
 */
export function importLine(entry: BuildImportEntry, dates: DateChoices | null = null): string {
  const file = entry.file_exported_at ? `file made ${momentText(entry.file_exported_at, dates)}` : 'file of an unknown date';
  const { created, updated, removed, skipped } = entry.summary;
  const counts = `${created ?? 0} new, ${updated ?? 0} updated, ${removed ?? 0} removed, ${skipped ?? 0} skipped`;
  return `${file} · ${counts} · by ${entry.imported_by?.name ?? 'a deleted person'}`;
}

/** The page's own way of writing a day and a time, for the part the account leaves at "automatic". */
const OWN_PARTS: OwnDateParts = {
  day: (date) => date.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }),
  time: (date) => date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }),
};

/**
 * A moment the server names (ISO 8601): "20 October 2026 at 14:03" in the browser's time zone - or in the account's
 * date order and clock where the person has chosen them, "2026-10-20 2:03 pm".
 */
export function momentText(value: string, dates: DateChoices | null = null): string {
  const date = parseTime(value);
  return chosenMoment(date, dates, 'en-GB', OWN_PARTS) ?? date.toLocaleString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/** "1 build", "250 builds". */
export function count(value: number, noun: string): string {
  return `${value} ${noun}${value === 1 ? '' : 's'}`;
}

function problem(message: string): BuildFileCheck {
  return { ok: false, message };
}

function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isGame(value: unknown): value is GameKey {
  return typeof value === 'string' && Object.hasOwn(GAME_LABELS, value);
}

/** A time with microseconds: Date is only promised to read milliseconds. */
function parseTime(value: string): Date {
  return new Date(value.replace(/(\.\d{3})\d+/, '$1'));
}
