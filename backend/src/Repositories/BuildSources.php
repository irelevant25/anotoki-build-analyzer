<?php

namespace App\Repositories;

use App\Db\Db;
use App\Models\BuildSource;
use App\Support\Clock;

final class BuildSources
{
    public function __construct(private readonly Db $db) {}

    public function byKey(string $key): ?BuildSource
    {
        $row = $this->db->one('SELECT * FROM build_sources WHERE key = ?', [$key]);

        return $row === null ? null : BuildSource::fromRow($row);
    }

    public function find(int $id): ?BuildSource
    {
        $row = $this->db->one('SELECT * FROM build_sources WHERE id = ?', [$id]);

        return $row === null ? null : BuildSource::fromRow($row);
    }

    /**
     * The row for a source key, created (switched on) when there is none.
     */
    public function firstOrCreate(string $key, string $game, string $name, string $baseUrl): BuildSource
    {
        $now = Clock::now();
        $this->db->run(
            'INSERT INTO build_sources (key, game, name, base_url, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, TRUE, ?, ?)
             ON CONFLICT (key) DO NOTHING',
            [$key, $game, $name, $baseUrl, $now, $now],
        );

        return $this->byKey($key) ?? throw new \RuntimeException("Could not store the build source {$key}");
    }

    /** @param array<string, mixed> $values */
    public function update(BuildSource $source, array $values): void
    {
        $this->db->update('build_sources', $values + ['updated_at' => Clock::now()], ['id' => $source->id]);
        foreach ($values as $column => $value) {
            if (property_exists($source, $column)) {
                $source->{$column} = $value;
            }
        }
    }

    /**
     * Builds and characters per source.
     *
     * @return array<int, array{builds: int, characters: int}> source id => counts
     */
    public function counts(): array
    {
        $counts = [];
        foreach ($this->db->all('SELECT build_source_id, count(*) AS builds, count(DISTINCT game_character_id) AS characters FROM builds GROUP BY build_source_id') as $row) {
            $counts[(int) $row['build_source_id']] = ['builds' => (int) $row['builds'], 'characters' => (int) $row['characters']];
        }

        return $counts;
    }
}
