import type { AuthBootConfig } from '@anotoki/lib/auth';

export type GameKey = 'genshin';
export type Severity = 'high' | 'medium' | 'low';
export type Grade = 'S' | 'A' | 'B' | 'C' | 'D' | '-';

// ------------------------------------------------------------------ errors

/**
 * What an error says happened, for a page to word: every error a member can meet carries one beside the
 * server's English `message`. (An import's 422 carries an ImportErrorCode instead; the refusals of the
 * admin-only routes carry none.) `not_set_up` and `update_pending` are the site gate's: no page words them -
 * the gate shows its own page in the site's place as soon as one arrives (shared/site-status.ts).
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
   * instead of the site's (shared/site-status.ts): where the setup page is, while the site was never installed.
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
  /** The build the character is judged by: the one the player chose, else its best - the closest match. */
  build: {
    build_id: number;
    /** Whether the player chose it ("Use this build"); false: it is the closest match. */
    chosen: boolean;
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

/**
 * A note of the site's own (builds.site_notes): written by its editors in the builds editor - in English, and in Slovak
 * where they wrote it. Shown in the reader's language where it is there, else in English (shared/i18n/site-note.ts).
 */
export interface SiteNote {
  en: string;
  sk?: string;
}

export interface StatPriority {
  stat: string;
  priority: number;
}

export interface TalentPriority {
  talent: string;
  priority: number;
}

export type TargetTier = 'basic' | 'intermediate' | 'advanced';

export const TARGET_TIERS: readonly TargetTier[] = ['basic', 'intermediate', 'advanced'];

/**
 * A stat target: a requirement the build states (`tiers` null: its `min` and `max` as stated, `from_rank` the
 * constellation it holds from) or a stat worked out in three tiers from the game's numbers (`tiers`). With an
 * evaluation on a character that has in-game totals: `current`, `ok` (a requirement met, or the basic tier
 * reached) and the tier reached.
 */
export interface StatTarget {
  stat: string;
  tiers?: Record<TargetTier, number> | null;
  min: number | null;
  max: number | null;
  /** The constellation the target holds from (0: from the start); null on a tiered stat. */
  from_rank?: number | null;
  current?: number | null;
  ok?: boolean | null;
  tier?: TargetTier | null;
}

/**
 * What a build's tiered targets rest on: the weapon, the set option in the game's notation ("4pc Blizzard Strayer";
 * null for a build that lists no sets) and the main stats of the three variable slots.
 */
export interface TargetsBasis {
  weapon: string;
  sets: string | null;
  main_stats: Record<string, string>;
}

export interface BuildWeapon {
  key: string;
  name: string;
  rank: number;
  refinement: number | null;
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
  /** The targets the build states, as stated. */
  stat_targets: StatTarget[] | null;
  /**
   * The build's targets as the builds browser shows them: the stated ones and every substat in three tiers, for the
   * first weapon the catalog knows and the build's own main stats; null while the catalog lacks the stats behind them.
   */
  targets: { rows: StatTarget[]; basis: TargetsBasis } | null;
  /** The teams the build is played in, best first; [] for none. */
  teams: BuildTeam[];
  /** The site's own note on the build; null for none. */
  notes: SiteNote | null;
  game_version: string | null;
  /** When the file that last carried the build was made; null for one no file has carried since it was made or brought back here. */
  checked_at: string | null;
  changed_at: string | null;
}

/**
 * A character a team names - a member or one who can take a member's place - as the catalog knows it now: its name
 * the catalog's where it is there (`known`), and whether it has builds to link to.
 */
export interface Teammate {
  key: string;
  name: string;
  known: boolean;
  icon_url: string | null;
  rarity: number | null;
  element: string | null;
  has_builds: boolean;
}

/** A team's member: the role it plays (a BuildRole, or null), the constellation the team asks of it, who can stand in. */
export interface TeamMember extends Teammate {
  role: string | null;
  min_rank: number;
  /** The build's own character - one member of each of its teams. */
  own: boolean;
  alternatives: Teammate[];
}

/** A team a build is played in: its name as the builds file has it (stored English), its members in order, the site's own note on it. */
export interface BuildTeam {
  name: string;
  members: TeamMember[];
  note: SiteNote | null;
}

export interface BuildCharacter {
  key: string;
  name: string;
  element: string | null;
  rarity: number | null;
  weapon_type: string | null;
  icon_url: string | null;
  released_at: string | null;
  /**
   * What the builds browser filters by besides the element, the weapon and the rarity - game terms, never translated:
   * the character's region ("Liyue"), the version it came with as the game numbers it ("6.1") and that version's name
   * where it has one ("Luna II").
   */
  region: string | null;
  version: string | null;
  version_name: string | null;
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
  /** The targets for this character: tiered for the weapon in hand where the catalog knows the stats, else the stated ones. */
  targets: StatTarget[];
  /** What the tiers rest on; null while the targets are the stated ones alone. */
  targets_basis: TargetsBasis | null;
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
    /**
     * The sheet's totals worked out from the gear (the character at its level, the weapon, the artifacts, the
     * 2-piece bonuses) for a character without in-game totals - what its stat targets are compared with; null with
     * in-game totals, or while the catalog keeps no stats of the character.
     */
    computed_stats: Record<string, number> | null;
    synced_at: string | null;
  };
  equipment: { totals: Record<string, number>; crit_value: number; slots: string[] };
  evaluations: Evaluation[];
  /** The closest match: of the builds the character's constellation reaches, the best scored. */
  best_build_id: number | null;
  /** The build the player chose to judge the character by; null: the closest match judges it. */
  chosen_build_id: number | null;
  /** The build the suggestions are for: the one asked for, else the chosen one, else the closest match. */
  focus_build_id: number | null;
  suggestions: Suggestion[];
  set_plans: SetPlan[];
  has_full_inventory: boolean;
  builds: Build[];
  /** Which of the builds' teammates the account has, by key, with each one's constellation (the character itself left out). */
  owned_teammates: Record<string, number | null>;
}

