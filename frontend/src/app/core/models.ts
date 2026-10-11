import type { AuthBootConfig } from '@anotoki/lib/auth';

export type GameKey = 'genshin' | 'hsr';
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
    source: string;
    variant: string;
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
 * a failed run's `error_code`. Their parameters: account_game_mismatch {account_game, data_game},
 * account_uid_mismatch {data_uid, account_uid}, showcase_http {status}, file_game_mismatch {file_game,
 * chosen_game}; the others have none.
 */
export type ImportErrorCode =
  | 'account_missing'
  | 'account_game_mismatch'
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
  | 'showcase_empty_hsr'
  | 'good_not_good'
  | 'good_empty'
  | 'hsr_not_export'
  | 'hsr_empty'
  | 'file_not_json'
  | 'file_unknown_format'
  | 'file_game_mismatch';

/**
 * What an import that went through warns about (the server's ImportKinds::WARNINGS). Their parameters:
 * unknown_character {ref}, unknown_weapon {weapon_label, ref}, unknown_set {ref}, good_artifacts_skipped
 * {count}, hsr_relics_skipped {count}; the others have none.
 */
export type ImportWarningCode =
  | 'unknown_character'
  | 'unknown_weapon'
  | 'unknown_set'
  | 'catalog_behind'
  | 'showcase_artifact_skipped'
  | 'showcase_relic_skipped'
  | 'good_traveler_no_element'
  | 'good_artifacts_skipped'
  | 'hsr_relics_skipped';

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
  kind: 'artifact' | 'relic' | 'planar';
  choose: number | null;
  pieces: SetPiece[];
  note: string | null;
}

/** A build as a source recommends it. The source is "Source N"; the page it was read from is not sent. */
export interface Build {
  id: number;
  source: { key: string; name: string };
  variant: string;
  role: string | null;
  recommended: boolean;
  weapons: BuildWeapon[];
  sets: SetOption[];
  main_stats: Record<string, StatPriority[]>;
  substats: StatPriority[];
  talent_priority: TalentPriority[] | null;
  stat_targets: StatTarget[] | null;
  notes: string | null;
  source_updated_at: string | null;
  game_version: string | null;
  fetched_at: string | null;
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
  sources: string[];
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
    source: { key: string; name: string };
    variant: string;
    role: string | null;
    recommended: boolean;
    source_updated_at: string | null;
    game_version: string | null;
    fetched_at: string | null;
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
      kind: 'artifact' | 'relic' | 'planar';
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

/** How to switch to the source's top set with pieces already owned. */
export interface SetPlan {
  kind: 'artifact' | 'relic' | 'planar';
  set: string;
  needed: number;
  owned: number;
  pieces: (Suggestion['item'] & { slot: string })[];
}

export interface CountedName {
  name: string;
  count: number;
}

/**
 * A set option counted across the builds. `name` is the label it was counted by (English, with its
 * "(any N)"); `pieces` is that option without the "(any N)" and `choose` that N, or null.
 */
export interface CountedSet extends CountedName {
  pieces: string;
  choose: number | null;
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
  consensus: {
    builds: number;
    sources: string[];
    main_stats: Record<string, CountedName[]>;
    sets: Record<string, CountedSet[]>;
    weapons: CountedName[];
  };
  suggestions: Suggestion[];
  set_plans: SetPlan[];
  has_full_inventory: boolean;
  builds: Build[];
}

// ------------------------------------------------------------------ sources

/** A build sync of one source ("builds") or a catalog refresh of one game ("catalog"). */
export interface SyncRunSummary {
  id: number;
  kind: 'builds' | 'catalog';
  game: GameKey | null;
  status: 'running' | 'success' | 'partial' | 'failed' | 'cancelled';
  started_at: string;
  finished_at: string | null;
  stats: Record<string, unknown> | null;
  /** While running: units done of total (characters, or catalog lists); total is null until listed. */
  progress: { done: number; total: number | null } | null;
  /** The next step may not start before this (the site's crawl delay). */
  next_step_at: string | null;
  last_step_at: string | null;
  started_by: { id: number; name: string } | null;
  source: { key: string; name: string; game: GameKey } | null;
  log?: string | null;
}

/** The syncs going on now (GET /api/sync). */
export interface SyncStatus {
  active: boolean;
  /** Milliseconds until the next step has work (0 = now); null when nothing is running. */
  wait_ms: number | null;
  runs: SyncRunSummary[];
}

/** What one step did (POST /api/sync/step). */
export interface SyncStepResult extends SyncStatus {
  /** Another step (another tab, the command line) was running; nothing was done. */
  busy: boolean;
  did: SyncRunSummary | null;
  lines: string[];
}

export interface SyncStartResult extends SyncStatus {
  started: SyncRunSummary[];
  skipped: { key: string; name: string; reason: string }[];
}

/** A build source as the API shows it: "Source 1" with a key like "genshin-1", never the site. */
export interface SourceStatus {
  key: string;
  game: GameKey;
  name: string;
  enabled: boolean;
  last_synced_at: string | null;
  last_status: string | null;
  last_error: string | null;
  builds: number;
  characters: number;
  running: boolean;
  latest_run: SyncRunSummary | null;
}

/** What importing a builds file into a source did (POST /api/sources/{key}/import, ADMIN). */
export interface BuildImportResult {
  created: number;
  updated: number;
  unchanged: number;
  removed: number;
  /** Characters whose builds were written. */
  characters: number;
  /** Characters of the file this installation's catalog lacks: skipped until the catalog is refreshed. */
  skipped: { key: string; name: string }[];
  /** The source afterwards, as GET /api/sources lists it. */
  source: SourceStatus;
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
