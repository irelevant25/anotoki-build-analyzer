import type { AuthBootConfig } from '@anotoki/lib/auth';

export type GameKey = 'genshin';
export type Severity = 'high' | 'medium' | 'low';
export type Grade = 'S' | 'A' | 'B' | 'C' | 'D' | '-';

// ------------------------------------------------------------------ errors

/**
 * What an error says happened, for a page to word: every error a member can meet carries one beside the
 * server's English `message`. (An import's 422 carries an ImportErrorCode instead; the refusals of the
 * admin-only routes carry none.) `not_set_up` and `update_pending` are the site gate's: no page words them -
 * the gate shows its own page in the site's place as soon as one arrives (core/site-status.ts).
 */
export type ApiErrorCode =
  | 'sign_in_required'
  | 'invalid_token'
  | 'editor_only'
  | 'admin_only'
  | 'visitor_unknown'
  | 'too_many_visitors'
  | 'visitor_limit'
  | 'visitors_unavailable'
  | 'visitor_move_retry'
  | 'too_many_imports'
  | 'not_found'
  | 'method_not_allowed'
  | 'server_error'
  | 'not_set_up'
  | 'update_pending'
  | 'invalid';

/** The parameters of a code or a kind: flat, named, strings and numbers (null where a kind says so). */
export type KindParams = Record<string, string | number | null>;

/** The body of an error answer of the API. */
export interface ApiErrorBody {
  /** The server's English sentence. */
  message: string;
  /** An ApiErrorCode or an ImportErrorCode - or one a newer server added; absent on admin-only refusals. */
  code?: string;
  /** With an ImportErrorCode (the 422 of an import): that code's parameters. */
  params?: KindParams;
  /** With "invalid": every message by field. */
  errors?: Record<string, string[]>;
  /**
   * With "not_set_up" - the site gate's answer, as "update_pending" is; for either the gate shows its own page
   * instead of the site's (core/site-status.ts): where the setup page is, while the site was never installed.
   */
  setup?: string;
}

// ------------------------------------------------------------------ meta

export interface StatMeta {
  label: string;
  short: string;
  percent: boolean;
}

export interface SlotMeta {
  key: string;
  label: string;
  variable: boolean;
}

export interface GameMeta {
  key: GameKey;
  label: string;
  slots: SlotMeta[];
  weapon_label: string;
  item_label: string;
  rank_label: string;
  refinement_prefix: string;
  rank_prefix: string;
  elements: string[];
  weapon_types: string[];
  stats: Record<string, StatMeta>;
  talents: Record<string, string>;
}

export interface Meta {
  games: GameMeta[];
  version: string;
  /**
   * Where people sign in - the anotoki library's boot configuration (the server's AuthRoutes::configBody()): the
   * IAM's address and this site's client id there, the IAM's "Sign-in required" switch for this site as the
   * server read it (it fails closed: true when it could never ask), and whether the IAM knows the site at all.
   * The auth module reads it at the start (app.config.ts).
   */
  auth: AuthBootConfig;
  /** How many days a visitor's data is kept after its last visit (the visitor notice says it). */
  visitors?: { keep_days: number };
}

// ------------------------------------------------------------------ accounts & imports

export interface AccountSummary {
  id: number;
  game: GameKey;
  uid: string | null;
  nickname: string | null;
  level: number | null;
  world_level: number | null;
  signature: string | null;
  has_full_inventory: boolean;
  last_import_at: string | null;
  counts: { characters: number; weapons: number; items: number };
  meta: Record<string, unknown> | null;
}

export interface EquippedWeapon {
  name: string;
  icon_url: string | null;
  rarity: number | null;
  level: number;
  refinement: number;
}

export interface SetCount {
  key: string;
  name: string;
  count: number;
}

export interface CharacterSummary {
  id: number;
  key: string;
  name: string;
  element: string | null;
  rarity: number | null;
  weapon_type: string | null;
  icon_url: string | null;
  level: number;
  rank: number;
  weapon: EquippedWeapon | null;
  sets: SetCount[];
  items_count: number;
}

export interface ScoreParts {
  weapon: number | null;
  sets: number | null;
  main_stats: number | null;
  substats: number | null;
  targets: number | null;
}

export interface CharacterOverview extends CharacterSummary {
  builds_count: number;
  best: {
    build_id: number;
    /** The build's variation: its role, a qualifier telling two builds of one role apart, the constellation it needs. */
    role: string;
    qualifier: string;
    min_rank: number;
    /** Whether the character has the constellation the build needs (when none of theirs has, the one needing the lowest). */
    applies: boolean;
    overall: number | null;
    grade: Grade;
    parts: ScoreParts;
    /** The first recommendation's English sentence; null when there is nothing to recommend. */
    top_issue: string | null;
    /** The same recommendation as a kind with its parameters, for the page to word; null with top_issue. */
    top_issue_kind: string | null;
    top_issue_params: KindParams | null;
    issues: number;
  } | null;
}

