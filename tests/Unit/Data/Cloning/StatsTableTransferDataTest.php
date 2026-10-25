<?php

declare(strict_types=1);

use App\Data\Cloning\StatsLoopData;
use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableRunPhase;

it('returns an empty (count-0) aggregate for one-shot phases that never ran', function (): void {
    $stats = new StatsTableTransferData;

    // Regression: a one-shot phase that did not execute must be skippable by
    // consumers (count === 0), not reported as having run in 0.0 ms.
    foreach ([TableRunPhase::EstimatingRows, TableRunPhase::DisableFkChecks, TableRunPhase::Clear] as $phase) {
        expect($stats->aggregate($phase)->count)->toBe(0);
    }
});

it('records a single-sample aggregate once a one-shot phase runs', function (): void {
    $stats = new StatsTableTransferData;
    $stats->setTotalRows(1000);
    $stats->recordClearTable(0.5);

    $cleared = $stats->aggregate(TableRunPhase::Clear);
    expect($cleared->count)->toBe(1);
    expect($cleared->sum)->toBe(0.5);

    // A sibling one-shot phase that still never ran stays at count 0.
    expect($stats->aggregate(TableRunPhase::DisableFkChecks)->count)->toBe(0);
});

it('estimates remaining time from the latest loop pace', function (): void {
    $stats = new StatsTableTransferData;
    $stats->setTotalRows(100);
    $stats->recordLoop(new StatsLoopData(
        chunkRows: 10,
        selectSeconds: 0.2,
        transformSeconds: 0.1,
        insertSeconds: 0.7,
        overallSeconds: 1.0,
        rowsDone: 10,
        rowsSkipped: 0,
    ));

    // 90 rows remaining × (1.0s / 10 rows) = 9.0s.
    expect($stats->estimatedSecondsRemaining)->toBe(9.0);
});

it('reports no ETA while the total or the pace is unknown', function (): void {
    $stats = new StatsTableTransferData;
    expect($stats->estimatedSecondsRemaining)->toBeNull(); // no total

    $stats->setTotalRows(100);
    expect($stats->estimatedSecondsRemaining)->toBeNull(); // rows remain but no completed loop → no pace
});

it('reports a zero ETA once every row is processed', function (): void {
    $stats = new StatsTableTransferData;
    $stats->setTotalRows(10);
    $stats->recordLoop(new StatsLoopData(
        chunkRows: 10,
        selectSeconds: 0.2,
        transformSeconds: 0.1,
        insertSeconds: 0.7,
        overallSeconds: 1.0,
        rowsDone: 10,
        rowsSkipped: 0,
    ));

    expect($stats->hasKnownTotal)->toBeTrue();
    expect($stats->percentComplete)->toBe(100.0);
    expect($stats->estimatedSecondsRemaining)->toBe(0.0);
});

it('treats the total as unknown once the rows processed overrun an estimate', function (): void {
    $stats = new StatsTableTransferData;
    $stats->setTotalRows(10, estimated: true);
    $stats->recordLoop(new StatsLoopData(
        chunkRows: 12,
        selectSeconds: 0.2,
        transformSeconds: 0.1,
        insertSeconds: 0.7,
        overallSeconds: 1.0,
        rowsDone: 11,
        rowsSkipped: 1,
    ));

    expect($stats->totalRowsEstimated)->toBeTrue();
    expect($stats->hasKnownTotal)->toBeFalse();
    expect($stats->percentComplete)->toBeNull();
    expect($stats->estimatedSecondsRemaining)->toBeNull();
});

it('marks a total as exact unless flagged as an estimate', function (): void {
    $stats = new StatsTableTransferData;
    $stats->setTotalRows(10);
    expect($stats->totalRowsEstimated)->toBeFalse();
    expect($stats->hasKnownTotal)->toBeTrue();

    $stats->setTotalRows(-5, estimated: true);
    expect($stats->totalRows)->toBe(0);
    expect($stats->totalRowsEstimated)->toBeTrue();
    expect($stats->hasKnownTotal)->toBeFalse();
});
