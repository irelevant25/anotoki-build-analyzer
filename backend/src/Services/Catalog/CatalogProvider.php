<?php

namespace App\Services\Catalog;

use App\Enums\Game;

/**
 * Where a game's catalog comes from (config/app.php "catalog.{game}.provider"): one list at a time, as rows the
 * catalog stores - keys made the way App\Support\Key makes them, in-game IDs as external_id -, never more than the
 * columns of App\Repositories\CatalogRows.
 */
interface CatalogProvider
{
    /**
     * One list (Catalog::CHARACTER, WEAPON or SET) as the provider has it now.
     *
     * @param  ?string  $etag  the version tag of the list as it was last read: a conditional request, answered
     *                         "unchanged" while the list is as it was
     */
    public function fetch(Game $game, string $list, ?string $etag): CatalogAnswer;
}
