<?php

namespace App\Services\Catalog;

use App\App;
use App\Enums\Game;
use LogicException;

/**
 * Each game's catalog provider, by config/app.php "catalog.{game}.provider" (a class of CatalogProvider's, made by
 * the container - a test puts in one of its own).
 */
final class CatalogProviders
{
    public function __construct(private readonly App $app) {}

    public function for(Game $game): CatalogProvider
    {
        $class = $this->app->config->get("catalog.{$game->value}.provider");
        if (!is_string($class) || !is_subclass_of($class, CatalogProvider::class)) {
            throw new LogicException("No catalog provider for {$game->label()} (config/app.php catalog.{$game->value}.provider).");
        }

        return $this->app->get($class);
    }
}
