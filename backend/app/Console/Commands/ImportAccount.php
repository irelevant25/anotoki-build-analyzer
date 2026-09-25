<?php

namespace App\Console\Commands;

use App\Enums\Game;
use App\Models\Account;
use App\Services\Import\ImportException;
use App\Services\Import\ImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('accounts:import {game : genshin or hsr} {source : A UID (Enka.Network showcase) or a path to an exported JSON file} {--account= : Existing account ID to merge a file into}')]
#[Description('Import a game account from Enka.Network (UID) or from a GOOD / Reliquary Archiver / HSR-Scanner file')]
class ImportAccount extends Command
{
    public function handle(ImportService $imports): int
    {
        $game = Game::from($this->argument('game'));
        $source = (string) $this->argument('source');
        $target = $this->option('account') ? Account::query()->findOrFail($this->option('account')) : null;

        try {
            $run = is_file($source)
                ? $imports->fromFile((string) file_get_contents($source), $game, $target, basename($source))
                : $imports->fromEnka($game, $source);
        } catch (ImportException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $summary = $run->summary;
        $this->info(sprintf('Imported into account #%d: %d characters, %d %s, %d %s (%s).',
            $run->account_id, $summary['characters'], $summary['weapons'], strtolower($game->weaponLabel()).'s',
            $summary['items'], strtolower($game->itemLabel()).'s', $run->status));

        foreach ($summary['warnings'] ?? [] as $warning) {
            $this->warn("  ! {$warning}");
        }
        foreach ($summary['unmatched'] ?? [] as $bucket => $names) {
            $this->warn("  ? not in catalog ({$bucket}): ".implode(', ', $names));
        }

        return self::SUCCESS;
    }
}
