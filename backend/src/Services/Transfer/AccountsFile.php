<?php

namespace App\Services\Transfer;

use App\Enums\Game;
use App\Presenters\AccountPresenter;
use App\Services\Analysis\CharacterAnalyzer;
use App\Services\Builds\BuildFile;
use App\Support\Clock;
use App\Support\Json;
use App\Support\Neutral;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * The file a signed-in person's own data is exported to and imported from: format "build-analyzer.accounts",
 * version 3 (docs/data-model.md, "Accounts files"). It carries their game accounts - each with its
 * characters, weapons, artifacts and import history - from one installation to another, or keeps
 * them as a backup. Version 2 - a character without its favourite star, before 1.0.0 - and version 1 - without
 * the build chosen for it either, before 0.8.0 - are read too.
 *
 * It holds nothing that only makes sense here: catalog entries by their key ("hutao"), never an internal
 * id; a build chosen for a character by its variation (role, qualifier, min_rank - BuildFile::identity()),
 * never its id; no user id and no account id; a source as "showcase" or "file", never the service or tool behind it;
 * an account's meta only the keys the account page shows (AccountPresenter::META_KEYS); every time in UTC,
 * to the second ("2026-09-29T14:03:12Z"), because an account without a UID is matched by when it was made,
 * and two installations must spell that time alike. Like every answer of the API it names no other site:
 * text passes through Neutral on the way out, and again on the way in.
 *
 * Reading is strict: a file is refused as a whole, naming its first problem (AccountsFileException), unless
 * every entry has exactly the fields and shapes the export writes. The caps are well above what a game
 * account holds (the games keep at most a few thousand artifacts); they only keep a file from
 * being absurd, and the export holds to them too, so whatever the export writes, the import reads.
 */
final class AccountsFile
{
    public const FORMAT = 'build-analyzer.accounts';

    public const VERSION = 3;

    /** The oldest version the reader takes: 1, whose characters carry no chosen build (and, like 2's, no star). */
    public const OLDEST_VERSION = 1;

    public const MAX_ACCOUNTS = 500;

    public const MAX_CHARACTERS = 1000;

    public const MAX_WEAPONS = 10000;

    public const MAX_ITEMS = 10000;

    /** An account's import history in a file: its newest runs. */
    public const MAX_IMPORTS = 200;

    private const MAX_SUBSTATS = 10;

    /** Entries of a character's talents or in-game totals. */
    private const MAX_STATS = 100;

    private const MAX_WARNINGS = 100;

    private const MAX_PARAMS = 20;

    private const MAX_BUCKETS = 10;

    private const MAX_UNMATCHED = 500;

    /** Text: its column's length where it has one. */
    private const MAX_NICKNAME = 100;

    private const MAX_SIGNATURE = 300;

    private const MAX_FILE_NAME = 255;

    private const MAX_ERROR = 2000;

    private const MAX_WARNING = 2000;

    private const MAX_PARAM = 500;

    private const MAX_META = 200;

    private const MAX_NAME = 300;

    private const MAX_EXTERNAL_UID = 64;

    private const MAX_APP_VERSION = 20;

    /** The fields, in the order the export writes them. */
    private const FIELDS = ['format', 'format_version', 'exported_at', 'app_version', 'accounts'];

    private const ACCOUNT_FIELDS = [
        'game',
        'uid',
        'nickname',
        'level',
        'world_level',
        'signature',
        'has_full_inventory',
        'last_import_at',
        'created_at',
        'meta',
        'characters',
        'weapons',
        'items',
        'imports',
    ];

    private const CHARACTER_FIELDS = ['key', 'level', 'ascension', 'rank', 'talents', 'final_stats', 'source', 'synced_at', 'build', 'favourite'];

    /** A character of version 2: no favourite star. */
    private const CHARACTER_FIELDS_V2 = ['key', 'level', 'ascension', 'rank', 'talents', 'final_stats', 'source', 'synced_at', 'build'];

    /** A character of version 1: no chosen build either. */
    private const CHARACTER_FIELDS_V1 = ['key', 'level', 'ascension', 'rank', 'talents', 'final_stats', 'source', 'synced_at'];

    /** The build chosen for a character, by its variation. */
    private const BUILD_FIELDS = ['role', 'qualifier', 'min_rank'];

    private const WEAPON_FIELDS = ['key', 'level', 'ascension', 'refinement', 'equipped', 'locked', 'external_uid', 'source'];

    private const ITEM_FIELDS = ['set', 'slot', 'rarity', 'level', 'main_stat', 'main_value', 'substats', 'equipped', 'locked', 'external_uid', 'source'];

    private const IMPORT_FIELDS = ['source', 'status', 'file_name', 'summary', 'error', 'created_at'];

