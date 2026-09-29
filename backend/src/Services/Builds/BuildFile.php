<?php

namespace App\Services\Builds;

use App\Enums\Game;
use App\Models\Build;
use App\Support\Clock;
use App\Support\Neutral;
use App\Support\Talents;
use App\Support\Text;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * The file a source's builds are exported to and imported from: format "build-analyzer.builds", version 1
 * (docs/data-model.md, "Builds files"). It carries builds from one installation to another - synced on a
 * computer a site answers, imported on a host the site refuses - and keeps them as a backup.
 *
 * Like every answer of the API it names no site: the source is its key ("genshin-2"), the page a build was
 * read from stays behind, and text copied from pages goes through Neutral.
 *
 * Reading is strict, because the analysis reads every stored build for every user. A file is refused as a
 * whole, naming its first problem, unless every build has exactly the fields and shapes a sync stores:
 * lists and objects where they belong, whole numbers where the app counts, the set kinds, slots and talents
 * of the source's game, text that fits its column, and no more entries than the caps below - far above what
 * any source publishes, and low enough to keep the set matcher's search (it tries the pieces of an option
 * against the equipped sets) small.
 */
final class BuildFile
{
    public const FORMAT = 'build-analyzer.builds';

    public const VERSION = 1;

    /** A whole source takes a few MB, pretty-printed. */
    public const MAX_BYTES = 16 * 1024 * 1024;

    public const MAX_BUILDS = 5000;

    /** Per build: sources give a few dozen entries at most. */
    private const MAX_WEAPONS = 100;

    private const MAX_SETS = 50;

    private const MAX_PIECES = 8;

    private const MAX_SET_KEYS = 50;

    private const MAX_STATS = 30;

    private const MAX_TALENTS = 10;

    private const MAX_TARGETS = 30;

    /**
     * Text without a column of its own: a build's notes; the notes of its weapons, sets and targets; names,
     * labels and keys of weapons and sets, which a site can spell longer than the catalog's columns (a
     * "Flex: A / B / C" piece, a weapon the catalog does not know).
     */
    private const MAX_NOTES = 20000;

    private const MAX_NOTE = 5000;

    private const MAX_NAME = 500;

    /** The fields of the file and of a build, in the order the export writes them. */
    private const FIELDS = ['format', 'format_version', 'source', 'game', 'exported_at', 'synced_at', 'builds'];

    private const BUILD_FIELDS = ['character', 'character_name', 'variant', 'role', 'recommended', 'position', 'weapons', 'sets',
        'main_stats', 'substats', 'talent_priority', 'stat_targets', 'notes', 'source_updated_at', 'game_version', 'fetched_at', 'changed_at'];

    /** A build may leave its times out: it was then fetched and changed when it is imported. */
    private const OPTIONAL = ['fetched_at', 'changed_at'];

    private const TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/';

    /** The build a problem is in ('builds[3] (hutao, "DPS")'), or null while the header is read. */
    private ?string $build = null;

    private function __construct(private readonly Game $game) {}

    /**
     * A stored build as the file holds it. Times keep their microseconds, so an import writes them back as
     * they were and a build's content hash (which reads source_updated_at) comes out the same.
     *
     * @return array<string, mixed>
     */
    public static function entry(Build $build): array
    {
        return [
            'character' => $build->character->key,
            'character_name' => $build->character->name,
            'variant' => Neutral::text($build->variant),
            'role' => Neutral::text($build->role),
            'recommended' => $build->recommended,
            'position' => $build->position,
            'weapons' => Neutral::deep($build->weapons),
            'sets' => Neutral::deep($build->sets),
            // An object even when empty: its keys are slots.
            'main_stats' => (object) $build->main_stats,
            'substats' => $build->substats,
            'talent_priority' => $build->talent_priority,
            'stat_targets' => Neutral::deep($build->stat_targets),
            'notes' => Neutral::text($build->notes),
            'source_updated_at' => self::time($build->source_updated_at),
            'game_version' => $build->game_version,
            'fetched_at' => self::time($build->fetched_at),
            'changed_at' => self::time($build->changed_at),
        ];
    }

