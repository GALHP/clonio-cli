<?php

declare(strict_types=1);

use App\Data\Cloning\StatsLoopData;
use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableRunPhase;
use App\Services\Output\CloningProgressDisplay;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A display over decorated in-memory sections, redrawing on every update.
 * Returns [display, per-table bar section, overall bar section].
 *
 * @return array{CloningProgressDisplay, ConsoleSectionOutput, ConsoleSectionOutput}
 */
function makeProgressDisplay(bool $phaseDetail = false): array
{
    $stream = fopen('php://memory', 'w+');
    $sections = [];
    $formatter = new OutputFormatter(true);
    $section = static function () use ($stream, &$sections, $formatter): ConsoleSectionOutput {
        return new ConsoleSectionOutput($stream, $sections, OutputInterface::VERBOSITY_NORMAL, true, $formatter);
    };

    $log = $section();
    $table = $section();
    $overall = $section();

    return [new CloningProgressDisplay($log, $table, $overall, $phaseDetail, minSecondsBetweenRedraws: 0), $table, $overall];
}

/** Current text of a section, without ANSI styling. */
function sectionText(ConsoleSectionOutput $section): string
{
    return (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $section->getContent());
}

function progressLoop(int $rows, float $seconds, int $skipped = 0): StatsLoopData
{
    return new StatsLoopData(
        chunkRows: $rows,
        selectSeconds: $seconds / 4,
        transformSeconds: $seconds / 4,
        insertSeconds: $seconds / 2,
        overallSeconds: $seconds,
        rowsDone: $rows - $skipped,
        rowsSkipped: $skipped,
    );
}

it('starts a table as a plain row counter labelled with the table name', function (): void {
    [$display, $table] = makeProgressDisplay();

    $display->startTable('users');

    expect(sectionText($table))->toMatch('/^  users  \[[^\]]+\]    0  $/');
});

it('shows the running one-shot phase on the table bar', function (): void {
    [$display, $table] = makeProgressDisplay();
    $display->startTable('users');

    $stats = new StatsTableTransferData;
    $stats->setStatus(TableRunPhase::EstimatingRows);
    $display->advanceTable($stats);

    expect(sectionText($table))->toContain('estimating rows…');
});

it('switches to a determinate bar with ETA and pace once an exact total is known', function (): void {
    [$display, $table] = makeProgressDisplay();
    $display->startTable('users');

    $stats = new StatsTableTransferData;
    $stats->setTotalRows(10);
    $stats->setStatus(TableRunPhase::Insert);
    $stats->recordLoop(progressLoop(rows: 2, seconds: 1.0));
    $display->advanceTable($stats);

    // 8 rows left × 0.5 s/row = 4 s; 0.5 s/row = 500,000 s per million rows.
    expect(sectionText($table))->toMatch('/^  users  \[[^\]]+\]  2\/10 \( 20%\)  ETA 4s · 500000\.0 s\/M$/');
});

it('marks an estimated total with a tilde', function (): void {
    [$display, $table] = makeProgressDisplay();
    $display->startTable('users');

    $stats = new StatsTableTransferData;
    $stats->setTotalRows(10, estimated: true);
    $stats->setStatus(TableRunPhase::Insert);
    $stats->recordLoop(progressLoop(rows: 2, seconds: 1.0));
    $display->advanceTable($stats);

    expect(sectionText($table))->toContain('2/~10 ( 20%)');
});

it('falls back to a plain row counter without ETA once an estimate is overrun', function (): void {
    [$display, $table] = makeProgressDisplay();
    $display->startTable('users');

    $stats = new StatsTableTransferData;
    $stats->setTotalRows(10, estimated: true);
    $stats->setStatus(TableRunPhase::Insert);
    $stats->recordLoop(progressLoop(rows: 8, seconds: 1.0));
    $display->advanceTable($stats);
    expect(sectionText($table))->toContain('8/~10');

    $stats->recordLoop(progressLoop(rows: 8, seconds: 1.0, skipped: 1));
    $display->advanceTable($stats);

    expect(sectionText($table))->toMatch('/^  users  \[[^\]]+\]   16  125000\.0 s\/M$/');
});

it('switches to the determinate bar right away, before any chunk pace is known', function (): void {
    [$display, $table] = makeProgressDisplay();
    $display->startTable('users');

    $stats = new StatsTableTransferData;
    $stats->setTotalRows(10);
    $stats->setStatus(TableRunPhase::Select);
    $display->advanceTable($stats);

    expect(sectionText($table))->toMatch('/^  users  \[[^\]]+\]  0\/10 \(  0%\)  $/');
});