    private const SUBSTAT_FIELDS = ['key', 'value', 'rolls'];

    private const KIND_FIELDS = ['code', 'params'];

    /** What an import run's summary may hold (anything else a stored one has stays behind). */
    private const SUMMARY_COUNTS = ['characters', 'weapons', 'items'];

    private const SUMMARY_FIELDS = ['characters', 'weapons', 'items', 'full_inventory', 'warnings', 'warning_kinds', 'unmatched', 'error'];

    public const SOURCES = ['showcase', 'file'];

    private const STATUSES = ['success', 'partial', 'failed'];

    private const TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/';

    private const CATALOG_KEY = '/^[a-z0-9_-]{1,160}$/';

    private const STAT_KEY = '/^[A-Za-z0-9_]{1,40}$/';

    private const CODE = '/^[a-z0-9_]{1,64}$/';

    private const UID = '/^[\x21-\x7e]{1,20}$/';

    /** A control character: a build's qualifier is one line (BuildFile). */
    private const CONTROL = '/[\x00-\x1f\x7f]/';

    private const SMALLINT = 32767;

    /** The version of the file being read (check()). */
    private int $version = self::VERSION;

    // ── writing ──────────────────────────────────────────────────────────────────────────────────

    /**
     * The whole file around its accounts (each made by account()).
     *
     * @param  list<array<string, mixed>>  $accounts
     * @return array<string, mixed>
     */
    public static function file(string $appVersion, DateTimeInterface $exportedAt, array $accounts): array
    {
        return [
            'format' => self::FORMAT,
            'format_version' => self::VERSION,
            'exported_at' => self::time($exportedAt),
            'app_version' => mb_substr($appVersion, 0, self::MAX_APP_VERSION),
            'accounts' => $accounts,
        ];
    }

    /**
     * One account as the file holds it, from its database rows: the account's, and those of its characters
     * (with `character_key`, the chosen build's `build_role`, `build_qualifier`, `build_min_rank` - null
     * for none - and `favourite`), weapons (`weapon_key`, `equipped_key`), items (`set_key`, `equipped_key`) and
     * import runs (oldest first; the newest MAX_IMPORTS are kept). Whatever a stored value holds that the
     * reader would refuse - a talent that is not a whole number, a stat key of another shape - stays behind,
     * so the file always imports.
     *
     * @param  array<string, mixed>  $account
     * @param  list<array<string, mixed>>  $characters
     * @param  list<array<string, mixed>>  $weapons
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, mixed>
     */
    public static function account(array $account, array $characters, array $weapons, array $items, array $runs): array
    {
        $game = Game::from((string) $account['game']);

        return [
            'game' => $game->value,
            'uid' => isset($account['uid']) ? (string) $account['uid'] : null,
            'nickname' => self::neutral($account['nickname'] ?? null, self::MAX_NICKNAME),
            'level' => self::smallint($account['level'] ?? null),
            'world_level' => self::smallint($account['world_level'] ?? null),
            'signature' => self::neutral($account['signature'] ?? null, self::MAX_SIGNATURE),
            'has_full_inventory' => (bool) ($account['has_full_inventory'] ?? false),
            'last_import_at' => self::time(Clock::parse($account['last_import_at'] ?? null)),
            'created_at' => self::time(Clock::parse($account['created_at'] ?? null)),
            'meta' => (object) self::writeMeta(Json::decode($account['meta'] ?? null) ?? []),
            'characters' => array_map(
                fn(array $row) => [
                    'key' => (string) $row['character_key'],
                    'level' => self::smallint($row['level'] ?? null) ?? 1,
                    'ascension' => self::smallint($row['ascension'] ?? null),
                    'rank' => self::smallint($row['rank'] ?? null) ?? 0,
                    'talents' => self::writeTalents(Json::decode($row['talents'] ?? null)),
                    'final_stats' => self::writeNumbers(Json::decode($row['final_stats'] ?? null), wholeOnly: false),
                    'source' => CharacterAnalyzer::importKind((string) $row['source']),
                    'synced_at' => self::time(Clock::parse($row['synced_at'] ?? null)),
                    'build' => self::writeBuild($game, $row),
                    'favourite' => (bool) ($row['favourite'] ?? false),
                ],
                $characters,
            ),
            'weapons' => array_map(
                fn(array $row) => [
                    'key' => (string) $row['weapon_key'],
                    'level' => self::smallint($row['level'] ?? null) ?? 1,
                    'ascension' => self::smallint($row['ascension'] ?? null),
                    'refinement' => max(1, min(5, (int) ($row['refinement'] ?? 1))),
                    'equipped' => isset($row['equipped_key']) ? (string) $row['equipped_key'] : null,
                    'locked' => (bool) ($row['locked'] ?? false),
                    'external_uid' => self::neutral($row['external_uid'] ?? null, self::MAX_EXTERNAL_UID),
                    'source' => CharacterAnalyzer::importKind((string) $row['source']),
                ],
                $weapons,
            ),
            'items' => array_values(
                array_map(
                    fn(array $row) => [
                        'set' => (string) $row['set_key'],
                        'slot' => (string) $row['slot'],
                        'rarity' => self::smallint($row['rarity'] ?? null) ?? 5,
                        'level' => self::smallint($row['level'] ?? null) ?? 0,
                        'main_stat' => (string) $row['main_stat'],
                        'main_value' => isset($row['main_value']) && is_numeric($row['main_value']) ? self::numeric($row['main_value']) : null,
                        'substats' => self::writeSubstats(Json::decode($row['substats'] ?? null) ?? []),
                        'equipped' => isset($row['equipped_key']) ? (string) $row['equipped_key'] : null,
                        'locked' => (bool) ($row['locked'] ?? false),
                        'external_uid' => self::neutral($row['external_uid'] ?? null, self::MAX_EXTERNAL_UID),
                        'source' => CharacterAnalyzer::importKind((string) $row['source']),
                        // An item of a slot the game does not have cannot be read back; none is ever stored.
                    ],
                    array_filter($items, fn(array $row) => in_array((string) $row['slot'], $game->slots(), true) && preg_match(self::STAT_KEY, (string) $row['main_stat']) === 1),
                ),
            ),
            'imports' => array_map(
                fn(array $row) => [
                    'source' => CharacterAnalyzer::importKind((string) $row['source']),
                    'status' => in_array($row['status'] ?? null, self::STATUSES, true) ? (string) $row['status'] : 'failed',
                    'file_name' => self::neutral($row['file_name'] ?? null, self::MAX_FILE_NAME),
                    'summary' => self::writeSummary(Json::decode($row['summary'] ?? null)),
                    'error' => self::neutral($row['error'] ?? null, self::MAX_ERROR),
                    'created_at' => self::time(Clock::parse($row['created_at'] ?? null)),
                ],
                array_slice($runs, -self::MAX_IMPORTS),
            ),
        ];
    }

