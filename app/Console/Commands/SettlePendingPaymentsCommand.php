<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Recurring\PaymentSettlementDeferral;
use Illuminate\Console\Command;

final class SettlePendingPaymentsCommand extends Command
{
    protected $signature = 'app:settle-pending-payments {--user= : Only this user id}';

    protected $description = 'Settle occurrence payments a batch left unsettled (a process that died after its commit, or a failed settlement)';

    public function handle(PaymentSettlementDeferral $deferral): int
    {
        $user = $this->option('user');
        $left = $deferral->sweep(is_numeric($user) ? (int) $user : null);

        $this->info($left === 0 ? 'Settled every pending payment.' : sprintf('%d pending payment(s) could not be settled yet.', $left));

        return $left === 0 ? self::SUCCESS : self::FAILURE;
    }
}