// ------------------------------------------------------------------ the admin panel's Builds page

/** A list of the game catalog, refreshed on its own. */
export type CatalogList = 'characters' | 'weapons' | 'sets';

/**
 * A list of the catalog as the Builds page shows it: its entries, when the game data provider last confirmed it (null:
 * not yet), and why its last refresh failed - the Builds page's own or the upkeep's after an answer - while it has not
 * worked since (English, as the admin panel).
 */
export interface CatalogListStatus {
  list: CatalogList;
  entries: number;
  refreshed_at: string | null;
  problem: string | null;
}

/** What one import did (its summary), when, from a file made when, by whom (null: a deleted person's, or nobody's). */
export interface BuildImportEntry {
  id: number;
  created_at: string | null;
  file_exported_at: string | null;
  /** What it did; the hand edits' counts since 0.7 (an import recorded before has none). */
  summary: {
    characters: number;
    builds: number;
    created: number;
    updated: number;
    unchanged: number;
    removed: number;
    skipped: number;
    edits_kept?: number;
    made_here?: number;
    kept_removed?: number;
    file_taken?: number;
  };
  imported_by: { id: number; name: string } | null;
}

/**
 * GET /api/admin/builds: a game's builds, its latest imports, its catalog's lists, and the characters with builds with
 * what the editors did to them (editors).
 */
export interface AdminBuildsGame {
  game: GameKey;
  characters: number;
  builds: number;
  checked_at: string | null;
  changed_at: string | null;
  imports: BuildImportEntry[];
  catalog: CatalogListStatus[];
  editing: EditingOverview[];
}

/**
 * A character with builds - or with builds an editor removed - on the Builds page (BuildEditor::overview()): how many
 * builds, how many of the file's carry an edit, how many were made here, removed, have notes, and whether their order
 * is an editor's.
 */
export interface EditingOverview {
  key: string;
  name: string;
  icon_url: string | null;
  builds: number;
  edited: number;
  made_here: number;
  removed: number;
  notes: number;
  ordered: boolean;
}

/** A build as an import's answer names it: its character and variation. */
export interface BuildVariationRef {
  character: { key: string; name: string };
  role: string;
  qualifier: string;
  min_rank: number;
}

/** One build an import creates, updates or removes, by its character and variation. */
export interface BuildChange extends BuildVariationRef {
  change: 'created' | 'updated' | 'removed';
}

/** A part of a build an import compares by itself with the file's (backend: BuildParts::PARTS). */
export type BuildPart = 'weapons' | 'sets' | 'main_stats' | 'substats' | 'talent_priority' | 'stat_targets' | 'teams' | 'game_version';

/**
 * A choice an import needs, for one build (docs/builds.md, "Hand edits"). Unanswered, the editors' work stands.
 * - edited: the file and an editor both changed `parts` of a build - the file's version of `taken`, which only the
 *   file changed, is taken all the same;
 * - made_here: the file brings a build an editor made here, unlike it in `parts`;
 * - removed_here: the file brings a build an editor removed, changed in `parts`;
 * - removed_by_file: the file lacks a build an editor edited (`parts`) or wrote notes on (`notes`).
 */
export interface BuildQuestion extends BuildVariationRef {
  id: string;
  kind: 'edited' | 'made_here' | 'removed_here' | 'removed_by_file';
  parts: BuildPart[];
  taken: BuildPart[];
  notes: boolean;
}

