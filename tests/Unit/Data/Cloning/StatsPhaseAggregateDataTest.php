<?php

declare(strict_types=1);

use App\Data\Cloning\StatsPhaseAggregateData;

it('starts the smoothed pace at the first sample with rows', function (): void {
    $aggregate = new StatsPhaseAggregateData;
    expect($aggregate->smoothedPace)->toBeNull();

    $aggregate->record(0.5, 0);
    expect($aggregate->smoothedPace)->toBeNull(); // no rows, no pace

    $aggregate->record(2.0, 4);
    expect($aggregate->smoothedPace)->toBe(0.5);
});

it('weights each new pace sample by 0.2 in the smoothed pace', function (): void {
    $aggregate = StatsPhaseAggregateData::withRecord(1.0, 10); // 0.1 s/row
    $aggregate->record(6.0, 10); // 0.6 s/row

    // 0.2 × 0.6 + 0.8 × 0.1 = 0.2
    expect($aggregate->smoothedPace)->toEqualWithDelta(0.2, 1e-12);
    expect($aggregate->latestPace)->toEqualWithDelta(0.6, 1e-12);
});
