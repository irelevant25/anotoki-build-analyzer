import { GAME_LABELS } from '../../../core/format';
import { DateChoices, OwnDateParts, chosenMoment } from '../../../core/i18n/date-patterns';
import { BuildImportResult, GameKey, SourceStatus } from '../../../core/models';

/**
 * The builds file a source is exported to and imported from (backend: Services/Builds/BuildFile). The
 * server checks every build of it; the Sources page only checks that a chosen file is a builds file of the
 * source it is imported into, so it can say so plainly and ask before any builds are replaced.
 */

export const BUILD_FILE_FORMAT = 'build-analyzer.builds';
export const BUILD_FILE_VERSION = 1;
/**
 * The largest file the server takes (BuildFile::MAX_BYTES), checked before sending: 8 MB is a common default
 * of PHP's post_max_size, and a server sent more can answer with a warning page instead of the API's JSON. A
 * whole source's file takes about 2 MB.
 */
export const BUILD_FILE_MAX_BYTES = 8 * 1024 * 1024;

/** A builds file of the source, with what the confirmation says about it. */
export interface BuildFileCheck {
  ok: true;
  builds: number;
  characters: number;
  /** When it was exported (ISO 8601), or null when the file does not say. */
  exportedAt: string | null;
}

export interface BuildFileProblem {
  ok: false;
  message: string;
}

type Source = Pick<SourceStatus, 'key' | 'name' | 'game' | 'builds'>;

/**
 * Whether a file's text is a builds file for this source. What the file says is only repeated when it has
 * the form the app writes itself (a game, a key like "genshin-2"): a message never shows other text from it.
 */
export function checkBuildFile(text: string, source: Source): BuildFileCheck | BuildFileProblem {
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
  if (version !== BUILD_FILE_VERSION) {
    return problem(
      typeof version === 'number' && version > BUILD_FILE_VERSION
        ? `This builds file has format version ${version}, and this installation reads version ${BUILD_FILE_VERSION}. Update this installation, then import again.`
        : 'This builds file has no format version this installation reads.',
    );
  }

  const game = data['game'];
  if (game !== source.game) {
    return problem(
      isGame(game)
        ? `This file holds ${GAME_LABELS[game]} builds, and ${source.name} is a ${GAME_LABELS[source.game]} source.`
        : 'This builds file names no game this installation knows.',
    );
  }

  const key = data['source'];
  if (key !== source.key) {
    const theirs = typeof key === 'string' && /^[a-z]+-\d{1,4}$/.test(key) ? key : 'another source';
    return problem(`This file holds the builds of ${theirs}, not of ${source.name} (${source.key}).`);
  }

  const builds = data['builds'];
  if (!Array.isArray(builds)) {
    return problem('This builds file holds no list of builds.');
  }
  const characters = new Set(builds.map((build) => (isObject(build) ? build['character'] : null)).filter((character) => typeof character === 'string'));
  const exported = data['exported_at'];

  return {
    ok: true,
    builds: builds.length,
    characters: characters.size,
    exportedAt: typeof exported === 'string' && !Number.isNaN(parseTime(exported).getTime()) ? exported : null,
  };
}

/**
 * What the confirmation asks before the source's builds are replaced by the file's. `dates` is the account's
 * date order and clock (AuthService.preferences()), which the time the file was exported is written in where
 * the person has chosen them.
 */
export function importQuestion(source: Source, file: BuildFileCheck, dates: DateChoices | null = null): string {
  const name = `${source.name} (${GAME_LABELS[source.game]})`;
  const builds = `the file's ${count(file.builds, 'build')} of ${count(file.characters, 'character')}`;
  const exported = file.exportedAt ? `, exported ${formatTime(file.exportedAt, dates)}` : '';
  return source.builds > 0
    ? `Replace the ${count(source.builds, 'build')} of ${name} with ${builds}${exported}?`
    : `Import into ${name} ${builds}${exported}?`;
}

/** What an import did: the counts, and the characters this installation's catalog lacks. */
export function importSummary(source: Pick<SourceStatus, 'name'>, result: BuildImportResult): string {
  const message = `Imported into ${source.name}: ${result.created} new, ${result.updated} updated, ${result.unchanged} unchanged, ${result.removed} removed.`;
  if (result.skipped.length === 0) {
    return message;
  }
  const names = result.skipped.slice(0, 5).map((character) => character.name);
  const more = result.skipped.length - names.length;
  return (
    `${message} Skipped ${count(result.skipped.length, 'character')} the catalog lacks ` +
    `(${names.join(', ')}${more > 0 ? ` and ${more} more` : ''}): refresh the catalog, then import again.`
  );
}

function problem(message: string): BuildFileProblem {
  return { ok: false, message };
}

function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isGame(value: unknown): value is GameKey {
  return typeof value === 'string' && Object.keys(GAME_LABELS).includes(value);
}

/** The file's times carry microseconds; Date is only promised to read milliseconds. */
function parseTime(value: string): Date {
  return new Date(value.replace(/(\.\d{3})\d+/, '$1'));
}

/** The page's own way of writing a day and a time, for the part the account leaves at "automatic". */
const OWN_PARTS: OwnDateParts = {
  day: (date) => date.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }),
  time: (date) => date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }),
};

/**
 * "29 September 2026 at 14:03", in the browser's time zone - or in the account's date order and clock where
 * the person has chosen them: "2026-09-29 2:03 pm".
 */
function formatTime(value: string, dates: DateChoices | null): string {
  const date = parseTime(value);
  return (
    chosenMoment(date, dates, 'en-GB', OWN_PARTS) ??
    date.toLocaleString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })
  );
}

function count(value: number, noun: string): string {
  return `${value} ${noun}${value === 1 ? '' : 's'}`;
}
