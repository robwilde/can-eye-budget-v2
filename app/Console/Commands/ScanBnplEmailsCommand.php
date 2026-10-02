<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ScheduleSource;
use App\DTOs\RawEmail;
use App\Models\User;
use App\Services\Bnpl\BnplOrderImporter;
use App\Support\Email\GmailMailbox;
use App\Support\Email\ScheduleParser;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class ScanBnplEmailsCommand extends Command
{
    private const int FETCH_LIMIT = 500;

    protected $signature = 'app:scan-bnpl-emails
        {--since=10d : How far back to read: <n>d, <n>w, <n>m or a Y-m-d date}
        {--user= : Owner of the imported orders; required when more than one user exists}
        {--dry-run : Parse the emails but write nothing}';

    protected $description = 'Scan the Gmail mailbox for BNPL schedule emails and create planned transactions';

    public function handle(ScheduleParser $parser, ScheduleSource $source, BnplOrderImporter $importer, GmailMailbox $mailbox): int
    {
        if (config('budget.bnpl_email_import') !== true) {
            $this->info('BNPL email import is disabled (BUDGET_BNPL_EMAIL_IMPORT).');

            return self::SUCCESS;
        }

        if (! $mailbox->isConfigured()) {
            $this->warn('Gmail is not configured (GMAIL_USERNAME / GMAIL_APP_PASSWORD).');

            return self::SUCCESS;
        }

        try {
            $user = $this->owner();
            $since = $this->since((string) $this->option('since'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = ['scanned' => 0, 'parsed' => 0, 'orders' => 0, 'plans' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($parser->strategies() as $strategy) {
            try {
                $emails = $source->fetch($strategy->query($since), self::FETCH_LIMIT);
            } catch (Throwable $e) {
                $this->error('Gmail fetch failed: '.$e->getMessage());
                Log::warning('BNPL scan failed', ['provider' => $strategy->provider()->value, 'exception' => $e->getMessage()]);

                return self::FAILURE;
            }

            if (count($emails) >= self::FETCH_LIMIT) {
                $this->warn(sprintf('Fetch window truncated at %d messages; narrow --since.', self::FETCH_LIMIT));
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
                } catch (Throwable $e) {
                    report($e);
                    $counts['failed']++;

                    continue;
                }

                if ($order->wasRecentlyCreated) {
                    $counts['orders']++;
                    $counts['plans'] += $order->planned_transaction_id === null ? 0 : 1;
                }
            }
        }

        $this->info($dryRun
            ? sprintf('Dry run: scanned %d email(s): %d schedule(s) parsed, %d skipped; nothing written.', $counts['scanned'], $counts['parsed'], $counts['skipped'])
            : sprintf(
                'Scanned %d email(s): %d order(s) created, %d plan(s) created, %d skipped, %d failed.',
                $counts['scanned'],
                $counts['orders'],
                $counts['plans'],
                $counts['skipped'],
                $counts['failed'],
            ));

        return self::SUCCESS;
    }

    /**
     * Financial records never get a guessed owner: with more than one user
     * the owner must be named, and a malformed id ("1oops") is rejected rather
     * than coerced to a real user.
     *
     * @throws InvalidArgumentException
     */
    private function owner(): User
    {
        $id = $this->option('user');

        if ($id !== null) {
            if (preg_match('/^[1-9]\d*$/', $id) !== 1) {
                throw new InvalidArgumentException('--user must be a user id (a positive whole number).');
            }

            return User::query()->find((int) $id)
                ?? throw new InvalidArgumentException("User {$id} does not exist.");
        }

        $users = User::query()->limit(2)->get();

        return match ($users->count()) {
            1 => $users->first(),
            0 => throw new InvalidArgumentException('No user exists to own the imported orders.'),
            default => throw new InvalidArgumentException('More than one user exists; pass --user=<id>.'),
        };
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