/** A build an import makes of an editor's edit and the file's changes without asking: the parts it keeps and takes. */
export interface BuildMerged extends BuildVariationRef {
  kept: BuildPart[];
  taken: BuildPart[];
}

/** A build whose team notes an import leaves without their team: they wait for a team of those members. */
export interface BuildNotesWaiting extends BuildVariationRef {
  notes: number;
}

/** The questions of an import's preview answered with the file - their ids, or all -, with the preview's plan. */
export interface BuildImportChoices {
  file: readonly string[] | 'all';
  plan: string;
}

/** POST /api/admin/builds/{game}/import[?dry_run=1]: what importing a builds file does (dry run) or did. */
export interface BuildImportPlan {
  dry_run: boolean;
  game: GameKey;
  exported_at: string;
  /** The file is older than the one the builds there are now came from. */
  older_than_current: boolean;
  /** Characters and builds of the file this installation has, and the teams of those builds. */
  characters: number;
  builds: number;
  teams: number;
  created: number;
  updated: number;
  unchanged: number;
  removed: number;
  /** Builds of the file's that keep an editor's edit afterwards. */
  edits_kept: number;
  /** Builds the editors' own afterwards, which no file carries: made here, or kept when the file dropped them. */
  made_here: number;
  /** Builds an editor removed that the file brings: they stay removed. */
  kept_removed: number;
  /** Questions answered with the file (none in a dry run). */
  file_taken: number;
  /** Characters of the file this installation's catalog lacks: skipped until the catalog is refreshed. */
  skipped: { key: string; name: string; builds: number }[];
  /** Teammates of the builds imported that the catalog lacks, the first 50: shown by their names; no build is skipped for one. */
  teammates_unknown: { key: string; name: string }[];
  /** How many teammates the catalog lacks, all told. */
  teammates_unknown_total: number;
  /** What the file and the editors' work disagree on: a choice each. */
  questions: BuildQuestion[];
  /** The questions' plan: sent back with the choices, so the import is refused (409 plan_changed) when they changed. */
  plan: string;
  /** Builds made of an editor's edit and the file's changes without asking. */
  merged: BuildMerged[];
  /** Characters whose builds an editor ordered: their order stays where the file's differs. */
  order_kept: { key: string; name: string }[];
  /** Builds whose team notes lose their team. */
  notes_dormant: BuildNotesWaiting[];
  /** The changes, the first MAX of them; changes_listed says how many. */
  changes: BuildChange[];
  changes_listed: number;
}

/**
 * POST /api/admin/catalog/{game}/{list}: whether the list changed since its last refresh (unchanged: the provider said
 * it is as it was, nothing written), how many entries the refresh wrote, and the list afterwards. A refresh that found
 * the provider closed, without the list or failing is an error answer (502, its sentence).
 */
export interface CatalogRefreshResult extends CatalogListStatus {
  outcome: 'changed' | 'unchanged';
  written: number;
}

// ------------------------------------------------------------------ the admin panel's builds editor

/** A weapon of a build as the editor edits it: the builds file's, without its note. */
export interface EditorWeapon {
  key: string;
  name: string;
  refinement: number | null;
}

/** A piece of a set option: how many pieces (2 or 4) of any of its sets, by key and name; its label as shown. */
export interface EditorSetPiece {
  label: string;
  count: number;
  keys: string[];
  names: string[];
}

/** A set option of a build: its kind, how many of its pieces must be met (null: all), its pieces. */
export interface EditorSetOption {
  kind: string;
  choose: number | null;
  pieces: EditorSetPiece[];
}

/** A stat target as the editor edits it: its numbers alone, from a constellation on. */
export interface EditorTarget {
  stat: string;
  min: number | null;
  max: number | null;
  from_rank: number;
}

/** A character a team names, by key and name. */
export interface EditorTeammate {
  key: string;
  name: string;
}

/** A team's member: the role it plays (null: none said), the constellation the team asks of it, who can stand in. */
export interface EditorMember extends EditorTeammate {
  role: string | null;
  min_rank: number;
  alternatives: EditorTeammate[];
}

export interface EditorTeam {
  name: string;
  members: EditorMember[];
}

/**
 * A build in the editor's shape (backend: BuildEditor::shape()): the builds file's - every text neutral, each list in
 * its order -, without its character and the texts the site no longer shows. A save sends it back so.
 */
export interface EditorBuild {
  role: string;
  qualifier: string;
  min_rank: number;
  weapons: EditorWeapon[];
  sets: EditorSetOption[];
  main_stats: Record<string, StatPriority[]>;
  substats: StatPriority[];
  talent_priority: TalentPriority[] | null;
  stat_targets: EditorTarget[] | null;
  teams: EditorTeam[];
  game_version: string | null;
}

