/**
 * The keys that are put together at run time: prefix -> every tail the code can add to it. A key is never
 * built from anything open - an address, the IAM's answer, what a person typed, a file - only from these
 * closed sets, and the code that words them goes through a table with a fallback.
 *
 * This file is read, as text, by the backend's key scan (backend/tests/support/translation_keys.php), which
 * cannot see a key that is never written out whole: every key listed here (or its plural family) must exist
 * in the database, and a database key under one of these prefixes that is not listed is unused. So the
 * tails are written out as plain strings - no spread, no constant.
 *
 * Three of the lists are the server's own: `recommendation.` is the keys of BuildEvaluator::KINDS,
 * `import.error.` those of ImportKinds::ERRORS and `import.warning.` those of ImportKinds::WARNINGS - a
 * backend test holds them equal, and a spec here holds each list equal to the table that words it.
 */
export const DYNAMIC_KEYS: Record<string, readonly string[]> = {
  // What a build recommends, as recommendationText() words it (recommendation-words.ts).
  'recommendation.': [
    'weapon_none',
    'weapon_level',
    'weapon_unlisted',
    'weapon_unlisted_owned',
    'weapon_unlisted_owned_on',
    'weapon_better_owned',
    'weapon_better_owned_on',
    'weapon_low_rank',
    'sets_missing',
    'sets_missing_empty',
    'sets_lower_option',
    'main_stat_missing',
    'main_stat_wrong',
    'item_level',
    'substats_weak',
    'target_below',
    'talent_level',
    'character_level',
  ],
  // Why an import was refused or failed (import-words.ts): the 422's code, a failed run's error_code.
  'import.error.': [
    'account_missing',
    'account_game_mismatch',
    'account_uid_mismatch',
    'unexpected',
    'uid_invalid',
    'showcase_unreachable',
    'showcase_uid_refused',
    'showcase_player_not_found',
    'showcase_maintenance',
    'showcase_busy',
    'showcase_down',
    'showcase_http',
    'showcase_bad_answer',
    'showcase_details_off',
    'showcase_empty',
    'showcase_empty_hsr',
    'good_not_good',
    'good_empty',
    'hsr_not_export',
    'hsr_empty',
    'file_not_json',
    'file_unknown_format',
    'file_game_mismatch',
  ],
  // What an import that went through warns about (import-words.ts): summary.warning_kinds[].code.
  'import.warning.': [
    'unknown_character',
    'unknown_weapon',
    'unknown_set',
    'catalog_behind',
    'showcase_artifact_skipped',
    'showcase_relic_skipped',
    'good_traveler_no_element',
    'good_artifacts_skipped',
    'hsr_relics_skipped',
  ],
  // An import run's status (import-words.ts).
  'import.status.': ['success', 'partial', 'failed'],
  // The whole-request failures errorKey() words (error-text.ts): the API's own codes verbatim, a visitor's
  // two limits (visitor_limit {limit}), and the four the page decides itself - offline, sign_in_required
  // (any other 401), http, unknown.
  'error.': [
    'offline',
    'sign_in_required',
    'editor_only',
    'admin_only',
    'visitor_unknown',
    'too_many_visitors',
    'visitors_unavailable',
    'visitor_limit_accounts',
    'visitor_limit_file_size',
    'too_many_imports',
    'not_found',
    'server_error',
    'not_set_up',
    'invalid',
    'http',
    'unknown',
  ],
  // Why the own-data file could not be imported or exported (shared/data-card): the API's codes verbatim
  // (AccountsFileException's kinds), and `unreadable` - the browser could not read the chosen file.
  'data.error.': ['unreadable', 'no_file', 'file_too_big', 'not_a_data_file', 'newer_version', 'bad_entry', 'too_many_accounts', 'export_too_big'],
  // What an import of the own-data file does to each game account (shared/data-card).
  'data.outcome.': ['added', 'replaced', 'kept'],
};
