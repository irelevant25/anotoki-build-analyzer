-- The schema the first (Laravel) version of the build analyzer made on PostgreSQL after its first
-- 8 migrations: pg_dump --schema-only of a database made by that version's own `artisan migrate`.
-- The adoption tests (tests/migrations.test.php) load it, fill it and bring it forward.

--
-- PostgreSQL database dump
--

--
-- Name: account_characters; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.account_characters (
    id bigint NOT NULL,
    account_id bigint NOT NULL,
    game_character_id bigint NOT NULL,
    level smallint DEFAULT '1'::smallint NOT NULL,
    ascension smallint,
    rank smallint DEFAULT '0'::smallint NOT NULL,
    talents json,
    final_stats json,
    source character varying(32) NOT NULL,
    synced_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

--
-- Name: COLUMN account_characters.rank; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.account_characters.rank IS 'Constellation / Eidolon';

--
-- Name: COLUMN account_characters.final_stats; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.account_characters.final_stats IS 'Total stats as shown in game (only Enka provides them)';

--
-- Name: account_characters_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.account_characters_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
-- Name: account_characters_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.account_characters_id_seq OWNED BY public.account_characters.id;

--
-- Name: account_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.account_items (
    id bigint NOT NULL,
    account_id bigint NOT NULL,
    game_set_id bigint NOT NULL,
    slot character varying(16) NOT NULL,
    rarity smallint DEFAULT '5'::smallint NOT NULL,
    level smallint DEFAULT '0'::smallint NOT NULL,
    main_stat character varying(32) NOT NULL,
    main_value double precision,
    substats json NOT NULL,
    equipped_character_id bigint,
    locked boolean DEFAULT false NOT NULL,
    external_uid character varying(64),
    source character varying(32) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

--
-- Name: COLUMN account_items.substats; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.account_items.substats IS '[{key, value, rolls}]';

--
-- Name: account_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.account_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
-- Name: account_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.account_items_id_seq OWNED BY public.account_items.id;

--
-- Name: account_weapons; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.account_weapons (
    id bigint NOT NULL,
    account_id bigint NOT NULL,
    game_weapon_id bigint NOT NULL,
    level smallint DEFAULT '1'::smallint NOT NULL,
    ascension smallint,
    refinement smallint DEFAULT '1'::smallint NOT NULL,
    equipped_character_id bigint,
    locked boolean DEFAULT false NOT NULL,
    external_uid character varying(64),
    source character varying(32) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

--
-- Name: COLUMN account_weapons.refinement; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.account_weapons.refinement IS 'Refinement / Superimposition';

--
-- Name: account_weapons_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.account_weapons_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
-- Name: account_weapons_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.account_weapons_id_seq OWNED BY public.account_weapons.id;

--
-- Name: accounts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.accounts (
    id bigint NOT NULL,
    game character varying(16) NOT NULL,
    uid character varying(20),
    nickname character varying(100),
    level smallint,
    world_level smallint,
    signature character varying(300),
    has_full_inventory boolean DEFAULT false NOT NULL,
    last_import_at timestamp(0) without time zone,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id bigint NOT NULL
);

--
-- Name: COLUMN accounts.level; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.accounts.level IS 'Adventure Rank / Trailblaze Level';

--
-- Name: COLUMN accounts.world_level; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.accounts.world_level IS 'World Level / Equilibrium Level';

--
-- Name: accounts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.accounts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
-- Name: accounts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.accounts_id_seq OWNED BY public.accounts.id;

--
-- Name: build_sources; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.build_sources (
    id bigint NOT NULL,
    key character varying(64) NOT NULL,
    game character varying(16) NOT NULL,
    name character varying(100) NOT NULL,
    base_url character varying(255) NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    last_synced_at timestamp(0) without time zone,
    last_status character varying(16),
    last_error text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

--
-- Name: COLUMN build_sources.key; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.build_sources.key IS 'Matches a scraper class, e.g. site.hsr';

--
-- Name: build_sources_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.build_sources_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
-- Name: build_sources_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.build_sources_id_seq OWNED BY public.build_sources.id;

--
-- Name: builds; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.builds (
    id bigint NOT NULL,
    build_source_id bigint NOT NULL,
    game_character_id bigint NOT NULL,
    variant character varying(120) NOT NULL,
    role character varying(60),
    recommended boolean DEFAULT false NOT NULL,
    "position" smallint DEFAULT '0'::smallint NOT NULL,
    weapons json NOT NULL,
    sets json NOT NULL,
    main_stats json NOT NULL,
    substats json NOT NULL,
    talent_priority json,
    stat_targets json,
    teams json,
    notes text,
    source_url character varying(500),
    source_updated_at timestamp(0) without time zone,
    game_version character varying(20),
    content_hash character varying(64) NOT NULL,
    fetched_at timestamp(0) without time zone,
    changed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

--
-- Name: COLUMN builds.variant; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.builds.variant IS 'Build name on the source, e.g. "Main DPS"';

--
-- Name: COLUMN builds.recommended; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.builds.recommended IS 'The source marks this variant as its main recommendation';

--
-- Name: COLUMN builds.weapons; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.builds.weapons IS '[{key, name, rank, note}]';

--
-- Name: COLUMN builds.sets; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.builds.sets IS '[{rank, kind, pieces: [{key, name, count}], note}]';

--
-- Name: COLUMN builds.main_stats; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.builds.main_stats IS '{slot: [{stat, priority}]}';

--
-- Name: COLUMN builds.substats; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.builds.substats IS '[{stat, priority}]';

--
-- Name: COLUMN builds.stat_targets; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.builds.stat_targets IS '[{stat, min, max, text}]';

--
-- Name: builds_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.builds_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
-- Name: builds_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.builds_id_seq OWNED BY public.builds.id;

--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration bigint NOT NULL
);

--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration bigint NOT NULL
);

--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection character varying(255) NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;

--
-- Name: game_characters; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_characters (
    id bigint NOT NULL,
    game character varying(16) NOT NULL,
    key character varying(120) NOT NULL,
    name character varying(160) NOT NULL,
    element character varying(20),
    rarity smallint,
    weapon_type character varying(30),
    external_id character varying(40),
    icon_url character varying(500),
    released_at timestamp(0) without time zone,
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

--
-- Name: COLUMN game_characters.weapon_type; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.game_characters.weapon_type IS 'Genshin weapon type or HSR path';

--
-- Name: COLUMN game_characters.external_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.game_characters.external_id IS 'In-game ID (Enka / yatta)';

--
-- Name: game_characters_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.game_characters_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
-- Name: game_characters_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.game_characters_id_seq OWNED BY public.game_characters.id;

--
-- Name: game_sets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_sets (
    id bigint NOT NULL,
    game character varying(16) NOT NULL,
    key character varying(160) NOT NULL,
    name character varying(200) NOT NULL,
    kind character varying(16) NOT NULL,
    external_id character varying(40),
    icon_url character varying(500),
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

--
-- Name: COLUMN game_sets.kind; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.game_sets.kind IS 'artifact | relic | planar';

--
-- Name: game_sets_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.game_sets_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
-- Name: game_sets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.game_sets_id_seq OWNED BY public.game_sets.id;

--
-- Name: game_weapons; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_weapons (
    id bigint NOT NULL,
    game character varying(16) NOT NULL,
    key character varying(160) NOT NULL,
    name character varying(200) NOT NULL,
    rarity smallint,
    weapon_type character varying(30),
    external_id character varying(40),
    icon_url character varying(500),
    meta json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

--
-- Name: COLUMN game_weapons.weapon_type; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.game_weapons.weapon_type IS 'Genshin weapon type or HSR path';

--
-- Name: game_weapons_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.game_weapons_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
-- Name: game_weapons_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.game_weapons_id_seq OWNED BY public.game_weapons.id;

--
-- Name: import_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.import_runs (
    id bigint NOT NULL,
    account_id bigint,
    game character varying(16) NOT NULL,
    source character varying(32) NOT NULL,
    status character varying(16) NOT NULL,
    file_name character varying(255),
    summary json,
    error text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_id bigint NOT NULL
);

--
-- Name: COLUMN import_runs.source; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.import_runs.source IS 'enka | good | hsr_scanner';

--
-- Name: COLUMN import_runs.status; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.import_runs.status IS 'success | partial | failed';

--
-- Name: import_runs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.import_runs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
-- Name: import_runs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.import_runs_id_seq OWNED BY public.import_runs.id;

--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);

--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);

--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;

--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);

--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;

--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);

--
-- Name: sync_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sync_runs (
    id bigint NOT NULL,
    build_source_id bigint,
    status character varying(16) NOT NULL,
    started_at timestamp(0) without time zone NOT NULL,
    finished_at timestamp(0) without time zone,
    stats json,
    log text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    kind character varying(16) DEFAULT 'builds'::character varying NOT NULL,
    game character varying(16),
    started_by bigint,
    state json,
    next_step_at timestamp(0) without time zone,
    last_step_at timestamp(0) without time zone
);

--
-- Name: COLUMN sync_runs.status; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.sync_runs.status IS 'running | success | partial | failed';

--
-- Name: COLUMN sync_runs.kind; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.sync_runs.kind IS 'builds | catalog';

--
-- Name: COLUMN sync_runs.state; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.sync_runs.state IS 'Position of a run in progress (list, cursor)';

--
-- Name: COLUMN sync_runs.next_step_at; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.sync_runs.next_step_at IS 'Earliest start of the next step (crawl delay)';

--
-- Name: sync_runs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sync_runs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

--
-- Name: sync_runs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sync_runs_id_seq OWNED BY public.sync_runs.id;

--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(100) NOT NULL,
    password character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);

--
-- Name: COLUMN users.id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.users.id IS 'IAM user id (the token sub)';

--
-- Name: COLUMN users.name; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.users.name IS 'preferred_username, refreshed from the token';

--
-- Name: COLUMN users.password; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.users.password IS 'Always empty: the IAM owns passwords';

--
-- Name: account_characters id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_characters ALTER COLUMN id SET DEFAULT nextval('public.account_characters_id_seq'::regclass);

--
-- Name: account_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_items ALTER COLUMN id SET DEFAULT nextval('public.account_items_id_seq'::regclass);

--
-- Name: account_weapons id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_weapons ALTER COLUMN id SET DEFAULT nextval('public.account_weapons_id_seq'::regclass);

--
-- Name: accounts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.accounts ALTER COLUMN id SET DEFAULT nextval('public.accounts_id_seq'::regclass);

--
-- Name: build_sources id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.build_sources ALTER COLUMN id SET DEFAULT nextval('public.build_sources_id_seq'::regclass);

--
-- Name: builds id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.builds ALTER COLUMN id SET DEFAULT nextval('public.builds_id_seq'::regclass);

--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);