export interface AccountDetail extends AccountSummary {
  characters: CharacterOverview[];
}

/** Where imported data came from: the in-game Character Showcase (by UID), or an inventory export file. */
export type ImportKind = 'showcase' | 'file';

/**
 * Why an import was refused or failed (the server's ImportKinds::ERRORS): the `code` of an import's 422, and
 * a failed run's `error_code`. Their parameters: account_uid_mismatch {data_uid, account_uid}, showcase_http
 * {status}; the others have none.
 */
export type ImportErrorCode =
  | 'account_missing'
  | 'account_uid_mismatch'
  | 'unexpected'
  | 'uid_invalid'
  | 'showcase_unreachable'
  | 'showcase_uid_refused'
  | 'showcase_player_not_found'
  | 'showcase_maintenance'
  | 'showcase_busy'
  | 'showcase_down'
  | 'showcase_http'
  | 'showcase_bad_answer'
  | 'showcase_details_off'
  | 'showcase_empty'
  | 'good_not_good'
  | 'good_empty'
  | 'file_not_json'
  | 'file_unknown_format';

/**
 * What an import that went through warns about (the server's ImportKinds::WARNINGS). Their parameters:
 * unknown_character {ref}, unknown_weapon {weapon_label, ref}, unknown_set {ref}, good_artifacts_skipped
 * {count}; the others have none.
 */
export type ImportWarningCode = 'unknown_character' | 'unknown_weapon' | 'unknown_set' | 'catalog_behind' | 'showcase_artifact_skipped' | 'good_traveler_no_element' | 'good_artifacts_skipped';

/** One warning of an import as a kind: `code` is an ImportWarningCode, or one a newer server added. */
export interface ImportWarningKind {
  code: string;
  params: KindParams;
}

/** The body of the 422 an import answers with: the English sentence, and what happened for the page to word. */
export interface ImportErrorBody {
  message: string;
  /** An ImportErrorCode, or one a newer server added. */
  code: string;
  params: KindParams;
}

export interface ImportRun {
  id: number;
  game: GameKey;
  source: ImportKind;
  status: 'success' | 'partial' | 'failed';
  file_name: string | null;
  /** Null for every failed run. */
  summary: {
    characters: number;
    weapons: number;
    items: number;
    full_inventory: boolean;
    /** The English sentences. */
    warnings: string[];
    /** The same warnings as kinds, index for index with `warnings`; absent on runs stored before kinds were kept. */
    warning_kinds?: ImportWarningKind[];
    unmatched: Record<string, string[]>;
  } | null;
  /** A failed run's English sentence, as stored. */
  error: string | null;
  /** A failed run's ImportErrorCode with its parameters; both null on runs stored before kinds were kept. */
  error_code: string | null;
  error_params: KindParams | null;
  created_at: string;
  account: AccountSummary | null;
  account_id: number | null;
}

// ------------------------------------------------------------------ builds

export interface StatPriority {
  stat: string;
  priority: number;
}

export interface TalentPriority {
  talent: string;
  priority: number;
}

export interface StatTarget {
  stat: string;
  min: number | null;
  max: number | null;
  text: string;
  /** The constellation the target holds from (0: from the start). */
  from_rank?: number;
  current?: number | null;
  ok?: boolean | null;
}

export interface BuildWeapon {
  key: string;
  name: string;
  rank: number;
  refinement: number | null;
  note: string | null;
  known: boolean;
  icon_url?: string | null;
  rarity?: number | null;
}

export interface SetPiece {
  label: string;
  count: number;
  keys: string[];
  names: string[];
  icon_url?: string | null;
}

export interface SetOption {
  rank: number;
  kind: 'artifact';
  choose: number | null;
  pieces: SetPiece[];
  note: string | null;
}

/** What a build is for (the server's Game::roles()); a newer server may add one, which a page words as "Build". */
export type BuildRole = 'main_dps' | 'sub_dps' | 'support';

/**
 * A recommended build: a variation of its character's - its role, a short qualifier when the character has two
 * builds of one role, the constellation it needs (min_rank) - in the character's order (position: the first is
 * the main one). It names nowhere it came from.
 */