    /** A time as the file writes it: UTC, to the microsecond ("2026-09-29T14:03:12.123456Z"). */
    public static function time(?DateTimeInterface $time): ?string
    {
        return $time === null ? null : DateTimeImmutable::createFromInterface($time)->setTimezone(Clock::utc())->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * The builds of a file, checked for the source they are imported into ($key "genshin-2", $label
     * "Source 2") and normalized as a sync stores them. fetched_at / changed_at are null where the file has
     * no valid time.
     *
     * @return array{exported_at: DateTimeImmutable, synced_at: DateTimeImmutable|null, builds: list<array<string, mixed>>}
     *
     * @throws BuildFileException
     */
    public static function read(mixed $file, Game $game, string $key, string $label): array
    {
        return (new self($game))->check($file, $key, $label);
    }

    private function check(mixed $file, string $key, string $label): array
    {
        if (! is_array($file) || array_is_list($file) || ($file['format'] ?? null) !== self::FORMAT) {
            throw new BuildFileException('This is not a builds file exported by the build analyzer.');
        }
        $version = $file['format_version'] ?? null;
        if ($version !== self::VERSION) {
            throw new BuildFileException(is_int($version) && $version > self::VERSION
                ? "This builds file has format version {$version}, and this installation reads version ".self::VERSION.'. Update it, then import again.'
                : 'This builds file has no format version this installation reads.');
        }
        $game = is_string($file['game'] ?? null) ? Game::tryFrom($file['game']) : null;
        if ($game !== $this->game) {
            throw new BuildFileException($game === null
                ? 'This builds file names no game this installation knows.'
                : "This file holds {$game->label()} builds, and {$label} is a {$this->game->label()} source.");
        }
        if (($file['source'] ?? null) !== $key) {
            // The file could hold any text there; only a key of the app's own form is repeated.
            $theirs = is_string($file['source'] ?? null) && preg_match('/^[a-z]+-\d{1,4}$/', $file['source']) ? $file['source'] : 'another source';
            throw new BuildFileException("This file holds the builds of {$theirs}, not of {$label} ({$key}).");
        }

        $this->fields($file, null, self::FIELDS);
        $exported = $this->readTime($file['exported_at'], 'exported_at');
        $synced = $this->readTime($file['synced_at'], 'synced_at', nullable: true);

        $builds = [];
        $seen = [];
        foreach ($this->list($file['builds'], 'builds', self::MAX_BUILDS) as $index => $entry) {
            $build = $this->readBuild($entry, $index);
            // (source, character, variant) is a build's identity: a second one would overwrite the first.
            $identity = $build['character']."\n".$build['variant'];
            if (isset($seen[$identity])) {
                $this->fail('its character and variant', "are those of builds[{$seen[$identity]}] too");
            }
            $seen[$identity] = $index;
            $builds[] = $build;
        }

        return ['exported_at' => $exported, 'synced_at' => $synced, 'builds' => $builds];
    }

    /**
     * @return array<string, mixed>
     */
    private function readBuild(mixed $entry, int $index): array
    {
        $this->build = null;
        $entry = $this->object($entry, "builds[{$index}]");

        // The character and variant first, so every later problem can name the build they make. Keys and
        // lengths as the catalog and the builds table hold them.
        $this->build = "builds[{$index}]";
        $this->fields(array_intersect_key($entry, ['character' => true, 'variant' => true]), null, ['character', 'variant']);
        $character = $this->key($entry['character'], 'character', 120);
        $variant = $this->text($entry['variant'], 'variant', 120, empty: false);
        $this->build = sprintf('builds[%d] (%s, "%s")', $index, $character, Text::limit($variant, 40));
        $this->fields($entry, null, self::BUILD_FIELDS, self::OPTIONAL);

        // In the order of the fields, so the first problem found is the first in the build.
        return [
            'character' => $character,
            'name' => $this->text($entry['character_name'], 'character_name', 160, empty: false),
            'variant' => $variant,
            'role' => $this->text($entry['role'], 'role', 60, nullable: true),
            'recommended' => $this->boolean($entry['recommended'], 'recommended'),
            'position' => $this->integer($entry['position'], 'position', 0, 32767),
            'weapons' => $this->weapons($entry['weapons']),
            'sets' => $this->sets($entry['sets']),
            'main_stats' => $this->mainStats($entry['main_stats']),
            'substats' => $this->ranked($entry['substats'], 'substats', 'stat', self::MAX_STATS),
            'talent_priority' => $this->ranked($entry['talent_priority'], 'talent_priority', 'talent', self::MAX_TALENTS, nullable: true) ?? [],
            'stat_targets' => $this->targets($entry['stat_targets']),
            'notes' => $this->text($entry['notes'], 'notes', self::MAX_NOTES, nullable: true),
            'source_updated_at' => $this->readTime($entry['source_updated_at'], 'source_updated_at', nullable: true),
            'game_version' => $this->text($entry['game_version'], 'game_version', 20, nullable: true),
            'fetched_at' => self::parseTime($entry['fetched_at'] ?? null),
            'changed_at' => self::parseTime($entry['changed_at'] ?? null),
        ];
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
            $this->fields($weapon, $at, ['key', 'name', 'rank', 'refinement', 'note', 'known']);
            $weapons[] = [
                'key' => $this->key($weapon['key'], "{$at}.key", self::MAX_NAME),
                'name' => $this->text($weapon['name'], "{$at}.name", self::MAX_NAME, empty: false),
                'rank' => $this->integer($weapon['rank'], "{$at}.rank", 1, 999),
                'refinement' => $this->integer($weapon['refinement'], "{$at}.refinement", 0, 99, nullable: true),
                'note' => $this->text($weapon['note'], "{$at}.note", self::MAX_NOTE, nullable: true),
                'known' => $this->boolean($weapon['known'], "{$at}.known"),
            ];
        }

        return $weapons;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sets(mixed $value): array
    {
        $kinds = $this->game === Game::Genshin ? ['artifact'] : ['relic', 'planar'];
        $sets = [];
        foreach ($this->list($value, 'sets', self::MAX_SETS) as $i => $option) {
            $at = "sets[{$i}]";
            $option = $this->object($option, $at);
            $this->fields($option, $at, ['rank', 'kind', 'choose', 'pieces', 'note']);
            $rank = $this->integer($option['rank'], "{$at}.rank", 1, 999);
            $kind = $this->oneOf($option['kind'], "{$at}.kind", $kinds);

            $pieces = [];
            foreach ($this->list($option['pieces'], "{$at}.pieces", self::MAX_PIECES, min: 1) as $j => $piece) {
                $in = "{$at}.pieces[{$j}]";
                $piece = $this->object($piece, $in);
                $this->fields($piece, $in, ['label', 'count', 'keys', 'names']);
                $label = $this->text($piece['label'], "{$in}.label", self::MAX_NAME);
                $count = $this->integer($piece['count'], "{$in}.count", 1, 9);
                // A piece accepts any of its sets ("any +80 EM set").
                $keys = [];
                foreach ($this->list($piece['keys'], "{$in}.keys", self::MAX_SET_KEYS, min: 1) as $k => $key) {
                    $keys[] = $this->key($key, "{$in}.keys[{$k}]", self::MAX_NAME);
                }
                $names = [];
                foreach ($this->list($piece['names'], "{$in}.names", self::MAX_SET_KEYS, min: 1) as $n => $name) {
                    $names[] = $this->text($name, "{$in}.names[{$n}]", self::MAX_NAME, empty: false);
                }
                $pieces[] = ['label' => $label, 'count' => $count, 'keys' => $keys, 'names' => $names];
            }

            $sets[] = [
                'rank' => $rank,
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
     * @return array<string, list<array{stat: string, priority: int}>>
     */
    private function mainStats(mixed $value): array
    {
        $result = [];
        foreach ($this->object($value, 'main_stats') as $slot => $list) {
            if (! in_array($slot, $this->game->slots(), true)) {
                $this->fail("main_stats.{$slot}", 'is not a slot of '.$this->game->label());
            }
            $result[$slot] = $this->ranked($list, "main_stats.{$slot}", 'stat', self::MAX_STATS, min: 1);
        }

        return $result;
    }

    /**
     * [{stat, priority}] or [{talent, priority}]: equal priorities are ties.
     *
     * @return list<array<string, string|int>>|null
     */
    private function ranked(mixed $value, string $where, string $name, int $max, int $min = 0, bool $nullable = false): ?array
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
                $name => $name === 'stat'
                    ? $this->stat($entry['stat'], "{$at}.stat")
                    : $this->oneOf($entry['talent'], "{$at}.talent", Talents::keys($this->game)),
                'priority' => $this->integer($entry['priority'], "{$at}.priority", 1, 99),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{stat: string, min: int|float|null, max: int|float|null, text: string}>
     */
    private function targets(mixed $value): array
    {
        $targets = [];
        foreach ($this->list($value, 'stat_targets', self::MAX_TARGETS, nullable: true) ?? [] as $i => $target) {
            $at = "stat_targets[{$i}]";
            $target = $this->object($target, $at);
            $this->fields($target, $at, ['stat', 'min', 'max', 'text']);
            $targets[] = [
                'stat' => $this->stat($target['stat'], "{$at}.stat"),
                'min' => $this->number($target['min'], "{$at}.min"),
                'max' => $this->number($target['max'], "{$at}.max"),
                'text' => $this->text($target['text'], "{$at}.text", self::MAX_NOTE),
            ];
        }

        return $targets;
    }

    // ── the shapes ────────────────────────────────────────────────────────────────────────────────

    /**
     * Refuses the file: "The file's synced_at must be ...", or "The file's builds[3] (hutao, "DPS") is not
     * valid: weapons[0].rank must be ...". What the file said is only quoted through Neutral, like
     * everything the API sends.
     */
    private function fail(string $where, string $problem): never
    {
        $where = Text::limit($where, 80);

        throw new BuildFileException((string) Neutral::text($this->build === null
            ? "The file's {$where} {$problem}."
            : "The file's {$this->build} is not valid: {$where} {$problem}."));
    }

    /**
     * Exactly these fields: none missing but the optional ones, and none the format does not have.
     *
     * @param  array<array-key, mixed>  $object
     * @param  list<string>  $fields
     * @param  list<string>  $optional
     */
    private function fields(array $object, ?string $where, array $fields, array $optional = []): void
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $object) && ! in_array($field, $optional, true)) {
                $this->fail($where === null ? $field : "{$where}.{$field}", 'is missing');
            }
        }
        foreach (array_keys($object) as $field) {
            if (! in_array($field, $fields, true)) {
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
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
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
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($where, 'must be a list'.($nullable ? ', or null' : ''));
        }
        if (count($value) < $min) {
            $this->fail($where, 'must not be empty');
        }
        if (count($value) > $max) {
            $this->fail($where, "must not have more than {$max} entries");
        }

        return $value;
    }

    private function text(mixed $value, string $where, int $max, bool $empty = true, bool $nullable = false): ?string
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (! is_string($value)) {
            $this->fail($where, 'must be text'.($nullable ? ', or null' : ''));
        }
        if (! $empty && trim($value) === '') {
            $this->fail($where, 'must not be empty');
        }
        if (mb_strlen($value) > $max) {
            $this->fail($where, "must not be longer than {$max} characters");
        }

        return $value;
    }

