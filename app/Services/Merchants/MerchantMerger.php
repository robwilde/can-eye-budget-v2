<?php

declare(strict_types=1);

namespace App\Services\Merchants;

use App\Enums\MerchantBrandStatus;
use App\Enums\PayeeStatus;
use App\Models\Merchant;
use App\Models\MerchantAlias;
use App\Models\MerchantBrand;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payees\PayeeConfirmer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Merges, deletes and unlinks a user's merchants. Merging records an alias per
 * source key instead of rewriting keys elsewhere, so the next sync or edit of an
 * old spelling still lands on the target.
 */
final class MerchantMerger
{
    public const string LOCK = 'merchant';

    public function __construct(private readonly PayeeConfirmer $confirmer) {}

    /**
     * @param  iterable<Merchant>  $sources
     */
    public function merge(User $user, iterable $sources, Merchant $target): void
    {
        $sourceIds = collect($sources)->map(fn (Merchant $merchant): int => $merchant->id)->unique()->values();

        if ($sourceIds->isEmpty() || $sourceIds->contains($target->id)) {
            throw new InvalidArgumentException('Merge needs at least one source other than the target.');
        }

        DB::transaction(function () use ($user, $sourceIds, $target): void {
            $merchants = $this->lockOwned($user, $sourceIds->push($target->id)->all());
            $target = $merchants->get($target->id);
            $sourceMerchants = $merchants->except([$target->id]);
            $sourceKeys = $sourceMerchants->pluck('merchant_key')->reject(fn (string $key): bool => $key === $target->merchant_key)->values();
            $keys = [...$sourceKeys->all(), $target->merchant_key];
            $payees = $this->lockPayees($user, $keys);
            $brands = $this->lockBrands($user, $keys);

            $this->moveAliases($user, $sourceMerchants, $sourceKeys, $target);
            $this->moveTransactions($user, $sourceMerchants->modelKeys(), $sourceKeys->all(), $target);
            $this->movePayees($user, $payees, $target);
            $this->moveBrands($brands, $target);

            Merchant::query()->where('user_id', $user->id)->whereKey($sourceMerchants->modelKeys())->delete();
        });
    }

    public function delete(User $user, Merchant $merchant): void
    {
        DB::transaction(function () use ($user, $merchant): void {
            $locked = $this->lockOwned($user, [$merchant->id])->get($merchant->id);

            Transaction::query()
                ->where('user_id', $user->id)
                ->where('merchant_id', $locked->id)
                ->update(['merchant_id' => null]);

            $locked->delete();
        });
    }

    public function unlink(User $user, Merchant $merchant): void
    {
        DB::transaction(function () use ($user, $merchant): void {
            $locked = $this->lockOwned($user, [$merchant->id])->get($merchant->id);

            $this->rewrite(
                Transaction::query()->withTrashed()->where('user_id', $user->id)->where('merchant_id', $locked->id),
                ['merchant_id' => null],
            );

            $locked->forceFill(['unlinked_at' => now()])->save();
        });
    }

    /**
     * @param  list<int>  $ids
     * @return EloquentCollection<int, Merchant>
     */
    private function lockOwned(User $user, array $ids): EloquentCollection
    {
        $merchants = Merchant::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($merchants->count() !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Merchant does not belong to this user.');
        }

        return $merchants;
    }

    /**
     * @param  EloquentCollection<int, Merchant>  $sourceMerchants
     * @param  Collection<int, string>  $sourceKeys
     */
    private function moveAliases(User $user, EloquentCollection $sourceMerchants, Collection $sourceKeys, Merchant $target): void
    {
        MerchantAlias::query()
            ->where('user_id', $user->id)
            ->whereIn('merchant_id', $sourceMerchants->modelKeys())
            ->where('merchant_key', $target->merchant_key)
            ->delete();

        MerchantAlias::query()
            ->where('user_id', $user->id)
            ->whereIn('merchant_id', $sourceMerchants->modelKeys())
            ->update(['merchant_id' => $target->id]);

        foreach ($sourceKeys as $key) {
            MerchantAlias::query()->updateOrCreate(
                ['user_id' => $user->id, 'merchant_key' => $key],
                ['merchant_id' => $target->id],
            );
        }
    }

