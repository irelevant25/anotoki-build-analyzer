<?php

namespace App\Services\Transfer;

use App\Auth\Visitors;
use App\Config;
use App\Db\Db;
use App\Enums\Game;
use App\Models\User;
use App\Repositories\CatalogRows;
use App\Repositories\Users;
use App\Services\Analysis\CharacterAnalyzer;
use App\Services\Catalog\Catalog;
use App\Support\Clock;
use App\Support\Json;
use App\Version;
use DateTimeImmutable;

/**
 * A signed-in person's own data, out to a file and back (AccountsFile is the file).
 *
 * The export is every game account of theirs, each with what it holds and its newest import runs; never
 * anybody else's (every query takes the person). It always fits what the import reads: past
 * config/app.php me.file_max_mb, or past the file's caps, it is refused as a whole (export_too_big) rather
 * than written as a file no copy could read back.
 *
 * The import never deletes an account, and never touches one of the person's that the file lacks. For each
 * account of the file, in its order:
 *
 *   - it is matched with one of the person's here: by game and UID; without a UID, by game and when it was
 *     made (created_at, compared in UTC to the second, as the file writes it - so the same file twice, or a
 *     copy on a host in another time zone, finds the same account);
 *   - no match: the account is added, with its characters, weapons, items and import history;
 *   - a match: the newer copy wins, by last_import_at (to the second; no time is the oldest; a tie keeps
 *     this one). A newer copy in the file replaces that account's contents - its details, characters,
 *     weapons and items - and adds the file's import runs it lacks (the same time and kind); the meta keys
 *     the file does not carry stay. Otherwise nothing of it changes (kept);
 *   - a character, weapon or set this copy's catalog does not know is left out and counted (skipped):
 *     imports never create catalog rows; a weapon or item equipped on a character it does not know is kept,
 *     equipped on nobody.
 *
 * A dry run answers the same counts and changes nothing. The import itself runs in one transaction that
 * first takes the person's users row FOR UPDATE, so two imports of files - or one and the person's own game
 * import, or the move at sign-in - take turns. No import run is recorded for the import itself.
 */
final class AccountsTransfer
{
    public function __construct(private readonly Db $db, private readonly Users $users, private readonly CatalogRows $catalog, private readonly Config $config) {}

    /** The largest file the import reads, and so the largest the export writes. */
    public function maxBytes(): int
    {
        return (int) round($this->maxMb() * 1048576);
    }

    public function maxMb(): float|int
    {
        $mb = $this->config->get('me.file_max_mb', 8);

        return is_numeric($mb) && $mb > 0 ? $mb + 0 : 8;
    }

