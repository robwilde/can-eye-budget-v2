<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TransferLinkSource;
use App\Models\User;
use App\Services\Transfers\TransferDetector;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

final class DetectTransfersCommand extends Command
{
    protected $signature = 'transfers:detect {--user= : Detect for a single user id; omit for every user} {--dry-run : Report the pairs without linking them}';

    protected $description = 'Link the two legs of internal transfers (rules, then strict single mutual matches). Idempotent.';

    /** @throws Throwable */
    public function handle(TransferDetector $detector): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $userId = $this->option('user');

        if ($userId !== null && ! User::query()->whereKey((int) $userId)->exists()) {
            $this->error("User {$userId} not found.");

            return self::FAILURE;
        }

        $rule = 0;
        $suggested = 0;

        User::query()
            ->when($userId !== null, fn ($q) => $q->whereKey((int) $userId))
            ->chunkById(100, function (Collection $users) use ($detector, $dryRun, &$rule, &$suggested): void {
                foreach ($users as $user) {
                    $result = $detector->run($user, $dryRun);
                    $rule += $result['rule'];
                    $suggested += $result['suggested'];

                    foreach ($result['pairs'] as $pair) {
                        // Only rule pairs are linked; suggestions are pending until confirmed.
                        $verb = $pair['source'] === TransferLinkSource::Rule->value
                            ? ($dryRun ? 'would link' : 'linked')
                            : ($dryRun ? 'would suggest' : 'suggested');

                        $this->line(sprintf(
                            '%s user %d: debit #%d <-> credit #%d (%s)',
                            $verb,
                            $user->id,
                            $pair['debit'],
                            $pair['credit'],
                            $pair['source'],
                        ));
                    }
                }
            });

        $this->info(sprintf(
            '%s %d rule-based pair(s); %s %d pair(s) for review.',
            $dryRun ? 'Would link' : 'Linked',
            $rule,
            $dryRun ? 'would suggest' : 'suggested',
            $suggested,
        ));

        return self::SUCCESS;
    }
}
