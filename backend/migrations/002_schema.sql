-- ============================================================================
-- 002_schema.sql - the build analyzer's tables
-- ============================================================================
-- On a new database this creates everything. On one brought forward from the
-- Laravel version by 001, the tables are already there in the same shape and
-- every CREATE ... IF NOT EXISTS here is a no-op. The names follow the Laravel
-- convention ({table}_{columns}_unique, _foreign, _index) so both ways end in the
-- same database, down to the constraint names - and the gate at the end checks
-- that, column by column, whichever way the database got here.
--
-- Times are TIMESTAMPTZ, written and read in UTC. Nested build data (weapons,
-- sets, stat priorities), substats and summaries are JSON (not JSONB: the order
-- of object keys, such as the slots of a build's main stats, is kept as written).
-- ============================================================================

-- ── People ──────────────────────────────────────────────────────────────────
-- The anotoki IAM owns identity. A row here is only an IAM user id (the access
-- token's `sub`) and a copy of their preferred_username for display, made on the
-- first request and refreshed from later tokens. Roles are never stored: they
-- come with each token.
CREATE TABLE IF NOT EXISTS users (
    id          BIGINT          NOT NULL,
    name        VARCHAR(100)    NOT NULL,
    created_at  TIMESTAMPTZ,
    updated_at  TIMESTAMPTZ,
    CONSTRAINT users_pkey PRIMARY KEY (id)
);

-- ── The game catalog ────────────────────────────────────────────────────────
-- Shared by everybody, filled by catalog runs. `key` is the normalized English
-- name (App\Support\Key), `external_id` the in-game ID; meta.ids lists more IDs
-- for the Travelers and Trailblazers, meta.bonuses the Genshin set bonus texts.
CREATE TABLE IF NOT EXISTS game_characters (
    id          BIGSERIAL,
    game        VARCHAR(16)     NOT NULL,
    key         VARCHAR(120)    NOT NULL,
    name        VARCHAR(160)    NOT NULL,
    element     VARCHAR(20),
    rarity      SMALLINT,
    weapon_type VARCHAR(30),                -- Genshin weapon type or HSR path
    external_id VARCHAR(40),
    icon_url    VARCHAR(500),
    released_at TIMESTAMPTZ,
    meta        JSON,
    created_at  TIMESTAMPTZ,
    updated_at  TIMESTAMPTZ,
    CONSTRAINT game_characters_pkey PRIMARY KEY (id),
    CONSTRAINT game_characters_game_key_unique UNIQUE (game, key)
);
CREATE INDEX IF NOT EXISTS game_characters_game_external_id_index ON game_characters (game, external_id);

CREATE TABLE IF NOT EXISTS game_weapons (
    id          BIGSERIAL,
    game        VARCHAR(16)     NOT NULL,
    key         VARCHAR(160)    NOT NULL,
    name        VARCHAR(200)    NOT NULL,
    rarity      SMALLINT,
    weapon_type VARCHAR(30),
    external_id VARCHAR(40),
    icon_url    VARCHAR(500),
    meta        JSON,
    created_at  TIMESTAMPTZ,
    updated_at  TIMESTAMPTZ,
    CONSTRAINT game_weapons_pkey PRIMARY KEY (id),
    CONSTRAINT game_weapons_game_key_unique UNIQUE (game, key)
);
CREATE INDEX IF NOT EXISTS game_weapons_game_external_id_index ON game_weapons (game, external_id);

CREATE TABLE IF NOT EXISTS game_sets (
    id          BIGSERIAL,
    game        VARCHAR(16)     NOT NULL,
    key         VARCHAR(160)    NOT NULL,
    name        VARCHAR(200)    NOT NULL,
    kind        VARCHAR(16)     NOT NULL,   -- artifact | relic | planar
    external_id VARCHAR(40),
    icon_url    VARCHAR(500),
    meta        JSON,
    created_at  TIMESTAMPTZ,
    updated_at  TIMESTAMPTZ,
    CONSTRAINT game_sets_pkey PRIMARY KEY (id),
    CONSTRAINT game_sets_game_key_unique UNIQUE (game, key)
);
CREATE INDEX IF NOT EXISTS game_sets_game_external_id_index ON game_sets (game, external_id);

-- ── Game accounts, each of one person ───────────────────────────────────────
-- (user_id, game, uid) is unique, so two people can import the same UID, each
-- into an account of their own. A file import has no UID. (user_id comes last
-- because that is where the first version added it; the order is the same
-- whichever way a database was made.)
CREATE TABLE IF NOT EXISTS accounts (
    id                  BIGSERIAL,
    game                VARCHAR(16)     NOT NULL,
    uid                 VARCHAR(20),
    nickname            VARCHAR(100),
    level               SMALLINT,               -- Adventure Rank / Trailblaze Level
    world_level         SMALLINT,               -- World Level / Equilibrium Level
    signature           VARCHAR(300),
    has_full_inventory  BOOLEAN         NOT NULL DEFAULT FALSE,
    last_import_at      TIMESTAMPTZ,
    meta                JSON,
    created_at          TIMESTAMPTZ,
    updated_at          TIMESTAMPTZ,
    user_id             BIGINT          NOT NULL,
    CONSTRAINT accounts_pkey PRIMARY KEY (id),
    CONSTRAINT accounts_user_id_game_uid_unique UNIQUE (user_id, game, uid),
    CONSTRAINT accounts_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

-- One import attempt; a failed one may have no account.
CREATE TABLE IF NOT EXISTS import_runs (
    id          BIGSERIAL,
    account_id  BIGINT,
    game        VARCHAR(16)     NOT NULL,
    source      VARCHAR(32)     NOT NULL,   -- enka | good | hsr_scanner | file (a file that could not be read)
    status      VARCHAR(16)     NOT NULL,   -- success | partial | failed
    file_name   VARCHAR(255),
    summary     JSON,
    error       TEXT,
    created_at  TIMESTAMPTZ,
    updated_at  TIMESTAMPTZ,
    user_id     BIGINT          NOT NULL,
    CONSTRAINT import_runs_pkey PRIMARY KEY (id),
    CONSTRAINT import_runs_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT import_runs_account_id_foreign FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS account_characters (
    id                  BIGSERIAL,
    account_id          BIGINT          NOT NULL,
    game_character_id   BIGINT          NOT NULL,
    level               SMALLINT        NOT NULL DEFAULT 1,
    ascension           SMALLINT,
    rank                SMALLINT        NOT NULL DEFAULT 0,     -- Constellation / Eidolon
    talents             JSON,
    final_stats         JSON,                                   -- in-game totals (showcase imports only)
    source              VARCHAR(32)     NOT NULL,
    synced_at           TIMESTAMPTZ,
    created_at          TIMESTAMPTZ,
    updated_at          TIMESTAMPTZ,
    CONSTRAINT account_characters_pkey PRIMARY KEY (id),
    CONSTRAINT account_characters_account_id_game_character_id_unique UNIQUE (account_id, game_character_id),
    CONSTRAINT account_characters_account_id_foreign FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE,
    CONSTRAINT account_characters_game_character_id_foreign FOREIGN KEY (game_character_id) REFERENCES game_characters (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS account_weapons (
    id                      BIGSERIAL,
    account_id              BIGINT          NOT NULL,
    game_weapon_id          BIGINT          NOT NULL,
    level                   SMALLINT        NOT NULL DEFAULT 1,
    ascension               SMALLINT,
    refinement              SMALLINT        NOT NULL DEFAULT 1,     -- Refinement / Superimposition
    equipped_character_id   BIGINT,
    locked                  BOOLEAN         NOT NULL DEFAULT FALSE,
    external_uid            VARCHAR(64),
    source                  VARCHAR(32)     NOT NULL,
    created_at              TIMESTAMPTZ,
    updated_at              TIMESTAMPTZ,
    CONSTRAINT account_weapons_pkey PRIMARY KEY (id),
    CONSTRAINT account_weapons_account_id_foreign FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE,
    CONSTRAINT account_weapons_game_weapon_id_foreign FOREIGN KEY (game_weapon_id) REFERENCES game_weapons (id) ON DELETE CASCADE,
    CONSTRAINT account_weapons_equipped_character_id_foreign FOREIGN KEY (equipped_character_id) REFERENCES game_characters (id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS account_weapons_account_id_equipped_character_id_index ON account_weapons (account_id, equipped_character_id);

-- Artifacts (Genshin) and relics (HSR). substats: [{key, value, rolls}].
CREATE TABLE IF NOT EXISTS account_items (
    id                      BIGSERIAL,
    account_id              BIGINT          NOT NULL,
    game_set_id             BIGINT          NOT NULL,
    slot                    VARCHAR(16)     NOT NULL,
    rarity                  SMALLINT        NOT NULL DEFAULT 5,
    level                   SMALLINT        NOT NULL DEFAULT 0,
    main_stat               VARCHAR(32)     NOT NULL,
    main_value              DOUBLE PRECISION,
    substats                JSON            NOT NULL,
    equipped_character_id   BIGINT,
    locked                  BOOLEAN         NOT NULL DEFAULT FALSE,
    external_uid            VARCHAR(64),
    source                  VARCHAR(32)     NOT NULL,
    created_at              TIMESTAMPTZ,
    updated_at              TIMESTAMPTZ,
    CONSTRAINT account_items_pkey PRIMARY KEY (id),
    CONSTRAINT account_items_account_id_foreign FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE,
    CONSTRAINT account_items_game_set_id_foreign FOREIGN KEY (game_set_id) REFERENCES game_sets (id) ON DELETE CASCADE,
    CONSTRAINT account_items_equipped_character_id_foreign FOREIGN KEY (equipped_character_id) REFERENCES game_characters (id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS account_items_account_id_slot_index ON account_items (account_id, slot);
CREATE INDEX IF NOT EXISTS account_items_account_id_equipped_character_id_index ON account_items (account_id, equipped_character_id);

-- ── Recommended builds, shared ──────────────────────────────────────────────
-- One row per scraper (an internal key such as "site.hsr": the API calls the
-- sources "genshin-1", "hsr-2" ... and the UI "Source 1", "Source 2").
CREATE TABLE IF NOT EXISTS build_sources (
    id              BIGSERIAL,
    key             VARCHAR(64)     NOT NULL,
    game            VARCHAR(16)     NOT NULL,
    name            VARCHAR(100)    NOT NULL,
    base_url        VARCHAR(255)    NOT NULL,
    enabled         BOOLEAN         NOT NULL DEFAULT TRUE,
    last_synced_at  TIMESTAMPTZ,
    last_status     VARCHAR(16),
    last_error      TEXT,
    created_at      TIMESTAMPTZ,
    updated_at      TIMESTAMPTZ,
    CONSTRAINT build_sources_pkey PRIMARY KEY (id),
    CONSTRAINT build_sources_key_unique UNIQUE (key)
);

-- A build is (source, character, variant). content_hash tells a changed build
-- from an unchanged one; `teams` is kept from the first version, not filled yet.
CREATE TABLE IF NOT EXISTS builds (
    id                  BIGSERIAL,
    build_source_id     BIGINT          NOT NULL,
    game_character_id   BIGINT          NOT NULL,
    variant             VARCHAR(120)    NOT NULL,
    role                VARCHAR(60),
    recommended         BOOLEAN         NOT NULL DEFAULT FALSE,
    "position"          SMALLINT        NOT NULL DEFAULT 0,
    weapons             JSON            NOT NULL,
    sets                JSON            NOT NULL,
    main_stats          JSON            NOT NULL,
    substats            JSON            NOT NULL,
    talent_priority     JSON,
    stat_targets        JSON,
    teams               JSON,
    notes               TEXT,
    source_url          VARCHAR(500),
    source_updated_at   TIMESTAMPTZ,
    game_version        VARCHAR(20),
    content_hash        VARCHAR(64)     NOT NULL,
    fetched_at          TIMESTAMPTZ,
    changed_at          TIMESTAMPTZ,
    created_at          TIMESTAMPTZ,
    updated_at          TIMESTAMPTZ,
    CONSTRAINT builds_pkey PRIMARY KEY (id),
    CONSTRAINT builds_build_source_id_game_character_id_variant_unique UNIQUE (build_source_id, game_character_id, variant),
    CONSTRAINT builds_build_source_id_foreign FOREIGN KEY (build_source_id) REFERENCES build_sources (id) ON DELETE CASCADE,
    CONSTRAINT builds_game_character_id_foreign FOREIGN KEY (game_character_id) REFERENCES game_characters (id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS builds_game_character_id_index ON builds (game_character_id);

-- A build sync of one source (kind builds) or a catalog refresh of one game
-- (kind catalog), worked through in steps: `state` is its place while it runs,
-- next_step_at the earliest next step its site allows (the crawl delay).
CREATE TABLE IF NOT EXISTS sync_runs (
    id              BIGSERIAL,
    build_source_id BIGINT,
    status          VARCHAR(16)     NOT NULL,   -- running | success | partial | failed | cancelled
    started_at      TIMESTAMPTZ     NOT NULL,
    finished_at     TIMESTAMPTZ,
    stats           JSON,
    log             TEXT,
    created_at      TIMESTAMPTZ,
    updated_at      TIMESTAMPTZ,
    kind            VARCHAR(16)     NOT NULL DEFAULT 'builds',
    game            VARCHAR(16),
    started_by      BIGINT,
    state           JSON,
    next_step_at    TIMESTAMPTZ,
    last_step_at    TIMESTAMPTZ,
    CONSTRAINT sync_runs_pkey PRIMARY KEY (id),
    CONSTRAINT sync_runs_build_source_id_foreign FOREIGN KEY (build_source_id) REFERENCES build_sources (id) ON DELETE CASCADE,
    CONSTRAINT sync_runs_started_by_foreign FOREIGN KEY (started_by) REFERENCES users (id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS sync_runs_status_kind_index ON sync_runs (status, kind);

-- ── Cache and locks ─────────────────────────────────────────────────────────
-- What would be memory or files elsewhere, in the database because the host has
-- no shared memory between requests: each site's last request time (the crawl
-- delay holds across the requests of a stepped sync), retry counters, showcase
-- answers, the import rate limit. Values are JSON; expiration is a Unix time.
CREATE TABLE IF NOT EXISTS cache (
    key         VARCHAR(255)    NOT NULL,
    value       TEXT            NOT NULL,
    expiration  BIGINT          NOT NULL,
    CONSTRAINT cache_pkey PRIMARY KEY (key)
);
CREATE INDEX IF NOT EXISTS cache_expiration_index ON cache (expiration);

-- One sync step at a time, one start at a time, one migration run at a time. A
-- lock expires on its own, so a request that died holding one cannot block the
-- next forever.
CREATE TABLE IF NOT EXISTS cache_locks (
    key         VARCHAR(255)    NOT NULL,
    owner       VARCHAR(255)    NOT NULL,
    expiration  BIGINT          NOT NULL,
    CONSTRAINT cache_locks_pkey PRIMARY KEY (key)
);
CREATE INDEX IF NOT EXISTS cache_locks_expiration_index ON cache_locks (expiration);

-- ── Closed sets ─────────────────────────────────────────────────────────────
-- Added here rather than inside the CREATEs so a database brought forward by
-- 001 gets them too.
DO $$
DECLARE
    t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['game_characters', 'game_weapons', 'game_sets', 'accounts', 'import_runs', 'build_sources', 'sync_runs'] LOOP
        IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = t || '_game_check') THEN
            EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I CHECK (game IN (''genshin'', ''hsr''))', t, t || '_game_check');
        END IF;
    END LOOP;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'game_sets_kind_check') THEN
        ALTER TABLE game_sets ADD CONSTRAINT game_sets_kind_check CHECK (kind IN ('artifact', 'relic', 'planar'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'sync_runs_kind_check') THEN
        ALTER TABLE sync_runs ADD CONSTRAINT sync_runs_kind_check CHECK (kind IN ('builds', 'catalog'));
    END IF;
END $$;

-- ── Verification gate ───────────────────────────────────────────────────────
-- Every column with its type and nullability, every constraint and index by
-- name, and nothing else in these tables.
DO $$
DECLARE
    wrong TEXT;
BEGIN
    WITH expected (tbl, col, typ, nullable) AS (VALUES
        ('users', 'id', 'bigint', FALSE), ('users', 'name', 'varchar(100)', FALSE),
        ('users', 'created_at', 'timestamptz', TRUE), ('users', 'updated_at', 'timestamptz', TRUE),

        ('game_characters', 'id', 'bigint', FALSE), ('game_characters', 'game', 'varchar(16)', FALSE),
        ('game_characters', 'key', 'varchar(120)', FALSE), ('game_characters', 'name', 'varchar(160)', FALSE),
        ('game_characters', 'element', 'varchar(20)', TRUE), ('game_characters', 'rarity', 'smallint', TRUE),
        ('game_characters', 'weapon_type', 'varchar(30)', TRUE), ('game_characters', 'external_id', 'varchar(40)', TRUE),
        ('game_characters', 'icon_url', 'varchar(500)', TRUE), ('game_characters', 'released_at', 'timestamptz', TRUE),
        ('game_characters', 'meta', 'json', TRUE), ('game_characters', 'created_at', 'timestamptz', TRUE),
        ('game_characters', 'updated_at', 'timestamptz', TRUE),

        ('game_weapons', 'id', 'bigint', FALSE), ('game_weapons', 'game', 'varchar(16)', FALSE),
        ('game_weapons', 'key', 'varchar(160)', FALSE), ('game_weapons', 'name', 'varchar(200)', FALSE),
        ('game_weapons', 'rarity', 'smallint', TRUE), ('game_weapons', 'weapon_type', 'varchar(30)', TRUE),
        ('game_weapons', 'external_id', 'varchar(40)', TRUE), ('game_weapons', 'icon_url', 'varchar(500)', TRUE),
        ('game_weapons', 'meta', 'json', TRUE), ('game_weapons', 'created_at', 'timestamptz', TRUE),
        ('game_weapons', 'updated_at', 'timestamptz', TRUE),

        ('game_sets', 'id', 'bigint', FALSE), ('game_sets', 'game', 'varchar(16)', FALSE),
        ('game_sets', 'key', 'varchar(160)', FALSE), ('game_sets', 'name', 'varchar(200)', FALSE),
        ('game_sets', 'kind', 'varchar(16)', FALSE), ('game_sets', 'external_id', 'varchar(40)', TRUE),
        ('game_sets', 'icon_url', 'varchar(500)', TRUE), ('game_sets', 'meta', 'json', TRUE),
        ('game_sets', 'created_at', 'timestamptz', TRUE), ('game_sets', 'updated_at', 'timestamptz', TRUE),

        ('accounts', 'id', 'bigint', FALSE), ('accounts', 'user_id', 'bigint', FALSE),
        ('accounts', 'game', 'varchar(16)', FALSE), ('accounts', 'uid', 'varchar(20)', TRUE),
        ('accounts', 'nickname', 'varchar(100)', TRUE), ('accounts', 'level', 'smallint', TRUE),
        ('accounts', 'world_level', 'smallint', TRUE), ('accounts', 'signature', 'varchar(300)', TRUE),
        ('accounts', 'has_full_inventory', 'boolean', FALSE), ('accounts', 'last_import_at', 'timestamptz', TRUE),
        ('accounts', 'meta', 'json', TRUE), ('accounts', 'created_at', 'timestamptz', TRUE),
        ('accounts', 'updated_at', 'timestamptz', TRUE),

        ('import_runs', 'id', 'bigint', FALSE), ('import_runs', 'user_id', 'bigint', FALSE),
        ('import_runs', 'account_id', 'bigint', TRUE), ('import_runs', 'game', 'varchar(16)', FALSE),
        ('import_runs', 'source', 'varchar(32)', FALSE), ('import_runs', 'status', 'varchar(16)', FALSE),
        ('import_runs', 'file_name', 'varchar(255)', TRUE), ('import_runs', 'summary', 'json', TRUE),
        ('import_runs', 'error', 'text', TRUE), ('import_runs', 'created_at', 'timestamptz', TRUE),
        ('import_runs', 'updated_at', 'timestamptz', TRUE),

        ('account_characters', 'id', 'bigint', FALSE), ('account_characters', 'account_id', 'bigint', FALSE),
        ('account_characters', 'game_character_id', 'bigint', FALSE), ('account_characters', 'level', 'smallint', FALSE),
        ('account_characters', 'ascension', 'smallint', TRUE), ('account_characters', 'rank', 'smallint', FALSE),
        ('account_characters', 'talents', 'json', TRUE), ('account_characters', 'final_stats', 'json', TRUE),
        ('account_characters', 'source', 'varchar(32)', FALSE), ('account_characters', 'synced_at', 'timestamptz', TRUE),
        ('account_characters', 'created_at', 'timestamptz', TRUE), ('account_characters', 'updated_at', 'timestamptz', TRUE),

        ('account_weapons', 'id', 'bigint', FALSE), ('account_weapons', 'account_id', 'bigint', FALSE),
        ('account_weapons', 'game_weapon_id', 'bigint', FALSE), ('account_weapons', 'level', 'smallint', FALSE),
        ('account_weapons', 'ascension', 'smallint', TRUE), ('account_weapons', 'refinement', 'smallint', FALSE),
        ('account_weapons', 'equipped_character_id', 'bigint', TRUE), ('account_weapons', 'locked', 'boolean', FALSE),
        ('account_weapons', 'external_uid', 'varchar(64)', TRUE), ('account_weapons', 'source', 'varchar(32)', FALSE),
        ('account_weapons', 'created_at', 'timestamptz', TRUE), ('account_weapons', 'updated_at', 'timestamptz', TRUE),

        ('account_items', 'id', 'bigint', FALSE), ('account_items', 'account_id', 'bigint', FALSE),
        ('account_items', 'game_set_id', 'bigint', FALSE), ('account_items', 'slot', 'varchar(16)', FALSE),
        ('account_items', 'rarity', 'smallint', FALSE), ('account_items', 'level', 'smallint', FALSE),
        ('account_items', 'main_stat', 'varchar(32)', FALSE), ('account_items', 'main_value', 'double precision', TRUE),
        ('account_items', 'substats', 'json', FALSE), ('account_items', 'equipped_character_id', 'bigint', TRUE),
        ('account_items', 'locked', 'boolean', FALSE), ('account_items', 'external_uid', 'varchar(64)', TRUE),
        ('account_items', 'source', 'varchar(32)', FALSE), ('account_items', 'created_at', 'timestamptz', TRUE),
        ('account_items', 'updated_at', 'timestamptz', TRUE),

        ('build_sources', 'id', 'bigint', FALSE), ('build_sources', 'key', 'varchar(64)', FALSE),
        ('build_sources', 'game', 'varchar(16)', FALSE), ('build_sources', 'name', 'varchar(100)', FALSE),
        ('build_sources', 'base_url', 'varchar(255)', FALSE), ('build_sources', 'enabled', 'boolean', FALSE),
        ('build_sources', 'last_synced_at', 'timestamptz', TRUE), ('build_sources', 'last_status', 'varchar(16)', TRUE),
        ('build_sources', 'last_error', 'text', TRUE), ('build_sources', 'created_at', 'timestamptz', TRUE),
        ('build_sources', 'updated_at', 'timestamptz', TRUE),

        ('builds', 'id', 'bigint', FALSE), ('builds', 'build_source_id', 'bigint', FALSE),
        ('builds', 'game_character_id', 'bigint', FALSE), ('builds', 'variant', 'varchar(120)', FALSE),
        ('builds', 'role', 'varchar(60)', TRUE), ('builds', 'recommended', 'boolean', FALSE),
        ('builds', 'position', 'smallint', FALSE), ('builds', 'weapons', 'json', FALSE),
        ('builds', 'sets', 'json', FALSE), ('builds', 'main_stats', 'json', FALSE),
        ('builds', 'substats', 'json', FALSE), ('builds', 'talent_priority', 'json', TRUE),
        ('builds', 'stat_targets', 'json', TRUE), ('builds', 'teams', 'json', TRUE),
        ('builds', 'notes', 'text', TRUE), ('builds', 'source_url', 'varchar(500)', TRUE),
        ('builds', 'source_updated_at', 'timestamptz', TRUE), ('builds', 'game_version', 'varchar(20)', TRUE),
        ('builds', 'content_hash', 'varchar(64)', FALSE), ('builds', 'fetched_at', 'timestamptz', TRUE),
        ('builds', 'changed_at', 'timestamptz', TRUE), ('builds', 'created_at', 'timestamptz', TRUE),
        ('builds', 'updated_at', 'timestamptz', TRUE),

        ('sync_runs', 'id', 'bigint', FALSE), ('sync_runs', 'build_source_id', 'bigint', TRUE),
        ('sync_runs', 'status', 'varchar(16)', FALSE), ('sync_runs', 'started_at', 'timestamptz', FALSE),
        ('sync_runs', 'finished_at', 'timestamptz', TRUE), ('sync_runs', 'stats', 'json', TRUE),
        ('sync_runs', 'log', 'text', TRUE), ('sync_runs', 'created_at', 'timestamptz', TRUE),
        ('sync_runs', 'updated_at', 'timestamptz', TRUE), ('sync_runs', 'kind', 'varchar(16)', FALSE),
        ('sync_runs', 'game', 'varchar(16)', TRUE), ('sync_runs', 'started_by', 'bigint', TRUE),
        ('sync_runs', 'state', 'json', TRUE), ('sync_runs', 'next_step_at', 'timestamptz', TRUE),
        ('sync_runs', 'last_step_at', 'timestamptz', TRUE),

        ('cache', 'key', 'varchar(255)', FALSE), ('cache', 'value', 'text', FALSE), ('cache', 'expiration', 'bigint', FALSE),
        ('cache_locks', 'key', 'varchar(255)', FALSE), ('cache_locks', 'owner', 'varchar(255)', FALSE),
        ('cache_locks', 'expiration', 'bigint', FALSE)
    ),
    actual AS (
        SELECT table_name::TEXT AS tbl, column_name::TEXT AS col,
               CASE data_type
                   WHEN 'character varying' THEN 'varchar(' || character_maximum_length || ')'
                   WHEN 'timestamp with time zone' THEN 'timestamptz'
                   ELSE data_type
               END AS typ,
               is_nullable = 'YES' AS nullable
          FROM information_schema.columns
         WHERE table_schema = current_schema()
           AND table_name IN ('users', 'game_characters', 'game_weapons', 'game_sets', 'accounts', 'import_runs',
                              'account_characters', 'account_weapons', 'account_items', 'build_sources', 'builds',
                              'sync_runs', 'cache', 'cache_locks')
    )
    SELECT string_agg(COALESCE(e.tbl, a.tbl) || '.' || COALESCE(e.col, a.col) || ' ('
                      || CASE WHEN a.col IS NULL THEN 'missing'
                              WHEN e.col IS NULL THEN 'not expected'
                              ELSE a.typ || CASE WHEN a.nullable THEN ' null' ELSE ' not null' END
                                   || ', expected ' || e.typ || CASE WHEN e.nullable THEN ' null' ELSE ' not null' END
                         END || ')', '; ')
      INTO wrong
      FROM expected e
      FULL JOIN actual a ON a.tbl = e.tbl AND a.col = e.col
     WHERE a.col IS NULL OR e.col IS NULL OR a.typ <> e.typ OR a.nullable <> e.nullable;

    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'The schema is not what 002 expects: %', wrong;
    END IF;

    WITH expected (name, kind) AS (VALUES
        ('users_pkey', 'p'), ('game_characters_pkey', 'p'), ('game_weapons_pkey', 'p'), ('game_sets_pkey', 'p'),
        ('accounts_pkey', 'p'), ('import_runs_pkey', 'p'), ('account_characters_pkey', 'p'),
        ('account_weapons_pkey', 'p'), ('account_items_pkey', 'p'), ('build_sources_pkey', 'p'),
        ('builds_pkey', 'p'), ('sync_runs_pkey', 'p'), ('cache_pkey', 'p'), ('cache_locks_pkey', 'p'),
        ('game_characters_game_key_unique', 'u'), ('game_weapons_game_key_unique', 'u'),
        ('game_sets_game_key_unique', 'u'), ('accounts_user_id_game_uid_unique', 'u'),
        ('account_characters_account_id_game_character_id_unique', 'u'), ('build_sources_key_unique', 'u'),
        ('builds_build_source_id_game_character_id_variant_unique', 'u'),
        ('accounts_user_id_foreign', 'f'), ('import_runs_user_id_foreign', 'f'), ('import_runs_account_id_foreign', 'f'),
        ('account_characters_account_id_foreign', 'f'), ('account_characters_game_character_id_foreign', 'f'),
        ('account_weapons_account_id_foreign', 'f'), ('account_weapons_game_weapon_id_foreign', 'f'),
        ('account_weapons_equipped_character_id_foreign', 'f'), ('account_items_account_id_foreign', 'f'),
        ('account_items_game_set_id_foreign', 'f'), ('account_items_equipped_character_id_foreign', 'f'),
        ('builds_build_source_id_foreign', 'f'), ('builds_game_character_id_foreign', 'f'),
        ('sync_runs_build_source_id_foreign', 'f'), ('sync_runs_started_by_foreign', 'f'),
        ('game_characters_game_check', 'c'), ('game_weapons_game_check', 'c'), ('game_sets_game_check', 'c'),
        ('accounts_game_check', 'c'), ('import_runs_game_check', 'c'), ('build_sources_game_check', 'c'),
        ('sync_runs_game_check', 'c'), ('game_sets_kind_check', 'c'), ('sync_runs_kind_check', 'c')
    )
    SELECT string_agg(e.name, ', ') INTO wrong
      FROM expected e
     WHERE NOT EXISTS (SELECT 1 FROM pg_constraint c
                        JOIN pg_namespace n ON n.oid = c.connamespace AND n.nspname = current_schema()
                       WHERE c.conname = e.name AND c.contype::TEXT = e.kind);

    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Constraints missing: %', wrong;
    END IF;

    -- What a deleted row takes with it.
    WITH expected (name, action) AS (VALUES
        ('accounts_user_id_foreign', 'c'), ('import_runs_user_id_foreign', 'c'), ('import_runs_account_id_foreign', 'c'),
        ('account_characters_account_id_foreign', 'c'), ('account_characters_game_character_id_foreign', 'c'),
        ('account_weapons_account_id_foreign', 'c'), ('account_weapons_game_weapon_id_foreign', 'c'),
        ('account_weapons_equipped_character_id_foreign', 'n'), ('account_items_account_id_foreign', 'c'),
        ('account_items_game_set_id_foreign', 'c'), ('account_items_equipped_character_id_foreign', 'n'),
        ('builds_build_source_id_foreign', 'c'), ('builds_game_character_id_foreign', 'c'),
        ('sync_runs_build_source_id_foreign', 'c'), ('sync_runs_started_by_foreign', 'n')
    )
    SELECT string_agg(e.name, ', ') INTO wrong
      FROM expected e
      JOIN pg_constraint c ON c.conname = e.name
     WHERE c.confdeltype::TEXT <> e.action;

    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Foreign keys with the wrong ON DELETE: %', wrong;
    END IF;

    SELECT string_agg(i, ', ') INTO wrong
      FROM unnest(ARRAY['game_characters_game_external_id_index', 'game_weapons_game_external_id_index',
                        'game_sets_game_external_id_index', 'account_weapons_account_id_equipped_character_id_index',
                        'account_items_account_id_slot_index', 'account_items_account_id_equipped_character_id_index',
                        'builds_game_character_id_index', 'sync_runs_status_kind_index', 'cache_expiration_index',
                        'cache_locks_expiration_index']) AS i
     WHERE to_regclass('public.' || i) IS NULL;

    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Indexes missing: %', wrong;
    END IF;
END $$;
