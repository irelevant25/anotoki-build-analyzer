<?php

namespace App\Services\Import;

use App\Enums\Game;
use App\Models\Account;
use App\Models\ImportRun;
use App\Services\Import\Enka\EnkaClient;
use App\Services\Import\Enka\EnkaGenshinParser;
use App\Services\Import\Enka\EnkaHsrParser;
use App\Services\Import\Files\GoodParser;
use App\Services\Import\Files\HsrScannerParser;
use Throwable;

/**
 * Entry point for all imports (API and console).
 */
class ImportService
{
    public function __construct(
        private readonly EnkaClient $enka,
        private readonly EnkaGenshinParser $enkaGenshin,
        private readonly EnkaHsrParser $enkaHsr,
        private readonly GoodParser $good,
        private readonly HsrScannerParser $hsrScanner,
        private readonly AccountImporter $importer,
    ) {}

    /**
     * @param  Account|null  $target  merge into this account (e.g. one created from a file, which has no UID yet);
     *                                by default the account is found by UID
     */
    public function fromEnka(Game $game, string $uid, ?Account $target = null): ImportRun
    {
        $uid = trim($uid);

        try {
            $json = $this->enka->profile($game, $uid);
            $parsed = $game === Game::Genshin ? $this->enkaGenshin->parse($json) : $this->enkaHsr->parse($json);
            $parsed->uid ??= $uid;

            return $this->importer->import($parsed, $target);
        } catch (Throwable $e) {
            $this->importer->recordFailure($game, 'enka', $e, $target ?? Account::query()->where('game', $game->value)->where('uid', $uid)->first());

            throw $e;
        }
    }

    /**
     * Import an exported JSON file. The format (GOOD or HSR scanner) is detected from the content.
     */
    public function fromFile(string $contents, ?Game $expectedGame = null, ?Account $target = null, ?string $fileName = null): ImportRun
    {
        $source = 'file';
        $game = $expectedGame ?? $target?->game ?? Game::Genshin;

        try {
            // Some exporters write a UTF-8 BOM.
            $json = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents, true);
            if (! is_array($json)) {
                throw new ImportException('The file is not valid JSON.');
            }

            [$game, $source, $parsed] = match (true) {
                GoodParser::matches($json) => [Game::Genshin, 'good', $this->good->parse($json)],
                HsrScannerParser::matches($json) => [Game::Hsr, 'hsr_scanner', $this->hsrScanner->parse($json)],
                default => throw new ImportException('Unknown file format. Upload a GOOD export (Genshin: Irminsul, Inventory Kamera...) or a Reliquary Archiver / HSR-Scanner export (Star Rail).'),
            };

            if ($expectedGame && $expectedGame !== $game) {
                throw new ImportException("This file contains {$game->label()} data, but you chose {$expectedGame->label()}.");
            }

            return $this->importer->import($parsed, $target, $fileName);
        } catch (Throwable $e) {
            $this->importer->recordFailure($game, $source, $e, $target, $fileName);

            throw $e;
        }
    }
}
