<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PlannedTransactionMatcher;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

final class MatchPlannedTransactionsCommand extends Command
{
    protected $signature = 'app:match-planned-transactions {--user= : Match a single user id; omit to process every user with active planned transactions}';

    protected $description = 'Tag imported transactions that match an active planned item (the same matching the sync/import pipeline performs)';

    public function handle(PlannedTransactionMatcher $matcher): int
    {
        $userId = $this->option('user');

        if ($userId !== null) {
            $user = User::query()->find((int) $userId);

            if ($user === null) {
                $this->error("User {$userId} not found.");

                return self::FAILURE;
            }

            $this->info("Matched {$matcher->matchForUser($user)} transaction(s).");

            return self::SUCCESS;
        }

        $matched = 0;

        User::query()
            ->whereHas('plannedTransactions', fn ($query) => $query->where('is_active', true))
            ->chunkById(100, function (Collection $users) use ($matcher, &$matched): void {
                foreach ($users as $user) {
                    $matched += $matcher->matchForUser($user);
                }
            });

        $this->info("Matched {$matched} transaction(s).");

        return self::SUCCESS;
    }
}