it('adds the per-phase pace breakdown in phase-detail mode', function (): void {
    [$display, $table] = makeProgressDisplay(phaseDetail: true);
    $display->startTable('users');

    $stats = new StatsTableTransferData;
    $stats->setTotalRows(10);
    $stats->setStatus(TableRunPhase::Insert);
    $stats->recordLoop(progressLoop(rows: 2, seconds: 0.000002));
    $display->advanceTable($stats);

    // 1 µs/row overall = 1 s/M; select and transform 0.25 µs/row = 250 ms/M.
    expect(sectionText($table))->toContain('ETA 0s · all 1.0 s/M · sel 250.0 ms/M · tr 250.0 ms/M · ins 500.0 ms/M');
});

it('formats the ETA in minutes and hours', function (int $totalRows, string $eta): void {
    [$display, $table] = makeProgressDisplay();
    $display->startTable('users');

    $stats = new StatsTableTransferData;
    $stats->setTotalRows($totalRows);
    $stats->setStatus(TableRunPhase::Insert);
    $stats->recordLoop(progressLoop(rows: 10, seconds: 1.0)); // 0.1 s/row
    $display->advanceTable($stats);

    expect(sectionText($table))->toContain('ETA '.$eta.' ·');
})->with([
    'just under a minute' => [599, '59s'],
    'one minute' => [610, '1m 0s'],
    'minutes and seconds' => [1000, '1m 39s'],
    'just under an hour' => [36_000, '59m 59s'],
    'one hour' => [36_010, '1h 0m'],
    'hours and minutes' => [100_000, '2h 46m'],
]);

it('fills the table bar when the table finishes', function (): void {
    [$display, $table] = makeProgressDisplay();
    $display->startTable('users');

    $stats = new StatsTableTransferData;
    $stats->setTotalRows(10);
    $stats->setStatus(TableRunPhase::Insert);
    $stats->recordLoop(progressLoop(rows: 4, seconds: 1.0));
    $display->advanceTable($stats);
    $display->finishTable();

    expect(sectionText($table))->toContain('10/10 (100%)');
});

it('counts finished tables on the overall bar', function (): void {
    [$display, , $overall] = makeProgressDisplay();

    $display->start(2);
    expect(sectionText($overall))->toMatch('/^\n  tables  \[[^\]]+\] 0\/2$/');

    $display->advanceOverall();
    expect(sectionText($overall))->toContain('1/2');

    $display->finish();
    expect(sectionText($overall))->toContain('2/2');
});

it('keeps log lines in their own section above the bars', function (): void {
    [$display, $table] = makeProgressDisplay();
    $display->startTable('users');

    $display->log->writeln('  ✓  users  (10 rows)');

    expect(sectionText($display->log))->toBe("  ✓  users  (10 rows)\n");
    expect(sectionText($table))->toContain('users  [');
});

it('builds its sections from a console output', function (): void {
    $display = CloningProgressDisplay::forConsole(new ConsoleOutput, phaseDetail: false);

    expect($display->log)->toBeInstanceOf(ConsoleSectionOutput::class);
});

it('renders the per-phase timing summary of a finished table', function (): void {
    $stats = new StatsTableTransferData;
    $stats->setTotalRows(4);
    $stats->recordClearTable(0.25);
    $stats->recordLoop(progressLoop(rows: 2, seconds: 2.0));
    $stats->recordLoop(progressLoop(rows: 2, seconds: 0.5));

    $output = new BufferedOutput;
    CloningProgressDisplay::renderTimingSummary($output, $stats);

    expect($output->fetch())->toBe(<<<'TXT'
              ── timing summary ──
              +------------------+--------+-----------+-----------+-----------+-----------+--------------+
              | phase            | chunks | min       | max       | avg       | total     | s/1M rows    |
              +------------------+--------+-----------+-----------+-----------+-----------+--------------+
              | clearingData     | 1      |  250.0 ms |  250.0 ms |  250.0 ms |  250.0 ms | 62500.0 s/M  |
              +------------------+--------+-----------+-----------+-----------+-----------+--------------+
              | selectData       | 2      |  125.0 ms |  500.0 ms |  312.5 ms |  625.0 ms | 156250.0 s/M |
              | transformingData | 2      |  125.0 ms |  500.0 ms |  312.5 ms |  625.0 ms | 156250.0 s/M |
              | insertingData    | 2      |  250.0 ms |   1.0 s   |  625.0 ms |   1.2 s   | 312500.0 s/M |
              +------------------+--------+-----------+-----------+-----------+-----------+--------------+
              | loop             | 2      |  500.0 ms |   2.0 s   |   1.2 s   |   2.5 s   | 625000.0 s/M |
              +------------------+--------+-----------+-----------+-----------+-----------+--------------+

        TXT);
});

it('renders no timing summary for a table that never ran a chunk', function (): void {
    $stats = new StatsTableTransferData;
    $stats->recordClearTable(0.25);

    $output = new BufferedOutput;
    CloningProgressDisplay::renderTimingSummary($output, $stats);

    expect($output->fetch())->toBe('');
});
