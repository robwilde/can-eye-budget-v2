<?php

declare(strict_types=1);

namespace App\Services\Investments;

use App\DTOs\NewTransfer;
use App\DTOs\RecordTrade;
use App\Enums\TradeActivity;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Exceptions\TradeRefusedException;
use App\Exceptions\TransferLinkRefusedException;
use App\Models\Account;
use App\Models\Security;
use App\Models\Trade;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\Transfers\TransferLinker;
use App\Support\Investments\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records investment activity on one of the user's accounts. A buy, sell, sweep, reinvestment or
 * dividend needs a security; interest and fees fall back to a cash security of the trade's currency.
 * Sells and sweep-outs are stored with a negative quantity and the amount is quantity times price plus
 * the fee, so a buy costs positive cents and a sell's proceeds are negative. Dividends and interest
 * are inflows and so negative amounts; a fee is an outflow. A deposit or withdrawal with a counter
 * account is a transfer pair, which follows the transfer counting rule; without one it is a plain
 * transaction. Everything is written in one database transaction with the account rows locked in id
 * order, and a refused request writes nothing.
 */
final readonly class TradeRecorder
{
    public function __construct(
        private TransferLinker $transfers,
        private AccountBalanceHistoryBuilder $history,
        private HoldingSnapshotter $holdings,
    ) {}

    /**
     * @throws TradeRefusedException when an account is not the user's or the request is incomplete
     */
    public function record(User $user, RecordTrade $input): Trade|Transaction
    {
        $account = $this->ownedAccount($user, $input->accountId);

        if (in_array($input->activity, [TradeActivity::Deposit, TradeActivity::Withdrawal], true)) {
            return $this->recordCashMovement($user, $account, $input);
        }

        $currency = $this->currencyOf($input->currency, $account);

        return DB::transaction(function () use ($user, $input, $currency, $account): Trade {
            $this->lockAccounts($user, $input->accountId);

            $security = $this->security($input, $currency);
            [$quantity, $price, $fee, $amount] = $this->figures($input);

            $trade = Trade::query()->create([
                'account_id' => $input->accountId,
                'security_id' => $security->id,
                'date' => $input->date,
                'quantity' => $quantity,
                'price' => $price,
                'fee' => $fee,
                'amount' => $amount,
                'currency' => $currency,
                'activity' => $input->activity,
                'name' => $input->name ?? $this->nameFor($input->activity, $quantity, $security),
            ])->refresh();

            if ($input->activity->movesShares()) {
                $this->holdings->refresh($user, $input->accountId, $security->id, $currency);
                $this->history->rebuild($account, CarbonImmutable::parse($input->date));
            }

            return $trade;
        });
    }

    /**
     * Changes a trade's quantity (signed: positive buys, negative sells), price or fee. The amount, the
     * name and, for a buy or sell, the activity are derived again and the trade is locked, so a later
     * import does not overwrite the edit.
     *
     * @throws TradeRefusedException when the trade is not on one of the user's accounts or has no shares
     */
    public function update(User $user, Trade $trade, ?string $quantity = null, ?int $price = null, ?int $fee = null): Trade
    {
        return DB::transaction(function () use ($user, $trade, $quantity, $price, $fee): Trade {
            $this->lockAccounts($user, $trade->account_id);

            $locked = Trade::query()->forUser($user)->whereKey($trade->id)->orderBy('id')->lockForUpdate()->first()
                ?? throw new TradeRefusedException('That trade is not on one of your accounts.');

            if (! $locked->activity->movesShares()) {
                throw new TradeRefusedException('Only a trade that moves shares has a quantity and a price to change.');
            }

            $newQuantity = $quantity === null ? $locked->quantity : $this->editedQuantity($quantity, $locked->activity);
            $newPrice = $price ?? $locked->price;
            $newFee = $fee ?? $locked->fee;

            if ($newPrice < 0 || $newFee < 0 || Decimal::isZero($newQuantity)) {
                throw new TradeRefusedException('A trade needs shares, a price and a fee that are not negative.');
            }

            $activity = match ($locked->activity) {
                TradeActivity::Buy, TradeActivity::Sell => Decimal::isNegative($newQuantity) ? TradeActivity::Sell : TradeActivity::Buy,
                default => $locked->activity,
            };

            $locked->update([
                'quantity' => $newQuantity,
                'price' => $newPrice,
                'fee' => $newFee,
                'amount' => Decimal::mulCents($newQuantity, $newPrice) + $newFee,
                'activity' => $activity,
                'name' => $this->nameFor($activity, $newQuantity, $locked->security),
                'locked' => true,
            ]);

            if ($locked->security_id !== null) {
                $this->holdings->refresh($user, $locked->account_id, $locked->security_id, $locked->currency);
                $this->history->rebuild($locked->account, $locked->date);
            }

            return $locked;
        });
    }

    /**
     * The name a trade gets from its activity, quantity and security, such as "Buy 10 VAS".
     */
    public function nameFor(TradeActivity $activity, string $quantity, ?Security $security): string
    {
        $parts = [$activity->label()];

        if ($activity->movesShares()) {
            $parts[] = Decimal::trim(Decimal::abs($quantity));
        }

        if ($security !== null) {
            $parts[] = $security->ticker;
        }

        return implode(' ', $parts);
    }

    private function ownedAccount(User $user, int $accountId): Account
    {
        return Account::query()->where('user_id', $user->id)->find($accountId)
            ?? throw new TradeRefusedException('That account is not one of yours.');
    }

    /**
     * Locks the accounts in id order and re-checks they are the user's, as the first statements of the
     * write's transaction.
     */
    private function lockAccounts(User $user, int ...$accountIds): void
    {
        $ids = array_values(array_unique($accountIds));
        sort($ids);

        $owned = Account::query()->where('user_id', $user->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id')->all();

        if (count($owned) !== count($ids)) {
            throw new TradeRefusedException('That account is not one of yours.');
        }
    }

    private function recordCashMovement(User $user, Account $account, RecordTrade $input): Transaction
    {
        if ($input->currency !== null && mb_trim($input->currency) !== '' && $this->currencyOf($input->currency, $account) !== $account->currency) {
            throw new TradeRefusedException('A deposit or withdrawal is in the investment account\'s currency.');
        }

        $amount = $input->amount ?? 0;

        if ($amount <= 0) {
            throw new TradeRefusedException('A deposit or withdrawal needs a positive amount.');
        }

        $deposit = $input->activity === TradeActivity::Deposit;

        if ($input->counterAccountId !== null) {
            $counter = $this->ownedAccount($user, $input->counterAccountId);

            if ($counter->currency !== $account->currency) {
                throw new TradeRefusedException('The counter-account must use the same currency as the investment account.');
            }

            try {
                return $this->transfers->create(new NewTransfer(
                    userId: $user->id,
                    fromAccountId: $deposit ? $counter->id : $account->id,
                    toAccountId: $deposit ? $account->id : $counter->id,
                    amount: $amount,
                    description: $input->name ?? $input->activity->label(),
                    postDate: $input->date,
                ));
            } catch (InvalidArgumentException|TransferLinkRefusedException $e) {
                throw new TradeRefusedException($e->getMessage(), 0, $e);
            }
        }

        return DB::transaction(function () use ($user, $account, $input, $amount, $deposit): Transaction {
            $this->lockAccounts($user, $account->id);

            $transaction = Transaction::query()->create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'amount' => $amount,
                'direction' => $deposit ? TransactionDirection::Credit : TransactionDirection::Debit,
                'description' => $input->name ?? $input->activity->label(),
                'post_date' => $input->date,
                'status' => TransactionStatus::Posted,
                'source' => TransactionSource::Manual,
            ]);

            $this->history->rebuildForTransactions($transaction);

            return $transaction;
        });
    }

    private function security(RecordTrade $input, string $currency): Security
    {
        if ($input->activity->needsSecurity()) {
            return Security::query()->find($input->securityId)
                ?? throw new TradeRefusedException('That activity needs a security.');
        }

        if ($input->securityId !== null) {
            return Security::query()->find($input->securityId)
                ?? throw new TradeRefusedException('That security does not exist.');
        }

        $key = ['ticker' => Security::normalise('CASH-'.$currency), 'exchange_mic' => ''];

        try {
            return Security::query()->firstOrCreate($key, ['name' => 'Cash ('.$currency.')', 'currency' => $currency]);
        } catch (UniqueConstraintViolationException) {
            return Security::query()->where($key)->lockForUpdate()->firstOrFail();
        }
    }

    /**
     * @return array{0: string, 1: int, 2: int, 3: int} the signed quantity, price, fee and amount
     */
    private function figures(RecordTrade $input): array
    {
        if ($input->activity->movesShares()) {
            if ($input->quantity === null || $input->price === null) {
                throw new TradeRefusedException('A trade needs a quantity and a price.');
            }

            $quantity = $this->signedQuantity($input->quantity, $input->activity);

            if (Decimal::isZero($quantity) || $input->price < 0 || $input->fee < 0) {
                throw new TradeRefusedException('A trade needs shares, a price and a fee that are not negative.');
            }

            return [$quantity, $input->price, $input->fee, Decimal::mulCents($quantity, $input->price) + $input->fee];
        }

        $amount = $input->amount ?? 0;

        if ($amount <= 0) {
            throw new TradeRefusedException('That activity needs a positive amount.');
        }

        $inflow = $input->activity !== TradeActivity::Fee;

        return ['0.00000000', 0, 0, $inflow ? -$amount : $amount];
    }

    private function signedQuantity(string $quantity, TradeActivity $activity): string
    {
        $value = $this->quantityOf($quantity);

        return $activity->reducesPosition() ? Decimal::negate(Decimal::abs($value)) : Decimal::abs($value);
    }

    /**
     * A quantity typed into an edit: for a buy or sell it keeps its sign, because the sign says which
     * of the two the trade is; any other activity keeps the sign its kind of movement has.
     */
    private function editedQuantity(string $quantity, TradeActivity $activity): string
    {
        return in_array($activity, [TradeActivity::Buy, TradeActivity::Sell], true)
            ? $this->quantityOf($quantity)
            : $this->signedQuantity($quantity, $activity);
    }

    private function quantityOf(string $quantity): string
    {
        try {
            return Decimal::of($quantity);
        } catch (InvalidArgumentException) {
            throw new TradeRefusedException('The quantity is not a number.');
        }
    }

    private function currencyOf(?string $currency, Account $account): string
    {
        $code = mb_strtoupper(mb_trim((string) $currency));
        $code = $code === '' ? $account->currency : $code;

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new TradeRefusedException('The currency must be a three-letter code.');
        }

        return $code;
    }
}
