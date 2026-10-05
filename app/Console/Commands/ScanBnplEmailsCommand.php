<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ScheduleSource;
use App\DTOs\RawEmail;
use App\Models\User;
use App\Services\Bnpl\BnplOrderImporter;
use App\Services\Bnpl\BnplReceiptLinker;
use App\Support\Email\ScheduleParser;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class ScanBnplEmailsCommand extends Command
{
    private const int FETCH_LIMIT = 500;

    protected $signature = 'app:scan-bnpl-emails
        {--since=10d : How far back to read: <n>d, <n>w, <n>m or a Y-m-d date}
        {--user= : Scan only this user id; defaults to every user with a connected Gmail mailbox}
        {--dry-run : Parse the emails but write nothing}';

    protected $description = 'Scan each connected Gmail mailbox for BNPL schedule emails and create planned transactions';

    public function handle(
        ScheduleParser $parser,
        ScheduleSource $source,
        BnplOrderImporter $importer,
        BnplReceiptLinker $linker,
    ): int {
        if (config('budget.bnpl_email_import') !== true) {
            $this->info('BNPL email import is disabled (BUDGET_BNPL_EMAIL_IMPORT).');

            return self::SUCCESS;
        }

        try {
            $users = $this->users();
            $since = $this->since((string) $this->option('since'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $users->exists()) {
            $this->info('No user with a connected Gmail mailbox; nothing to scan.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = ['scanned' => 0, 'parsed' => 0, 'orders' => 0, 'plans' => 0, 'linked' => 0, 'ambiguous' => 0, 'skipped' => 0, 'failed' => 0];
        $fetchFailed = false;

        $users->with('gmailCredential')->chunkById(100, function (Collection $chunk) use ($parser, $source, $importer, $linker, $since, $dryRun, &$counts, &$fetchFailed): void {
            foreach ($chunk as $user) {
                foreach ($parser->strategies() as $strategy) {
                    try {
                        $emails = $source->fetch($user, $strategy->query($since), self::FETCH_LIMIT);
                    } catch (Throwable $e) {
                        $this->error(sprintf('Gmail fetch failed for user %d: %s', $user->id, $e->getMessage()));
                        Log::warning('BNPL scan failed', ['user_id' => $user->id, 'provider' => $strategy->provider()->value, 'exception' => $e->getMessage()]);
                        $fetchFailed = true;

                        break;
                    }

                    if (count($emails) >= self::FETCH_LIMIT) {
                        $this->warn(sprintf('Fetch window truncated at %d messages for user %d; narrow --since.', self::FETCH_LIMIT, $user->id));
                    }

                    foreach ($this->oldestFirst($emails) as $email) {
                        $counts['scanned']++;
                        $schedule = $strategy->parse($email);

                        if ($schedule === null) {
                            $counts['skipped']++;

                            continue;
                        }

                        $counts['parsed']++;

                        if ($dryRun) {
                            continue;
                        }

                        try {
                            $order = $importer->import($user, $email, $schedule);

                            if ($order->wasRecentlyCreated) {
                                $counts['orders']++;
                                $counts['plans'] += $order->planned_transaction_id === null ? 0 : 1;
                            }

                            $link = $linker->link($order, $email, $schedule);
                        } catch (Throwable $e) {
                            report($e);
                            $counts['failed']++;

                            continue;
                        }

                        $counts['linked'] += $link->transaction === null ? 0 : 1;
                        $counts['ambiguous'] += $link->ambiguous ? 1 : 0;
                    }
                }
            }
        });

        $this->info($dryRun
            ? sprintf('Dry run: scanned %d email(s): %d schedule(s) parsed, %d skipped; nothing written.', $counts['scanned'], $counts['parsed'], $counts['skipped'])
            : sprintf(
                'Scanned %d email(s): %d order(s) created, %d plan(s) created, %d instalment(s) linked, %d ambiguous, %d skipped, %d failed.',
                $counts['scanned'],
                $counts['orders'],
                $counts['plans'],
                $counts['linked'],
                $counts['ambiguous'],
                $counts['skipped'],
                $counts['failed'],
            ));

        return $fetchFailed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Builder<User>
     *
     * @throws InvalidArgumentException
     */
    private function users(): Builder
    {
        $id = $this->option('user');

        if ($id === null) {
            return User::query()->whereHas('gmailCredential');
        }

        if (preg_match('/^[1-9]\d*$/', $id) !== 1) {
            throw new InvalidArgumentException('--user must be a user id (a positive whole number).');
        }

        if (! User::query()->whereKey((int) $id)->exists()) {
            throw new InvalidArgumentException("User {$id} does not exist.");
        }

        return User::query()->whereKey((int) $id)->whereHas('gmailCredential');
    }

    /**
     * @throws InvalidArgumentException
     */
    private function since(string $value): CarbonImmutable
    {
        $timezone = (string) config('app.timezone');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);
            } catch (InvalidFormatException) {
                $date = null;
            }

            if ($date !== null && $date->format('Y-m-d') === $value) {
                return $date;
            }
        }

        if (preg_match('/^(\d+)([dwm])$/', $value, $m) === 1) {
            $today = CarbonImmutable::today($timezone);

            return match ($m[2]) {
                'd' => $today->subDays((int) $m[1]),
                'w' => $today->subWeeks((int) $m[1]),
                default => $today->subMonths((int) $m[1]),
            };
        }

        throw new InvalidArgumentException('--since must be Y-m-d or <n>d|w|m.');
    }

    /**
     * The earliest email of an order seeds it with the fullest schedule, so
     * import in date order (undated last, then by message id for a stable run).
     *
     * @param  list<RawEmail>  $emails
     * @return list<RawEmail>
     */
    private function oldestFirst(array $emails): array
    {
        usort($emails, static fn (RawEmail $a, RawEmail $b): int => [$a->date === null, $a->date?->getTimestamp(), $a->messageId]
            <=> [$b->date === null, $b->date?->getTimestamp(), $b->messageId]);

        return $emails;
    }
}
