import { TranslationKey } from '../../../shared/i18n/en';
import { GameKey } from '../../../api/models';
import { SCANNER_COMMAND, SCANNER_GUIDE } from './scanner';

/**
 * The two ways in: the public in-game Character Showcase, read by UID, and an inventory file - from the project's own
 * scanner (game-scanner/, the owner's request of 2026-10-27), or any other tool that writes the GOOD format.
 *
 * The guides name no other website, service or tool and link to none (the app credits nobody by name). The one
 * address they hold is the project's own: the file method's first step shows the line that starts the scanner, with a
 * Copy button, and links to the scanner's instructions - both from scanner.ts, never a string.
 *
 * Every text here is a key, written out whole: the words are the site's own, in the reader's language,
 * around the games' menu names ("Character Showcase", "Edit Profile", UID, GOOD), which every language
 * keeps as they are. The template words a key with the translate pipe and tracks by it. No key is ever put
 * together from the game or the method the address asks for - these two tables are all there is.
 */
export type ImportMethodKey = 'showcase' | 'file';

export interface ImportMethod {
  key: ImportMethodKey;
  title: TranslationKey;
  icon: string;
  summary: TranslationKey;
  pros: TranslationKey[];
  cons: TranslationKey[];
}

export interface GuideStep {
  title: TranslationKey;
  body: TranslationKey;
  icon: string;
  tip?: TranslationKey;
  /** A line to copy and paste, shown under the body with a Copy button: its name (heard before it) and the line itself. */
  command?: { label: TranslationKey; text: string };
  /** A link under the body: its words and where it goes. */
  link?: { label: TranslationKey; href: string };
}

export interface Guide {
  intro: TranslationKey;
  steps: GuideStep[];
  troubleshooting: TranslationKey[];
  /** What the file to upload looks like, shown in the upload step. */
  fileHint?: TranslationKey;
}

export const METHODS: Record<GameKey, ImportMethod[]> = {
  genshin: [
    {
      key: 'showcase',
      title: 'importGuide.genshin.showcase.title',
      icon: 'user',
      summary: 'importGuide.genshin.showcase.summary',
      pros: ['importGuide.genshin.showcase.pro1', 'importGuide.genshin.showcase.pro2', 'importGuide.genshin.showcase.pro3'],
      cons: ['importGuide.genshin.showcase.con1', 'importGuide.genshin.showcase.con2'],
    },
    {
      key: 'file',
      title: 'importGuide.genshin.file.title',
      icon: 'layers',
      summary: 'importGuide.genshin.file.summary',
      pros: ['importGuide.genshin.file.pro1', 'importGuide.genshin.file.pro2', 'importGuide.genshin.file.pro3'],
      cons: ['importGuide.genshin.file.con1', 'importGuide.genshin.file.con2'],
    },
  ],
};

export const GUIDES: Record<GameKey, Record<ImportMethodKey, Guide>> = {
  genshin: {
    showcase: {
      intro: 'importGuide.genshin.showcase.intro',
      steps: [
        {
          icon: 'user',
          title: 'importGuide.genshin.showcase.step1.title',
          body: 'importGuide.genshin.showcase.step1.body',
        },
        {
          icon: 'columns',
          title: 'importGuide.genshin.showcase.step2.title',
          body: 'importGuide.genshin.showcase.step2.body',
          tip: 'importGuide.genshin.showcase.step2.tip',
        },
        {
          icon: 'eye',
          title: 'importGuide.genshin.showcase.step3.title',
          body: 'importGuide.genshin.showcase.step3.body',
        },
        {
          icon: 'clock',
          title: 'importGuide.genshin.showcase.step4.title',
          body: 'importGuide.genshin.showcase.step4.body',
        },
        {
          icon: 'key',
          title: 'importGuide.genshin.showcase.step5.title',
          body: 'importGuide.genshin.showcase.step5.body',
        },
      ],
      troubleshooting: ['importGuide.genshin.showcase.trouble1', 'importGuide.genshin.showcase.trouble2', 'importGuide.genshin.showcase.trouble3'],
    },
    file: {
      intro: 'importGuide.genshin.file.intro',
      fileHint: 'importGuide.genshin.file.fileHint',
      steps: [
        {
          icon: 'download',
          title: 'importGuide.genshin.file.step1.title',
          body: 'importGuide.genshin.file.step1.body',
          tip: 'importGuide.genshin.file.step1.tip',
          command: { label: 'importGuide.genshin.file.step1.command', text: SCANNER_COMMAND },
          link: { label: 'importGuide.genshin.file.step1.link', href: SCANNER_GUIDE },
        },
        {
          icon: 'play',
          title: 'importGuide.genshin.file.step2.title',
          body: 'importGuide.genshin.file.step2.body',
        },
        {
          icon: 'gamepad',
          title: 'importGuide.genshin.file.step3.title',
          body: 'importGuide.genshin.file.step3.body',
          tip: 'importGuide.genshin.file.step3.tip',
        },
        {
          icon: 'save',
          title: 'importGuide.genshin.file.step4.title',
          body: 'importGuide.genshin.file.step4.body',
        },
      ],
      troubleshooting: ['importGuide.genshin.file.trouble1', 'importGuide.genshin.file.trouble2', 'importGuide.genshin.file.trouble3'],
    },
  },
};
