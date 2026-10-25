<?php

declare(strict_types=1);

namespace App\Services\Output;

use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableRunPhase;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Live nested progress for `cloning:run` on an interactive terminal: a scrolling
 * results log on top, the per-table bar in the middle and the overall table bar
 * pinned at the bottom.
 *
 * The bars own the terminal, so every other write must go through `$log`, or
 * Symfony's cursor math desyncs and re-prints the bars.
 */
final readonly class CloningProgressDisplay
{
    private const string INDETERMINATE_FORMAT = '  %table%  [%bar%] %current%  %message%';

    private const string DETERMINATE_FORMAT = '  %table%  [%bar%] %current%/%approx%%max% (%percent:3s%%)  %message%';

    private ProgressBar $tableBar;

    private ProgressBar $overallBar;

    /**
     * @param  bool  $phaseDetail  Show the per-phase pace breakdown on the per-table bar (`-vv`).
     */
    public function __construct(
        public ConsoleSectionOutput $log,
        ConsoleSectionOutput $tableSection,
        ConsoleSectionOutput $overallSection,
        private bool $phaseDetail,
        float $minSecondsBetweenRedraws = 1 / 25,
    ) {
        $this->tableBar = new ProgressBar($tableSection, minSecondsBetweenRedraws: $minSecondsBetweenRedraws);
        $this->overallBar = new ProgressBar($overallSection, minSecondsBetweenRedraws: $minSecondsBetweenRedraws);
        // Leading newline keeps a blank line between the per-table bar and the
        // overall bar (the section owns both lines, so it stays stable on redraw).
        $this->overallBar->setFormat("\n  <info>tables</info>  [%bar%] %current%/%max%");
    }

    /**
     * Sections created first render higher up: the log, then the per-table bar,
     * then the overall bar.
     */
    public static function forConsole(ConsoleOutput $output, bool $phaseDetail): self
    {
        return new self($output->section(), $output->section(), $output->section(), $phaseDetail);
    }

    public function start(int $totalTables): void
    {
        $this->overallBar->setMaxSteps($totalTables);
        $this->overallBar->start();
    }

    /**
     * (Re)initialise the per-table bar for a new table. The row total is not
     * known yet, so the bar starts as a plain row counter. The `%message%` slot
     * carries the one-shot activity first, then the per-chunk ETA and pace.
     */
    public function startTable(string $tableName): void
    {
        $this->tableBar->setFormat(self::INDETERMINATE_FORMAT);
        $this->tableBar->setMessage($tableName, 'table');
        $this->tableBar->setMessage('', 'message');
        $this->tableBar->start(0);
    }

    /**
     * Update the per-table bar from the table's live stats. It is determinate
     * while the (possibly estimated) total holds, and falls back to a plain row
     * counter once an estimate turns out too low.
     */
    public function advanceTable(StatsTableTransferData $stats): void
    {
        if ($stats->status?->isOneShot() === true) {
            $this->tableBar->setMessage($stats->status->label().'…', 'message');
            $this->tableBar->display();

            return;
        }

        $this->tableBar->setMessage($this->formatProgress($stats), 'message');

        $max = $stats->hasKnownTotal ? $stats->totalRows : 0;
        $switchesFormat = $this->tableBar->getMaxSteps() !== $max;

        if ($switchesFormat) {
            $this->tableBar->setMaxSteps($max);
            $this->tableBar->setFormat($stats->hasKnownTotal ? self::DETERMINATE_FORMAT : self::INDETERMINATE_FORMAT);
        }

        $this->tableBar->setMessage($stats->totalRowsEstimated ? '~' : '', 'approx');
        $this->tableBar->setProgress($stats->rowsProcessed);

        // setProgress() throttles redraws; show a format switch right away.
        if ($switchesFormat) {
            $this->tableBar->display();
        }
    }

    public function finishTable(): void
    {
        $this->tableBar->finish();
    }

    public function advanceOverall(): void
    {
        $this->overallBar->advance();
    }

    public function finish(): void
    {
        $this->overallBar->finish();
    }

    /**
     * Per-phase timing table for one finished table (`-vvv`). Used with and
     * without live bars; writes nothing for a table that never ran a chunk.
     */
    public static function renderTimingSummary(OutputInterface $sink, StatsTableTransferData $timings): void
    {
        if ($timings->loopAggregate->count === 0) {
            return;
        }

        $rows = [];

        foreach (TableRunPhase::cases() as $phase) {
            $agg = $timings->aggregate($phase);
            if ($agg->count === 0) {
                continue;
            }

            if ($phase === TableRunPhase::Loop || $phase === TableRunPhase::Select) {
                $rows[] = new TableSeparator;
            }

            // count > 0 here, so min/max/average are always set.
            $rows[] = [
                $phase->value,
                $agg->count,
                self::formatSeconds($agg->min ?? 0.0), // @pest-mutate-ignore: IncrementFloat,DecrementFloat
                self::formatSeconds($agg->max ?? 0.0), // @pest-mutate-ignore: IncrementFloat,DecrementFloat
                self::formatSeconds($agg->averageSeconds ?? 0.0), // @pest-mutate-ignore: IncrementFloat,DecrementFloat
                self::formatSeconds($agg->sum),
                self::formatThroughput($agg->pacePerMillion),
            ];
        }

        $indent = str_repeat(' ', 6);
        $sink->writeln(sprintf('%s── timing summary ──', $indent));
        $buffer = new BufferedOutput($sink->getVerbosity(), $sink->isDecorated(), $sink->getFormatter());
        $table = new Table($buffer);
        $table->setHeaders(['phase', 'chunks', 'min', 'max', 'avg', 'total', 's/1M rows']);
        $table->setRows($rows);
        $table->render();

        foreach (explode("\n", rtrim($buffer->fetch(), "\n")) as $line) {
            $sink->writeln($indent.$line);
        }
    }

    /**
     * ETA plus the overall pace, expanded to the per-phase pace breakdown when
     * `$phaseDetail` is set.
     */
    private function formatProgress(StatsTableTransferData $stats): string
    {
        $parts = $stats->estimatedSecondsRemaining !== null
            ? ['ETA '.$this->formatEta($stats->estimatedSecondsRemaining)]
            : [];
        $overall = trim(self::formatThroughput($stats->loopAggregate->latestPacePerMillion));

        if (! $this->phaseDetail) {
            if ($overall !== '—') {
                $parts[] = $overall;
            }

            return implode(' · ', $parts);
        }

        $parts[] = 'all '.$overall;
        $parts[] = 'sel '.trim(self::formatThroughput($stats->selectAggregate->latestPacePerMillion));
        $parts[] = 'tr '.trim(self::formatThroughput($stats->transformAggregate->latestPacePerMillion));
        $parts[] = 'ins '.trim(self::formatThroughput($stats->insertAggregate->latestPacePerMillion));

        return implode(' · ', $parts);
    }

    private function formatEta(float $seconds): string
    {
        $seconds = (int) round($seconds);

        return match (true) {
            $seconds < 60 => sprintf('%ds', $seconds),
            $seconds < 3600 => sprintf('%dm %ds', intdiv($seconds, 60), $seconds % 60),
            default => sprintf('%dh %dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60)),
        };
    }

    private static function formatSeconds(float $seconds): string
    {
        if ($seconds < 1.0) {
            return sprintf('%6.1f ms', $seconds * 1000.0);
        }

        return sprintf('%5.1f s', $seconds);
    }

    private static function formatThroughput(?float $secondsPerMillion): string
    {
        if ($secondsPerMillion === null) {
            return '—';
        }

        if ($secondsPerMillion >= 1.0) {
            return sprintf('%5.1f s/M', $secondsPerMillion);
        }

        return sprintf('%5.1f ms/M', $secondsPerMillion * 1000.0);
    }
}
