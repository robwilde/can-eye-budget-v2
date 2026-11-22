<?php

declare(strict_types=1);

namespace App\Services\Investments;

use App\Enums\ImportSource;
use App\Exceptions\HoldingRefusedException;
use App\Models\Account;
use App\Models\Holding;
use App\Models\Trade;
use App\Models\User;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an account's position in a security: its holdings and its trades. An account that a
 * provider feeds is refused, because the next sync would recreate the holding; a manual account can
 * always delete.
 */
final readonly class HoldingDeleter
{
    public function __construct(private AccountBalanceHistoryBuilder $history) {}

    /**
     * @throws HoldingRefusedException when the holding is not the user's or a linked provider would recreate it
     */
    public function delete(User $user, Holding $holding): void
    {
        $own = Holding::query()->forUser($user)->whereKey($holding->id)->first()
            ?? throw new HoldingRefusedException('That holding is not on one of your accounts.');

        DB::transaction(function () use ($user, $own): void {
            $account = Account::query()->where('user_id', $user->id)->whereKey($own->account_id)->lockForUpdate()->firstOrFail();

            if ($account->import_source === ImportSource::Redbark) {
                throw new HoldingRefusedException('This account is linked to a provider, which would recreate the holding on its next sync.');
            }

            Holding::query()->where('account_id', $account->id)->where('security_id', $own->security_id)->orderBy('id')->lockForUpdate()->get(['id']);

            Trade::query()->where('account_id', $account->id)->where('security_id', $own->security_id)->delete();
            Holding::query()->where('account_id', $account->id)->where('security_id', $own->security_id)->delete();

            $this->history->rebuild($account);
        });
    }
}
