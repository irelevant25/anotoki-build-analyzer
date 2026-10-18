<?php

namespace App\Services\Sync;

use App\Models\SyncRun;
use App\Support\Neutral;

/**
 * A run's log, extended as the run goes (saved with the run), so a paused or failed run shows how far it
 * got. A very long log keeps its beginning and its end, where the summary is.
 *
 * The log is shown on the Sources page, so every line is written without other sites' names or addresses
 * (App\Support\Neutral): an exception message that names a URL arrives as "the source page".
 */
final class RunLog
{
    private const LIMIT = 60000;

    private const HEAD = 20000;

    /** @var list<string> */
    private array $written = [];

    public function __construct(private readonly SyncRun $run) {}

    public function add(string $line): void
    {
        $line = (string) Neutral::text($line);
        $this->written[] = $line;

        $log = ($this->run->log ?? '') === '' ? $line : $this->run->log . "\n" . $line;
        if (strlen($log) > self::LIMIT) {
            // mb_strcut counts bytes but never splits a character.
            $log = mb_strcut($log, 0, self::HEAD) . "\n…\n" . mb_strcut($log, -(self::LIMIT - self::HEAD - 8));
        }
        $this->run->log = $log;
    }

    /**
     * @return list<string> the lines added through this instance
     */
    public function written(): array
    {
        return $this->written;
    }
}
