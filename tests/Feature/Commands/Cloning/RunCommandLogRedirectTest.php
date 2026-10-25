<?php

declare(strict_types=1);

use App\Commands\Cloning\RunCommand;
use App\Logging\AuditBuffer;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/** The stderr handler of the default log stack. */
function stderrLogHandler(): StreamHandler
{
    $logger = Log::driver();
    expect($logger)->toBeInstanceOf(Logger::class);
    $monolog = $logger->getLogger();
    expect($monolog)->toBeInstanceOf(Monolog::class);

    $handlers = array_values(array_filter(
        $monolog->getHandlers(),
        static fn (object $h): bool => $h instanceof StreamHandler && $h->getUrl() === 'php://stderr',
    ));
    expect($handlers)->toHaveCount(1);

    return $handlers[0];
}

function redirectRunLogs(OutputInterface $section): Closure
{
    return (fn (OutputInterface $s): Closure => $this->redirectLogsTo($s))->call(app(RunCommand::class), $section);
}

beforeEach(function (): void {
    app(AuditBuffer::class)->clear();
});

it('moves warnings from stderr into the section until restored', function (): void {
    $stderr = stderrLogHandler();
    $levelBefore = $stderr->getLevel();
    $section = new BufferedOutput;

    $restore = redirectRunLogs($section);

    expect($stderr->getLevel())->toBe(Level::Emergency);

    Log::warning('row_skipped', ['table' => 'users']);
    Log::info('table_transferred', ['table' => 'users']);

    expect($section->fetch())->toBe("  WARNING: row_skipped {\"table\":\"users\"}\n");
    // The audit trail still records every event.
    expect(array_column(app(AuditBuffer::class)->records(), 'event'))->toBe(['row_skipped', 'table_transferred']);

    $restore();

    expect($stderr->getLevel())->toBe($levelBefore);

    Log::error('audit_delivery_failed');
    expect($section->fetch())->toBe('');
});

it('leaves logging alone when the default logger is not Monolog-backed', function (): void {
    Log::shouldReceive('driver')->andReturn(new NullLogger);
    $section = new BufferedOutput;

    $restore = redirectRunLogs($section);
    $restore();

    expect($section->fetch())->toBe('');
});