/** A team's note whose team is gone: it waits for a team of these members (their keys, sorted, one space apart). */
export interface WaitingNote {
  key: string;
  members: EditorTeammate[];
  note: SiteNote;
}

/** A build's notes as the editor edits them: the build's, each team's in the teams' order (null: none), the waiting ones. */
export interface EditorNotes {
  build: SiteNote | null;
  teams: (SiteNote | null)[];
  waiting: WaitingNote[];
}

/** A stored build as the editor gets it (BuildEditor::load()). */
export interface EditorEntry {
  id: number;
  /** From a builds file, or made here (no file has it). */
  origin: 'file' | 'made_here';
  build: EditorBuild;
  /** What the last builds file said of it, for "Use the file's"; null for one made here. */
  file: EditorBuild | null;
  /** Where the file puts it among the character's builds; null for one made here. */
  file_position: number | null;
  /** The parts that differ from what the file said of it. */
  edited: BuildPart[];
  notes: EditorNotes;
  checked_at: string | null;
  changed_at: string | null;
  edited_at: string | null;
}

/** A build of the file's an editor removed: remembered with what the file said of it and its notes, to be brought back. */
export interface RemovedBuild {
  id: number;
  build: EditorBuild;
  file_position: number;
  notes: EditorNotes;
  removed_at: string | null;
}

/** A character as the editor names it. */
export interface EditorCharacter {
  key: string;
  name: string;
  element: string | null;
  weapon_type: string | null;
  rarity: number | null;
  icon_url: string | null;
}

/** GET (and PUT's answer) /api/admin/builds/{game}/characters/{key}: a character's builds as the editor edits them. */
export interface CharacterEditing {
  game: GameKey;
  character: EditorCharacter;
  /** What a save is checked against: the builds as they were loaded. */
  version: string;
  /** Whose order the builds are in: the file's, or an editor's (said, never guessed). */
  order: 'file' | 'editors';
  builds: EditorEntry[];
  removed: RemovedBuild[];
}

/** A build of a save: the stored one it is (null: new), the removed one it brings back (or null), its notes. */
export interface EditorSaveBuild {
  id: number | null;
  removal: number | null;
  build: EditorBuild;
  notes: { build: SiteNote | null; teams: (SiteNote | null)[]; waiting: { key: string; note: SiteNote }[] };
}

/** PUT /api/admin/builds/{game}/characters/{key}: the character's builds as the editor leaves them, in their order. */
export interface EditorSave {
  version: string;
  builds: EditorSaveBuild[];
  /** "file": the order given back to the file. */
  order?: 'file';
}

/** Why a save was refused (422, 409): the server's sentence, its code, the build of the save and the path in it. */
export interface EditorRefusal {
  message: string;
  /** invalid_body, invalid_build, invalid_note, names_another_site (422); busy, changed (409). */
  code?: string;
  build?: number | null;
  path?: string | null;
}

/** The caps a build is held to: the builds file's (BuildFile::limits()) and the editor's. */
export interface EditorLimits {
  qualifier: number;
  weapons: number;
  refinement: number;
  sets: number;
  pieces: number;
  set_keys: number;
  stats: number;
  talents: number;
  priority: number;
  targets: number;
  bound: number;
  teams: number;
  team_name: number;
  alternatives: number;
  name: number;
  game_version: number;
  note_build: number;
  note_team: number;
  builds: number;
}

/** The game's facts the editor holds a build to (BuildEditor::facts()), with their English names. */
export interface EditorFacts {
  roles: string[];
  max_rank: number;
  rank_prefix: string;
  refinement_prefix: string;
  /** Each slot whose main stat varies, with the main stats a build may give it. */
  slots: Record<string, string[]>;
  substats: string[];
  stats: string[];
  talents: string[];
  /** Each kind of set, with the piece counts it has. */
  set_kinds: Record<string, number[]>;
  team_size: number;
  labels: { stats: Record<string, string>; talents: Record<string, string>; slots: Record<string, string>; set_kinds: Record<string, string> };
  limits: EditorLimits;
}

/** A weapon of the catalog, to pick. */
export interface EditorCatalogWeapon {
  key: string;
  name: string;
  weapon_type: string | null;
  rarity: number | null;
  icon_url: string | null;
}

/** A set of the catalog, to pick. */
export interface EditorCatalogSet {
  key: string;
  name: string;
  kind: string;
  icon_url: string | null;
}

/** GET /api/admin/builds/{game}/catalog: what the editor picks from and holds a build to, the catalog's by name. */
export interface EditorCatalog {
  game: GameKey;
  facts: EditorFacts;
  characters: EditorCharacter[];
  weapons: EditorCatalogWeapon[];
  sets: EditorCatalogSet[];
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