    /** A time as the file writes it, and as two copies compare it: UTC, to the second ("2026-09-29T14:03:12Z"). */
    public static function time(?DateTimeInterface $time): ?string
    {
        return $time === null ? null : DateTimeImmutable::createFromInterface($time)->setTimezone(Clock::utc())->format('Y-m-d\TH:i:s\Z');
    }

    /** Text as the file holds it, written or read: other sites' names and addresses replaced, in at most $max characters. */
    public static function neutral(?string $text, int $max): ?string
    {
        $text = Neutral::text($text);
        if ($text === null || mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }

    private static function smallint(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, min(self::SMALLINT, (int) $value)) : null;
    }

    private static function numeric(mixed $value): int|float
    {
        $number = is_int($value) || is_float($value) ? $value : (float) $value;

        return is_float($number) && !is_finite($number) ? 0 : $number;
    }

    /**
     * The build chosen for a character, by its variation; null for none - and for a stored one the reader would
     * refuse, which no build of this installation is.
     *
     * @return array{role: string, qualifier: string, min_rank: int}|null
     */
    private static function writeBuild(Game $game, array $row): ?array
    {
        $role = $row['build_role'] ?? null;
        $qualifier = self::neutral(isset($row['build_qualifier']) ? (string) $row['build_qualifier'] : null, BuildFile::MAX_QUALIFIER);
        $minRank = $row['build_min_rank'] ?? null;
        if (
            !in_array($role, $game->roles(), true) ||
            $qualifier === null ||
            preg_match(self::CONTROL, $qualifier) === 1 ||
            !is_numeric($minRank) ||
            (int) $minRank < 0 ||
            (int) $minRank > $game->maxRank()
        ) {
            return null;
        }

        return ['role' => $role, 'qualifier' => $qualifier, 'min_rank' => (int) $minRank];
    }

