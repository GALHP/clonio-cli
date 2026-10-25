<?php

declare(strict_types=1);

namespace App\Data\Cloning;

/**
 * Timings of a single chunk-loop iteration inside `transferTable()`, folded
 * into `StatsTableTransferData::recordLoop()` and not kept afterwards.
 *
 * `rowsDone` and `rowsSkipped` count rows for **this loop only**, not
 * cumulative — inspect the parent `StatsTableTransferData` for
 * cumulative progress.
 */
final readonly class StatsLoopData
{
    public function __construct(
        public int $chunkRows,
        public float $selectSeconds,
        public float $transformSeconds,
        public float $insertSeconds,
        public float $overallSeconds,
        public int $rowsDone,
        public int $rowsSkipped,
    ) {}
}
