export type GameKey = 'genshin' | 'hsr';
export type Severity = 'high' | 'medium' | 'low';
export type Grade = 'S' | 'A' | 'B' | 'C' | 'D' | '-';

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
    top_issue: string | null;
    issues: number;
  } | null;
}

export interface AccountDetail extends AccountSummary {
  characters: CharacterOverview[];
}

export interface ImportRun {
  id: number;
  game: GameKey;
  source: string;
  status: 'success' | 'partial' | 'failed';
  file_name: string | null;
  summary: {
    characters: number;
    weapons: number;
    items: number;
    full_inventory: boolean;
    tool: string | null;
    warnings: string[];
    unmatched: Record<string, string[]>;
  } | null;
  error: string | null;
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

export interface Build {
  id: number;
  source: { key: string; name: string; base_url: string };
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
  source_url: string | null;
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

export interface Recommendation {
  severity: Severity;
  category: 'weapon' | 'sets' | 'main_stats' | 'substats' | 'targets' | 'talents' | 'levels';
  message: string;
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
    source_url: string | null;
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

export interface CharacterAnalysis {
  account: AccountSummary;
  character: CharacterSummary & {
    talents: Record<string, number | Record<string, number>> | null;
    final_stats: Record<string, number> | null;
    final_stats_source: string | null;
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
    sets: Record<string, CountedName[]>;
    weapons: CountedName[];
  };
  suggestions: Suggestion[];
  set_plans: SetPlan[];
  has_full_inventory: boolean;
  builds: Build[];
}

// ------------------------------------------------------------------ sources

export interface SyncRunSummary {
  id: number;
  status: 'running' | 'success' | 'partial' | 'failed';
  started_at: string;
  finished_at: string | null;
  stats: Record<string, unknown> | null;
  source?: { key: string; name: string; game: GameKey };
  log?: string | null;
}

export interface SourceStatus {
  key: string;
  game: GameKey;
  name: string;
  base_url: string;
  enabled: boolean;
  last_synced_at: string | null;
  last_status: string | null;
  last_error: string | null;
  builds: number;
  characters: number;
  running: boolean;
  latest_run: SyncRunSummary | null;
}
