<?php

declare(strict_types=1);

use App\Logging\ConsoleOutputHandler;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Console\Output\BufferedOutput;

function consoleLogRecord(Level $level, string $message, array $context = []): LogRecord
{
    return new LogRecord(new DateTimeImmutable('2026-04-01T14:32:00', new DateTimeZone('UTC')), 'clonio', $level, $message, $context);
}

it('writes warnings and errors as one indented line with their context', function (): void {
    $output = new BufferedOutput;
    $handler = new ConsoleOutputHandler($output);

    $handler->handle(consoleLogRecord(Level::Warning, 'row_skipped', ['table' => 'users', 'pk' => ['id' => 1]]));
    $handler->handle(consoleLogRecord(Level::Error, 'table_transfer_failed'));

    expect($output->fetch())->toBe(
        "  WARNING: row_skipped {\"table\":\"users\",\"pk\":{\"id\":1}}\n"
        ."  ERROR: table_transfer_failed\n"
    );
});

it('handles warnings and above and bubbles by default', function (): void {
    $handler = new ConsoleOutputHandler(new BufferedOutput);

    expect($handler->getLevel())->toBe(Level::Warning)
        ->and($handler->getBubble())->toBeTrue()
        ->and($handler->isHandling(consoleLogRecord(Level::Info, 'table_transferred')))->toBeFalse();
});

it('styles warnings as comments and errors as errors', function (): void {
    $output = new BufferedOutput(decorated: true);
    $handler = new ConsoleOutputHandler($output);

    $handler->handle(consoleLogRecord(Level::Warning, 'w'));
    $handler->handle(consoleLogRecord(Level::Error, 'e'));

    expect($output->fetch())->toBe("  \e[33mWARNING: w\e[39m\n  \e[37;41mERROR: e\e[39;49m\n");
});

it('escapes console tags in the logged text', function (): void {
    $output = new BufferedOutput(decorated: true);
    $handler = new ConsoleOutputHandler($output);

    $handler->handle(consoleLogRecord(Level::Warning, 'bad <info>value</info>'));

    expect($output->fetch())->toBe("  \e[33mWARNING: bad <info>value</info>\e[39m\n");
});

it('falls back to the bare message when a formatter does not produce a string', function (): void {
    $output = new BufferedOutput;
    $handler = new ConsoleOutputHandler($output);
    $handler->setFormatter(new NormalizerFormatter);

    $handler->handle(consoleLogRecord(Level::Warning, 'row_skipped', ['table' => 'users']));

    expect($output->fetch())->toBe("  row_skipped\n");
});

it('keeps line breaks inside a logged message', function (): void {
    $output = new BufferedOutput;
    $handler = new ConsoleOutputHandler($output);

    $handler->handle(consoleLogRecord(Level::Error, "table_transfer_failed\nSQLSTATE[23000]"));

    expect($output->fetch())->toBe("  ERROR: table_transfer_failed\nSQLSTATE[23000]\n");
});

it('leaves out ignored messages', function (): void {
    $output = new BufferedOutput;
    $handler = new ConsoleOutputHandler($output, ignoredMessages: ['row_skipped']);

    expect($handler->handle(consoleLogRecord(Level::Warning, 'row_skipped')))->toBeFalse();
    $handler->handle(consoleLogRecord(Level::Warning, 'table_not_found'));

    expect($output->fetch())->toBe("  WARNING: table_not_found\n");
});
