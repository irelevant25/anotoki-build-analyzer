<?php

/**
 * A signed-in person's own data as a file, out and back (GET /api/me/export, POST /api/me/import): the
 * export carries keys, not ids, and names no other site; it comes back as the same accounts; the same file
 * twice changes nothing; the newer copy of an account wins, never deleting one; an account without a UID is
 * found by when it was made, whatever time zone wrote the file; what this copy's catalog lacks is skipped
 * and counted; a dry run changes nothing; nobody, a visitor and another person never reach it; a file that
 * is not right is refused whole; an import takes turns with the person's other writes.
 */

declare(strict_types=1);

require __DIR__.'/lib.php';

use App\Db\Db;
use App\Repositories\Users;
use App\Services\Transfer\AccountsFile;
use App\Services\Transfer\AccountsTransfer;
use App\Support\Json;
use App\Web\HttpError;
use App\Support\Neutral;
use Tests\Support\Answer;
use Tests\Support\Client;

bootTests();

const ALICE = 1;
const BOB = 2;

beforeEach(function () {
    seedGenshinCatalog();
    seedHsrCatalog();
    seedUser(ALICE, 'alice');
    seedUser(BOB, 'bob');
});

/**
 * Alice's three accounts, with everything an account holds: a Genshin one by UID (a showcase and two file
 * imports, one failed), a Genshin one from a file (no UID), a Star Rail one.
 *
 * @return array{main: int, file: int, hsr: int}
 */
function aliceAccounts(): array
{
    $main = seedAccount(ALICE, 'genshin', ['uid' => '700000001', 'nickname' => 'Alice main', 'level' => 58, 'world_level' => 8,
        'signature' => 'Hello there', 'has_full_inventory' => true, 'last_import_at' => '2026-09-20 08:00:00.250000+00',
        'created_at' => '2026-09-01 10:00:00.654321+00', 'meta' => ['region' => 'EU', 'achievements' => 900, 'tool' => 'Inventory Kamera']]);
    $hutao = idOf('game_characters', 'hutao');
    character($main, $hutao, ['level' => 90, 'ascension' => 6, 'rank' => 1, 'talents' => ['normal' => 10, 'skill' => 9, 'burst' => 9, 'bonus' => ['skill' => 3]],
        'final_stats' => ['hp' => 30512, 'critRate_' => 70.5], 'source' => 'enka', 'synced_at' => '2026-09-20 08:00:00+00']);
    character($main, idOf('game_characters', 'kaedeharakazuha'), ['level' => 80, 'ascension' => 5, 'rank' => 0, 'source' => 'good']);
    weapon($main, idOf('game_weapons', 'staffofhoma'), ['equipped_character_id' => $hutao, 'locked' => true, 'external_uid' => 'weapon_1']);
    weapon($main, idOf('game_weapons', 'dragonsbane'), ['level' => 70, 'refinement' => 5]);
    item($main, idOf('game_sets', 'crimsonwitchofflames'), ['slot' => 'flower', 'main_stat' => 'hp', 'main_value' => 4780.0, 'equipped_character_id' => $hutao,
        'substats' => [['key' => 'critRate_', 'value' => 10.5, 'rolls' => 3], ['key' => 'critDMG_', 'value' => 21.0, 'rolls' => null]]]);
    item($main, idOf('game_sets', 'shimenawasreminiscence'), ['slot' => 'goblet', 'main_stat' => 'pyro_dmg_', 'main_value' => 46.6, 'level' => 16]);
    run($main, 'enka', 'success', '2026-09-10 08:00:00.5+00', ['characters' => 1, 'weapons' => 0, 'items' => 0, 'full_inventory' => false, 'tool' => null,
        'warnings' => ['The page https://enka.network/u/700000001 was slow.'], 'warning_kinds' => [['code' => 'slow', 'params' => ['host' => 'enka.network']]],
        'unmatched' => []]);
    run($main, 'good', 'partial', '2026-09-20 08:00:00+00', ['characters' => 2, 'weapons' => 2, 'items' => 2, 'full_inventory' => true, 'tool' => 'GOODScanner',
        'warnings' => [], 'warning_kinds' => [], 'unmatched' => ['characters' => ['Genshin Optimizer Person']]], 'GOODScanner.json');
    run($main, 'file', 'failed', '2026-09-15 08:00:00+00', ['error' => ['code' => 'file_not_json', 'params' => []]], 'notes.txt', 'The file is not valid JSON.');

    $file = seedAccount(ALICE, 'genshin', ['nickname' => 'From a file', 'last_import_at' => '2026-09-05 06:30:00.5+00', 'created_at' => '2026-09-05 06:30:00.123+00']);
    item($file, idOf('game_sets', 'emblemofseveredfate'), ['slot' => 'sands', 'main_stat' => 'enerRech_', 'main_value' => 51.8]);
    run($file, 'good', 'success', '2026-09-05 06:30:00+00', ['characters' => 0, 'weapons' => 0, 'items' => 1, 'full_inventory' => true, 'warnings' => []], 'export.json');

    $hsr = seedAccount(ALICE, 'hsr', ['uid' => '800000001', 'nickname' => 'Trailblazer', 'level' => 70, 'world_level' => 6,
        'last_import_at' => '2026-09-21 18:00:00+00', 'created_at' => '2026-09-02 12:00:00+00', 'meta' => ['platform' => 'PC', 'trailblazer' => 'Stelle']]);
    $kafka = idOf('game_characters', 'kafka');
    character($hsr, $kafka, ['level' => 80, 'ascension' => 6, 'rank' => 0, 'talents' => ['basic' => 6, 'skill' => 10], 'source' => 'hsr_scanner']);
    weapon($hsr, idOf('game_weapons', 'patienceisallyouneed'), ['level' => 80, 'equipped_character_id' => $kafka, 'source' => 'hsr_scanner']);
    item($hsr, idOf('game_sets', 'prisonerindeepconfinement'), ['slot' => 'head', 'main_stat' => 'hp', 'main_value' => 705, 'source' => 'hsr_scanner']);
    item($hsr, idOf('game_sets', 'firmamentfrontlineglamoth'), ['slot' => 'sphere', 'main_stat' => 'lightning_dmg_', 'main_value' => 38.8, 'source' => 'hsr_scanner',
        'equipped_character_id' => $kafka]);

    return ['main' => $main, 'file' => $file, 'hsr' => $hsr];
}

