<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AccountClass;
use App\Jobs\SendBillAlertsJob;
use App\Models\Account;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

final class SendBillAlertsCommand extends Command
{
    protected $signature = 'app:send-bill-alerts';

    protected $description = 'Queue the daily bill alerts (unpaid subscriptions, low projected cash) for every user with tracked cash (budget.bill_alerts)';

    public function handle(): int
    {
        if (! config('budget.bill_alerts')) {
            $this->info('Bill alerts are off.');

            return self::SUCCESS;
        }

        $queued = 0;

        User::query()
            ->whereIn('id', Account::query()->active()->tracked()->whereIn('type', [AccountClass::Transaction->value, AccountClass::Savings->value])->select('user_id'))
            ->chunkById(100, function (Collection $users) use (&$queued): void {
                foreach ($users as $user) {
                    SendBillAlertsJob::dispatch($user);
                    $queued++;
                }
            });

        $this->info(sprintf('Queued bill alerts for %d user(s).', $queued));

        return self::SUCCESS;
    }
}
