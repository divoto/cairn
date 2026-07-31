<?php

declare(strict_types=1);

namespace Divoto\Cairn\Commands;

use Divoto\Cairn\Maintenance\Doctor;
use Divoto\Cairn\Maintenance\Finding;
use Divoto\Cairn\Support\Tables;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Report what this installation is actually doing.
 *
 * Reads configuration and says what it means, so a deployer can see the
 * consequences of their settings in one place rather than inferring them from
 * a config file's comments.
 *
 * It never says whether a deployment is lawful. That depends on context a
 * package cannot see.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'cairn:doctor';

    protected $description = 'Report what this Cairn installation stores and exposes';

    public function handle(Doctor $doctor, DatabaseManager $database): int
    {
        $findings = $doctor->examine();

        $this->newLine();
        $this->line('  <options=bold>Cairn</> — what this installation is doing');
        $this->newLine();

        $this->reportStorage($database);

        if ($findings === []) {
            $this->components->info(
                'Nothing to report. This installation is running Cairn\'s defaults: '
                .'cookieless, no IP stored, no personal data.'
            );

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($findings as $finding) {
            $this->render($finding);
        }

        $this->line('  <fg=gray>These are observations, not errors — several of them may be</>');
        $this->line('  <fg=gray>entirely deliberate. Cairn does not and cannot tell you whether</>');
        $this->line('  <fg=gray>a deployment complies with any particular regulation.</>');
        $this->newLine();

        return self::SUCCESS;
    }

    private function render(Finding $finding): void
    {
        $marker = $finding->severe ? '<fg=red>●</>' : '<fg=yellow>●</>';

        $this->line("  {$marker} <options=bold>{$finding->title}</>");
        $this->line("    <fg=gray>{$finding->setting}</>");
        $this->newLine();

        foreach ($this->wrap($finding->implication) as $line) {
            $this->line("    {$line}");
        }

        $this->newLine();
    }

    /**
     * Row counts, so the reader can see what is actually stored.
     */
    private function reportStorage(DatabaseManager $database): void
    {
        foreach (Tables::all() as $table) {
            try {
                $count = $database->connection(Tables::connection())->table($table)->count();
                $this->line(sprintf('  <fg=gray>%s</> %s', str_pad($table, 24), number_format($count)));
            } catch (Throwable) {
                $this->line(sprintf('  <fg=gray>%s</> <fg=red>not found — run migrate</>', str_pad($table, 24)));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, int $width = 72): array
    {
        return explode("\n", wordwrap($text, $width));
    }
}
