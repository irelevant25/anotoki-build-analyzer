<?php

namespace App\Services\Import;

/**
 * What an import can say, as kinds a page words in the reader's language: kind => the names of its
 * parameters. The English sentence beside each kind (an ImportException's message, a warning's message) is
 * that kind with its parameters filled in.
 *
 * Parameters are flat strings and numbers. Game names (*_game) are Game::label(); weapon_label is
 * Game::weaponLabel() as it is ("Weapon", "Light Cone"); ref is a catalog reference's name, else its key,
 * else its in-game ID; count is a number. What comes from an uploaded file (ref, data_uid) goes through
 * Neutral on the way out, like the messages.
 *
 * A new kind is a row here, its throw or warn() site, and its string on the frontend - the tests compare
 * every site in src/ with these lists.
 */
final class ImportKinds
{
    /** The kinds of an ImportException, and "unexpected" for a failure that is not one. */
    public const ERRORS = [
        'account_missing' => [],
        'account_game_mismatch' => ['account_game', 'data_game'],
        'account_uid_mismatch' => ['data_uid', 'account_uid'],
        'unexpected' => [],
        'uid_invalid' => [],
        'showcase_unreachable' => [],
        'showcase_uid_refused' => [],
        'showcase_player_not_found' => [],
        'showcase_maintenance' => [],
        'showcase_busy' => [],
        'showcase_down' => [],
        'showcase_http' => ['status'],
        'showcase_bad_answer' => [],
        'showcase_details_off' => [],
        'showcase_empty' => [],
        'showcase_empty_hsr' => [],
        'good_not_good' => [],
        'good_empty' => [],
        'hsr_not_export' => [],
        'hsr_empty' => [],
        'file_not_json' => [],
        'file_unknown_format' => [],
        'file_game_mismatch' => ['file_game', 'chosen_game'],
    ];

    /** The kinds of a warning of an import that went through (summary.warning_kinds). */
    public const WARNINGS = [
        'unknown_character' => ['ref'],
        'unknown_weapon' => ['weapon_label', 'ref'],
        'unknown_set' => ['ref'],
        'catalog_behind' => [],
        'showcase_artifact_skipped' => [],
        'showcase_relic_skipped' => [],
        'good_traveler_no_element' => [],
        'good_artifacts_skipped' => ['count'],
        'hsr_relics_skipped' => ['count'],
    ];

    /**
     * A warning as ParsedAccount and AccountImporter hold it.
     *
     * @param  string  $kind  a key of WARNINGS
     * @param  array<string, string|int>  $params  exactly the parameters WARNINGS lists for $kind
     * @return array{message: string, kind: string, params: array<string, string|int>}
     */
    public static function warning(string $kind, array $params, string $message): array
    {
        return ['message' => $message, 'kind' => $kind, 'params' => $params];
    }

    /**
     * A catalog reference ({name?, key?, external_id?}) as one word for a parameter: the first of its name,
     * key and in-game ID that is there.
     *
     * @param  array<string, mixed>  $ref
     */
    public static function ref(array $ref): string
    {
        foreach (['name', 'key', 'external_id'] as $field) {
            if (isset($ref[$field]) && is_scalar($ref[$field]) && (string) $ref[$field] !== '') {
                return (string) $ref[$field];
            }
        }

        return '';
    }
}
