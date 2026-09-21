<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\RuleBacklogApplier;
use Illuminate\Console\Command;

/**
 * Applies a user's existing auto-apply rules to their uncategorised backlog.
 *
 * Separate from categories:mine-rules, which *creates* rules from categorised
 * history. This one creates nothing; it only makes the rules already on file
 * reach rows that predate them.
 */
final class ApplyRuleBacklogCommand extends Command
{
    protected $signature = 'rules:apply-backlog
        {--user= : The user id to sweep; omit to sweep every user}
        {--dry-run : Report what would be categorised without writing}';

    protected $description = 'Apply existing auto-apply rules to uncategorised transactions';

    public function handle(RuleBacklogApplier $applier): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $userOption = $this->option('user');

        $users = $userOption === null
            ? User::query()->orderBy('id')->get()
            : User::query()->where('id', (int) $userOption)->get();

        if ($users->isEmpty()) {
            $this->error('No matching user.');

            return self::FAILURE;
        }

        foreach ($users as $user) {
            $result = $applier->apply($user, $dryRun);

            $this->info(sprintf(
                'User %d: %s %d of %d uncategorised transaction(s).',
                $user->id,
                $dryRun ? 'would categorise' : 'categorised',
                $result['categorised'],
                $result['scanned'],
            ));
        }

        if ($dryRun) {
            $this->warn('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }
}
