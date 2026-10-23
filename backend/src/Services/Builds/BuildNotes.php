<?php

namespace App\Services\Builds;

use App\Services\Translations\SiteTranslations;
use App\Support\Json;
use App\Support\Neutral;

/**
 * The site's own notes on a build (builds.site_notes, migration 022; docs/builds.md, "Notes"): written in the editor,
 * in English and - where somebody wrote it - Slovak, on the build itself and on each of its teams:
 *
 *   {"build": {"en": "...", "sk": "..."}, "teams": {"hutao kaedeharakazuha xingqiu": {"en": "..."}}}
 *
 * A team's note goes by the team's members (teamKey()), whatever their order or the team's name: a team whose members
 * change - by hand, or by an import - leaves its note waiting for a team of those members (dormant()), never lost.
 * Notes never travel in a builds file, and an import never changes one.
 *
 * English is required, Slovak optional (the released languages, SiteTranslations::RELEASED); each note within its
 * cap, one line or many, without control characters; and none names another website or holds a web address - the
 * check the site's own strings get (SiteTranslations::otherSites()): refused, never reworded behind the editor's back.
 */
final class BuildNotes
{
    /** A build's note: a few paragraphs at most. */
    public const MAX_BUILD = 20000;

    /** A team's note: a rotation, a word on a stand-in. */
    public const MAX_TEAM = 5000;

    /**
     * What a team's note goes by: its members' keys, sorted, one space apart.
     *
     * @param  array<string, mixed>  $team
     */
    public static function teamKey(array $team): string
    {
        $keys = array_map(fn($member) => (string) (is_array($member) ? $member['key'] ?? '' : ''), $team['members'] ?? []);
        sort($keys, SORT_STRING);

        return implode(' ', $keys);
    }

    /**
     * builds.site_notes read back: {build?: {en, sk?}, teams?: {key: {en, sk?}}}; none as [].
     *
     * @return array<string, mixed>
     */
    public static function read(mixed $column): array
    {
        return Json::decode($column) ?? [];
    }

    /** Whether there is any note at all - on the build, or on one of its teams, waiting or not. */
    public static function any(array $notes): bool
    {
        return !empty($notes['build']) || !empty($notes['teams']);
    }

    /**
     * The teams' notes no team of these has the members of: waiting for one.
     *
     * @param  array<string, mixed>  $notes  read()
     * @param  list<array<string, mixed>>  $teams
     * @return list<string>  their keys (teamKey())
     */
    public static function dormant(array $notes, array $teams): array
    {
        $present = array_flip(array_map(self::teamKey(...), $teams));

        return array_values(array_filter(array_map('strval', array_keys((array) ($notes['teams'] ?? []))), fn(string $key) => !isset($present[$key])));
    }

    /**
     * A build's notes as the pages show them: the build's, and each team's beside its team - {en, sk?}, or null where
     * there is none. Every text neutral once more, as everything the API sends. A note waiting for a team of its
     * members is shown nowhere but in the editor.
     *
     * @param  array<string, mixed>  $notes  read()
     * @param  list<array<string, mixed>>  $teams
     * @return array{build: ?array<string, string>, teams: list<?array<string, string>>}
     */
    public static function shown(array $notes, array $teams): array
    {
        $byTeam = (array) ($notes['teams'] ?? []);

        return [
            'build' => self::shownNote($notes['build'] ?? null),
            'teams' => array_map(fn(array $team) => self::shownNote($byTeam[self::teamKey($team)] ?? null), $teams),
        ];
    }

    /**
     * A build's notes as the editor shows them: shown()'s, and the ones waiting for a team of their members - with
     * those members' names (`$names`: key => name).
     *
     * @param  array<string, mixed>  $notes  read()
     * @param  list<array<string, mixed>>  $teams
     * @param  array<string, string>  $names
     * @return array{build: ?array<string, string>, teams: list<?array<string, string>>, waiting: list<array{key: string, members: list<array{key: string, name: string}>, note: array<string, string>}>}
     */
    public static function forEditor(array $notes, array $teams, array $names = []): array
    {
        $byTeam = (array) ($notes['teams'] ?? []);

        return self::shown($notes, $teams) + [
            'waiting' => array_map(
                fn(string $key) => [
                    // Neutral, as every key the API sends (BuildFile::neutralKey()): one the names list learned of later too.
                    'key' => implode(' ', array_map(BuildFile::neutralKey(...), explode(' ', $key))),
                    'members' => array_map(fn(string $member) => ['key' => BuildFile::neutralKey($member), 'name' => (string) Neutral::text($names[$member] ?? $member)], explode(' ', $key)),
                    'note' => self::shownNote($byTeam[$key]),
                ],
                self::dormant($notes, $teams),
            ),
        ];
    }

