import { GameKey } from '../../core/models';

export type ImportMethodKey = 'enka' | 'file';

export interface ImportMethod {
  key: ImportMethodKey;
  title: string;
  icon: string;
  summary: string;
  pros: string[];
  cons: string[];
}

export interface GuideLink {
  label: string;
  url: string;
}

export interface GuideStep {
  title: string;
  body: string;
  icon: string;
  links?: GuideLink[];
  tip?: string;
}

export interface Guide {
  intro: string;
  steps: GuideStep[];
  troubleshooting: string[];
  /** File names the exporter produces, shown in the upload step. */
  fileHint?: string;
}

export const METHODS: Record<GameKey, ImportMethod[]> = {
  genshin: [
    {
      key: 'enka',
      title: 'Character Showcase (UID)',
      icon: 'badge',
      summary: 'Reads the characters you show on your in-game profile through Enka.Network.',
      pros: ['Nothing to install, takes a minute', 'Includes your in-game total stats (ER, CRIT, EM...)', 'Repeat it with other characters to build up your roster'],
      cons: ['Up to 12 characters per import', 'No unequipped artifacts, so no "better piece you own" suggestions'],
    },
    {
      key: 'file',
      title: 'Full inventory (exporter tool)',
      icon: 'inventory_2',
      summary: 'A tool on your PC exports every character, weapon and artifact as a GOOD file.',
      pros: ['All characters, weapons and artifacts at once', 'Finds better artifacts you already own', 'Talent levels and exact roll counts'],
      cons: ['Needs a Windows tool (Irminsul) running while you log in', 'No in-game total stats (combine with a UID import)'],
    },
  ],
  hsr: [
    {
      key: 'enka',
      title: 'Character Showcase (UID)',
      icon: 'badge',
      summary: 'Reads your showcase and support characters through Enka.Network.',
      pros: ['Nothing to install, takes a minute', 'Exact relic roll counts', 'Repeat it with other characters to build up your roster'],
      cons: ['Only showcase + support characters (about 8)', 'No unequipped relics, so no "better piece you own" suggestions'],
    },
    {
      key: 'file',
      title: 'Full inventory (exporter tool)',
      icon: 'inventory_2',
      summary: 'Reliquary Archiver (or HSR-Scanner) exports every character, light cone and relic.',
      pros: ['All characters, light cones and relics at once', 'Finds better relics you already own', 'Same file the Fribbels HSR Optimizer uses'],
      cons: ['Needs a Windows tool running while you log in'],
    },
  ],
};

export const GUIDES: Record<GameKey, Record<ImportMethodKey, Guide>> = {
  genshin: {
    enka: {
      intro: 'Enka.Network can only see what your public profile shows, so first put the characters you want analyzed into your Character Showcase.',
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
        'Old data: Enka caches a profile for a few minutes; import again a bit later.',
        'Characters from an earlier import stay in the app, so you can rotate your showcase and import several times.',
      ],
    },
    file: {
      intro: 'Irminsul reads the data the game sends to your PC when you log in and saves it as a GOOD file (the format Genshin Optimizer uses). It does not change the game.',
      fileHint: 'genshin_export_YYYY-MM-DD_HH-MM.json',
      steps: [
        {
          icon: 'download',
          title: 'Download Irminsul',
          body: 'Download irminsul-windows-x64.exe from the latest release. No installation is needed.',
          links: [{ label: 'Irminsul releases (GitHub)', url: 'https://github.com/konkers/irminsul/releases' }],
          tip: 'Alternative without packet capture: Inventory Kamera scans your inventory from screenshots (English game text only).',
        },
        {
          icon: 'play_circle',
          title: 'Start Irminsul before the game',
          body: 'Run Irminsul and accept the administrator prompt (it uses Windows\' built-in packet monitor). Press the ▶ button under "Packet Capture".',
        },
        {
          icon: 'sports_esports',
          title: 'Log into Genshin Impact',
          body: 'Start the game and click through the door into the world. Wait until Irminsul shows green checkmarks for characters, weapons and artifacts.',
          tip: 'Already in the game? Log out to the title screen and enter again while Irminsul is capturing.',
        },
        {
          icon: 'save',
          title: 'Export the GOOD file',
          body: 'Click the download icon in Irminsul and save the file, or use "copy to clipboard" and paste it in the next step.',
        },
      ],
      troubleshooting: [
        'Nothing captured: make sure Irminsul was capturing before you entered the world, then relog.',
        'After a game update the tool may need an update too; check the releases page.',
        'Third-party tools are used at your own risk. Irminsul only reads data; this app never asks for your password.',
      ],
    },
  },
  hsr: {
    enka: {
      intro: 'Enka.Network reads your public profile: the Character Showcase plus your Support Characters.',
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
        'Old data: Enka caches a profile for a few minutes; import again a bit later.',
      ],
    },
    file: {
      intro: 'Reliquary Archiver reads the data the game sends when you log in and exports it in the format the Fribbels HSR Optimizer uses. HSR-Scanner files (OCR) work too.',
      fileHint: 'archive_output-YYYY-MM-DDTHH-MM-SS.json or HSRScanData_*.json',
      steps: [
        {
          icon: 'download',
          title: 'Download Reliquary Archiver',
          body: 'Get the "pktmon" build (no driver needed, runs as administrator) or the "pcap" build (requires Npcap installed in WinPcap-compatible mode).',
          links: [
            { label: 'Reliquary Archiver releases', url: 'https://github.com/IceDynamix/reliquary-archiver/releases' },
            { label: 'Npcap (only for the pcap build)', url: 'https://npcap.com/#download' },
          ],
          tip: 'Alternative: HSR-Scanner reads your inventory from the screen (English text, 16:9, don\'t move the mouse while it scans).',
        },
        {
          icon: 'pause_circle',
          title: 'Start the game, stop at "Click to Start"',
          body: 'Launch Honkai: Star Rail and wait on the title screen. Do not enter the game yet.',
        },
        {
          icon: 'play_circle',
          title: 'Run the archiver',
          body: 'Start Reliquary Archiver and wait until it says "Waiting for login...".',
        },
        {
          icon: 'sports_esports',
          title: 'Enter the game',
          body: 'Click to start. The archiver switches to "Connected!" and collects your characters, light cones and relics.',
        },
        {
          icon: 'save',
          title: 'Download the export',
          body: 'Press Download in the archiver and save the JSON file.',
        },
      ],
      troubleshooting: [
        'Already in the game? Log out to the title screen and enter again while the archiver is running.',
        'A VPN can prevent the capture.',
        '"Unsupported Game Version" after a patch: wait for the next archiver release.',
        'Third-party tools are used at your own risk. They only read data; this app never asks for your password.',
      ],
    },
  },
};