--
-- Name: game_characters id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_characters ALTER COLUMN id SET DEFAULT nextval('public.game_characters_id_seq'::regclass);

--
-- Name: game_sets id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_sets ALTER COLUMN id SET DEFAULT nextval('public.game_sets_id_seq'::regclass);

--
-- Name: game_weapons id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_weapons ALTER COLUMN id SET DEFAULT nextval('public.game_weapons_id_seq'::regclass);

--
-- Name: import_runs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_runs ALTER COLUMN id SET DEFAULT nextval('public.import_runs_id_seq'::regclass);

--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);

--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);

--
-- Name: sync_runs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sync_runs ALTER COLUMN id SET DEFAULT nextval('public.sync_runs_id_seq'::regclass);

--
-- Name: account_characters account_characters_account_id_game_character_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_characters
    ADD CONSTRAINT account_characters_account_id_game_character_id_unique UNIQUE (account_id, game_character_id);

--
-- Name: account_characters account_characters_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_characters
    ADD CONSTRAINT account_characters_pkey PRIMARY KEY (id);

--
-- Name: account_items account_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_items
    ADD CONSTRAINT account_items_pkey PRIMARY KEY (id);

--
-- Name: account_weapons account_weapons_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_weapons
    ADD CONSTRAINT account_weapons_pkey PRIMARY KEY (id);

