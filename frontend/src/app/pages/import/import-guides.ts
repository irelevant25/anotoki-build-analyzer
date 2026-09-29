import { GameKey } from '../../core/models';

/**
 * The two ways in: the public in-game Character Showcase, read by UID, and an inventory file exported by a
 * tool on the player's PC.
 *
 * The guides name no website, service or tool and link to none (the app credits nobody by name); they
 * describe what to look for instead - an exporter that writes the file format the backend reads.
 */
export type ImportMethodKey = 'showcase' | 'file';

export interface ImportMethod {
  key: ImportMethodKey;
  title: string;
  icon: string;
  summary: string;
  pros: string[];
  cons: string[];
}

export interface GuideStep {
  title: string;
  body: string;
  icon: string;
  tip?: string;
}

export interface Guide {
  intro: string;
  steps: GuideStep[];
  troubleshooting: string[];
  /** What the file to upload looks like, shown in the upload step. */
  fileHint?: string;
}

export const METHODS: Record<GameKey, ImportMethod[]> = {
  genshin: [
    {
      key: 'showcase',
      title: 'Character Showcase (UID)',
      icon: 'badge',
      summary: 'Reads the characters you show on your public in-game profile.',
      pros: ['Nothing to install, takes a minute', 'Includes your in-game total stats (ER, CRIT, EM...)', 'Repeat it with other characters to build up your roster'],
      cons: ['Up to 12 characters per import', 'No unequipped artifacts, so no "better piece you own" suggestions'],
    },
    {
      key: 'file',
      title: 'Full inventory (exporter tool)',
      icon: 'inventory_2',
      summary: 'An inventory exporter on your PC saves every character, weapon and artifact as a GOOD file.',
      pros: ['All characters, weapons and artifacts at once', 'Finds better artifacts you already own', 'Talent levels and exact roll counts'],
      cons: ['Needs an exporter tool on a Windows PC', 'No in-game total stats (combine with a UID import)'],
    },
  ],
  hsr: [
    {
      key: 'showcase',
      title: 'Character Showcase (UID)',
      icon: 'badge',
      summary: 'Reads your showcase and support characters from your public in-game profile.',
      pros: ['Nothing to install, takes a minute', 'Exact relic roll counts', 'Repeat it with other characters to build up your roster'],
      cons: ['Only showcase + support characters (about 8)', 'No unequipped relics, so no "better piece you own" suggestions'],
    },
    {
      key: 'file',
      title: 'Full inventory (exporter tool)',
      icon: 'inventory_2',
      summary: 'A relic exporter on your PC saves every character, light cone and relic as a JSON file.',
      pros: ['All characters, light cones and relics at once', 'Finds better relics you already own', 'The file relic optimizers read, so one export serves both'],
      cons: ['Needs an exporter tool on a Windows PC'],
    },
  ],
};