    /** @return array<string, mixed> */
    private static function writeMeta(array $meta): array
    {
        $out = [];
        foreach (AccountPresenter::META_KEYS as $key) {
            $value = $meta[$key] ?? null;
            if (is_string($value)) {
                $out[$key] = self::neutral($value, self::MAX_META);
            } elseif (is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private static function writeNumbers(?array $values, bool $wholeOnly): ?object
    {
        $out = [];
        foreach ($values ?? [] as $key => $value) {
            if (is_string($key) && preg_match(self::STAT_KEY, $key) === 1 && (is_int($value) || (!$wholeOnly && is_float($value) && is_finite($value)))) {
                $out[$key] = $wholeOnly ? max(0, min(self::SMALLINT, $value)) : $value;
            }
        }

        return $out === [] ? null : (object) array_slice($out, 0, self::MAX_STATS, true);
    }

    /** Talent levels: {normal: 9, ...}, with a constellation's extra levels as one object ({bonus: {skill: 3}}). */
    private static function writeTalents(?array $talents): ?object
    {
        $out = [];
        foreach ($talents ?? [] as $key => $value) {
            if (!is_string($key) || preg_match(self::STAT_KEY, $key) !== 1) {
                continue;
            }
            if (is_int($value)) {
                $out[$key] = max(0, min(self::SMALLINT, $value));
            } elseif (is_array($value) && ($inner = self::writeNumbers($value, wholeOnly: true)) !== null) {
                $out[$key] = $inner;
            }
        }

        return $out === [] ? null : (object) array_slice($out, 0, self::MAX_STATS, true);
    }

    /** @return list<array{key: string, value: int|float, rolls: int|null}> */
    private static function writeSubstats(array $substats): array
    {
        $out = [];
        foreach ($substats as $substat) {
            if (
                is_array($substat) &&
                is_string($substat['key'] ?? null) &&
                preg_match(self::STAT_KEY, $substat['key']) === 1 &&
                (is_int($substat['value'] ?? null) || (is_float($substat['value'] ?? null) && is_finite($substat['value'])))
            ) {
                $rolls = $substat['rolls'] ?? null;
                $out[] = ['key' => $substat['key'], 'value' => $substat['value'], 'rolls' => is_int($rolls) ? max(0, min(self::SMALLINT, $rolls)) : null];
            }
        }

        return array_slice($out, 0, self::MAX_SUBSTATS);
    }

    /** An import run's summary: its counts, its warnings and their kinds, what matched nothing, a failure's kind. */
    private static function writeSummary(?array $summary): ?object
    {
        if ($summary === null) {
            return null;
        }
        $out = [];
        foreach (self::SUMMARY_COUNTS as $key) {
            if (is_int($summary[$key] ?? null)) {
                $out[$key] = max(0, $summary[$key]);
            }
        }
        if (is_bool($summary['full_inventory'] ?? null)) {
            $out['full_inventory'] = $summary['full_inventory'];
        }
        if (is_array($summary['warnings'] ?? null) && array_is_list($summary['warnings'])) {
            $out['warnings'] = array_slice(array_map(fn($warning) => self::neutral(is_string($warning) ? $warning : '', self::MAX_WARNING), $summary['warnings']), 0, self::MAX_WARNINGS);
        }
        if (is_array($summary['warning_kinds'] ?? null) && array_is_list($summary['warning_kinds'])) {
            $kinds = array_map(fn($kind) => self::writeKind($kind), $summary['warning_kinds']);
            if (!in_array(null, $kinds, true)) {
                $out['warning_kinds'] = array_slice($kinds, 0, self::MAX_WARNINGS);
            }
        }
        if (is_array($summary['unmatched'] ?? null) && $summary['unmatched'] !== [] && !array_is_list($summary['unmatched'])) {
            $buckets = [];
            foreach ($summary['unmatched'] as $bucket => $names) {
                if (is_string($bucket) && preg_match(self::CODE, $bucket) === 1 && is_array($names) && array_is_list($names)) {
                    $buckets[$bucket] = array_slice(array_values(array_map(fn($name) => self::neutral((string) $name, self::MAX_NAME), array_filter($names, 'is_string'))), 0, self::MAX_UNMATCHED);
                }
            }
            if ($buckets !== []) {
                $out['unmatched'] = (object) array_slice($buckets, 0, self::MAX_BUCKETS, true);
            }
        }
        if (is_array($summary['error'] ?? null) && ($error = self::writeKind($summary['error'])) !== null) {
            $out['error'] = $error;
        }

        return (object) $out;
    }

    /** @return array{code: string, params: object}|null */
    private static function writeKind(mixed $kind): ?array
    {
        if (!is_array($kind) || !is_string($kind['code'] ?? null) || preg_match(self::CODE, $kind['code']) !== 1) {
            return null;
        }
        $params = [];
        foreach (is_array($kind['params'] ?? null) ? $kind['params'] : [] as $name => $value) {
            if (!is_string($name) || preg_match(self::STAT_KEY, $name) !== 1) {
                continue;
            }
            if (is_string($value)) {
                $params[$name] = self::neutral($value, self::MAX_PARAM);
            } elseif (is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
                $params[$name] = $value;
            }
        }

        return ['code' => $kind['code'], 'params' => (object) array_slice($params, 0, self::MAX_PARAMS, true)];
    }

    // ── reading ──────────────────────────────────────────────────────────────────────────────────

    /**
     * The accounts of a file, checked and normalized: games as Game, times as UTC to the second (null where
     * the file has none), text neutral. A JSON object (decoded as an array) or anything else.
     *
     * @return array{exported_at: DateTimeImmutable, accounts: list<array<string, mixed>>}
     *
     * @throws AccountsFileException
     */
    public static function read(mixed $file): array
    {
        return (new self())->check($file);
    }

    private function check(mixed $file): array
    {
        if (!is_array($file) || $file === [] || array_is_list($file) || ($file['format'] ?? null) !== self::FORMAT) {
            throw new AccountsFileException('not_a_data_file', [], 'This is not a file of game accounts exported by the build analyzer.');
        }
        $version = $file['format_version'] ?? null;
        if (is_int($version) && $version > self::VERSION) {
            throw new AccountsFileException(
                'newer_version',
                ['version' => $version],
                "This file was written by a newer version of the build analyzer (format version {$version}); this copy reads version " . self::VERSION . '.',
            );
        }
        $this->fields($file, '(file)', self::FIELDS);
        if (!is_int($version) || $version < self::OLDEST_VERSION) {
            $this->fail('format_version', 'not a format version');
        }
        $this->version = $version;
        $exportedAt = $this->checkTime($file['exported_at'], 'exported_at') ?? $this->fail('exported_at', 'missing');
        $this->text($file['app_version'], 'app_version', self::MAX_APP_VERSION);

        $accounts = [];
        $uids = [];
        foreach ($this->list($file['accounts'], 'accounts', self::MAX_ACCOUNTS) as $index => $entry) {
            $account = $this->checkAccount($entry, "accounts[{$index}]");
            if ($account['uid'] !== null) {
                $same = $account['game']->value . ':' . $account['uid'];
                if (isset($uids[$same])) {
                    $this->fail("accounts[{$index}]", "the same game and UID as accounts[{$uids[$same]}]");
                }
                $uids[$same] = $index;
            }
            $accounts[] = $account;
        }

        return ['exported_at' => $exportedAt, 'accounts' => $accounts];
    }

    /** @return array<string, mixed> */
    private function checkAccount(mixed $entry, string $path): array
    {
        $this->fields($entry, $path, self::ACCOUNT_FIELDS);
        $game = is_string($entry['game']) ? Game::tryFrom($entry['game']) : null;
        if ($game === null) {
            $this->fail("{$path}.game", 'not a game of this site');
        }
        $uid = $entry['uid'];
        if ($uid !== null && (!is_string($uid) || preg_match(self::UID, $uid) !== 1)) {
            $this->fail("{$path}.uid", 'not a UID');
        }

        $characters = [];
        $keys = [];
        foreach ($this->list($entry['characters'], "{$path}.characters", self::MAX_CHARACTERS) as $index => $character) {
            $at = "{$path}.characters[{$index}]";
            $this->fields(
                $character,
                $at,
                match ($this->version) {
                    1 => self::CHARACTER_FIELDS_V1,
                    2 => self::CHARACTER_FIELDS_V2,
                    default => self::CHARACTER_FIELDS,
                },
            );
            $key = $this->catalogKey($character['key'], "{$at}.key");
            if (isset($keys[$key])) {
                $this->fail("{$at}.key", 'a character listed twice');
            }
            $keys[$key] = true;
            $characters[] = [
                'key' => $key,
                'level' => $this->int($character['level'], "{$at}.level", 0, self::SMALLINT),
                'ascension' => $this->int($character['ascension'], "{$at}.ascension", 0, self::SMALLINT, nullable: true),
                'rank' => $this->int($character['rank'], "{$at}.rank", 0, self::SMALLINT),
                'talents' => $this->talents($character['talents'], "{$at}.talents"),
                'final_stats' => $this->numbers($character['final_stats'], "{$at}.final_stats", wholeOnly: false),
                'source' => $this->source($character['source'], "{$at}.source"),
                'synced_at' => $this->checkTime($character['synced_at'], "{$at}.synced_at"),
                'build' => $this->version === 1 ? null : $this->build($character['build'], "{$at}.build", $game),
                'favourite' => $this->version < 3 ? false : $this->bool($character['favourite'], "{$at}.favourite"),
            ];
        }

        $weapons = [];
        foreach ($this->list($entry['weapons'], "{$path}.weapons", self::MAX_WEAPONS) as $index => $weapon) {
            $at = "{$path}.weapons[{$index}]";
            $this->fields($weapon, $at, self::WEAPON_FIELDS);
            $weapons[] = [
                'key' => $this->catalogKey($weapon['key'], "{$at}.key"),
                'level' => $this->int($weapon['level'], "{$at}.level", 0, self::SMALLINT),
                'ascension' => $this->int($weapon['ascension'], "{$at}.ascension", 0, self::SMALLINT, nullable: true),
                'refinement' => $this->int($weapon['refinement'], "{$at}.refinement", 1, 5),
                'equipped' => $weapon['equipped'] === null ? null : $this->catalogKey($weapon['equipped'], "{$at}.equipped"),
                'locked' => $this->bool($weapon['locked'], "{$at}.locked"),
                'external_uid' => $this->text($weapon['external_uid'], "{$at}.external_uid", self::MAX_EXTERNAL_UID),
                'source' => $this->source($weapon['source'], "{$at}.source"),
            ];
        }

        $items = [];
        foreach ($this->list($entry['items'], "{$path}.items", self::MAX_ITEMS) as $index => $item) {
            $at = "{$path}.items[{$index}]";
            $this->fields($item, $at, self::ITEM_FIELDS);
            if (!is_string($item['slot']) || !in_array($item['slot'], $game->slots(), true)) {
                $this->fail("{$at}.slot", 'not a slot of ' . $game->label());
            }
            $substats = [];
            foreach ($this->list($item['substats'], "{$at}.substats", self::MAX_SUBSTATS) as $n => $substat) {
                $this->fields($substat, "{$at}.substats[{$n}]", self::SUBSTAT_FIELDS);
                $substats[] = [
                    'key' => $this->statKey($substat['key'], "{$at}.substats[{$n}].key"),
                    'value' => $this->checkNumber($substat['value'], "{$at}.substats[{$n}].value"),
                    'rolls' => $this->int($substat['rolls'], "{$at}.substats[{$n}].rolls", 0, self::SMALLINT, nullable: true),
                ];
            }
            $items[] = [
                'set' => $this->catalogKey($item['set'], "{$at}.set"),
                'slot' => $item['slot'],
                'rarity' => $this->int($item['rarity'], "{$at}.rarity", 0, self::SMALLINT),
                'level' => $this->int($item['level'], "{$at}.level", 0, self::SMALLINT),
                'main_stat' => $this->statKey($item['main_stat'], "{$at}.main_stat", 32),
                'main_value' => $item['main_value'] === null ? null : $this->checkNumber($item['main_value'], "{$at}.main_value"),
                'substats' => $substats,
                'equipped' => $item['equipped'] === null ? null : $this->catalogKey($item['equipped'], "{$at}.equipped"),
                'locked' => $this->bool($item['locked'], "{$at}.locked"),
                'external_uid' => $this->text($item['external_uid'], "{$at}.external_uid", self::MAX_EXTERNAL_UID),
                'source' => $this->source($item['source'], "{$at}.source"),
            ];
        }

        $imports = [];
        foreach ($this->list($entry['imports'], "{$path}.imports", self::MAX_IMPORTS) as $index => $run) {
            $at = "{$path}.imports[{$index}]";
            $this->fields($run, $at, self::IMPORT_FIELDS);
            if (!in_array($run['status'], self::STATUSES, true)) {
                $this->fail("{$at}.status", 'not success, partial or failed');
            }
            $imports[] = [
                'source' => $this->source($run['source'], "{$at}.source"),
                'status' => $run['status'],
                'file_name' => $this->text($run['file_name'], "{$at}.file_name", self::MAX_FILE_NAME),
                'summary' => $this->summary($run['summary'], "{$at}.summary"),
                'error' => $this->text($run['error'], "{$at}.error", self::MAX_ERROR),
                'created_at' => $this->checkTime($run['created_at'], "{$at}.created_at"),
            ];
        }

        return [
            'game' => $game,
            'uid' => $uid,
            'nickname' => $this->text($entry['nickname'], "{$path}.nickname", self::MAX_NICKNAME),
            'level' => $this->int($entry['level'], "{$path}.level", 0, self::SMALLINT, nullable: true),
            'world_level' => $this->int($entry['world_level'], "{$path}.world_level", 0, self::SMALLINT, nullable: true),
            'signature' => $this->text($entry['signature'], "{$path}.signature", self::MAX_SIGNATURE),
            'has_full_inventory' => $this->bool($entry['has_full_inventory'], "{$path}.has_full_inventory"),
            'last_import_at' => $this->checkTime($entry['last_import_at'], "{$path}.last_import_at"),
            'created_at' => $this->checkTime($entry['created_at'], "{$path}.created_at"),
            'meta' => $this->meta($entry['meta'], "{$path}.meta"),
            'characters' => $characters,
            'weapons' => $weapons,
            'items' => $items,
            'imports' => $imports,
        ];
    }

    /**
     * The build chosen for a character, by its variation as BuildFile names one: a role of the game's, a qualifier
     * of one line ('' for none), the constellation the build needs. Null: none chosen.
     *
     * @return array{role: string, qualifier: string, min_rank: int}|null
     */
    private function build(mixed $build, string $path, Game $game): ?array
    {
        if ($build === null) {
            return null;
        }
        $this->fields($build, $path, self::BUILD_FIELDS);
        if (!in_array($build['role'], $game->roles(), true)) {
            $this->fail("{$path}.role", 'not a role of ' . $game->label());
        }
        $qualifier = $build['qualifier'];
        if (!is_string($qualifier) || mb_strlen($qualifier) > BuildFile::MAX_QUALIFIER || preg_match(self::CONTROL, $qualifier) === 1) {
            $this->fail("{$path}.qualifier", 'not a text of one line of at most ' . BuildFile::MAX_QUALIFIER . ' characters');
        }

        return [
            'role' => $build['role'],
            'qualifier' => (string) self::neutral($qualifier, BuildFile::MAX_QUALIFIER),
            'min_rank' => $this->int($build['min_rank'], "{$path}.min_rank", 0, $game->maxRank()),
        ];
    }

    /** @return array<string, mixed> */
    private function meta(mixed $meta, string $path): array
    {
        $meta = $this->map($meta, $path, count(AccountPresenter::META_KEYS));
        $out = [];
        foreach ($meta as $key => $value) {
            if (!in_array($key, AccountPresenter::META_KEYS, true)) {
                $this->fail("{$path}.{$key}", 'not a detail an account keeps');
            }
            $out[$key] = $this->scalar($value, "{$path}.{$key}", self::MAX_META);
        }

        return $out;
    }

    /** @return array<string, int|float>|null */
    private function numbers(mixed $values, string $path, bool $wholeOnly): ?array
    {
        if ($values === null) {
            return null;
        }
        $out = [];
        foreach ($this->map($values, $path, self::MAX_STATS) as $key => $value) {
            $this->statKey($key, "{$path} (a key)");
            $out[$key] = $wholeOnly ? $this->int($value, "{$path}.{$key}", 0, self::SMALLINT) : $this->checkNumber($value, "{$path}.{$key}");
        }

        return $out === [] ? null : $out;
    }

    /** @return array<string, int|array<string, int>>|null */
    private function talents(mixed $talents, string $path): ?array
    {
        if ($talents === null) {
            return null;
        }
        $out = [];
        foreach ($this->map($talents, $path, self::MAX_STATS) as $key => $value) {
            $this->statKey($key, "{$path} (a key)");
            $out[$key] = is_array($value)
                ? $this->numbers($value, "{$path}.{$key}", wholeOnly: true) ?? $this->fail("{$path}.{$key}", 'an empty object')
                : $this->int($value, "{$path}.{$key}", 0, self::SMALLINT);
        }

        return $out === [] ? null : $out;
    }

    /** @return array<string, mixed>|null */
    private function summary(mixed $summary, string $path): ?array
    {
        if ($summary === null) {
            return null;
        }
        $summary = $this->map($summary, $path, count(self::SUMMARY_FIELDS));
        $out = [];
        foreach ($summary as $key => $value) {
            $at = "{$path}.{$key}";
            $out[$key] = match ($key) {
                'characters', 'weapons', 'items' => $this->int($value, $at, 0, PHP_INT_MAX),
                'full_inventory' => $this->bool($value, $at),
                'warnings' => array_map(
                    fn($warning) => is_string($warning) && mb_strlen($warning) <= self::MAX_WARNING
                        ? self::neutral($warning, self::MAX_WARNING)
                        : $this->fail($at, 'a warning that is not a short text'),
                    $this->list($value, $at, self::MAX_WARNINGS),
                ),
                'warning_kinds' => array_map(fn($kind) => $this->kind($kind, $at), $this->list($value, $at, self::MAX_WARNINGS)),
                'unmatched' => $this->unmatched($value, $at),
                'error' => $this->kind($value, $at),
                default => $this->fail($at, 'unknown field'),
            };
        }

        return $out;
    }

    /** @return array<string, list<string>> */
    private function unmatched(mixed $value, string $path): array
    {
        $out = [];
        foreach ($this->map($value, $path, self::MAX_BUCKETS) as $bucket => $names) {
            if (preg_match(self::CODE, $bucket) !== 1) {
                $this->fail($path, 'not a list of names');
            }
            $out[$bucket] = array_map(
                fn($name) => is_string($name) && mb_strlen($name) <= self::MAX_NAME ? self::neutral($name, self::MAX_NAME) : $this->fail("{$path}.{$bucket}", 'a name that is not a short text'),
                $this->list($names, "{$path}.{$bucket}", self::MAX_UNMATCHED),
            );
        }

        return $out;
    }

    /** @return array{code: string, params: array<string, mixed>} */
    private function kind(mixed $kind, string $path): array
    {
        $this->fields($kind, $path, self::KIND_FIELDS);
        if (!is_string($kind['code']) || preg_match(self::CODE, $kind['code']) !== 1) {
            $this->fail("{$path}.code", 'not a code');
        }
        $params = [];
        foreach ($this->map($kind['params'], "{$path}.params", self::MAX_PARAMS) as $name => $value) {
            $this->statKey($name, "{$path}.params (a name)");
            $params[$name] = $this->scalar($value, "{$path}.params.{$name}", self::MAX_PARAM);
        }

        return ['code' => $kind['code'], 'params' => $params];
    }

    // ── the shapes ───────────────────────────────────────────────────────────────────────────────

    private function fail(string $path, string $problem): never
    {
        throw new AccountsFileException('bad_entry', ['path' => $path], "{$path}: {$problem}.");
    }

    /** An object with exactly these fields. */
    private function fields(mixed $value, string $path, array $fields): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->fail($path, 'not an object');
        }
        foreach (array_keys($value) as $field) {
            if (!in_array($field, $fields, true)) {
                $this->fail($path, "an unknown field \"{$field}\"");
            }
        }
        foreach ($fields as $field) {
            if (!array_key_exists($field, $value)) {
                $this->fail($path, "no field \"{$field}\"");
            }
        }
    }

    /** An object of any keys (an empty one decodes as a list). @return array<string, mixed> */
    private function map(mixed $value, string $path, int $max): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->fail($path, 'not an object');
        }
        if (count($value) > $max) {
            $this->fail($path, "more than {$max} entries");
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                $this->fail($path, 'a key that is a number');
            }
        }

        return $value;
    }

    /** @return list<mixed> */
    private function list(mixed $value, string $path, int $max): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->fail($path, 'not a list');
        }
        if (count($value) > $max) {
            $this->fail($path, "more than {$max} entries");
        }

        return $value;
    }

    private function int(mixed $value, string $path, int $min, int $max, bool $nullable = false): ?int
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            $this->fail($path, "not a whole number from {$min} to {$max}");
        }

        return $value;
    }

    private function checkNumber(mixed $value, string $path): int|float
    {
        if (!is_int($value) && !(is_float($value) && is_finite($value))) {
            $this->fail($path, 'not a number');
        }

        return $value;
    }

    private function bool(mixed $value, string $path): bool
    {
        if (!is_bool($value)) {
            $this->fail($path, 'not true or false');
        }

        return $value;
    }

    /** Text or null, at most $max characters as the file has it; neutral. */
    private function text(mixed $value, string $path, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > $max) {
            $this->fail($path, "not a text of at most {$max} characters");
        }

        return self::neutral($value, $max);
    }

    private function scalar(mixed $value, string $path, int $max): string|int|float|bool|null
    {
        if (is_string($value)) {
            return $this->text($value, $path, $max);
        }
        if ($value === null || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
            return $value;
        }
        $this->fail($path, 'not a text, a number or true / false');
    }

    private function catalogKey(mixed $value, string $path): string
    {
        if (!is_string($value) || preg_match(self::CATALOG_KEY, $value) !== 1) {
            $this->fail($path, 'not a key of the game catalog');
        }

        return $value;
    }

    private function statKey(mixed $value, string $path, int $max = 40): string
    {
        if (!is_string($value) || preg_match(self::STAT_KEY, $value) !== 1 || strlen($value) > $max) {
            $this->fail($path, 'not a stat key');
        }

        return $value;
    }

    private function source(mixed $value, string $path): string
    {
        if (!in_array($value, self::SOURCES, true)) {
            $this->fail($path, 'not "showcase" or "file"');
        }

        return $value;
    }

    /** A time with its offset, in UTC to the second; null stays null. */
    private function checkTime(mixed $value, string $path): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $time = is_string($value) && preg_match(self::TIME, $value) === 1 ? Clock::parse($value) : null;
        if ($time === null) {
            $this->fail($path, 'not a time such as 2026-09-29T14:03:12Z');
        }

        return self::second($time);
    }

    /** A time cut to its second, in UTC: how a file's time and a stored one are compared. */
    public static function second(DateTimeInterface $time): DateTimeImmutable
    {
        $utc = DateTimeImmutable::createFromInterface($time)->setTimezone(Clock::utc());

        return $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), (int) $utc->format('s'));
    }
}
