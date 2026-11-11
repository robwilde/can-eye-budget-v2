<?php

declare(strict_types=1);

namespace App\Services\Merchants;

use App\Enums\MerchantBrandStatus;
use App\Enums\PayeeStatus;
use App\Enums\RuleTriggerField;
use App\Models\Merchant;
use App\Models\MerchantAlias;
use App\Models\MerchantBrand;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
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

            $this->moveAliases($user, $sourceMerchants, $sourceKeys, $target);
            $this->moveTransactions($user, $sourceMerchants->modelKeys(), $sourceKeys->all(), $target);
            $this->moveRules($user, $sourceKeys->all(), $target);
            $this->movePayees($user, $sourceKeys->all(), $target);
            $this->moveBrands($user, $sourceKeys->all(), $target);

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
                Transaction::query()->where('user_id', $user->id)->where('merchant_id', $locked->id),
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
                ->where('user_id', $user->id)
                ->where(fn (Builder $query): Builder => $query
                    ->whereIn('merchant_id', $sourceIds)
                    ->orWhere(fn (Builder $unlinked): Builder => $unlinked
                        ->whereNull('merchant_id')
                        ->whereIn('merchant_key', $sourceKeys))),
            ['merchant_id' => $target->id],
            rekey: true,
        );
    }

    /**
     * Rule triggers that name a source merchant_key follow the merge, because the rows they
     * matched now resolve to the target's key.
     *
     * @param  list<string>  $sourceKeys
     */
    private function moveRules(User $user, array $sourceKeys, Merchant $target): void
    {
        UserRule::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(function (UserRule $rule) use ($sourceKeys, $target): void {
                $changed = false;

                $triggers = array_map(function (array $trigger) use ($sourceKeys, $target, &$changed): array {
                    if (($trigger['field'] ?? null) === RuleTriggerField::MerchantKey->value && in_array($trigger['value'] ?? null, $sourceKeys, true)) {
                        $trigger['value'] = $target->merchant_key;
                        $changed = true;
                    }

                    return $trigger;
                }, $rule->triggers);

                if ($changed) {
                    $rule->update(['triggers' => $triggers]);
                }
            });
    }

    /**
     * @param  Builder<Transaction>  $query
     * @param  array<string, mixed>  $values
     * @param  bool  $rekey  recompute each row's merchant_key, which now resolves through the aliases
     */
    private function rewrite(Builder $query, array $values, bool $rekey = false): void
    {
        $query->chunkById(200, function ($transactions) use ($values, $rekey): void {
            foreach ($transactions as $transaction) {
                $transaction->forceFill([
                    ...$values,
                    'locked_fields' => array_values(array_unique([...($transaction->locked_fields ?? []), self::LOCK])),
                ]);

                if ($rekey) {
                    $transaction->merchant_key = $transaction->resolveMerchantKey();
                }

                $transaction->saveQuietly();
            }
        });
    }

    /**
     * @param  list<string>  $sourceKeys
     */
    private function movePayees(User $user, array $sourceKeys, Merchant $target): void
    {
        $payees = Payee::query()
            ->where('user_id', $user->id)
            ->whereIn('merchant_key', [...$sourceKeys, $target->merchant_key])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($payees->isEmpty()) {
            return;
        }

        $isConfirmed = fn (Payee $payee): bool => $payee->status === PayeeStatus::Confirmed && $payee->confirmed_category_id !== null;

        $winner = $payees->first(fn (Payee $payee): bool => $payee->merchant_key === $target->merchant_key && $isConfirmed($payee))
            ?? $payees->first($isConfirmed)
            ?? $payees->first(fn (Payee $payee): bool => $payee->merchant_key === $target->merchant_key)
            ?? $payees->first();

        Payee::query()->whereKey($payees->reject(fn (Payee $payee): bool => $payee->is($winner))->modelKeys())->delete();

        $winner->forceFill(['merchant_key' => $target->merchant_key])->save();
    }

    /**
     * @param  list<string>  $sourceKeys
     */
    private function moveBrands(User $user, array $sourceKeys, Merchant $target): void
    {
        $brands = MerchantBrand::query()
            ->where('user_id', $user->id)
            ->whereIn('merchant_key', [...$sourceKeys, $target->merchant_key])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

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