    /**
     * @param  list<int>  $sourceIds
     * @param  list<string>  $sourceKeys
     */
    private function moveTransactions(User $user, array $sourceIds, array $sourceKeys, Merchant $target): void
    {
        $this->rewrite(
            Transaction::query()
                ->withTrashed()
                ->where('user_id', $user->id)
                ->where(fn (Builder $query): Builder => $query
                    ->whereIn('merchant_id', $sourceIds)
                    ->orWhere(fn (Builder $unlinked): Builder => $unlinked
                        ->whereNull('merchant_id')
                        ->whereIn('merchant_key', $sourceKeys)
                        ->where(fn (Builder $unlocked): Builder => $unlocked
                            ->whereNull('locked_fields')
                            ->orWhereJsonDoesntContain('locked_fields', self::LOCK)))),
            ['merchant_id' => $target->id],
        );
    }

    /**
     * @param  Builder<Transaction>  $query
     * @param  array<string, mixed>  $values
     */
    private function rewrite(Builder $query, array $values): void
    {
        $query->chunkById(200, function ($transactions) use ($values): void {
            foreach ($transactions as $transaction) {
                $transaction->forceFill([
                    ...$values,
                    'locked_fields' => array_values(array_unique([...($transaction->locked_fields ?? []), self::LOCK])),
                ])->saveQuietly();
            }
        });
    }

    /**
     * @param  list<string>  $keys
     * @return EloquentCollection<int, Payee>
     */
    private function lockPayees(User $user, array $keys): EloquentCollection
    {
        return Payee::query()
            ->where('user_id', $user->id)
            ->whereIn('merchant_key', $keys)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  list<string>  $keys
     * @return EloquentCollection<int, MerchantBrand>
     */
    private function lockBrands(User $user, array $keys): EloquentCollection
    {
        return MerchantBrand::query()
            ->where('user_id', $user->id)
            ->whereIn('merchant_key', $keys)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  EloquentCollection<int, Payee>  $payees
     */
    private function movePayees(User $user, EloquentCollection $payees, Merchant $target): void
    {
        if ($payees->isEmpty()) {
            return;
        }

        $isConfirmed = fn (Payee $payee): bool => $payee->status === PayeeStatus::Confirmed && $payee->confirmed_category_id !== null;

        $winner = $payees->first(fn (Payee $payee): bool => $payee->merchant_key === $target->merchant_key && $isConfirmed($payee))
            ?? $payees->first($isConfirmed)
            ?? $payees->first(fn (Payee $payee): bool => $payee->merchant_key === $target->merchant_key)
            ?? $payees->first();

        Payee::query()->whereKey($payees->reject(fn (Payee $payee): bool => $payee->is($winner))->modelKeys())->delete();

        $rekeyed = $winner->merchant_key !== $target->merchant_key;

        $winner->forceFill(['merchant_key' => $target->merchant_key])->save();

        if ($rekeyed && $isConfirmed($winner)) {
            $this->reconfirm($user, $winner);
        }
    }

    private function reconfirm(User $user, Payee $payee): void
    {
        $payee->forceFill(['user_rule_id' => null])->save();

        try {
            $this->confirmer->confirm($user, $payee, (int) $payee->confirmed_category_id, $payee->budget_tag);
        } catch (InvalidArgumentException) {
            $payee->forceFill([
                'status' => PayeeStatus::Pending,
                'confirmed_category_id' => null,
                'budget_tag' => null,
                'resolved_at' => null,
            ])->save();
        }
    }

    /**
     * @param  EloquentCollection<int, MerchantBrand>  $brands
     */
    private function moveBrands(EloquentCollection $brands, Merchant $target): void
    {
        if ($brands->isEmpty()) {
            return;
        }

        $winner = $brands->sortBy([
            fn (MerchantBrand $a, MerchantBrand $b): int => $this->rank($a->status) <=> $this->rank($b->status),
            fn (MerchantBrand $a, MerchantBrand $b): int => $a->partial <=> $b->partial,
            fn (MerchantBrand $a, MerchantBrand $b): int => ($b->merchant_key === $target->merchant_key) <=> ($a->merchant_key === $target->merchant_key),
            fn (MerchantBrand $a, MerchantBrand $b): int => $a->id <=> $b->id,
        ])->first();

        MerchantBrand::query()->whereKey($brands->reject(fn (MerchantBrand $brand): bool => $brand->is($winner))->modelKeys())->delete();

        $winner->forceFill(['merchant_key' => $target->merchant_key])->save();
    }

    private function rank(MerchantBrandStatus $status): int
    {
        return match ($status) {
            MerchantBrandStatus::Resolved => 0,
            MerchantBrandStatus::Vetoed => 1,
            MerchantBrandStatus::Unresolved => 2,
        };
    }
}