function character(int $account, int $character, array $values): void
{
    db()->insert('account_characters', ['account_id' => $account, 'game_character_id' => $character,
        'talents' => Json::column($values['talents'] ?? null), 'final_stats' => Json::column($values['final_stats'] ?? null)]
        + array_diff_key($values, ['talents' => 1, 'final_stats' => 1]));
}

function weapon(int $account, int $weapon, array $values): void
{
    db()->insert('account_weapons', ['account_id' => $account, 'game_weapon_id' => $weapon] + $values + ['level' => 90, 'refinement' => 1, 'source' => 'good']);
}

function item(int $account, int $set, array $values): void
{
    db()->insert('account_items', ['account_id' => $account, 'game_set_id' => $set, 'substats' => Json::encode($values['substats'] ?? [])]
        + array_diff_key($values, ['substats' => 1]) + ['rarity' => 5, 'level' => 20, 'source' => 'good']);
}

function run(int $account, string $source, string $status, string $at, ?array $summary, ?string $fileName = null, ?string $error = null): void
{
    $game = db()->value('SELECT game FROM accounts WHERE id = ?', [$account]);
    $user = (int) db()->value('SELECT user_id FROM accounts WHERE id = ?', [$account]);
    db()->insert('import_runs', ['user_id' => $user, 'account_id' => $account, 'game' => $game, 'source' => $source, 'status' => $status,
        'file_name' => $fileName, 'summary' => Json::column($summary), 'error' => $error, 'created_at' => $at, 'updated_at' => $at]);
}

/** A connection of its own: another request, holding what it holds. */
function otherConnection(): PDO
{
    return Db::pdo((array) T::$app->config->get('database'), T::$database, false);
}

function person(int $id): Client
{
    return (new Client)->asIam($id);
}

function exported(int $id): Answer
{
    return person($id)->get('/api/me/export')->status(200);
}