    /**
     * The person's accounts as the file's text (compact JSON) and the name to save it under.
     *
     * @return array{body: string, filename: string}
     *
     * @throws AccountsFileException export_too_big
     */
    public function export(User $person): array
    {
        $accounts = $this->db->all('SELECT * FROM accounts WHERE user_id = ? ORDER BY game, created_at, id', [$person->id]);
        if (count($accounts) > AccountsFile::MAX_ACCOUNTS) {
            throw $this->tooBig();
        }

        $byAccount = function (string $sql) use ($person): array {
            $grouped = [];
            foreach ($this->db->all($sql, [$person->id]) as $row) {
                $grouped[(int) $row['account_id']][] = $row;
            }

            return $grouped;
        };
        $characters = $byAccount('SELECT ac.*, c.key AS character_key
              FROM account_characters ac JOIN accounts a ON a.id = ac.account_id JOIN game_characters c ON c.id = ac.game_character_id
             WHERE a.user_id = ? ORDER BY ac.account_id, ac.id');
        $weapons = $byAccount('SELECT aw.*, w.key AS weapon_key, e.key AS equipped_key
              FROM account_weapons aw JOIN accounts a ON a.id = aw.account_id JOIN game_weapons w ON w.id = aw.game_weapon_id
              LEFT JOIN game_characters e ON e.id = aw.equipped_character_id
             WHERE a.user_id = ? ORDER BY aw.account_id, aw.id');
        $items = $byAccount('SELECT i.*, s.key AS set_key, e.key AS equipped_key
              FROM account_items i JOIN accounts a ON a.id = i.account_id JOIN game_sets s ON s.id = i.game_set_id
              LEFT JOIN game_characters e ON e.id = i.equipped_character_id
             WHERE a.user_id = ? ORDER BY i.account_id, i.id');
        $runs = $byAccount('SELECT r.* FROM import_runs r JOIN accounts a ON a.id = r.account_id
             WHERE a.user_id = r.user_id AND a.user_id = ? ORDER BY r.account_id, r.created_at, r.id');

        $entries = [];
        foreach ($accounts as $row) {
            $id = (int) $row['id'];
            if (count($characters[$id] ?? []) > AccountsFile::MAX_CHARACTERS || count($weapons[$id] ?? []) > AccountsFile::MAX_WEAPONS || count($items[$id] ?? []) > AccountsFile::MAX_ITEMS) {
                throw $this->tooBig();
            }
            $entries[] = AccountsFile::account($row, $characters[$id] ?? [], $weapons[$id] ?? [], $items[$id] ?? [], $runs[$id] ?? []);
        }

        $now = Clock::now();
        $body = json_encode(AccountsFile::file(Version::CURRENT, $now, $entries), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (strlen($body) > $this->maxBytes()) {
            throw $this->tooBig();
        }

        return ['body' => $body, 'filename' => 'build-analyzer-accounts-' . $now->format('Y-m-d') . '.json'];
    }

    /**
     * Imports a file AccountsFile::read() accepted into the person's accounts, or with $dryRun only says
     * what that would do.
     *
     * @param  array{exported_at: DateTimeImmutable, accounts: list<array<string, mixed>>}  $file
     * @return array{dry_run: bool, added: int, replaced: int, kept: int, skipped: int, accounts: list<array<string, mixed>>}
     *
     * @throws AccountsFileException too_many_accounts
     */
    public function import(User $person, array $file, bool $dryRun): array
    {
        if ($dryRun) {
            return $this->answer($this->plan($person, $file), true);
        }

        return $this->db->transaction(function () use ($person, $file): array {
            // The plan is made under the lock, from the accounts as they are now. A person whose data an
            // admin deleted while the file was on its way has no row to write under any more.
            if (!$this->users->lockForWrite($person->id)) {
                throw Visitors::ownerGone($person);
            }
            $plan = $this->plan($person, $file);
            $this->apply($person, $plan);

            return $this->answer($plan, false);
        });
    }

    /**
     * What becomes of each account of the file: added, replaced (with the id of the person's account) or
     * kept, the catalog ids of its entries, and how many of them this copy does not know.
     *
     * @return list<array<string, mixed>>
     */
    private function plan(User $person, array $file): array
    {
        $existing = $this->db->all('SELECT id, game, uid, created_at, last_import_at, meta FROM accounts WHERE user_id = ? ORDER BY created_at, id', [$person->id]);
        $byUid = [];
        $byMade = [];
        foreach ($existing as $row) {
            if ($row['uid'] !== null) {
                $byUid[$row['game'] . '|' . $row['uid']] = $row;
            } else {
                $byMade[$row['game'] . '|' . $this->second($row['created_at'])][] = $row;
            }
        }

        $ids = $this->catalogIds($file['accounts']);
        $plan = [];
        $added = 0;
        foreach ($file['accounts'] as $account) {
            /** @var Game $game */
            $game = $account['game'];
            if ($account['uid'] !== null) {
                $match = $byUid[$game->value . '|' . $account['uid']] ?? null;
            } else {
                // Several made in the same second pair up in order, each with one of the person's.
                $made = $game->value . '|' . ($account['created_at'] ? AccountsFile::time($account['created_at']) : '-');
                $match = isset($byMade[$made]) ? array_shift($byMade[$made]) : null;
            }

            if ($match === null) {
                $outcome = 'added';
                $added++;
            } else {
                $ours = $this->second($match['last_import_at']);
                $theirs = $account['last_import_at'] ? AccountsFile::time($account['last_import_at']) : '-';
                // "-" sorts before every time: no time is the oldest.
                $outcome = strcmp($theirs, $ours) > 0 ? 'replaced' : 'kept';
            }

            $step = [
                'outcome' => $outcome,
                'account' => $account,
                'id' => $match === null ? null : (int) $match['id'],
                'meta' => $match === null ? [] : Json::decode($match['meta']) ?? [],
                'skipped' => 0,
            ];
            if ($outcome !== 'kept') {
                $step = $this->resolve($step, $ids[$game->value]);
            }
            $plan[] = $step;
        }

        if ($added > 0 && count($existing) + $added > AccountsFile::MAX_ACCOUNTS) {
            throw new AccountsFileException(
                'too_many_accounts',
                ['max' => AccountsFile::MAX_ACCOUNTS],
                'After this import you would have more than ' . AccountsFile::MAX_ACCOUNTS . ' game accounts, more than one file can carry. Delete accounts you no longer need first.',
            );
        }

        return $plan;
    }

    /**
     * The catalog ids of every key the file names, per game and list (key => id); a key this copy lacks
     * has none.
     *
     * @return array<string, array<string, array<string, int>>>
     */
    private function catalogIds(array $accounts): array
    {
        $keys = [];
        foreach (Game::cases() as $game) {
            $keys[$game->value] = [Catalog::CHARACTER => [], Catalog::WEAPON => [], Catalog::SET => []];
        }
        foreach ($accounts as $account) {
            $at = &$keys[$account['game']->value];
            foreach ($account['characters'] as $character) {
                $at[Catalog::CHARACTER][] = $character['key'];
            }
            foreach ($account['weapons'] as $weapon) {
                $at[Catalog::WEAPON][] = $weapon['key'];
                $at[Catalog::CHARACTER][] = $weapon['equipped'];
            }
            foreach ($account['items'] as $item) {
                $at[Catalog::SET][] = $item['set'];
                $at[Catalog::CHARACTER][] = $item['equipped'];
            }
            unset($at);
        }

        $ids = [];
        foreach ($keys as $game => $lists) {
            foreach ($lists as $type => $names) {
                $names = array_values(array_unique(array_filter($names, 'is_string')));
                $ids[$game][$type] = array_map(fn($entry) => $entry->id, $names === [] ? [] : $this->catalog->byKeys(Game::from($game), $type, $names));
            }
        }

        return $ids;
    }

    /** The step with its entries' catalog ids, and what was left out. */
    private function resolve(array $step, array $ids): array
    {
        $account = $step['account'];
        $skipped = 0;
        $equipped = fn(?string $key): ?int => $key === null ? null : $ids[Catalog::CHARACTER][$key] ?? null;

        $characters = [];
        foreach ($account['characters'] as $character) {
            $id = $ids[Catalog::CHARACTER][$character['key']] ?? null;
            if ($id === null) {
                $skipped++;
            } else {
                $characters[] = $character + ['catalog_id' => $id];
            }
        }
        $weapons = [];
        foreach ($account['weapons'] as $weapon) {
            $id = $ids[Catalog::WEAPON][$weapon['key']] ?? null;
            if ($id === null) {
                $skipped++;
            } else {
                $weapons[] = $weapon + ['catalog_id' => $id, 'equipped_id' => $equipped($weapon['equipped'])];
            }
        }
        $items = [];
        foreach ($account['items'] as $item) {
            $id = $ids[Catalog::SET][$item['set']] ?? null;
            if ($id === null) {
                $skipped++;
            } else {
                $items[] = $item + ['catalog_id' => $id, 'equipped_id' => $equipped($item['equipped'])];
            }
        }

        return ['characters' => $characters, 'weapons' => $weapons, 'items' => $items, 'skipped' => $skipped] + $step;
    }

    private function apply(User $person, array $plan): void
    {
        $now = Clock::now();
        foreach ($plan as $step) {
            if ($step['outcome'] === 'kept') {
                continue;
            }
            $account = $step['account'];
            /** @var Game $game */
            $game = $account['game'];
            $columns = [
                'nickname' => $account['nickname'],
                'level' => $account['level'],
                'world_level' => $account['world_level'],
                'signature' => $account['signature'],
                'has_full_inventory' => $account['has_full_inventory'],
                'last_import_at' => $account['last_import_at'],
                'updated_at' => $now,
            ];

            if ($step['outcome'] === 'added') {
                $meta = $account['meta'];
                $id = (int) $this->db->insert(
                    'accounts',
                    $columns + [
                        'user_id' => $person->id,
                        'game' => $game->value,
                        'uid' => $account['uid'],
                        'meta' => Json::column($meta === [] ? null : $meta),
                        'created_at' => $account['created_at'],
                    ],
                );
                $have = [];
            } else {
                $id = (int) $step['id'];
                $meta = array_merge($step['meta'], $account['meta']);
                $this->db->update('accounts', $columns + ['meta' => Json::column($meta === [] ? null : $meta)], ['id' => $id, 'user_id' => $person->id]);
                $this->db->run('DELETE FROM account_characters WHERE account_id = ?', [$id]);
                $this->db->run('DELETE FROM account_weapons WHERE account_id = ?', [$id]);
                $this->db->run('DELETE FROM account_items WHERE account_id = ?', [$id]);
                $have = [];
                foreach ($this->db->all('SELECT source, created_at FROM import_runs WHERE account_id = ? AND user_id = ?', [$id, $person->id]) as $run) {
                    $have[$this->second($run['created_at']) . '|' . CharacterAnalyzer::importKind((string) $run['source'])] = true;
                }
            }

            $this->db->insertMany(
                'account_characters',
                array_map(
                    fn(array $character) => [
                        'account_id' => $id,
                        'game_character_id' => $character['catalog_id'],
                        'level' => $character['level'],
                        'ascension' => $character['ascension'],
                        'rank' => $character['rank'],
                        'talents' => Json::column($character['talents']),
                        'final_stats' => Json::column($character['final_stats']),
                        'source' => self::stored($game, $character['source']),
                        'synced_at' => $character['synced_at'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $step['characters'],
                ),
                200,
            );

            $this->db->insertMany(
                'account_weapons',
                array_map(
                    fn(array $weapon) => [
                        'account_id' => $id,
                        'game_weapon_id' => $weapon['catalog_id'],
                        'level' => $weapon['level'],
                        'ascension' => $weapon['ascension'],
                        'refinement' => $weapon['refinement'],
                        'equipped_character_id' => $weapon['equipped_id'],
                        'locked' => $weapon['locked'],
                        'external_uid' => $weapon['external_uid'],
                        'source' => self::stored($game, $weapon['source']),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $step['weapons'],
                ),
                200,
            );

            $this->db->insertMany(
                'account_items',
                array_map(
                    fn(array $item) => [
                        'account_id' => $id,
                        'game_set_id' => $item['catalog_id'],
                        'slot' => $item['slot'],
                        'rarity' => $item['rarity'],
                        'level' => $item['level'],
                        'main_stat' => $item['main_stat'],
                        'main_value' => $item['main_value'],
                        'substats' => Json::encode($item['substats']),
                        'equipped_character_id' => $item['equipped_id'],
                        'locked' => $item['locked'],
                        'external_uid' => $item['external_uid'],
                        'source' => self::stored($game, $item['source']),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $step['items'],
                ),
                100,
            );

            $runs = [];
            foreach ($account['imports'] as $run) {
                $same = ($run['created_at'] ? AccountsFile::time($run['created_at']) : '-') . '|' . $run['source'];
                if (isset($have[$same])) {
                    continue;
                }
                $have[$same] = true;
                $runs[] = [
                    'user_id' => $person->id,
                    'account_id' => $id,
                    'game' => $game->value,
                    'source' => self::stored($game, $run['source']),
                    'status' => $run['status'],
                    'file_name' => $run['file_name'],
                    'summary' => Json::column($run['summary']),
                    'error' => $run['error'],
                    'created_at' => $run['created_at'],
                    'updated_at' => $now,
                ];
            }
            $this->db->insertMany('import_runs', $runs, 100);
        }
    }

    /** @return array{dry_run: bool, added: int, replaced: int, kept: int, skipped: int, accounts: list<array<string, mixed>>} */
    private function answer(array $plan, bool $dryRun): array
    {
        $count = fn(string $outcome) => count(array_filter($plan, fn(array $step) => $step['outcome'] === $outcome));

        return [
            'dry_run' => $dryRun,
            'added' => $count('added'),
            'replaced' => $count('replaced'),
            'kept' => $count('kept'),
            'skipped' => array_sum(array_column($plan, 'skipped')),
            'accounts' => array_map(
                fn(array $step) => [
                    'game' => $step['account']['game']->value,
                    'uid' => $step['account']['uid'],
                    'nickname' => $step['account']['nickname'],
                    'outcome' => $step['outcome'],
                    'skipped' => $step['skipped'],
                ],
                $plan,
            ),
        ];
    }

    /** What the database calls a source: the showcase service's name, or the game's file format. */
    private static function stored(Game $game, string $kind): string
    {
        return $kind === 'showcase' ? 'enka' : ($game === Game::Genshin ? 'good' : 'hsr_scanner');
    }

    /** A stored time as the file writes it ("-" for none). */
    private function second(mixed $value): string
    {
        $time = is_string($value) ? Clock::parse($value) : null;

        return $time === null ? '-' : (string) AccountsFile::time($time);
    }

    private function tooBig(): AccountsFileException
    {
        $mb = $this->maxMb();

        return new AccountsFileException(
            'export_too_big',
            ['max_mb' => $mb, 'max_accounts' => AccountsFile::MAX_ACCOUNTS],
            "Your accounts take more than one file carries (at most {$mb} MB and " . AccountsFile::MAX_ACCOUNTS . ' game accounts). Delete accounts you no longer need, then export again.',
        );
    }
}