export interface Build {
  id: number;
  /** A BuildRole, or one a newer server added. */
  role: string;
  qualifier: string;
  min_rank: number;
  position: number;
  weapons: BuildWeapon[];
  sets: SetOption[];
  main_stats: Record<string, StatPriority[]>;
  substats: StatPriority[];
  talent_priority: TalentPriority[] | null;
  stat_targets: StatTarget[] | null;
  notes: string | null;
  game_version: string | null;
  /** When the file that last carried the build was made. */
  checked_at: string | null;
  changed_at: string | null;
}

export interface BuildCharacter {
  key: string;
  name: string;
  element: string | null;
  rarity: number | null;
  weapon_type: string | null;
  icon_url: string | null;
  released_at: string | null;
  builds: number;
  /** The roles of its builds, in the game's order. */
  roles: string[];
  changed_at: string | null;
}

/** GET /api/builds: per game, how many characters have builds and how many builds, when they were checked and last changed. */
export interface BuildsOverview {
  game: GameKey;
  characters: number;
  builds: number;
  checked_at: string | null;
  changed_at: string | null;
}

export interface CharacterBuilds {
  character: {
    key: string;
    name: string;
    element: string | null;
    rarity: number | null;
    weapon_type: string | null;
    icon_url: string | null;
  };
  builds: Build[];
}

// ------------------------------------------------------------------ analysis

/**
 * What a recommendation says (the server's BuildEvaluator::KINDS), each with its parameters:
 * weapon_none {weapon_label, options}; weapon_level {name, level, max_level}; weapon_unlisted {name,
 * weapon_label, options}, _owned + {owned, refinement}, _owned_on + {character}; weapon_better_owned {owned,
 * refinement, owned_rank, name, rank}, _on + {character}; weapon_low_rank {name, rank, options}; sets_missing
 * {set_label, best, best_choose, current}; sets_missing_empty {set_label, best, best_choose};
 * sets_lower_option {set_label, current, rank, best, best_choose}; main_stat_missing {slot_label, wanted};
 * main_stat_wrong {slot_label, stat, wanted}; item_level {slot_label, level, max_level}; substats_weak
 * {slot_label, percent, stats}; target_below {stat, stat_key, current, min}; talent_level {talent, level};
 * character_level {level}. `best` is a set option without its "(any N)", `best_choose` that N or null.
 */
export type RecommendationKind =
  | 'weapon_none'
  | 'weapon_level'
  | 'weapon_unlisted'
  | 'weapon_unlisted_owned'
  | 'weapon_unlisted_owned_on'
  | 'weapon_better_owned'
  | 'weapon_better_owned_on'
  | 'weapon_low_rank'
  | 'sets_missing'
  | 'sets_missing_empty'
  | 'sets_lower_option'
  | 'main_stat_missing'
  | 'main_stat_wrong'
  | 'item_level'
  | 'substats_weak'
  | 'target_below'
  | 'talent_level'
  | 'character_level';

export interface Recommendation {
  severity: Severity;
  category: 'weapon' | 'sets' | 'main_stats' | 'substats' | 'targets' | 'talents' | 'levels';
  /** A RecommendationKind, or one a newer server added. */
  kind: string;
  params: KindParams;
  /** The same in English. */
  message: string;
  /** The slot's key, when the recommendation is about one piece. */
  slot?: string;
}

export interface ScoredSubstat {
  key: string;
  value: number;
  rolls: number | null;
  weight: number;
  roll_value: number;
}

export interface ItemScore {
  score: number;
  ideal: number;
  percent: number;
  grade: Grade;
  useful_rolls: number;
  substats: ScoredSubstat[];
}

export interface AnalyzedItem extends ItemScore {
  id: number;
  slot: string;
  set: { key: string; name: string; icon_url: string | null };
  rarity: number;
  level: number;
  max_level: number;
  main_stat: string;
  main_value: number | null;
  main_ok: boolean | null;
}

export interface OwnedBetterWeapon {
  key: string;
  name: string;
  rank: number;
  refinement: number;
  level: number;
  equipped_on: string | null;
}

export interface Evaluation {
  build: {
    id: number;
    role: string;
    qualifier: string;
    min_rank: number;
    /** Whether the character has the constellation the build needs: one they have not is judged, never their best. */
    applies: boolean;
    position: number;
    game_version: string | null;
    checked_at: string | null;
  };
  score: { overall: number | null; grade: Grade; parts: ScoreParts };
  weapon: {
    score: number;
    equipped: (EquippedWeapon & { key: string }) | null;
    rank: number | null;
    recommended_refinement?: number | null;
    owned_better: OwnedBetterWeapon[];
    total: number;
  } | null;
  sets: {
    score: number;
    groups: {
      kind: 'artifact';
      score: number;
      matched_rank: number | null;
      equipped: SetCount[];
      best: string;
    }[];
  } | null;
  main_stats: {
    score: number;
    slots: Record<string, { current: string | null; recommended: string[]; score: number; ok: boolean }>;
  } | null;
  items: Record<string, AnalyzedItem>;
  targets: StatTarget[];
  substat_weights: Record<string, number>;
  recommendations: Recommendation[];
}

