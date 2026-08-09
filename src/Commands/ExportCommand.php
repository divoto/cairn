<?php

declare(strict_types=1);

namespace Divoto\Cairn\Commands;

use Divoto\Cairn\Maintenance\Eraser;
use Illuminate\Console\Command;

/**
 * Produce everything Cairn holds about one subject, as JSON.
 *
 * For servicing a subject access request. Writes to a file or to standard
 * output, so it can be piped.
 */
final class ExportCommand extends Command
{
    protected $signature = 'cairn:export
        {subject : A visitor hash (hex or raw) or a user ID}
        {--user : Treat the subject as a user ID rather than a visitor hash}
        {--path= : Write to this file instead of standard output}';

    protected $description = 'Export everything Cairn holds about a visitor or user as JSON';

    public function handle(Eraser $eraser): int
    {
        $argument = $this->argument('subject');
        $subject = is_scalar($argument) ? (string) $argument : '';

        $data = $this->option('user')
            ? $eraser->exportUser($subject)
            : $eraser->exportVisitor($subject);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            file_put_contents($path, $json);
            $this->components->info(sprintf('Written to %s.', $path));

            return self::SUCCESS;
        }

        $this->output->writeln($json);

        return self::SUCCESS;
    }
}