--
-- Name: accounts accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT accounts_pkey PRIMARY KEY (id);

--
-- Name: accounts accounts_user_id_game_uid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT accounts_user_id_game_uid_unique UNIQUE (user_id, game, uid);

--
-- Name: build_sources build_sources_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.build_sources
    ADD CONSTRAINT build_sources_key_unique UNIQUE (key);

--
-- Name: build_sources build_sources_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.build_sources
    ADD CONSTRAINT build_sources_pkey PRIMARY KEY (id);

--
-- Name: builds builds_build_source_id_game_character_id_variant_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.builds
    ADD CONSTRAINT builds_build_source_id_game_character_id_variant_unique UNIQUE (build_source_id, game_character_id, variant);

--
-- Name: builds builds_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.builds
    ADD CONSTRAINT builds_pkey PRIMARY KEY (id);

--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);

--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);

--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);

--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);

--
-- Name: game_characters game_characters_game_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_characters
    ADD CONSTRAINT game_characters_game_key_unique UNIQUE (game, key);

--
-- Name: game_characters game_characters_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_characters
    ADD CONSTRAINT game_characters_pkey PRIMARY KEY (id);

--
-- Name: game_sets game_sets_game_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_sets
    ADD CONSTRAINT game_sets_game_key_unique UNIQUE (game, key);

--
-- Name: game_sets game_sets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_sets
    ADD CONSTRAINT game_sets_pkey PRIMARY KEY (id);

--
-- Name: game_weapons game_weapons_game_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_weapons
    ADD CONSTRAINT game_weapons_game_key_unique UNIQUE (game, key);

--
-- Name: game_weapons game_weapons_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_weapons
    ADD CONSTRAINT game_weapons_pkey PRIMARY KEY (id);

--
-- Name: import_runs import_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_runs
    ADD CONSTRAINT import_runs_pkey PRIMARY KEY (id);

--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);

--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);

--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);