/** @param array<string, mixed>|string $file */
function importAs(int $id, array|string $file, bool $dryRun = false, ?Client $client = null): Answer
{
    return ($client ?? person($id))->request('POST', '/api/me/import'.($dryRun ? '?dry_run=1' : ''), null,
        ['body' => is_string($file) ? $file : (string) json_encode($file, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
}

/** A file without the moment it was written: two exports of the same accounts are then equal. */
function withoutMoment(array $file): array
{
    unset($file['exported_at']);

    return $file;
}

/** Every row a person's accounts are made of, ids and all: what "nothing changed" compares. */
function everything(): array
{
    return array_map(fn (string $table) => db()->all("SELECT * FROM {$table} ORDER BY id"),
        ['accounts', 'account_characters', 'account_weapons', 'account_items', 'import_runs']);
}

/** Every key of a nested array, at any depth. */
function keysOf(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }
    $keys = array_is_list($value) ? [] : array_keys($value);
    foreach ($value as $item) {
        $keys = [...$keys, ...keysOf($item)];
    }

    return array_values(array_unique($keys));
}

function outcome(Answer $answer): array
{
    return array_intersect_key($answer->json(), array_flip(['dry_run', 'added', 'replaced', 'kept', 'skipped']));
}

// ── The file ─────────────────────────────────────────────────────────────────────────────────────

test('the export: keys, never ids; sources as showcase or file; UTC to the second; and no other site named', function () {
    aliceAccounts();
    seedAccount(BOB, 'genshin', ['uid' => '700000099', 'nickname' => 'Bob only']);
    $answer = exported(ALICE);
    same('attachment; filename="build-analyzer-accounts-'.gmdate('Y-m-d').'.json"', $answer->header('Content-Disposition'), 'saved as a file');
    same('no-store', $answer->header('Cache-Control'), 'never cached');
    check(! str_contains($answer->body, "\n"), 'compact JSON');
    $file = $answer->json();

    same(['format', 'format_version', 'exported_at', 'app_version', 'accounts'], array_keys($file), 'the header');
    same(['build-analyzer.accounts', 1], [$file['format'], $file['format_version']], 'format and version');
    same(['700000001', null, '800000001'], array_column($file['accounts'], 'uid'), 'Alice\'s three accounts, by game and when they were made; not Bob\'s');
    $keys = keysOf($file);
    foreach (['id', 'user_id', 'account_id', 'game_character_id', 'game_weapon_id', 'game_set_id', 'equipped_character_id', 'tool'] as $key) {
        check(! in_array($key, $keys, true), "no {$key} anywhere");
    }

    $main = $file['accounts'][0];
    same(['region' => 'EU', 'achievements' => 900], $main['meta'], 'the meta an account page shows, and not the tool');
    same(['2026-09-01T10:00:00Z', '2026-09-20T08:00:00Z'], [$main['created_at'], $main['last_import_at']], 'times in UTC, to the second');
    same(['hutao', 'kaedeharakazuha'], array_column($main['characters'], 'key'), 'characters by key');
    same(['key' => 'hutao', 'level' => 90, 'ascension' => 6, 'rank' => 1, 'talents' => ['normal' => 10, 'skill' => 9, 'burst' => 9, 'bonus' => ['skill' => 3]],
        'final_stats' => ['hp' => 30512, 'critRate_' => 70.5], 'source' => 'showcase', 'synced_at' => '2026-09-20T08:00:00Z'], $main['characters'][0], 'a character');
    same(['staffofhoma', 'hutao', 'file'], [$main['weapons'][0]['key'], $main['weapons'][0]['equipped'], $main['weapons'][0]['source']], 'a weapon, equipped by key');
    same(['crimsonwitchofflames', 'flower', 'hutao'], [$main['items'][0]['set'], $main['items'][0]['slot'], $main['items'][0]['equipped']], 'an item');
    same([['key' => 'critRate_', 'value' => 10.5, 'rolls' => 3], ['key' => 'critDMG_', 'value' => 21, 'rolls' => null]], $main['items'][0]['substats'], 'its substats');
    same(['showcase', 'file', 'file'], array_column($main['imports'], 'source'), 'the import history, oldest first, as showcase or file');
    same(['file_not_json', 'The file is not valid JSON.'], [$main['imports'][1]['summary']['error']['code'] ?? null, $main['imports'][1]['error']], 'a failed run keeps its kind');
    same(['PC', 'Stelle'], [$file['accounts'][2]['meta']['platform'], $file['accounts'][2]['meta']['trailblazer']], 'the Star Rail meta');

    foreach (array_merge(...array_map(fn ($account) => [...$account['characters'], ...$account['weapons'], ...$account['items'], ...$account['imports']], $file['accounts'])) as $entry) {
        check(in_array($entry['source'], ['showcase', 'file'], true), 'a neutral source: '.$entry['source']);
    }
    foreach (['enka', 'good', 'hsr_scanner', 'GOODScanner', 'Kamera'] as $name) {
        check(! str_contains($answer->body, '"'.$name) && ! str_contains($answer->body, $name.'"'), "{$name} is nowhere");
    }
    same([], Neutral::namesIn($answer->body), 'no other site, service or tool named');
    check(! preg_match('~https?://|www\.~', $answer->body), 'no web address');

    same([], exported(BOB)->json('accounts.0.characters') ?? [], 'Bob\'s export has his account and nothing of Alice\'s');
    same(['Bob only'], array_column(exported(BOB)->json('accounts'), 'nickname'), 'only his');
});

test('the round trip: exported, deleted, imported - the same accounts, and the same analysis', function () {
    $ids = aliceAccounts();
    $analysis = function (int $account): array {
        $data = person(ALICE)->get("/api/accounts/{$account}/characters/hutao")->status(200)->json();
        array_walk_recursive($data, function (&$value, $key) {
            if (in_array($key, ['id', 'account_id'], true)) {
                $value = null;
            }
        });

        return $data;
    };
    $before = $analysis($ids['main']);
    $file = exported(ALICE)->json();

    db()->run('DELETE FROM accounts WHERE user_id = ?', [ALICE]);
    same(['dry_run' => false, 'added' => 3, 'replaced' => 0, 'kept' => 0, 'skipped' => 0], outcome(importAs(ALICE, $file)->status(200)), 'all three added');
    same(withoutMoment($file), withoutMoment(exported(ALICE)->json()), 'the export of the copy is the export of the original');

    $main = (int) db()->value("SELECT id FROM accounts WHERE user_id = ? AND uid = '700000001'", [ALICE]);
    same($before, $analysis($main), 'the same analysis');
    same(3, (int) db()->value('SELECT count(*) FROM import_runs WHERE account_id = ?', [$main]), 'the history came back');
    same(0, (int) db()->value("SELECT count(*) FROM import_runs WHERE source = 'file'"), 'stored as the game\'s file format');
    same(['enka', 'good'], db()->column('SELECT DISTINCT source FROM account_characters WHERE account_id = ? ORDER BY 1', [$main]), 'the stored source names');
    same(['EU', 900], [Json::decode(db()->value('SELECT meta FROM accounts WHERE id = ?', [$main]))['region'] ?? null,
        Json::decode(db()->value('SELECT meta FROM accounts WHERE id = ?', [$main]))['achievements'] ?? null], 'the meta');
});

test('the same file twice changes nothing - imported over the original, or over its own copy', function () {
    aliceAccounts();
    $file = exported(ALICE)->json();
    $before = everything();
    same(['dry_run' => false, 'added' => 0, 'replaced' => 0, 'kept' => 3, 'skipped' => 0], outcome(importAs(ALICE, $file)->status(200)), 'over the original: kept');
    same($before, everything(), 'not a row changed');
    // Saved by an editor that writes a byte order mark, or sent as plain text: the same file.
    importAs(ALICE, "\xEF\xBB\xBF".json_encode($file))->status(200)->path('kept', 3);
    person(ALICE)->request('POST', '/api/me/import', null, ['body' => (string) json_encode($file), 'type' => 'text/plain'])->status(200)->path('kept', 3);
    same($before, everything(), 'still not a row');

    importAs(BOB, $file)->status(200)->path('added', 3);
    $copy = everything();
    same(['dry_run' => false, 'added' => 0, 'replaced' => 0, 'kept' => 3, 'skipped' => 0], outcome(importAs(BOB, $file)->status(200)), 'over the copy: kept');
    same($copy, everything(), 'not a row changed the second time');
});

// ── The newer copy ─────────────────────────────────────────────────────────────────────────────────

test('a newer copy in the file replaces that account\'s contents, keeping the meta it does not carry', function () {
    $ids = aliceAccounts();
    $file = exported(ALICE)->json();
    $file['accounts'][0]['last_import_at'] = '2026-09-25T09:00:00Z';
    $file['accounts'][0]['nickname'] = 'Alice, later';
    $file['accounts'][0]['meta'] = ['region' => 'ASIA'];
    $file['accounts'][0]['characters'][0]['level'] = 85;
    array_pop($file['accounts'][0]['weapons']);
    $file['accounts'][0]['imports'][] = ['source' => 'showcase', 'status' => 'success', 'file_name' => null,
        'summary' => ['characters' => 1, 'warnings' => []], 'error' => null, 'created_at' => '2026-09-25T09:00:00Z'];
    $others = [db()->all('SELECT * FROM accounts WHERE id <> ? ORDER BY id', [$ids['main']]), db()->all('SELECT * FROM account_items WHERE account_id <> ? ORDER BY id', [$ids['main']])];

    $answer = importAs(ALICE, $file)->status(200);
    same(['dry_run' => false, 'added' => 0, 'replaced' => 1, 'kept' => 2, 'skipped' => 0], outcome($answer), 'one replaced, the others kept');
    same(['replaced', 'kept', 'kept'], array_column($answer->json('accounts'), 'outcome'), 'which');

    $account = db()->one('SELECT * FROM accounts WHERE id = ?', [$ids['main']]);
    same(['Alice, later', '2026-09-25 09:00:00+00'], [$account['nickname'], $account['last_import_at']], 'the account\'s details are the file\'s');
    same(['region' => 'ASIA', 'achievements' => 900, 'tool' => 'Inventory Kamera'], Json::decode($account['meta']), 'the file\'s meta over this one\'s, the rest kept');
    same(85, (int) db()->value("SELECT ac.level FROM account_characters ac JOIN game_characters c ON c.id = ac.game_character_id WHERE ac.account_id = ? AND c.key = 'hutao'", [$ids['main']]), 'a character');
    same(1, (int) db()->value('SELECT count(*) FROM account_weapons WHERE account_id = ?', [$ids['main']]), 'the weapons are the file\'s');
    same(4, (int) db()->value('SELECT count(*) FROM import_runs WHERE account_id = ?', [$ids['main']]), 'the new run added, the three it had not doubled');
    same($others, [db()->all('SELECT * FROM accounts WHERE id <> ? ORDER BY id', [$ids['main']]), db()->all('SELECT * FROM account_items WHERE account_id <> ? ORDER BY id', [$ids['main']])], 'the other accounts untouched');
});

test('an older copy in the file is kept as this one is - and so is a copy as new as this one', function () {
    $ids = aliceAccounts();
    $file = exported(ALICE)->json();
    $before = everything();

    $older = $file;
    $older['accounts'][0]['last_import_at'] = '2026-09-19T08:00:00Z';
    $older['accounts'][0]['characters'][0]['level'] = 1;
    $older['accounts'][0]['weapons'] = [];
    same(['dry_run' => false, 'added' => 0, 'replaced' => 0, 'kept' => 3, 'skipped' => 0], outcome(importAs(ALICE, $older)->status(200)), 'older: kept');
    same($before, everything(), 'nothing changed');

    // The same second (this copy has 08:00:00.25): a tie keeps this one.
    $tie = $older;
    $tie['accounts'][0]['last_import_at'] = '2026-09-20T08:00:00Z';
    importAs(ALICE, $tie)->status(200)->path('kept', 3);
    same($before, everything(), 'a tie changed nothing either');

    // This copy without a time is the oldest.
    db()->run('UPDATE accounts SET last_import_at = NULL WHERE id = ?', [$ids['main']]);
    importAs(ALICE, $older)->status(200)->path('replaced', 1);
    same(0, (int) db()->value('SELECT count(*) FROM account_weapons WHERE account_id = ?', [$ids['main']]), 'replaced by the file\'s');
});

test('an import never deletes an account: one the file lacks stays, one only the file has is added', function () {
    $ids = aliceAccounts();
    $file = exported(ALICE)->json();
    $only = $file['accounts'][2];
    $file['accounts'] = [$file['accounts'][0]];
    $file['accounts'][0]['last_import_at'] = '2026-09-30T00:00:00Z';
    $file['accounts'][0]['characters'] = [];
    $file['accounts'][0]['weapons'] = [];
    $file['accounts'][0]['items'] = [];
    $only['uid'] = '800000002';
    $file['accounts'][] = $only;

    same(['dry_run' => false, 'added' => 1, 'replaced' => 1, 'kept' => 0, 'skipped' => 0], outcome(importAs(ALICE, $file)->status(200)), 'replaced and added');
    same(4, (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = ?', [ALICE]), 'the two the file lacks are still there, and one more');
    check(db()->one('SELECT id FROM accounts WHERE id = ?', [$ids['file']]) !== null && db()->one('SELECT id FROM accounts WHERE id = ?', [$ids['hsr']]) !== null, 'untouched');
    same(1, (int) db()->value('SELECT count(*) FROM account_items WHERE account_id = ?', [$ids['file']]), 'with what they hold');
    same(0, (int) db()->value('SELECT count(*) FROM account_characters WHERE account_id = ?', [$ids['main']]), 'the replaced one empty, as the newer copy is');
});

// ── Without a UID ────────────────────────────────────────────────────────────────────────────────

test('an account without a UID is found by when it was made, in UTC to the second, whatever zone wrote the file', function () {
    $ids = aliceAccounts();
    $before = everything();

    // The database answering in another time zone writes the same file.
    $plain = exported(ALICE)->json();
    db()->run("SET TIME ZONE 'Asia/Tokyo'");
    try {
        same(withoutMoment($plain), withoutMoment(exported(ALICE)->json()), 'the same times from a session in Tokyo');
    } finally {
        db()->run("SET TIME ZONE 'UTC'");
    }
    same('2026-09-05T06:30:00Z', $plain['accounts'][1]['created_at'], 'made at 06:30:00.123 UTC, to the second');

    // A file that writes the same moments with an offset (a copy elsewhere, a hand edit): the same account.
    $elsewhere = $plain;
    $elsewhere['accounts'][1]['created_at'] = '2026-09-05T08:30:00+02:00';
    $elsewhere['accounts'][1]['last_import_at'] = '2026-09-05T08:30:00.999+02:00';
    $answer = importAs(ALICE, $elsewhere)->status(200);
    same(['kept', 'kept', 'kept'], array_column($answer->json('accounts'), 'outcome'), 'matched and kept, not added');
    same($before, everything(), 'nothing changed');

    // Newer there: it replaces the one made at that moment, and no other.
    $elsewhere['accounts'][1]['last_import_at'] = '2026-09-06T08:30:00+02:00';
    $elsewhere['accounts'][1]['items'] = [];
    same(['kept', 'replaced', 'kept'], array_column(importAs(ALICE, $elsewhere)->status(200)->json('accounts'), 'outcome'), 'replaced');
    same(0, (int) db()->value('SELECT count(*) FROM account_items WHERE account_id = ?', [$ids['file']]), 'that account');

    // Made a second later: another account.
    $elsewhere['accounts'][1]['created_at'] = '2026-09-05T08:30:01+02:00';
    same(['kept', 'added', 'kept'], array_column(importAs(ALICE, $elsewhere)->status(200)->json('accounts'), 'outcome'), 'added');
});

test('accounts without a UID made in the same second pair up in order', function () {
    seedAccount(ALICE, 'genshin', ['nickname' => 'First', 'created_at' => '2026-09-05 06:30:00.100+00', 'last_import_at' => '2026-09-05 06:30:00.100+00']);
    seedAccount(ALICE, 'genshin', ['nickname' => 'Second', 'created_at' => '2026-09-05 06:30:00.900+00', 'last_import_at' => '2026-09-05 06:30:00.900+00']);
    $file = exported(ALICE)->json();
    same(['First', 'Second'], array_column($file['accounts'], 'nickname'), 'in the order they were made');
    $before = everything();
    importAs(ALICE, $file)->status(200)->path('kept', 2)->path('added', 0);
    same($before, everything(), 'each found its own');

    importAs(BOB, $file)->status(200)->path('added', 2);
    importAs(BOB, $file)->status(200)->path('kept', 2)->path('added', 0);
    same(['First', 'Second'], db()->column('SELECT nickname FROM accounts WHERE user_id = ? ORDER BY id', [BOB]), 'Bob\'s copy, twice, still two');
});

// ── The catalog ──────────────────────────────────────────────────────────────────────────────────

test('what this copy\'s catalog does not know is skipped and counted; equipped on an unknown character, on nobody', function () {
    aliceAccounts();
    $file = exported(ALICE)->json();
    $main = &$file['accounts'][0];
    $main['characters'][] = ['key' => 'somebodynew', 'level' => 90, 'ascension' => 6, 'rank' => 0, 'talents' => null, 'final_stats' => null,
        'source' => 'file', 'synced_at' => null];
    $main['weapons'][] = ['key' => 'anewblade', 'level' => 90, 'ascension' => 6, 'refinement' => 1, 'equipped' => 'hutao', 'locked' => false,
        'external_uid' => null, 'source' => 'file'];
    $main['weapons'][0]['equipped'] = 'somebodynew';
    $main['items'][] = ['set' => 'anewset', 'slot' => 'plume', 'rarity' => 5, 'level' => 20, 'main_stat' => 'atk', 'main_value' => 311,
        'substats' => [], 'equipped' => null, 'locked' => false, 'external_uid' => null, 'source' => 'file'];
    unset($main);

    $dry = importAs(BOB, $file, dryRun: true)->status(200);
    same(['dry_run' => true, 'added' => 3, 'replaced' => 0, 'kept' => 0, 'skipped' => 3], outcome($dry), 'the dry run counts them');
    same([3, 0, 0], array_column($dry->json('accounts'), 'skipped'), 'in the account they are in');
    importAs(BOB, $file)->status(200)->path('skipped', 3);

    $bobs = (int) db()->value("SELECT id FROM accounts WHERE user_id = ? AND uid = '700000001'", [BOB]);
    same([2, 2, 2], [(int) db()->value('SELECT count(*) FROM account_characters WHERE account_id = ?', [$bobs]),
        (int) db()->value('SELECT count(*) FROM account_weapons WHERE account_id = ?', [$bobs]),
        (int) db()->value('SELECT count(*) FROM account_items WHERE account_id = ?', [$bobs])], 'the known ones in, the unknown left out');
    same(null, db()->value("SELECT aw.equipped_character_id FROM account_weapons aw JOIN game_weapons w ON w.id = aw.game_weapon_id WHERE aw.account_id = ? AND w.key = 'staffofhoma'", [$bobs]), 'equipped on nobody');
    same(0, (int) db()->value("SELECT count(*) FROM game_characters WHERE key = 'somebodynew'") + (int) db()->value("SELECT count(*) FROM game_sets WHERE key = 'anewset'"), 'no catalog row made');
});

test('a dry run says what the import would do and changes nothing', function () {
    aliceAccounts();
    $file = exported(ALICE)->json();
    $file['accounts'][2]['last_import_at'] = '2026-09-30T00:00:00Z';
    $before = everything();

    $dry = importAs(ALICE, $file, dryRun: true)->status(200);
    same(['dry_run' => true, 'added' => 0, 'replaced' => 1, 'kept' => 2, 'skipped' => 0], outcome($dry), 'what it would do');
    same(['game' => 'hsr', 'uid' => '800000001', 'nickname' => 'Trailblazer', 'outcome' => 'replaced', 'skipped' => 0], $dry->json('accounts.2'), 'per account');
    same($before, everything(), 'nothing changed');

    $done = importAs(ALICE, $file)->status(200);
    same(array_replace($dry->json(), ['dry_run' => false]), $done->json(), 'the import did just that');
});

// ── Who ──────────────────────────────────────────────────────────────────────────────────────────

test('nobody, a visitor and a token that does not verify are refused; another person\'s data is never touched', function () {
    aliceAccounts();
    $file = exported(ALICE)->json();
    $aliceBefore = everything();

    // Nobody is asked to sign in; a token that does not verify is told so (the page signs in again).
    foreach (['nobody' => [new Client, 'sign_in_required'], 'an expired token' => [(new Client)->withToken(iamToken(ALICE, claims: ['exp' => time() - 120])), 'invalid_token']] as $who => [$client, $code]) {
        same([401, $code], [$client->get('/api/me/export')->status, $client->get('/api/me/export')->json('code')], "{$who}: the export");
        same([401, $code], [importAs(ALICE, $file, client: $client)->status, importAs(ALICE, $file, client: $client)->json('code')], "{$who}: the import");
    }

    // A browser's visitor (made by a game import without a sign-in) has no own-data file.
    $visit = (new Client('198.51.100.7'))->post('/api/imports/file', ['content' => (string) json_encode(['format' => 'GOOD', 'version' => 2,
        'source' => 'Some Exporter', 'characters' => [['key' => 'HuTao', 'level' => 80, 'constellation' => 0, 'ascension' => 5, 'talent' => ['auto' => 1, 'skill' => 1, 'burst' => 1]]],
        'weapons' => [], 'artifacts' => []])])->status(201);
    $visitor = (new Client('198.51.100.7'))->withHeaders(['X-Visitor-Key' => $visit->header('X-Visitor-Key')]);
    same(200, $visitor->get('/api/accounts')->status, 'the visitor has its accounts');
    same([401, 'sign_in_required'], [$visitor->get('/api/me/export')->status, $visitor->get('/api/me/export')->json('code')], 'a visitor: the export');
    same([401, 'sign_in_required'], [importAs(ALICE, $file, client: $visitor)->status, importAs(ALICE, $file, client: $visitor)->json('code')], 'a visitor: the import');

    // Bob imports Alice's file: copies of his own, and Alice's rows as they were.
    $aliceRows = fn () => [db()->all('SELECT * FROM accounts WHERE user_id = ? ORDER BY id', [ALICE]),
        db()->all('SELECT ac.* FROM account_characters ac JOIN accounts a ON a.id = ac.account_id WHERE a.user_id = ? ORDER BY ac.id', [ALICE]),
        db()->all('SELECT * FROM import_runs WHERE user_id = ? ORDER BY id', [ALICE])];
    $before = $aliceRows();
    importAs(BOB, $file)->status(200)->path('added', 3);
    same($before, $aliceRows(), 'Alice\'s data untouched');
    same(3, (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = ?', [BOB]), 'Bob has his own three');
    // ... and a newer copy of hers in his file replaces his, never hers.
    $file['accounts'][0]['last_import_at'] = '2026-09-30T00:00:00Z';
    $file['accounts'][0]['characters'] = [];
    importAs(BOB, $file)->status(200)->path('replaced', 1);
    same($before, $aliceRows(), 'still untouched');
    same(2, (int) db()->value("SELECT count(*) FROM account_characters ac JOIN accounts a ON a.id = ac.account_id WHERE a.user_id = ? AND a.uid = '700000001'", [ALICE]), 'hers keeps its characters');
});

// ── Refusals ─────────────────────────────────────────────────────────────────────────────────────

test('a file that is not right is refused whole, with a code and a sentence, and nothing is written', function () {
    T::$app->config->set('me.import_per_minute', 1000);
    aliceAccounts();
    $file = exported(ALICE)->json();
    $before = everything();
    $edit = function (callable $change) use ($file): array {
        $copy = $file;
        $change($copy);

        return $copy;
    };

    $cases = [
        'no body' => ['', 'no_file', []],
        'not JSON' => ['this is not JSON', 'not_a_data_file', []],
        'a list' => ['[1, 2]', 'not_a_data_file', []],
        'a builds file' => [['format' => 'build-analyzer.builds', 'format_version' => 1], 'not_a_data_file', []],
        'a newer version' => [$edit(fn (&$f) => $f['format_version'] = 2), 'newer_version', ['version' => 2]],
        'version zero' => [$edit(fn (&$f) => $f['format_version'] = 0), 'bad_entry', ['path' => 'format_version']],
        'an unknown field' => [$edit(fn (&$f) => $f['accounts'][1]['password'] = 'x'), 'bad_entry', ['path' => 'accounts[1]']],
        'a missing field' => [$edit(function (&$f) { unset($f['accounts'][0]['items'][0]['locked']); }), 'bad_entry', ['path' => 'accounts[0].items[0]']],
        'an id smuggled in' => [$edit(fn (&$f) => $f['accounts'][0]['characters'][0]['id'] = 5), 'bad_entry', ['path' => 'accounts[0].characters[0]']],
        'another game\'s slot' => [$edit(fn (&$f) => $f['accounts'][0]['items'][0]['slot'] = 'sphere'), 'bad_entry', ['path' => 'accounts[0].items[0].slot']],
        'a game this site does not know' => [$edit(fn (&$f) => $f['accounts'][2]['game'] = 'zzz'), 'bad_entry', ['path' => 'accounts[2].game']],
        'a level that is text' => [$edit(fn (&$f) => $f['accounts'][0]['characters'][0]['level'] = '90'), 'bad_entry', ['path' => 'accounts[0].characters[0].level']],
        'a source by its name' => [$edit(fn (&$f) => $f['accounts'][0]['weapons'][0]['source'] = 'good'), 'bad_entry', ['path' => 'accounts[0].weapons[0].source']],
        'a time without its zone' => [$edit(fn (&$f) => $f['accounts'][0]['created_at'] = '2026-09-01 10:00:00'), 'bad_entry', ['path' => 'accounts[0].created_at']],
        'a meta key nobody shows' => [$edit(fn (&$f) => $f['accounts'][0]['meta']['tool'] = 'x'), 'bad_entry', ['path' => 'accounts[0].meta.tool']],
        'one UID twice' => [$edit(fn (&$f) => $f['accounts'][1]['uid'] = '700000001'), 'bad_entry', ['path' => 'accounts[1]']],
        'a character twice' => [$edit(fn (&$f) => $f['accounts'][0]['characters'][1] = $f['accounts'][0]['characters'][0]), 'bad_entry', ['path' => 'accounts[0].characters[1].key']],
        'a nickname too long' => [$edit(fn (&$f) => $f['accounts'][0]['nickname'] = str_repeat('x', 101)), 'bad_entry', ['path' => 'accounts[0].nickname']],
        'too many substats' => [$edit(fn (&$f) => $f['accounts'][0]['items'][0]['substats'] = array_fill(0, 11, ['key' => 'hp', 'value' => 1, 'rolls' => null])), 'bad_entry', ['path' => 'accounts[0].items[0].substats']],
        'an unknown summary field' => [$edit(fn (&$f) => $f['accounts'][0]['imports'][0]['summary']['tool'] = 'x'), 'bad_entry', ['path' => 'accounts[0].imports[0].summary.tool']],
    ];
    foreach ($cases as $what => [$body, $code, $params]) {
        foreach ([true, false] as $dry) {
            $answer = importAs(ALICE, $body, $dry)->status(422);
            same([$code, $params], [$answer->json('code'), $answer->json('params')], "{$what} ({$answer->body})");
            check(is_string($answer->json('message')) && $answer->json('message') !== '', "{$what}: a sentence");
        }
    }
    same('accounts[0].items[0].slot: not a slot of Genshin Impact.', importAs(ALICE, $cases['another game\'s slot'][0])->json('message'), 'the sentence names the place');
    same($before, everything(), 'nothing written');

    // Larger than me.file_max_mb: refused before it is read.
    T::$app->config->set('me.file_max_mb', 0.001);
    $answer = importAs(ALICE, $file)->status(422);
    same(['file_too_big', ['max_mb' => 0.001]], [$answer->json('code'), $answer->json('params')], 'too big');
    same($before, everything(), 'still nothing');
});

test('the export never writes what the import would refuse: past the size, it says so', function () {
    aliceAccounts();
    $size = strlen(exported(ALICE)->body);
    T::$app->config->set('me.file_max_mb', ($size - 1) / 1048576);
    $answer = person(ALICE)->get('/api/me/export')->status(422)->path('code', 'export_too_big');
    same(500, $answer->json('params.max_accounts'), 'the caps in its parameters');
    T::$app->config->set('me.file_max_mb', $size / 1048576);
    importAs(ALICE, exported(ALICE)->body)->status(200)->path('kept', 3);
});

test('five imports a minute per person, dry runs included', function () {
    aliceAccounts();
    $file = exported(ALICE)->body;
    for ($i = 1; $i <= 5; $i++) {
        importAs(ALICE, $file, dryRun: $i % 2 === 1)->status(200);
    }
    importAs(ALICE, $file)->status(429)->path('code', 'too_many_imports');
    importAs(BOB, $file)->status(200);
});

test('an import takes turns with the person\'s other writes: it waits for the person\'s row', function () {
    aliceAccounts();
    $file = exported(ALICE)->body;
    db()->run('DELETE FROM accounts WHERE user_id = ?', [ALICE]);

    // Another request of Alice's holds her row. FOR NO KEY UPDATE: it does not stop the foreign-key checks of
    // inserted accounts (FOR KEY SHARE), so only the import's own lock of the row can make it wait.
    $other = otherConnection();
    $other->beginTransaction();
    $other->prepare('SELECT id FROM users WHERE id = ? FOR NO KEY UPDATE')->execute([ALICE]);
    db()->run("SET lock_timeout = '200ms'");
    try {
        importAs(ALICE, $file, dryRun: true)->status(200)->path('added', 3);
        same(500, importAs(ALICE, $file)->status, 'the import waited for the row (here: until its lock timeout)');
        same(500, person(ALICE)->post('/api/imports/file', ['content' => (string) json_encode(['format' => 'GOOD', 'version' => 2, 'source' => 'x',
            'characters' => [['key' => 'HuTao', 'level' => 80, 'constellation' => 0, 'ascension' => 5, 'talent' => ['auto' => 1, 'skill' => 1, 'burst' => 1]]],
            'weapons' => [], 'artifacts' => []])])->status, 'and so does a game import');
        importAs(BOB, $file)->status(200)->path('added', 3);
    } finally {
        $other->rollBack();
        db()->run('SET lock_timeout = 0');
    }
    same(0, (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = ?', [ALICE]), 'nothing of Alice\'s written meanwhile');
    importAs(ALICE, $file)->status(200)->path('added', 3);
});

test('a person whose data an admin deleted while their file was on its way: 401 sign_in_required, nothing written', function () {
    aliceAccounts();
    $file = AccountsFile::read(json_decode(exported(ALICE)->body, true));
    $alice = T::$app->get(Users::class)->find(ALICE) ?? throw new AssertionFailed('no Alice');
    // The request found Alice; then the People page deleted her row, and her accounts with it.
    db()->run('DELETE FROM users WHERE id = ?', [ALICE]);

    try {
        T::$app->get(AccountsTransfer::class)->import($alice, $file, false);
        check(false, 'the import went through');
    } catch (HttpError $e) {
        same([401, 'sign_in_required'], [$e->status, $e->extra['code'] ?? null], 'the refusal');
    }
    same(0, (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = ?', [ALICE]), 'nothing written under her id');
});

finishTests();