    private function integer(mixed $value, string $where, int $min, int $max, bool $nullable = false): ?int
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (! is_int($value) || $value < $min || $value > $max) {
            $this->fail($where, "must be a whole number from {$min} to {$max}".($nullable ? ', or null' : ''));
        }

        return $value;
    }

    /** A stat target's bound. JSON can spell numbers too large for a float (1E400), which arrive as INF. */
    private function number(mixed $value, string $where): int|float|null
    {
        if ($value !== null && (! (is_int($value) || is_float($value)) || ! is_finite((float) $value) || abs($value) > 1_000_000)) {
            $this->fail($where, 'must be a number from -1000000 to 1000000, or null');
        }

        return $value;
    }

    private function boolean(mixed $value, string $where): bool
    {
        if (! is_bool($value)) {
            $this->fail($where, 'must be true or false');
        }

        return $value;
    }

    /** A catalog key ("hutao", "staffofhoma"): lowercase letters and digits, as App\Support\Key makes them. */
    private function key(mixed $value, string $where, int $max): string
    {
        if (! is_string($value) || ! preg_match('/^[a-z0-9]+$/', $value) || strlen($value) > $max) {
            $this->fail($where, "must be a key of lowercase letters and digits, at most {$max} long");
        }

        return $value;
    }

    /** A canonical stat key ("critRate_", "pyro_dmg_", "spd"; App\Support\Stats). */
    private function stat(mixed $value, string $where): string
    {
        if (! is_string($value) || ! preg_match('/^[A-Za-z][A-Za-z_]{0,39}$/', $value)) {
            $this->fail($where, 'must be a stat key such as critRate_ or pyro_dmg_');
        }

        return $value;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function oneOf(mixed $value, string $where, array $allowed): string
    {
        if (! in_array($value, $allowed, true)) {
            $last = array_pop($allowed);
            $this->fail($where, 'must be '.($allowed === [] ? $last : implode(', ', $allowed).' or '.$last));
        }

        return $value;
    }

    private function readTime(mixed $value, string $where, bool $nullable = false): ?DateTimeImmutable
    {
        if ($value === null && $nullable) {
            return null;
        }

        return self::parseTime($value)
            ?? $this->fail($where, 'must be a time in ISO 8601, such as 2026-09-29T14:03:12Z'.($nullable ? ', or null' : ''));
    }

    /** A time as the file writes one (ISO 8601 with its zone; fractions of a second optional), or null. */
    private static function parseTime(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match(self::TIME, $value)) {
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
