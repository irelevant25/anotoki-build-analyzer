<?php

/**
 * sync-builds.php - read recommended builds from the build sources, from the command line
 *
 *   php sync-builds.php [source]                 every enabled source (or one: its key, or its API key, e.g.
 *                                                genshin-1)
 *     --game=genshin                             only the sources of one game
 *     --character=hu_tao                         only these characters (slug or name; repeatable)
 *     --limit=5                                  stop after this many characters per source
 *     --force                                    also sources synced in the last stale_hours
 *     --check-names                              only list each site's characters and report the names the
 *                                                catalog does not know
 *     --dry-run                                  parse and compare with the stored builds, write nothing
 *     -v                                         with --dry-run: a line for every character
 *     --cache-hours=96                           reuse pages fetched in the last 96 hours (default: the
 *                                                cache_hours of config/fetch.local.php, 12) - with --dry-run,
 *                                                a parser change is checked against pages already fetched
 *
 * The same stepped runs as "Sync now" / "Sync all" on the Sources page: a stale catalog is refreshed first,
 * every source is worked through step by step (joining a run already going, from the browser say), and
 * each site's crawl delay holds across all of them. The waits are slept through here.
 *
 * Unlike the UI, the command line names the sites (it is the developer's tool). (App\Commands\SyncBuilds)
 */

require __DIR__ . '/vendor/autoload.php';

App\Cli::helpIfAsked($argv, __FILE__);

exit(App\Commands\SyncBuilds::run(App\Cli::app(), $argv));