--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);

--
-- Name: sync_runs sync_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sync_runs
    ADD CONSTRAINT sync_runs_pkey PRIMARY KEY (id);

--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);

--
-- Name: account_items_account_id_equipped_character_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX account_items_account_id_equipped_character_id_index ON public.account_items USING btree (account_id, equipped_character_id);

--
-- Name: account_items_account_id_slot_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX account_items_account_id_slot_index ON public.account_items USING btree (account_id, slot);

--
-- Name: account_weapons_account_id_equipped_character_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX account_weapons_account_id_equipped_character_id_index ON public.account_weapons USING btree (account_id, equipped_character_id);

--
-- Name: builds_game_character_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX builds_game_character_id_index ON public.builds USING btree (game_character_id);

--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);

--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);

--
-- Name: failed_jobs_connection_queue_failed_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX failed_jobs_connection_queue_failed_at_index ON public.failed_jobs USING btree (connection, queue, failed_at);

--
-- Name: game_characters_game_external_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX game_characters_game_external_id_index ON public.game_characters USING btree (game, external_id);

--
-- Name: game_sets_game_external_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX game_sets_game_external_id_index ON public.game_sets USING btree (game, external_id);

--
-- Name: game_weapons_game_external_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX game_weapons_game_external_id_index ON public.game_weapons USING btree (game, external_id);

--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);

--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);

--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);

--
-- Name: sync_runs_status_kind_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sync_runs_status_kind_index ON public.sync_runs USING btree (status, kind);

--
-- Name: account_characters account_characters_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_characters
    ADD CONSTRAINT account_characters_account_id_foreign FOREIGN KEY (account_id) REFERENCES public.accounts(id) ON DELETE CASCADE;

--
-- Name: account_characters account_characters_game_character_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_characters
    ADD CONSTRAINT account_characters_game_character_id_foreign FOREIGN KEY (game_character_id) REFERENCES public.game_characters(id) ON DELETE CASCADE;

--
-- Name: account_items account_items_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_items
    ADD CONSTRAINT account_items_account_id_foreign FOREIGN KEY (account_id) REFERENCES public.accounts(id) ON DELETE CASCADE;

--
-- Name: account_items account_items_equipped_character_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_items
    ADD CONSTRAINT account_items_equipped_character_id_foreign FOREIGN KEY (equipped_character_id) REFERENCES public.game_characters(id) ON DELETE SET NULL;

--
-- Name: account_items account_items_game_set_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_items
    ADD CONSTRAINT account_items_game_set_id_foreign FOREIGN KEY (game_set_id) REFERENCES public.game_sets(id) ON DELETE CASCADE;

--
-- Name: account_weapons account_weapons_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_weapons
    ADD CONSTRAINT account_weapons_account_id_foreign FOREIGN KEY (account_id) REFERENCES public.accounts(id) ON DELETE CASCADE;

--
-- Name: account_weapons account_weapons_equipped_character_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_weapons
    ADD CONSTRAINT account_weapons_equipped_character_id_foreign FOREIGN KEY (equipped_character_id) REFERENCES public.game_characters(id) ON DELETE SET NULL;

--
-- Name: account_weapons account_weapons_game_weapon_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.account_weapons
    ADD CONSTRAINT account_weapons_game_weapon_id_foreign FOREIGN KEY (game_weapon_id) REFERENCES public.game_weapons(id) ON DELETE CASCADE;

--
-- Name: accounts accounts_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT accounts_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;

--
-- Name: builds builds_build_source_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.builds
    ADD CONSTRAINT builds_build_source_id_foreign FOREIGN KEY (build_source_id) REFERENCES public.build_sources(id) ON DELETE CASCADE;

--
-- Name: builds builds_game_character_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.builds
    ADD CONSTRAINT builds_game_character_id_foreign FOREIGN KEY (game_character_id) REFERENCES public.game_characters(id) ON DELETE CASCADE;

--
-- Name: import_runs import_runs_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_runs
    ADD CONSTRAINT import_runs_account_id_foreign FOREIGN KEY (account_id) REFERENCES public.accounts(id) ON DELETE CASCADE;

--
-- Name: import_runs import_runs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_runs
    ADD CONSTRAINT import_runs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;

--
-- Name: sync_runs sync_runs_build_source_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sync_runs
    ADD CONSTRAINT sync_runs_build_source_id_foreign FOREIGN KEY (build_source_id) REFERENCES public.build_sources(id) ON DELETE CASCADE;

--
-- Name: sync_runs sync_runs_started_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sync_runs
    ADD CONSTRAINT sync_runs_started_by_foreign FOREIGN KEY (started_by) REFERENCES public.users(id) ON DELETE SET NULL;

--
-- PostgreSQL database dump complete
--
