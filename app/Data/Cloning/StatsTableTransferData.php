<?php

declare(strict_types=1);

namespace App\Data\Cloning;

/**
 * Per-table timing container. One instance travels through every chunk
 * loop of a single `transferTable()` invocation.
 *
 * Each chunk is folded in via `recordLoop(StatsLoopData)` into O(1) running
 * per-phase aggregates; no per-chunk history is kept, so memory stays flat
 * however many chunks a table needs.
 */
final class StatsTableTransferData
{
    public private(set) ?TableRunPhase $status = null;

    /** Wall-clock seconds for each one-shot phase; null until (and unless) it runs. */
    public private(set) ?float $countingRowsSeconds = null;

    public private(set) ?float $disableFkSeconds = null;

    public private(set) ?float $clearTableSeconds = null;

    public private(set) int $totalRows = 0;

    public private(set) int $rowsDone = 0;

    public private(set) int $rowsSkipped = 0;

    /** Rows accounted for so far (transferred + skipped). */
    public int $rowsProcessed {
        get => $this->rowsDone + $this->rowsSkipped;
    }

    /** Rows still outstanding against `totalRows` (never negative). */
    public int $rowsRemaining {
        get => max(0, $this->totalRows - $this->rowsProcessed);
    }

    /** Completion ratio in the range [0, 100], or null when the total is unknown. */
    public ?float $percentComplete {
        get => $this->totalRows > 0
            ? min(100.0, ($this->rowsProcessed / $this->totalRows) * 100.0)
            : null;
    }

    /**
     * Estimated wall-clock seconds until this table finishes, from the latest
     * loop pace × outstanding rows. 0.0 once no rows remain, and 0.0 until a
     * row total and at least one completed loop are known.
     */
    public float $estimatedSecondsRemaining {
        get {
            if ($this->rowsRemaining <= 0) {
                return 0.0;
            }

            return $this->loopAggregate->latestPace * $this->rowsRemaining;
        }
    }

    public private(set) StatsPhaseAggregateData $selectAggregate;

    public private(set) StatsPhaseAggregateData $transformAggregate;

    public private(set) StatsPhaseAggregateData $insertAggregate;

    /**
     * Per-loop aggregate over each chunk's wall-clock time
     * (`StatsLoopData::$overallSeconds`, rows = chunkRows). Useful for
     * loop throughput including inter-phase overhead.
     */
    public private(set) StatsPhaseAggregateData $loopAggregate;

    public function __construct()
    {
        $this->selectAggregate = new StatsPhaseAggregateData;
        $this->transformAggregate = new StatsPhaseAggregateData;
        $this->insertAggregate = new StatsPhaseAggregateData;
        $this->loopAggregate = new StatsPhaseAggregateData;
    }

    public function setStatus(TableRunPhase $status): void
    {
        $this->status = $status;
    }

    public function setTotalRows(int $totalRows): void
    {
        $this->totalRows = max(0, $totalRows);
    }

    public function recordCountingRows(float $seconds): void
    {
        $this->countingRowsSeconds = $seconds;
    }

    public function recordDisableFk(float $seconds): void
    {
        $this->disableFkSeconds = $seconds;
    }

    public function recordClearTable(float $seconds): void
    {
        $this->clearTableSeconds = $seconds;
    }

    /** Fold a completed chunk loop into the running aggregates in O(1). */
    public function recordLoop(StatsLoopData $loop): void
    {
        $this->selectAggregate->record($loop->selectSeconds, $loop->chunkRows);
        $this->transformAggregate->record($loop->transformSeconds, $loop->chunkRows);
        $this->insertAggregate->record($loop->insertSeconds, $loop->chunkRows);
        $this->loopAggregate->record($loop->overallSeconds, $loop->chunkRows);

        $this->rowsDone += $loop->rowsDone;
        $this->rowsSkipped += $loop->rowsSkipped;
    }

    public function aggregate(TableRunPhase $phase): StatsPhaseAggregateData
    {
        return match ($phase) {
            TableRunPhase::CountingRows => $this->oneShotAggregate($this->countingRowsSeconds),
            TableRunPhase::DisableFkChecks => $this->oneShotAggregate($this->disableFkSeconds),
            TableRunPhase::Clear => $this->oneShotAggregate($this->clearTableSeconds),
            TableRunPhase::Select => $this->selectAggregate,
            TableRunPhase::Transform => $this->transformAggregate,
            TableRunPhase::Insert => $this->insertAggregate,
            TableRunPhase::Loop => $this->loopAggregate,
        };
    }

    /**
     * Wrap a one-shot phase duration as a single-sample aggregate. A phase that
     * never ran (null) yields an empty (count-0) aggregate so consumers can skip it.
     */
    private function oneShotAggregate(?float $seconds): StatsPhaseAggregateData
    {
        return $seconds === null
            ? new StatsPhaseAggregateData
            : StatsPhaseAggregateData::withRecord($seconds, $this->totalRows);
    }
}