export const GUIDES: Record<GameKey, Record<ImportMethodKey, Guide>> = {
  genshin: {
    showcase: {
      intro: 'Only what your public profile shows can be read, so first put the characters you want analyzed into your Character Showcase.',
      steps: [
        {
          icon: 'person',
          title: 'Open your profile',
          body: 'In the game press Esc to open the Paimon menu, click your avatar at the top left and choose "Edit Profile".',
        },
        {
          icon: 'view_carousel',
          title: 'Fill the Character Showcase',
          body: 'Open "Character Showcase" and pick up to 12 characters. Their weapons and artifacts are shown as they are equipped right now.',
          tip: 'Start with the characters you want to improve; you can swap them later and import again.',
        },
        {
          icon: 'visibility',
          title: 'Turn on "Show Character Details"',
          body: 'Still in the profile settings, enable "Show Character Details". Without it only names and levels are public.',
        },
        {
          icon: 'schedule',
          title: 'Let the profile refresh',
          body: 'The public profile updates when you log out or after a few minutes. Entering and leaving your Serenitea Pot also refreshes it.',
        },
        {
          icon: 'fingerprint',
          title: 'Find your UID',
          body: 'Your UID is the 9 or 10 digit number in the bottom-right corner of the game screen and on your profile.',
        },
      ],
      troubleshooting: [
        '"Show Character Details is turned off": enable it in Edit Profile and wait a few minutes.',
        'Old data: a public profile is cached for a few minutes; import again a bit later.',
        'Characters from an earlier import stay in the app, so you can rotate your showcase and import several times.',
      ],
    },
    file: {
      intro:
        'An inventory exporter reads the data the game sends to your PC when you log in (or scans your inventory from screenshots) and saves it as a GOOD file, the common JSON format for Genshin Impact inventories. It does not change the game.',
      fileHint: 'A .json file in the GOOD format',
      steps: [
        {
          icon: 'download',
          title: 'Get an inventory exporter',
          body: 'Use an exporter that saves the GOOD format. Download it from its official release page; most need no installation.',
          tip: 'Exporters that read the game data need administrator rights for Windows\' packet monitor; screenshot scanners need English game text.',
        },
        {
          icon: 'play_circle',
          title: 'Start the exporter before the game',
          body: 'Run the exporter and start its capture before you enter the world.',
        },
        {
          icon: 'sports_esports',
          title: 'Log into Genshin Impact',
          body: 'Start the game and click through the door into the world. Wait until the exporter shows that it has your characters, weapons and artifacts.',
          tip: 'Already in the game? Log out to the title screen and enter again while the exporter is capturing.',
        },
        {
          icon: 'save',
          title: 'Export the GOOD file',
          body: 'Save the export as a file, or copy it to the clipboard and paste it in the next step.',
        },
      ],
      troubleshooting: [
        'Nothing captured: make sure the exporter was capturing before you entered the world, then relog.',
        'After a game update the exporter may need an update too; check its release page.',
        'Tools from other people are used at your own risk. An exporter only reads data; this app never asks for your game password.',
      ],
    },
  },
  hsr: {
    showcase: {
      intro: 'Only your public profile can be read: the Character Showcase plus your Support Characters.',
      steps: [
        {
          icon: 'person',
          title: 'Open your profile',
          body: 'Press Esc to open the Phone menu and click your avatar to open your Trailblazer profile.',
        },
        {
          icon: 'view_carousel',
          title: 'Set the Character Showcase',
          body: 'Choose the characters to showcase. Their light cones and relics are read as currently equipped.',
        },
        {
          icon: 'group',
          title: 'Set your Support Characters',
          body: 'Support Characters are public too, so they are imported as well.',
          tip: 'Rotate the showcase and import again to cover more characters; earlier imports are kept.',
        },
        {
          icon: 'schedule',
          title: 'Let the profile refresh',
          body: 'Changes become visible after a few minutes or after logging out.',
        },
        {
          icon: 'fingerprint',
          title: 'Find your UID',
          body: 'Your UID is shown at the bottom left of the screen and on your profile (9 or 10 digits).',
        },
      ],
      troubleshooting: [
        '"This profile shows no characters": add characters to the showcase and wait a few minutes.',
        'Old data: a public profile is cached for a few minutes; import again a bit later.',
      ],
    },
    file: {
      intro:
        'A relic exporter reads the data the game sends when you log in and saves your characters, light cones and relics as a JSON file, the format relic optimizers read. Files from screen-scanning exporters work too.',
      fileHint: 'A .json file with your characters, light cones and relics',
      steps: [
        {
          icon: 'download',
          title: 'Get a relic exporter',
          body: 'Use an exporter that saves characters, light cones and relics as JSON. Download it from its official release page.',
          tip: 'Screen-scanning exporters need English game text and a 16:9 window; do not move the mouse while they scan.',
        },
        {
          icon: 'pause_circle',
          title: 'Start the game, stop at "Click to Start"',
          body: 'Launch Honkai: Star Rail and wait on the title screen. Do not enter the game yet.',
        },
        {
          icon: 'play_circle',
          title: 'Run the exporter',
          body: 'Start the exporter and wait until it is ready for your login.',
        },
        {
          icon: 'sports_esports',
          title: 'Enter the game',
          body: 'Click to start. The exporter connects and collects your characters, light cones and relics.',
        },
        {
          icon: 'save',
          title: 'Save the export',
          body: 'Save the JSON file the exporter offers.',
        },
      ],
      troubleshooting: [
        'Already in the game? Log out to the title screen and enter again while the exporter is running.',
        'A VPN can prevent the capture.',
        'An "unsupported game version" message after a patch: wait for the exporter\'s next release.',
        'Tools from other people are used at your own risk. They only read data; this app never asks for your game password.',
      ],
    },
  },
};