export interface Suggestion {
  slot: string;
  reason: 'better_substats' | 'completes_set' | 'fixes_main_stat';
  gain: number;
  current_percent: number | null;
  item: ItemScore & {
    id: number;
    set: { key: string; name: string; icon_url: string | null };
    rarity: number;
    level: number;
    main_stat: string;
    main_value: number | null;
    equipped_on: string | null;
  };
}

/** How to switch to the build's top set with pieces already owned. */
export interface SetPlan {
  kind: 'artifact';
  set: string;
  needed: number;
  owned: number;
  pieces: (Suggestion['item'] & { slot: string })[];
}

export interface CharacterAnalysis {
  account: AccountSummary;
  character: CharacterSummary & {
    talents: Record<string, number | Record<string, number>> | null;
    final_stats: Record<string, number> | null;
    final_stats_source: ImportKind | null;
    synced_at: string | null;
  };
  equipment: { totals: Record<string, number>; crit_value: number; slots: string[] };
  evaluations: Evaluation[];
  best_build_id: number | null;
  focus_build_id: number | null;
  suggestions: Suggestion[];
  set_plans: SetPlan[];
  has_full_inventory: boolean;
  builds: Build[];
}

// ------------------------------------------------------------------ the admin panel's Builds page

/** A list of the game catalog, refreshed on its own. */
export type CatalogList = 'characters' | 'weapons' | 'sets';

/** A list of the catalog as the Builds page shows it: its entries, when it was last refreshed (null: not yet). */
export interface CatalogListStatus {
  list: CatalogList;
  entries: number;
  refreshed_at: string | null;
}

/** What one import did (its summary), when, from a file made when, by whom (null: a deleted person's, or nobody's). */
export interface BuildImportEntry {
  id: number;
  created_at: string | null;
  file_exported_at: string | null;
  summary: { characters: number; builds: number; created: number; updated: number; unchanged: number; removed: number; skipped: number };
  imported_by: { id: number; name: string } | null;
}

/** GET /api/admin/builds: a game's builds, its latest imports and its catalog's lists (editors). */
export interface AdminBuildsGame {
  game: GameKey;
  characters: number;
  builds: number;
  checked_at: string | null;
  changed_at: string | null;
  imports: BuildImportEntry[];
  catalog: CatalogListStatus[];
}

/** One build an import creates, updates or removes, by its character and variation. */
export interface BuildChange {
  change: 'created' | 'updated' | 'removed';
  character: { key: string; name: string };
  role: string;
  qualifier: string;
  min_rank: number;
}

/** POST /api/admin/builds/{game}/import[?dry_run=1]: what importing a builds file does (dry run) or did. */
export interface BuildImportPlan {
  dry_run: boolean;
  game: GameKey;
  exported_at: string;
  /** The file is older than the one the builds there are now came from. */
  older_than_current: boolean;
  /** Characters and builds of the file this installation has. */
  characters: number;
  builds: number;
  created: number;
  updated: number;
  unchanged: number;
  removed: number;
  /** Characters of the file this installation's catalog lacks: skipped until the catalog is refreshed. */
  skipped: { key: string; name: string; builds: number }[];
  /** The changes, the first MAX of them; changes_listed says how many. */
  changes: BuildChange[];
  changes_listed: number;
}

/** POST /api/admin/catalog/{game}/{list}: how many entries the refresh wrote, and the list afterwards. */
export interface CatalogRefreshResult extends CatalogListStatus {
  written: number;
}

// ------------------------------------------------------------------ visitors and a person's own data

/**
 * What POST /api/visitor/move did: the visitor's accounts moved into the person's, and the ones they both had
 * (kept, the person's copy) - or, after a silent sign-in into an account that has data here, nothing (held:
 * the visitor stays as it is, its key this browser's).
 */
export interface VisitorMove {
  found: boolean;
  moved: number;
  kept: number;
  imports: number;
  held: boolean;
}

/** What importing the own-data file does (dry run) or did, to each game account in it. */
export type DataOutcome = 'added' | 'replaced' | 'kept';

/** POST /api/me/import[?dry_run=1]. */
export interface DataImportResult {
  dry_run: boolean;
  added: number;
  replaced: number;
  kept: number;
  skipped: number;
  accounts: { game: GameKey; uid: string | null; nickname: string | null; outcome: DataOutcome; skipped: number }[];
}
