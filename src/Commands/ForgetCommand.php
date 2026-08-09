<?php

declare(strict_types=1);

namespace Divoto\Cairn\Commands;

use Divoto\Cairn\Maintenance\Eraser;
use Illuminate\Console\Command;

/**
 * Erase everything Cairn holds about one subject.
 *
 * Removes the rows *and* rebuilds the aggregates they contributed to. Deleting
 * an entry without recomputing would leave the person's activity still counted
 * in every rollup — present in the totals, merely no longer attributable.
 */
final class ForgetCommand extends Command
{
    protected $signature = 'cairn:forget
        {subject : A visitor hash (hex or raw) or a user ID}
        {--user : Treat the subject as a user ID rather than a visitor hash}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Erase everything Cairn holds about a visitor or user';

    public function handle(Eraser $eraser): int
    {
        $argument = $this->argument('subject');
        $subject = is_scalar($argument) ? (string) $argument : '';
        $isUser = (bool) $this->option('user');

        if (! $this->option('force') && ! $this->confirmErasure($subject, $isUser)) {
            $this->components->warn('Nothing was erased.');

            return self::SUCCESS;
        }

        $removed = $isUser ? $eraser->forgetUser($subject) : $eraser->forgetVisitor($subject);

        $rebuilt = $removed['aggregates_rebuilt'] ?? 0;
        unset($removed['aggregates_rebuilt']);

        foreach ($removed as $table => $count) {
            $this->line(sprintf('  <fg=gray>%s</> %d removed', str_pad($table, 16), $count));
        }

        $this->line(sprintf('  <fg=gray>%s</> %d rewritten', str_pad('aggregates', 16), $rebuilt));
        $this->newLine();

        if (array_sum($removed) === 0) {
            $this->components->info('Nothing was found for that subject.');

            if (! $isUser) {
                $this->explainRotation();
            }

            return self::SUCCESS;
        }

        $this->components->info('Erased, and the affected rollups have been recomputed.');

        if (! $isUser) {
            $this->explainRotation();
        }

        return self::SUCCESS;
    }

    private function confirmErasure(string $subject, bool $isUser): bool
    {
        return $this->confirm(sprintf(
            'Permanently erase all Cairn data for %s "%s"?',
            $isUser ? 'user' : 'visitor',
            $subject,
        ), false);
    }

    /**
     * Say plainly what this command could not reach.
     *
     * Implying a completeness Cairn cannot deliver would be worse than the
     * limitation itself — somebody servicing an erasure request needs to know
     * exactly what was and was not covered.
     */
    private function explainRotation(): void
    {
        $this->newLine();
        $this->line('  <fg=gray>A visitor hash is derived from a salt that rotates every 24 hours and</>');
        $this->line('  <fg=gray>is never stored, so this reaches at most one day of activity. The same</>');
        $this->line('  <fg=gray>person\'s earlier rows sit behind hashes nobody can compute any more —</>');
        $this->line('  <fg=gray>including Cairn. They are already unlinkable to them.</>');
    }
}
