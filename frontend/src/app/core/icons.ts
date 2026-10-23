import type { IconSet } from '@anotoki/lib/ui/config';
import type { GameKey } from './models';
import {
  iconArrowDown,
  iconArrowRight,
  iconArrowUp,
  iconBan,
  iconChart,
  iconClock,
  iconColumns,
  iconDatabase,
  iconDownload,
  iconGamepad,
  iconKey,
  iconLayers,
  iconLightbulb,
  iconLock,
  iconMinus,
  iconMoreHorizontal,
  iconPencil,
  iconPlay,
  iconSave,
  iconTrash,
  iconUndo,
  iconUpload,
  iconUsers,
  iconWrench,
} from '@anotoki/lib/ui/icons';

/*
 * The icons this site's own templates name, for <anotoki-icon> (the anotoki family's kit, registered by
 * provideAnotokiUi in app.config.ts). The kit draws its own (sun, user, search, plus, check, alert, info,
 * refresh, chevrons, ...) without being told; these are the family's other icons the site uses - data from
 * @anotoki/lib/ui/icons, one constant each, so nothing else of that set lands in the build. Since anotoki-lib 0.6.0
 * the ones this site drew itself are the family's too: upload (the imports), lightbulb (a tip), minus (what an import method lacks, beside the kit's check), wrench
 * ("Fixed" in What's new), gamepad (the game itself, in the wizard's guides) and save (saving the export). The builds
 * editor's: arrowUp and arrowDown (moving a build or a row of one), arrowRight beside the kit's own arrowLeft (moving
 * a team's member, its columns side by side), undo (a part back as the file has it). A game's
 * mark is the kit's own (Genshin Impact's sparkle).
 */

/** Every icon this site names beyond the kit's own: `<anotoki-icon name="trash" />`. */
export const SITE_ICONS: IconSet = {
  ban: iconBan,
  chart: iconChart,
  clock: iconClock,
  columns: iconColumns,
  database: iconDatabase,
  download: iconDownload,
  key: iconKey,
  layers: iconLayers,
  lock: iconLock,
  more: iconMoreHorizontal,
  pencil: iconPencil,
  play: iconPlay,
  trash: iconTrash,
  users: iconUsers,
  upload: iconUpload,
  lightbulb: iconLightbulb,
  minus: iconMinus,
  wrench: iconWrench,
  gamepad: iconGamepad,
  save: iconSave,
  arrowUp: iconArrowUp,
  arrowDown: iconArrowDown,
  arrowRight: iconArrowRight,
  undo: iconUndo,
};

/** A game's mark, by game: Genshin Impact's sparkle (the kit's). */
const GAME_ICONS: Record<GameKey, string> = { genshin: 'sparkle' };

export function gameIcon(game: GameKey): string {
  return GAME_ICONS[game];
}