    /**
     * The notes of an editor's save - {build, teams, waiting} as forEditor() gives them, the waiting ones as [{key,
     * note}] - checked and keyed as stored: null for none. `teams` has a note or null for each team, in the teams'
     * order. A waiting note's key is the members of the team it waits for - 2 to the game's team size, each once, the
     * build's own character among them -, sorted as stored whatever order they came in. A waiting note whose team is
     * there again becomes that team's note, unless the team has a note of its own.
     *
     * @param  list<array<string, mixed>>  $teams  the build's teams, as BuildFile reads them
     * @param  string  $own  the build's character's key, neutral (BuildFile::neutralKey())
     * @return ?array<string, mixed>
     *
     * @throws BuildEditException
     */
    public static function fromEditor(mixed $value, array $teams, int $index, int $teamSize, string $own): ?array
    {
        if (!self::hasExactly($value, ['build', 'teams', 'waiting'])) {
            throw self::refused('notes must be an object of exactly build, teams and waiting', $index, 'notes');
        }
        $notes = [];
        if (($build = self::note($value['build'], 'notes.build', self::MAX_BUILD, $index)) !== null) {
            $notes['build'] = $build;
        }

        // As many as there are teams: a note left out would be a note lost.
        if (!is_array($value['teams']) || !array_is_list($value['teams']) || count($value['teams']) !== count($teams)) {
            throw self::refused('notes.teams must be a list of a note or null for each team, in the teams\' order', $index, 'notes.teams');
        }
        $byTeam = [];
        foreach ($value['teams'] as $i => $note) {
            if (($note = self::note($note, "notes.teams[{$i}]", self::MAX_TEAM, $index)) !== null) {
                $byTeam[self::teamKey($teams[$i])] = $note;
            }
        }

        if (!is_array($value['waiting']) || !array_is_list($value['waiting'])) {
            throw self::refused('notes.waiting must be a list of {key, note}', $index, 'notes.waiting');
        }
        $present = array_flip(array_map(self::teamKey(...), $teams));
        $waiting = [];
        foreach ($value['waiting'] as $i => $entry) {
            $at = "notes.waiting[{$i}]";
            if (!self::hasExactly($entry, ['key', 'note']) || !is_string($entry['key']) || !preg_match('/^[a-z0-9]+(?: [a-z0-9]+)+\z/', $entry['key'])) {
                throw self::refused("{$at} must be {key, note}, its key the keys of a team's members, one space apart", $index, $at);
            }
            $members = explode(' ', $entry['key']);
            if (count(array_unique($members)) !== count($members) || count($members) > $teamSize || !in_array($own, $members, true)) {
                throw self::refused("{$at}.key must be the keys of a team's 2 to {$teamSize} members, each once, the build's own character among them", $index, "{$at}.key");
            }
            sort($members, SORT_STRING);
            $key = implode(' ', $members);
            if (isset($waiting[$key])) {
                throw self::refused("{$at} waits for the same members as another", $index, $at);
            }
            $note = self::note($entry['note'], "{$at}.note", self::MAX_TEAM, $index) ?? throw self::refused("{$at}.note must be a note", $index, "{$at}.note");
            if (isset($present[$key])) {
                if (isset($byTeam[$key])) {
                    throw self::refused("{$at} waits for a team that has a note of its own: keep one of the two", $index, $at);
                }
                $byTeam[$key] = $note;
            } else {
                $waiting[$key] = $note;
            }
        }
        if (($byTeam += $waiting) !== []) {
            $notes['teams'] = $byTeam;
        }

        return $notes === [] ? null : $notes;
    }

    /**
     * One note of a save: {en, sk?} - English required, Slovak optional, a text left empty as none -, or null.
     *
     * @return ?array<string, string>
     *
     * @throws BuildEditException
     */
    private static function note(mixed $value, string $path, int $max, int $index): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw self::refused("{$path} must be an object of its English (en) and, where written, its Slovak (sk)", $index, $path);
        }
        foreach (array_keys($value) as $language) {
            if (!in_array($language, SiteTranslations::RELEASED, true)) {
                // Never the language as sent: it could be anything.
                throw self::refused("{$path} holds a language the site's notes have not (they have " . implode(', ', SiteTranslations::RELEASED) . ')', $index, $path);
            }
        }
        $note = [];
        foreach (SiteTranslations::RELEASED as $language) {
            $text = $value[$language] ?? null;
            if ($text === null) {
                continue;
            }
            $at = "{$path}.{$language}";
            if (!is_string($text)) {
                throw self::refused("{$at} must be text", $index, $at);
            }
            $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
            if ($text === '') {
                continue;
            }
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
                throw self::refused("{$at} must hold no control characters", $index, $at);
            }
            if (mb_strlen($text) > $max) {
                throw self::refused("{$at} must not be longer than {$max} characters", $index, $at);
            }
            if (($found = SiteTranslations::otherSites($text)) !== []) {
                throw new BuildEditException("{$at} " . self::naming($found) . ' Nothing the site shows names or links another website, service or tool.', 'names_another_site', $index, $at);
            }
            $note[$language] = $text;
        }
        if ($note === []) {
            return null;
        }
        if (!isset($note['en'])) {
            throw self::refused("{$path}.en must be written: English first, Slovak where wanted", $index, "{$path}.en");
        }

        return $note;
    }

    /**
     * What a text names: "holds a web address." or 'names another site ("A", "B").'
     *
     * @param  list<string>  $found  SiteTranslations::otherSites()
     */
    public static function naming(array $found): string
    {
        return $found === ['a web address'] ? 'holds a web address.' : 'names another site (' . implode(', ', array_map(fn(string $name) => '"' . $name . '"', $found)) . ').';
    }

    /** A note as the API sends it: every text neutral; null for none. */
    private static function shownNote(mixed $note): ?array
    {
        if (!is_array($note) || $note === []) {
            return null;
        }

        return array_map(fn($text) => (string) Neutral::text((string) $text), $note);
    }

    /**
     * Whether $value is an object of exactly these fields, in any order.
     *
     * @param  list<string>  $fields
     */
    public static function hasExactly(mixed $value, array $fields): bool
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return false;
        }
        $keys = array_map('strval', array_keys($value));
        sort($keys);
        sort($fields);

        return $keys === $fields;
    }

    private static function refused(string $problem, int $index, string $path): BuildEditException
    {
        return new BuildEditException('Build ' . ($index + 1) . ": {$problem}.", 'invalid_note', $index, $path);
    }
}
