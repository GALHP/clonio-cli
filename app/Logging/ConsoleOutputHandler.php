<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Monolog handler that writes each record as one styled line to a console output.
 *
 * `cloning:run` attaches it to the log section while live progress bars own the
 * terminal, so warnings stay visible where a raw stderr write would corrupt the bars.
 */
final class ConsoleOutputHandler extends AbstractProcessingHandler
{
    /**
     * @param  list<string>  $ignoredMessages  Log messages (event names) to leave out.
     */
    public function __construct(
        private readonly OutputInterface $output,
        int|string|Level $level = Level::Warning,
        bool $bubble = true,
        private readonly array $ignoredMessages = [],
    ) {
        parent::__construct($level, $bubble);
    }

    public function isHandling(LogRecord $record): bool
    {
        return parent::isHandling($record) && ! in_array($record->message, $this->ignoredMessages, true);
    }

    protected function write(LogRecord $record): void
    {
        $style = $record->level->isHigherThan(Level::Warning) ? 'error' : 'comment';
        $line = is_string($record->formatted) ? $record->formatted : $record->message;

        $this->output->writeln(sprintf('  <%s>%s</%s>', $style, OutputFormatter::escape(rtrim($line)), $style));
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new LineFormatter('%level_name%: %message% %context%', allowInlineLineBreaks: true, ignoreEmptyContextAndExtra: true);
    }
}
