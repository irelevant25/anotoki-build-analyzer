import type { IconPaths, IconSet } from '@anotoki/lib/ui/config';
import {
  iconBan,
  iconChart,
  iconClock,
  iconColumns,
  iconDatabase,
  iconDownload,
  iconKey,
  iconLayers,
  iconLock,
  iconMoreHorizontal,
  iconPencil,
  iconPlay,
  iconTrash,
  iconUsers,
} from '@anotoki/lib/ui/icons';

/*
 * The icons this site's own templates name, for <anotoki-icon> (the anotoki family's kit, registered by
 * provideAnotokiUi in app.config.ts). The kit draws its own (sun, user, search, plus, check, alert, info,
 * refresh, chevrons, ...) without being told; these are the family's other icons the site uses - data from
 * @anotoki/lib/ui/icons, one constant each, so nothing else of that set lands in the build - and the few the
 * family has none of yet, drawn here the same way: 24-unit line icons in the manner of the kit's (Lucide's
 * shapes), each a list of path `d` strings and nothing else.
 */

/** Honkai: Star Rail's mark on its accounts and in the wizard: a rocket (Genshin Impact's is the kit's sparkle). */
const rocket: IconPaths = [
  'M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z',
  'M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z',
  'M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0',
  'M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5',
];
/** A file going up: the imports. */
const upload: IconPaths = [
  'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4',
  'M17 8l-5-5-5 5',
  'M12 3v12',
];
/** A tip: the wizard's guides, a character's first thing to improve. */
const lightbulb: IconPaths = [
  'M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5',
  'M9 18h6',
  'M10 22h4',
];
/** Pause: a sync waiting, a guide's "pause the capture". */
const pause: IconPaths = [
  'M15 4h2a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-2a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z',
  'M7 4h2a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z',
];
/** What an import method lacks (beside the kit's check for what it has). */
const minus: IconPaths = ['M5 12h14'];
/** "Fixed", in What's new. */
const wrench: IconPaths = [
  'M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z',
];
/** The game itself, in the wizard's guides ("Log into Genshin Impact"). */
const gamepad: IconPaths = [
  'M6 11h4',
  'M8 9v4',
  'M15 12h.01',
  'M18 10h.01',
  'M17.32 5H6.68a4 4 0 0 0-3.98 3.59C2.6 9.42 2 14.46 2 16a3 3 0 0 0 3 3c1 0 1.5-.5 2-1l1.41-1.41A2 2 0 0 1 9.83 16h4.34a2 2 0 0 1 1.41.59L17 18c.5.5 1 1 2 1a3 3 0 0 0 3-3c0-1.55-.6-6.58-.69-7.26A4 4 0 0 0 17.32 5z',
];
/** Saving the export, in the wizard's guides. */
const save: IconPaths = [
  'M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z',
  'M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7',
  'M7 3v4a1 1 0 0 0 1 1h7',
];

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
  rocket,
  upload,
  lightbulb,
  pause,
  minus,
  wrench,
  gamepad,
  save,
};

/** A game's mark: Genshin Impact's sparkle (the kit's), Honkai: Star Rail's rocket. */
export function gameIcon(game: 'genshin' | 'hsr'): string {
  return game === 'hsr' ? 'rocket' : 'sparkle';
}
