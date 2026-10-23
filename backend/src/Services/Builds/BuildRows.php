<?php

namespace App\Services\Builds;

use App\Support\Json;

/**
 * A build as the table `builds` stores it, and back - what an import (BuildImport) and an editor's save (BuildEditor)
 * both write: its columns, what the last file said of it beside them (builds.file_build, migration 022), its content
 * hash. A build here is an array in the shape BuildFile::read() gives a file's - its variation, its place among its
 * character's builds (`position`), its content.
 */
final class BuildRows
{
    /**
     * A stored build: its columns decoded, in the shape BuildFile::read() gives a file's.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function decoded(array $row): array
    {
        return [
            'role' => (string) $row['role'],
            'qualifier' => (string) $row['qualifier'],
            'min_rank' => (int) $row['min_rank'],
            'position' => (int) $row['position'],
            'weapons' => Json::decode($row['weapons']) ?? [],
            'sets' => Json::decode($row['sets']) ?? [],
            'main_stats' => Json::decode($row['main_stats']) ?? [],
            'substats' => Json::decode($row['substats']) ?? [],
            'talent_priority' => Json::decode($row['talent_priority']),
            'stat_targets' => Json::decode($row['stat_targets']),
            'teams' => Json::decode($row['teams']) ?? [],
            'notes' => $row['notes'],
            'game_version' => $row['game_version'],
        ];
    }

    /**
     * What builds.file_build holds of a build: its variation, place and content as its columns store them (migration
     * 022 wrote every build's so) - teams NULL for none, main stats an object.
     *
     * @param  array<string, mixed>  $build
     * @return array<string, mixed>
     */
    public static function stored(array $build): array
    {
        return [
            'role' => $build['role'],
            'qualifier' => $build['qualifier'],
            'min_rank' => $build['min_rank'],
            'position' => $build['position'],
            'weapons' => $build['weapons'],
            'sets' => $build['sets'],
            'main_stats' => (object) $build['main_stats'],
            'substats' => $build['substats'],
            'talent_priority' => $build['talent_priority'],
            'stat_targets' => $build['stat_targets'],
            'teams' => $build['teams'] === [] ? null : $build['teams'],
            'notes' => $build['notes'],
            'game_version' => $build['game_version'],
        ];
    }

    /**
     * builds.file_build (or build_removals.file_build) read back, in the shape decoded() gives; null for a build made
     * here.
     *
     * @return ?array<string, mixed>
     */
    public static function fromStored(mixed $column): ?array
    {
        $stored = Json::decode($column);
        if ($stored === null) {
            return null;
        }

        return [
            'role' => (string) ($stored['role'] ?? ''),
            'qualifier' => (string) ($stored['qualifier'] ?? ''),
            'min_rank' => (int) ($stored['min_rank'] ?? 0),
            'position' => (int) ($stored['position'] ?? 0),
            'weapons' => $stored['weapons'] ?? [],
            'sets' => $stored['sets'] ?? [],
            'main_stats' => $stored['main_stats'] ?? [],
            'substats' => $stored['substats'] ?? [],
            'talent_priority' => $stored['talent_priority'] ?? null,
            'stat_targets' => $stored['stat_targets'] ?? null,
            'teams' => $stored['teams'] ?? [],
            'notes' => $stored['notes'] ?? null,
            'game_version' => $stored['game_version'] ?? null,
        ];
    }

    /**
     * What is stored of a build besides its character, identity and place, in the order its content hash reads it:
     * 0.5's content, then its teams when it has any - so a build without teams keeps the hash it had, and the first
     * import of a version 3 file changed only the builds that gained teams.
     *
     * @param  array<string, mixed>  $build
     * @return array<string, mixed>
     */
    public static function content(array $build): array
    {
        $content = [
            'weapons' => $build['weapons'],
            'sets' => $build['sets'],
            'main_stats' => $build['main_stats'],
            'substats' => $build['substats'],
            'talent_priority' => $build['talent_priority'],
            'stat_targets' => $build['stat_targets'],
            'notes' => $build['notes'],
            'game_version' => $build['game_version'],
        ];
        if ($build['teams'] !== []) {
            $content['teams'] = $build['teams'];
        }

        return $content;
    }

    /**
     * The hash of a build's content's JSON (App\Support\Json): of what the file said of it (builds.file_build) - the
     * same as its content's while nobody edited it -, of its content for a build made here.
     */
    public static function hash(array $content): string
    {
        return hash('sha256', Json::encode($content));
    }

    /**
     * A build as table columns, its identity and place with them, and the file's version beside it (null: made here).
     *
     * @param  array<string, mixed>  $build
     * @param  ?array<string, mixed>  $base
     * @return array<string, mixed>
     */
    public static function columns(array $build, ?array $base): array
    {
        $columns = ['role' => $build['role'], 'qualifier' => $build['qualifier'], 'min_rank' => $build['min_rank'], 'position' => $build['position']];
        foreach (self::content($build) as $column => $value) {
            $columns[$column] = in_array($column, ['notes', 'game_version'], true) ? $value : Json::column($value);
        }
        // An empty main stats object is {}, not [].
        $columns['main_stats'] = Json::column((object) $build['main_stats']);
        // Always written - NULL for none -, so an update that leaves a build without teams clears its old ones.
        $columns['teams'] = $build['teams'] === [] ? null : Json::column($build['teams']);

        return $columns + [
            'file_build' => $base === null ? null : Json::column(self::stored($base)),
            'content_hash' => self::hash(self::content($base ?? $build)),
        ];
    }

    /**
     * Each weapon with whether the catalog knows it now.
     *
     * @param  list<array<string, mixed>>  $weapons
     * @param  array<string, mixed>  $catalog  the catalog's weapons by key
     * @return list<array<string, mixed>>
     */
    public static function known(array $weapons, array $catalog): array
    {
        return array_map(fn(array $weapon) => array_replace($weapon, ['known' => isset($catalog[$weapon['key'] ?? ''])]), $weapons);
    }
}
