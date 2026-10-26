<?php

namespace App\Services\Builds;

use App\Enums\Game;
use App\Models\Build;
use App\Support\Clock;
use App\Support\Key;
use App\Support\Neutral;
use App\Support\Stats;
use App\Support\Talents;
use App\Support\Text;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * The file a game's builds are imported from and exported to: format "build-analyzer.builds", version 3
 * (docs/builds.md). The builds are put together elsewhere and brought in as one file per game on the admin
 * panel's Builds page (BuildImport); the export writes the same, as a backup.
 *
 * A build is a variation of a character's: its role (Game::roles()), a short qualifier telling two builds of one
 * role apart, and the constellation it needs (min_rank). (character, role, qualifier without regard to case,
 * min_rank) is its identity, once in a file. A character's builds come in the order of preference: the first is
 * the main one. A stat target can start from a constellation (from_rank), each (stat, from_rank) once in a build,
 * and is one of three (the editor's round of 2026-10-26): a requirement - its min and/or max as stated -, the three
 * tiers an editor set by hand in place of the ones the site works out for the stat (`tiers`), or the stat switched
 * off (`off`: none worked out for it) - the last two optional fields, which a file without them reads as before and
 * the export writes only where they are set. A build lists the teams it is played in (version 3): each a name, its
 * members - the build's own character among them - with the constellation the team asks of each and who can take a
 * member's place, and a note.
 *
 * Like every answer of the API the file names no site: every text passes through Neutral - on the way out, and
 * again on the way in, so a file edited by hand cannot bring a site's name into what the API sends or the
 * analysis writes.
 *
 * Reading is strict, because the analysis reads every stored build for every user. A file is refused as a whole,
 * naming its first problem, unless every build has exactly the fields and shapes of the format: lists and objects
 * where they belong, whole numbers where the app counts, the roles, set kinds, slots, stats and talents of the
 * file's game, a slot's main stats among those the slot can have, and set pieces as the set matcher reads them -
 * each set listed once, 2 or 4 pieces of it, which keeps the matcher's search small: no more than two equipped
 * sets can satisfy such pieces. The caps on sizes are well above anything a build needs; they only keep a file
 * from being absurd. Whatever the export writes, the import reads.
 */
final class BuildFile
{
    public const FORMAT = 'build-analyzer.builds';

    public const VERSION = 3;

    /** A common default of PHP's post_max_size; a game's builds take about 1 MB (1.2 MB with their teams, 2026-10-22). */
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const MAX_BUILDS = 5000;

    /** A build's qualifier: a word or two ("Melt", "Hyperbloom"), its column's length. */
    public const MAX_QUALIFIER = 40;

    /** Per build: a few dozen entries at most. */
    private const MAX_WEAPONS = 100;

    private const MAX_SETS = 50;

    /** A "choose two" option lists one piece per set; a build can list many. */
    private const MAX_PIECES = 30;

    /** A group such as "any 18% ATK set" grows with every new set (13 in 2026). */
    private const MAX_SET_KEYS = 100;

    private const MAX_STATS = 30;

    private const MAX_TALENTS = 10;

    private const MAX_TARGETS = 30;

    /** A build's teams: the sites list a few - thirteen at most so far (2026-10-22); more makes a file absurd. */
    public const MAX_TEAMS = 20;

    /** A team's name: a few words ("Vaporize", "Hyperbloom Aggravate"), one line. */
    public const MAX_TEAM_NAME = 60;

    /** Who can take a team member's place. */
    public const MAX_ALTERNATIVES = 5;

    /** A weapon's refinement, as the games count it. */
    private const MAX_REFINEMENT = 5;

    /** A stat's or a talent's priority: 1 the most important. */
    private const MAX_PRIORITY = 99;

    /** A stat target's bound, either way. */
    private const MAX_BOUND = 1_000_000;

    /** Text that has a column: its length there. */
    private const MAX_CHARACTER = 120;

    private const MAX_CHARACTER_NAME = 160;

    private const MAX_GAME_VERSION = 20;

    /**
     * Text without a column of its own: a build's notes; the notes of its weapons, sets and targets; names, labels
     * and keys of weapons and sets, which can be spelled longer than the catalog's columns - a "Flex: A / B / C"
     * piece grows with every option, a weapon the catalog does not know keeps the name it was given.
     */
    private const MAX_NOTES = 20000;

    private const MAX_NOTE = 5000;

    private const MAX_NAME = 1000;

    /** The fields of the file and of a build, in the order the export writes them. */
    private const FIELDS = ['format', 'format_version', 'game', 'exported_at', 'builds'];

    private const BUILD_FIELDS = [
        'character',
        'character_name',
        'role',
        'qualifier',
        'min_rank',
        'weapons',
        'sets',
        'main_stats',
        'substats',
        'talent_priority',
        'stat_targets',
        'teams',
        'notes',
        'game_version',
    ];

    /** A team, and a member of one (format version 3). */
    private const TEAM_FIELDS = ['name', 'members', 'note'];

    private const MEMBER_FIELDS = ['key', 'name', 'role', 'min_rank', 'alternatives'];

    private const TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/';

    /** The build a problem is in ('builds[3] (hutao, main_dps C2+)'), or null while the header is read. */
    private ?string $build = null;

    /** The index of the build being read, or null while the header is read. */
    private ?int $index = null;

    /** @param  bool  $editor  one build of an editor's save (readEntry()): its problems said without the file's words */
    private function __construct(private readonly Game $game, private readonly bool $editor = false) {}

    /**
     * A build's identity within its game: its character, role, qualifier (without regard to case) and the
     * constellation it needs. An import keeps the id of a build whose identity it finds again.
     */
    public static function identity(string $character, string $role, string $qualifier, int $minRank): string
    {
        return $character . "\n" . $role . "\n" . mb_strtolower($qualifier) . "\n" . $minRank;
    }

    /** A build's variation in a log line or a problem: "main_dps", "sub_dps \"Melt\"", "support C4+". */
    public static function variation(Game $game, string $role, string $qualifier, int $minRank): string
    {
        return $role . ($qualifier === '' ? '' : ' "' . Text::limit($qualifier, self::MAX_QUALIFIER) . '"') . ($minRank > 0 ? " {$game->rankPrefix()}{$minRank}+" : '');
    }

    /**
     * A stored build as the file holds it, with its character. Text is made neutral within the reader's caps, and
     * a piece's sets that neutral words make alike are listed once, so the file always imports; the order of its
     * weapons and set options is their rank.
     *
     * @return array<string, mixed>
     */
    public static function entry(Build $build): array
    {
        return [
            'character' => $build->character->key,
            'character_name' => self::neutral($build->character->name, self::MAX_CHARACTER_NAME),
            'role' => $build->role,
            'qualifier' => self::qualifierText($build->qualifier),
            'min_rank' => $build->min_rank,
            'weapons' => array_map(
                fn(array $weapon) => [
                    'key' => self::neutralKey((string) ($weapon['key'] ?? '')),
                    'name' => self::neutral((string) ($weapon['name'] ?? ''), self::MAX_NAME),
                    'refinement' => $weapon['refinement'] ?? null,
                    'note' => self::neutral($weapon['note'] ?? null, self::MAX_NOTE),
                ],
                $build->weapons,
            ),
            'sets' => array_map(
                fn(array $option) => [
                    'kind' => $option['kind'] ?? null,
                    'choose' => $option['choose'] ?? null,
                    'pieces' => array_map(
                        fn(array $piece) => [
                            'label' => self::neutral((string) ($piece['label'] ?? ''), self::MAX_NAME),
                            'count' => $piece['count'] ?? null,
                            'keys' => array_values(array_unique(array_map(fn($key) => self::neutralKey((string) $key), $piece['keys'] ?? []))),
                            'names' => array_values(array_unique(array_map(fn($name) => self::neutral((string) $name, self::MAX_NAME), $piece['names'] ?? []))),
                        ],
                        $option['pieces'] ?? [],
                    ),
                    'note' => self::neutral($option['note'] ?? null, self::MAX_NOTE),
                ],
                $build->sets,
            ),
            // An object even when empty: its keys are slots.
            'main_stats' => (object) $build->main_stats,
            'substats' => $build->substats,
            'talent_priority' => $build->talent_priority,
            'stat_targets' =>
                $build->stat_targets === null
                    ? null
                    : array_map(
                        // The tiers set by hand and a stat switched off only where they are: a file from before reads the same.
                        fn(array $target) => [
                            'stat' => $target['stat'] ?? null,
                            'min' => $target['min'] ?? null,
                            'max' => $target['max'] ?? null,
                            'text' => self::neutral((string) ($target['text'] ?? ''), self::MAX_NOTE),
                            'from_rank' => $target['from_rank'] ?? 0,
                        ] +
                            (is_array($target['tiers'] ?? null)
                                ? [
                                    'tiers' => [
                                        'basic' => $target['tiers']['basic'] ?? null,
                                        'intermediate' => $target['tiers']['intermediate'] ?? null,
                                        'advanced' => $target['tiers']['advanced'] ?? null,
                                    ],
                                ]
                                : []) +
                            (($target['off'] ?? false) === true ? ['off' => true] : []),
                        $build->stat_targets,
                    ),
            'teams' => self::teamsEntry($build->teams),
            'notes' => self::neutral($build->notes, self::MAX_NOTES),
            'game_version' => self::neutral($build->game_version, self::MAX_GAME_VERSION),
        ];
    }

    /**
     * A build's teams as the file holds them, every text neutral within its cap. Neutral words can make two keys
     * alike: a member or an alternative already there is left out, so is an alternative that is a member too, and
     * a team whose members are then another team's, or fewer than two - so the file always imports.
     *
     * @param  list<array<string, mixed>>  $teams
     * @return list<array<string, mixed>>
     */
    public static function teamsEntry(array $teams): array
    {
        $entries = [];
        $seen = [];
        foreach ($teams as $team) {
            $members = [];
            foreach ($team['members'] ?? [] as $member) {
                $key = self::neutralKey((string) ($member['key'] ?? ''));
                $members[$key] ??= [
                    'key' => $key,
                    'name' => self::neutral((string) ($member['name'] ?? ''), self::MAX_CHARACTER_NAME),
                    'role' => $member['role'] ?? null,
                    'min_rank' => $member['min_rank'] ?? 0,
                    'alternatives' => $member['alternatives'] ?? [],
                ];
            }
            foreach ($members as $key => $member) {
                $alternatives = [];
                foreach ($member['alternatives'] as $alternative) {
                    $other = self::neutralKey((string) ($alternative['key'] ?? ''));
                    if (!isset($members[$other])) {
                        $alternatives[$other] ??= ['key' => $other, 'name' => self::neutral((string) ($alternative['name'] ?? ''), self::MAX_CHARACTER_NAME)];
                    }
                }
                $members[$key]['alternatives'] = array_values($alternatives);
            }
            $set = self::memberSet(array_keys($members));
            if (count($members) < 2 || isset($seen[$set])) {
                continue;
            }
            $seen[$set] = true;
            $entries[] = [
                'name' => self::lineText((string) ($team['name'] ?? ''), self::MAX_TEAM_NAME),
                'members' => array_values($members),
                'note' => self::neutral($team['note'] ?? null, self::MAX_NOTE),
            ];
        }

        return $entries;
    }

    /**
     * A team's members as a set, whatever their order: what tells two teams of a build apart.
     *
     * @param  list<string>  $keys
     */
    private static function memberSet(array $keys): string
    {
        sort($keys);

        return implode(' ', $keys);
    }

    /** A time as the file writes it: UTC, to the second ("2026-10-20T10:00:00Z"). */
    public static function time(DateTimeInterface $time): string
    {
        return DateTimeImmutable::createFromInterface($time)->setTimezone(Clock::utc())->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Text as a builds file holds it, written or read: other sites' names and addresses replaced (Neutral), in at
     * most $max characters - "the source page" can take more room than the address it stands for. Text that is
     * neutral already comes back as it was.
     */
    public static function neutral(?string $text, int $max): ?string
    {
        $text = Neutral::text($text);
        if ($text === null || mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }

    /** A qualifier as it is stored: neutral, trimmed, within its column. */
    public static function qualifierText(string $qualifier): string
    {
        return self::lineText($qualifier, self::MAX_QUALIFIER);
    }

    /** One line of text as it is stored (a qualifier, a team's name): neutral, trimmed, within $max. */
    private static function lineText(string $text, int $max): string
    {
        return trim((string) self::neutral(trim($text), $max));
    }

    /** A key of the form Key makes, the same way: one that spells a site's name stops doing so. */
    public static function neutralKey(string $key): string
    {
        return Neutral::mentionsAny($key) ? Key::from((string) Neutral::text($key)) : $key;
    }

    /**
     * The builds of a file of $game's, checked and normalized as they are stored: each weapon and set option with
     * its rank (its place in its list, from 1), each build with its position among its character's (from 0), an
     * empty talent priority or list of targets as null, its teams a list ([] for none). Whether a weapon is in the
     * catalog is the import's to say; whether a teammate is, the page's.
     *
     * @return array{exported_at: DateTimeImmutable, builds: list<array<string, mixed>>}
     *
     * @throws BuildFileException
     */
    public static function read(mixed $file, Game $game): array
    {
        return (new self($game))->check($file);
    }

    /**
     * One build of an editor's save, checked as a file's build is (every field, shape and cap the same) and given back
     * as read() gives one - but for its position, which is its place in the save. A problem names the build's index,
     * the path in it and the problem (BuildFileException), its message the path and the problem alone.
     *
     * @return array<string, mixed>
     *
     * @throws BuildFileException
     */
    public static function readEntry(mixed $entry, Game $game, int $index): array
    {
        return (new self($game, editor: true))->readBuild($entry, $index);
    }

    /**
     * The caps on a build, as the reader holds a file's builds to them: what an editor's page holds its fields to.
     *
     * @return array<string, int>
     */
    public static function limits(): array
    {
        return [
            'qualifier' => self::MAX_QUALIFIER,
            'weapons' => self::MAX_WEAPONS,
            'refinement' => self::MAX_REFINEMENT,
            'sets' => self::MAX_SETS,
            'pieces' => self::MAX_PIECES,
            'set_keys' => self::MAX_SET_KEYS,
            'stats' => self::MAX_STATS,
            'talents' => self::MAX_TALENTS,
            'priority' => self::MAX_PRIORITY,
            'targets' => self::MAX_TARGETS,
            'bound' => self::MAX_BOUND,
            'teams' => self::MAX_TEAMS,
            'team_name' => self::MAX_TEAM_NAME,
            'alternatives' => self::MAX_ALTERNATIVES,
            'name' => self::MAX_NAME,
            'game_version' => self::MAX_GAME_VERSION,
        ];
    }

    /**
     * The main stats a build may give each slot whose main stat varies, as the reader takes them: the game's options,
     * and "Elemental DMG" (the character's own element's) where the slot has an element's DMG among them.
     *
     * @return array<string, list<string>>  slot => stat keys
     */
    public static function mainStatChoices(Game $game): array
    {
        $choices = [];
        foreach ($game->variableSlots() as $slot) {
            $choices[$slot] = self::slotStats($game, $slot);
        }

        return $choices;
    }

    private function check(mixed $file): array
    {
        if (!is_array($file) || array_is_list($file) || ($file['format'] ?? null) !== self::FORMAT) {
            throw new BuildFileException('This is not a builds file of the build analyzer.');
        }
        $version = $file['format_version'] ?? null;
        if ($version !== self::VERSION) {
            throw new BuildFileException(
                match (true) {
                    $version === 1 => 'This builds file has format version 1 (the builds of one source). This installation reads version ' .
                        self::VERSION .
                        ': the builds of a game, as variations of each character.',
                    $version === 2 => 'This builds file has format version 2 (builds without their teams). This installation reads version ' .
                        self::VERSION .
                        ': make the file again, or export it again from this installation.',
                    is_int($version) && $version > self::VERSION => "This builds file has format version {$version}, and this installation reads version " .
                        self::VERSION .
                        '. Update this installation, then import again.',
                    default => 'This builds file has no format version this installation reads.',
                },
            );
        }
        $game = is_string($file['game'] ?? null) ? Game::tryFrom($file['game']) : null;
        if ($game !== $this->game) {
            throw new BuildFileException($game === null ? 'This builds file names no game this installation knows.' : "This file holds {$game->label()} builds, not {$this->game->label()} builds.");
        }

        $this->fields($file, null, self::FIELDS);
        $exported = $this->readTime($file['exported_at'], 'exported_at');

        $builds = [];
        $seen = [];
        $positions = [];
        foreach ($this->list($file['builds'], 'builds', self::MAX_BUILDS) as $index => $entry) {
            $build = $this->readBuild($entry, $index);
            // A second build of the same identity would take the first one's place.
            $identity = self::identity($build['character'], $build['role'], $build['qualifier'], $build['min_rank']);
            if (isset($seen[$identity])) {
                $this->fail('its character, role, qualifier and min_rank', "are those of builds[{$seen[$identity]}] too");
            }
            $seen[$identity] = $index;
            // The file's order is the character's order of preference.
            $positions[$build['character']] = isset($positions[$build['character']]) ? $positions[$build['character']] + 1 : 0;
            $build['position'] = $positions[$build['character']];
            $builds[] = $build;
        }

        return ['exported_at' => $exported, 'builds' => $builds];
    }

    /**
     * @return array<string, mixed>
     */
    private function readBuild(mixed $entry, int $index): array
    {
        $this->build = null;
        $this->index = $index;
        $entry = $this->object($entry, $this->editor ? 'the build' : "builds[{$index}]");

        // What makes the build first, so every later problem can name it.
        $this->build = "builds[{$index}]";
        $this->fields(array_intersect_key($entry, array_flip(['character', 'role', 'qualifier', 'min_rank'])), null, ['character', 'role', 'qualifier', 'min_rank']);
        // Matched with the catalog as it is; a character the catalog lacks is skipped, and only named neutrally.
        $character = $this->key($entry['character'], 'character', self::MAX_CHARACTER, neutral: false);
        $role = $this->oneOf($entry['role'], 'role', $this->game->roles());
        $qualifier = $this->qualifier($entry['qualifier']);
        $minRank = $this->integer($entry['min_rank'], 'min_rank', 0, $this->game->maxRank());
        $this->build = sprintf('builds[%d] (%s, %s)', $index, $character, self::variation($this->game, $role, $qualifier, $minRank));
        $this->fields($entry, null, self::BUILD_FIELDS);

        // In the order of the fields, so the first problem found is the first in the build.
        return [
            'character' => $character,
            'name' => $this->text($entry['character_name'], 'character_name', self::MAX_CHARACTER_NAME, empty: false),
            'role' => $role,
            'qualifier' => $qualifier,
            'min_rank' => $minRank,
            'weapons' => $this->weapons($entry['weapons']),
            'sets' => $this->sets($entry['sets']),
            'main_stats' => $this->mainStats($entry['main_stats']),
            'substats' => $this->ranked($entry['substats'], 'substats', 'stat', self::MAX_STATS, Stats::substatKeys($this->game)),
            'talent_priority' => $this->ranked($entry['talent_priority'], 'talent_priority', 'talent', self::MAX_TALENTS, Talents::keys($this->game), nullable: true) ?: null,
            'stat_targets' => $this->targets($entry['stat_targets']) ?: null,
            'teams' => $this->teams($entry['teams'], $character),
            'notes' => $this->text($entry['notes'], 'notes', self::MAX_NOTES, nullable: true),
            'game_version' => $this->text($entry['game_version'], 'game_version', self::MAX_GAME_VERSION, nullable: true),
        ];
    }

    /** One line of text, at most MAX_QUALIFIER characters once trimmed; empty for a build that needs none. */
    private function qualifier(mixed $value): string
    {
        return $this->line($value, 'qualifier', self::MAX_QUALIFIER, empty: true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function weapons(mixed $value): array
    {
        $weapons = [];
        foreach ($this->list($value, 'weapons', self::MAX_WEAPONS) as $i => $weapon) {
            $at = "weapons[{$i}]";
            $weapon = $this->object($weapon, $at);
            $this->fields($weapon, $at, ['key', 'name', 'refinement', 'note']);
            $weapons[] = [
                'key' => $this->key($weapon['key'], "{$at}.key", self::MAX_NAME),
                'name' => $this->text($weapon['name'], "{$at}.name", self::MAX_NAME, empty: false),
                'rank' => $i + 1,
                'refinement' => $this->integer($weapon['refinement'], "{$at}.refinement", 1, self::MAX_REFINEMENT, nullable: true),
                'note' => $this->text($weapon['note'], "{$at}.note", self::MAX_NOTE, nullable: true),
            ];
        }

        return $weapons;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sets(mixed $value): array
    {
        $kinds = $this->game->setKinds();
        $sets = [];
        foreach ($this->list($value, 'sets', self::MAX_SETS) as $i => $option) {
            $at = "sets[{$i}]";
            $option = $this->object($option, $at);
            $this->fields($option, $at, ['kind', 'choose', 'pieces', 'note']);
            $kind = $this->oneOf($option['kind'], "{$at}.kind", $kinds);

            $pieces = [];
            foreach ($this->list($option['pieces'], "{$at}.pieces", self::MAX_PIECES, min: 1) as $j => $piece) {
                $in = "{$at}.pieces[{$j}]";
                $piece = $this->object($piece, $in);
                $this->fields($piece, $in, ['label', 'count', 'keys', 'names']);
                $label = $this->text($piece['label'], "{$in}.label", self::MAX_NAME);
                // A 2-piece or 4-piece bonus (the counts the game's kind of set knows).
                $count = $this->oneOf($piece['count'], "{$in}.count", $this->game->setPieceCounts($kind));
                // A piece accepts any of its sets ("any +80 EM set"), each listed once.
                $keys = [];
                foreach ($this->list($piece['keys'], "{$in}.keys", self::MAX_SET_KEYS, min: 1) as $k => $key) {
                    $keys[] = $this->unique($this->key($key, "{$in}.keys[{$k}]", self::MAX_NAME), $keys, "{$in}.keys[{$k}]");
                }
                $names = [];
                foreach ($this->list($piece['names'], "{$in}.names", self::MAX_SET_KEYS, min: 1) as $n => $name) {
                    $names[] = $this->unique($this->text($name, "{$in}.names[{$n}]", self::MAX_NAME, empty: false), $names, "{$in}.names[{$n}]");
                }
                $pieces[] = ['label' => $label, 'count' => $count, 'keys' => $keys, 'names' => $names];
            }

            $sets[] = [
                'rank' => $i + 1,
                'kind' => $kind,
                // How many of the pieces must be satisfied; null: all of them.
                'choose' => $this->integer($option['choose'], "{$at}.choose", 1, count($pieces), nullable: true),
                'pieces' => $pieces,
                'note' => $this->text($option['note'], "{$at}.note", self::MAX_NOTE, nullable: true),
            ];
        }

        return $sets;
    }

    /**
     * The main stats of the slots whose main stat varies, each among those the slot can have - an "Elemental DMG"
     * that becomes the character's own element where the slot has such stats.
     *
     * @return array<string, list<array{stat: string, priority: int}>>
     */
    private function mainStats(mixed $value): array
    {
        $result = [];
        foreach ($this->object($value, 'main_stats') as $slot => $list) {
            if (!in_array($slot, $this->game->variableSlots(), true)) {
                $this->fail("main_stats.{$slot}", 'is not a slot whose main stat varies (' . implode(', ', $this->game->variableSlots()) . ')');
            }
            $result[$slot] = $this->ranked($list, "main_stats.{$slot}", 'stat', self::MAX_STATS, $this->mainStatsOf($slot), min: 1);
        }

        return $result;
    }

    /** @return list<string> */
    private function mainStatsOf(string $slot): array
    {
        return self::slotStats($this->game, $slot);
    }

    /** @return list<string> */
    private static function slotStats(Game $game, string $slot): array
    {
        $stats = $game->mainStatOptions()[$slot] ?? [];
        foreach ($game->elements() as $element) {
            if (in_array(Stats::elementDmg($element), $stats, true)) {
                $stats[] = Stats::ELEMENTAL_DMG;
                break;
            }
        }

        return $stats;
    }

    /**
     * [{stat, priority}] or [{talent, priority}], each stat or talent once: equal priorities are ties.
     *
     * @param  list<string>  $allowed
     * @return list<array<string, string|int>>|null
     */
    private function ranked(mixed $value, string $where, string $name, int $max, array $allowed, int $min = 0, bool $nullable = false): ?array
    {
        $list = $this->list($value, $where, $max, $min, $nullable);
        if ($list === null) {
            return null;
        }

        $result = [];
        foreach ($list as $i => $entry) {
            $at = "{$where}[{$i}]";
            $entry = $this->object($entry, $at);
            $this->fields($entry, $at, [$name, 'priority']);
            $result[] = [
                $name => $this->unique($this->oneOf($entry[$name], "{$at}.{$name}", $allowed), array_column($result, $name), "{$at}.{$name}"),
                'priority' => $this->integer($entry['priority'], "{$at}.priority", 1, self::MAX_PRIORITY),
            ];
        }

        return $result;
    }

    /**
     * A stat target is one of three (the class comment): a requirement - its min and/or max as stated -, the three
     * tiers set by hand (`tiers`: basic, intermediate and advanced, numbers nought or more that rise or stay, in
     * place of min and max) or the stat switched off (`off`: true, with no min, max or tiers). `tiers` and `off` are
     * optional fields; `text` is a requirement's, kept as it is on the others.
     *
     * @return list<array{stat: string, min: int|float|null, max: int|float|null, text: string, from_rank: int, tiers?: array{basic: int|float, intermediate: int|float, advanced: int|float}, off?: true}>
     */
    private function targets(mixed $value): array
    {
        $targets = [];
        $seen = [];
        foreach ($this->list($value, 'stat_targets', self::MAX_TARGETS, nullable: true) ?? [] as $i => $target) {
            $at = "stat_targets[{$i}]";
            $target = $this->object($target, $at);
            $this->fields($target, $at, ['stat', 'min', 'max', 'text', 'from_rank'], ['tiers', 'off']);
            $stat = $this->stat($target['stat'], "{$at}.stat");
            $min = $this->number($target['min'], "{$at}.min");
            $max = $this->number($target['max'], "{$at}.max");
            if ($min !== null && $max !== null && $min > $max) {
                $this->fail("{$at}.min", 'must not be greater than its max');
            }
            $text = $this->text($target['text'], "{$at}.text", self::MAX_NOTE);
            $fromRank = $this->integer($target['from_rank'], "{$at}.from_rank", 0, $this->game->maxRank());
            $entry = ['stat' => $stat, 'min' => $min, 'max' => $max, 'text' => $text, 'from_rank' => $fromRank];
            if (array_key_exists('off', $target)) {
                if ($target['off'] !== true) {
                    $this->fail("{$at}.off", 'must be true, or left out');
                }
                if ($min !== null || $max !== null || array_key_exists('tiers', $target)) {
                    $this->fail("{$at}.off", 'switches the stat off: no min, max or tiers go with it');
                }
                $entry['off'] = true;
            } elseif (array_key_exists('tiers', $target)) {
                $tiers = $this->object($target['tiers'], "{$at}.tiers");
                $this->fields($tiers, "{$at}.tiers", ['basic', 'intermediate', 'advanced']);
                $values = [];
                foreach (['basic', 'intermediate', 'advanced'] as $tier) {
                    $number = $tiers[$tier];
                    if (!(is_int($number) || is_float($number)) || !is_finite((float) $number) || $number < 0 || $number > self::MAX_BOUND) {
                        $this->fail("{$at}.tiers.{$tier}", 'must be a number from 0 to ' . self::MAX_BOUND);
                    }
                    $values[$tier] = $number;
                }
                if ($values['basic'] > $values['intermediate'] || $values['intermediate'] > $values['advanced']) {
                    $this->fail("{$at}.tiers", 'must rise or stay from basic to intermediate to advanced');
                }
                if ($min !== null || $max !== null) {
                    $this->fail("{$at}.tiers", 'stands in place of min and max: leave those null');
                }
                $entry['tiers'] = $values;
            }
            // From each constellation on, one target a stat: the analysis keeps the highest one a player has reached.
            if (isset($seen["{$stat}\n{$fromRank}"])) {
                $this->fail("{$at}.stat", "repeats stat_targets[{$seen["{$stat}\n{$fromRank}"]}] from the same from_rank");
            }
            $seen["{$stat}\n{$fromRank}"] = $i;
            $targets[] = $entry;
        }

        return $targets;
    }

    /**
     * The teams the build is played in, best first. A team has its name, its members in the order the team lists
     * them - 2 to Game::teamSize(), each character once, the build's own character among them (a team without it
     * is no team of this build) - and a note. A member has the role it plays (null where unknown), the
     * constellation the team asks of it, and who can take its place: none a member of the same team, and none for
     * the build's own character, whose stand-in would make the team another character's. No two teams of a build
     * have the same members. Teammates are matched with the catalog when the build is shown, never here: one the
     * catalog lacks is no reason to skip the build.
     *
     * @return list<array{name: string, members: list<array<string, mixed>>, note: string|null}>
     */
    private function teams(mixed $value, string $character): array
    {
        // Compared as members' keys are read: made neutral (the character itself is matched as it is).
        $own = self::neutralKey($character);
        $teams = [];
        $seen = [];
        foreach ($this->list($value, 'teams', self::MAX_TEAMS) as $i => $team) {
            $at = "teams[{$i}]";
            $team = $this->object($team, $at);
            $this->fields($team, $at, self::TEAM_FIELDS);
            $name = $this->line($team['name'], "{$at}.name", self::MAX_TEAM_NAME);

            $members = [];
            foreach ($this->list($team['members'], "{$at}.members", $this->game->teamSize(), min: 2) as $j => $member) {
                $in = "{$at}.members[{$j}]";
                $member = $this->object($member, $in);
                $this->fields($member, $in, self::MEMBER_FIELDS);
                $key = $this->unique($this->key($member['key'], "{$in}.key", self::MAX_CHARACTER), array_column($members, 'key'), "{$in}.key");
                if ($member['role'] !== null && !in_array($member['role'], $this->game->roles(), true)) {
                    $this->fail("{$in}.role", 'must be ' . implode(', ', $this->game->roles()) . ', or null');
                }
                $members[] = [
                    'key' => $key,
                    'name' => $this->text($member['name'], "{$in}.name", self::MAX_CHARACTER_NAME, empty: false),
                    'role' => $member['role'],
                    'min_rank' => $this->integer($member['min_rank'], "{$in}.min_rank", 0, $this->game->maxRank()),
                    'alternatives' => $this->alternatives($member['alternatives'], "{$in}.alternatives"),
                ];
            }

            $keys = array_column($members, 'key');
            if (!in_array($own, $keys, true)) {
                $this->fail("{$at}.members", "must have the build's own character ({$own}) among them");
            }
            foreach ($members as $j => $member) {
                if ($member['key'] === $own && $member['alternatives'] !== []) {
                    $this->fail("{$at}.members[{$j}].alternatives", "must be empty: the build's own character has no stand-in in its team");
                }
                foreach ($member['alternatives'] as $k => $alternative) {
                    if (in_array($alternative['key'], $keys, true)) {
                        $this->fail("{$at}.members[{$j}].alternatives[{$k}].key", 'is a member of the same team');
                    }
                }
            }
            $set = self::memberSet($keys);
            if (isset($seen[$set])) {
                $this->fail("{$at}.members", "are those of teams[{$seen[$set]}] too");
            }
            $seen[$set] = $i;

            $teams[] = ['name' => $name, 'members' => $members, 'note' => $this->text($team['note'], "{$at}.note", self::MAX_NOTE, nullable: true)];
        }

        return $teams;
    }

    /**
     * Who can take a team member's place, each once.
     *
     * @return list<array{key: string, name: string}>
     */
    private function alternatives(mixed $value, string $where): array
    {
        $alternatives = [];
        foreach ($this->list($value, $where, self::MAX_ALTERNATIVES) as $k => $alternative) {
            $at = "{$where}[{$k}]";
            $alternative = $this->object($alternative, $at);
            $this->fields($alternative, $at, ['key', 'name']);
            $alternatives[] = [
                'key' => $this->unique($this->key($alternative['key'], "{$at}.key", self::MAX_CHARACTER), array_column($alternatives, 'key'), "{$at}.key"),
                'name' => $this->text($alternative['name'], "{$at}.name", self::MAX_CHARACTER_NAME, empty: false),
            ];
        }

        return $alternatives;
    }

    // ── the shapes ────────────────────────────────────────────────────────────────────────────────

    /**
     * Refuses the file: "The file's exported_at must be ...", or "The file's builds[3] (hutao, main_dps) is not
     * valid: weapons[0].refinement must be ..." - an editor's build: "weapons[0].refinement must be ...". What the
     * file said is only quoted through Neutral, like everything the API sends.
     */
    private function fail(string $where, string $problem): never
    {
        $where = Text::limit($where, 80);
        $message = match (true) {
            $this->editor => "{$where} {$problem}.",
            $this->build === null => "The file's {$where} {$problem}.",
            default => "The file's {$this->build} is not valid: {$where} {$problem}.",
        };

        throw new BuildFileException((string) Neutral::text($message), $this->index, (string) Neutral::text($where), (string) Neutral::text($problem));
    }

    /**
     * Exactly these fields: none missing, and none the format does not have - the $optional ones there or not.
     *
     * @param  array<array-key, mixed>  $object
     * @param  list<string>  $fields
     * @param  list<string>  $optional
     */
    private function fields(array $object, ?string $where, array $fields, array $optional = []): void
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $object)) {
                $this->fail($where === null ? $field : "{$where}.{$field}", 'is missing');
            }
        }
        foreach (array_keys($object) as $field) {
            if (!in_array($field, $fields, true) && !in_array($field, $optional, true)) {
                $this->fail($where === null ? (string) $field : "{$where}.{$field}", 'is not a field of this format');
            }
        }
    }

    /**
     * A JSON object; {} arrives as an empty array, like [].
     *
     * @return array<array-key, mixed>
     */
    private function object(mixed $value, string $where): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->fail($where, 'must be an object');
        }

        return $value;
    }

    /**
     * @return list<mixed>|null
     */
    private function list(mixed $value, string $where, int $max, int $min = 0, bool $nullable = false): ?array
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value)) {
            $this->fail($where, 'must be a list' . ($nullable ? ', or null' : ''));
        }
        if (count($value) < $min) {
            $this->fail($where, $min === 1 ? 'must not be empty' : "must have at least {$min} entries");
        }
        if (count($value) > $max) {
            $this->fail($where, "must not have more than {$max} entries");
        }

        return $value;
    }

    /**
     * $value, which the list $before must not hold already.
     *
     * @template T of string|int
     *
     * @param  T  $value
     * @param  list<string|int>  $before
     * @return T
     */
    private function unique(string|int $value, array $before, string $where): string|int
    {
        if (in_array($value, $before, true)) {
            $this->fail($where, 'repeats an earlier entry of its list');
        }

        return $value;
    }

    /**
     * One line of text (a qualifier, a team's name) - no line break or other control character -, at most $max
     * characters once trimmed as the file spells it, made neutral and trimmed; empty only where $empty allows it.
     */
    private function line(mixed $value, string $where, int $max, bool $empty = false): string
    {
        if (!is_string($value)) {
            $this->fail($where, $empty ? 'must be text (empty for none)' : 'must be text');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            $this->fail($where, 'must be one line of text');
        }
        if (!$empty && trim($value) === '') {
            $this->fail($where, 'must not be empty');
        }
        if (mb_strlen(trim($value)) > $max) {
            $this->fail($where, "must not be longer than {$max} characters");
        }

        return self::lineText($value, $max);
    }

    /** Text from the file, made neutral (neutral()); its length is checked as the file spells it. */
    private function text(mixed $value, string $where, int $max, bool $empty = true, bool $nullable = false): ?string
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (!is_string($value)) {
            $this->fail($where, 'must be text' . ($nullable ? ', or null' : ''));
        }
        if (!$empty && trim($value) === '') {
            $this->fail($where, 'must not be empty');
        }
        if (mb_strlen($value) > $max) {
            $this->fail($where, "must not be longer than {$max} characters");
        }

        return self::neutral($value, $max);
    }

    private function integer(mixed $value, string $where, int $min, int $max, bool $nullable = false): ?int
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            $this->fail($where, "must be a whole number from {$min} to {$max}" . ($nullable ? ', or null' : ''));
        }

        return $value;
    }

    /** A stat target's bound. JSON can spell numbers too large for a float (1E400), which arrive as INF. */
    private function number(mixed $value, string $where): int|float|null
    {
        if ($value !== null && (!(is_int($value) || is_float($value)) || !is_finite((float) $value) || abs($value) > self::MAX_BOUND)) {
            $this->fail($where, 'must be a number from -' . self::MAX_BOUND . ' to ' . self::MAX_BOUND . ', or null');
        }

        return $value;
    }

    /**
     * A catalog key ("hutao", "staffofhoma"): lowercase letters and digits, as App\Support\Key makes them; made
     * neutral (neutralKey()) unless it is matched with the catalog as it is.
     */
    private function key(mixed $value, string $where, int $max, bool $neutral = true): string
    {
        if (!is_string($value) || !preg_match('/^[a-z0-9]+$/', $value) || strlen($value) > $max) {
            $this->fail($where, "must be a key of lowercase letters and digits, at most {$max} long");
        }

        return $neutral ? self::neutralKey($value) : $value;
    }

    /** A stat key of the game (App\Support\Stats::keys()): the API sends them as they are. */
    private function stat(mixed $value, string $where): string
    {
        if (!in_array($value, Stats::keys($this->game), true)) {
            $this->fail($where, "must be a stat key of {$this->game->label()}, such as critRate_ or enerRech_");
        }

        return $value;
    }

    /**
     * @template T of string|int
     *
     * @param  list<T>  $allowed
     * @return T
     */
    private function oneOf(mixed $value, string $where, array $allowed): string|int
    {
        if (!in_array($value, $allowed, true)) {
            $last = array_pop($allowed);
            $this->fail($where, 'must be ' . ($allowed === [] ? $last : implode(', ', $allowed) . ' or ' . $last));
        }

        return $value;
    }

    private function readTime(mixed $value, string $where): DateTimeImmutable
    {
        return self::parseTime($value) ?? $this->fail($where, 'must be a time in ISO 8601 from 1970 on, such as 2026-10-20T10:00:00Z');
    }

    /**
     * A time as the file writes one (ISO 8601 with its zone; fractions of a second optional) from 1970 on, or
     * null. Earlier years are nothing a file carries, and some (the year 0) PostgreSQL cannot store.
     */
    private static function parseTime(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || !preg_match(self::TIME, $value) || (int) substr($value, 0, 4) < 1970) {
            return null;
        }
        try {
            $time = new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
        // An impossible date ("2026-02-30") is read as another day, with a warning.
        $problems = DateTimeImmutable::getLastErrors();
        if ($problems !== false && ($problems['warning_count'] > 0 || $problems['error_count'] > 0)) {
            return null;
        }

        return $time->setTimezone(Clock::utc());
    }
}
