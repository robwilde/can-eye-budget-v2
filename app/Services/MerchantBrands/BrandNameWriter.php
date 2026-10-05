<?php

declare(strict_types=1);

namespace App\Services\MerchantBrands;

use App\Enums\CleanDescriptionSource;
use App\Enums\MerchantBrandStatus;
use App\Models\MerchantBrand;
use App\Models\Transaction;
use App\Models\User;
use App\Support\CleanDescriptionDeriver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class BrandNameWriter
{
    private const int CHUNK = 200;

    public function fill(User $user, string $merchantKey, string $title): int
    {
        $filled = 0;
        $tidied = CleanDescriptionDeriver::tidy($title);

        Transaction::query()
            ->where('user_id', $user->id)
            ->where('merchant_key', $merchantKey)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('clean_description')
                ->orWhere('clean_description', '')
                ->orWhereIn('clean_description_source', [CleanDescriptionSource::Derived, CleanDescriptionSource::Brand]))
            ->chunkById(self::CHUNK, function (Collection $rows) use ($user, $merchantKey, $title, $tidied, &$filled): void {
                foreach ($rows as $row) {
                    if ($row->clean_description_source === CleanDescriptionSource::Brand && $row->clean_description === $tidied && CleanDescriptionDeriver::tidy($row->merchant_name) === null) {
                        continue;
                    }

                    $filled += $this->fillRow($user, $merchantKey, $row->id, $title) ? 1 : 0;
                }
            });

        return $filled;
    }

    public function fillFromResolvedBrands(User $user): void
    {
        MerchantBrand::query()
            ->where('user_id', $user->id)
            ->where('status', MerchantBrandStatus::Resolved)
            ->where('partial', false)
            ->whereNotNull('title')
            ->where('title', '!=', '')
            ->each(fn (MerchantBrand $brand) => $this->fillIfResolved($user, $brand->merchant_key));
    }

    public function fillIfResolved(User $user, string $merchantKey): void
    {
        DB::transaction(function () use ($user, $merchantKey): void {
            $brand = MerchantBrand::query()
                ->where('user_id', $user->id)
                ->where('merchant_key', $merchantKey)
                ->lockForUpdate()
                ->first();

            if ($brand?->status === MerchantBrandStatus::Resolved && ! $brand->partial && filled($brand->title)) {
                $this->fill($user, $merchantKey, $brand->title);
            }
        });
    }

    public function revoke(User $user, string $merchantKey): void
    {
        DB::transaction(function () use ($user, $merchantKey): void {
            Transaction::query()
                ->withTrashed()
                ->where('user_id', $user->id)
                ->where('merchant_key', $merchantKey)
                ->where(fn (Builder $query): Builder => $query->whereNull('deleted_at')->orWhereNull('folded_into_transaction_id'))
                ->where('clean_description_source', CleanDescriptionSource::Brand)
                ->lockForUpdate()
                ->chunkById(self::CHUNK, function (Collection $rows) use ($user, $merchantKey): void {
                    foreach ($rows as $row) {
                        $this->revokeRow($user, $merchantKey, $row->id);
                    }
                });
        });
    }

    private function fillRow(User $user, string $merchantKey, int $id, string $title): bool
    {
        return DB::transaction(function () use ($user, $merchantKey, $id, $title): bool {
            $row = $this->lockedRow($user, $merchantKey, $id);

            if ($row === null) {
                return false;
            }

            $feed = CleanDescriptionDeriver::tidy($row->merchant_name);
            $offered = $feed === null
                ? $row->offerCleanDescription($title, CleanDescriptionSource::Brand)
                : $row->offerCleanDescription($feed, CleanDescriptionSource::Feed);

            if (! $offered) {
                return false;
            }

            $row->save();

            return true;
        });
    }

    private function revokeRow(User $user, string $merchantKey, int $id): void
    {
        DB::transaction(function () use ($user, $merchantKey, $id): void {
            $row = $this->lockedRow($user, $merchantKey, $id, withTrashed: true);

            if ($row === null || $row->clean_description_source !== CleanDescriptionSource::Brand) {
                return;
            }

            $feed = CleanDescriptionDeriver::tidy($row->merchant_name);
            $name = $feed ?? CleanDescriptionDeriver::fromDescription($row->description);

            $row->clean_description = $name;
            $row->clean_description_source = $name === null ? null : ($feed === null ? CleanDescriptionSource::Derived : CleanDescriptionSource::Feed);
            $row->save();
        });
    }

    private function lockedRow(User $user, string $merchantKey, int $id, bool $withTrashed = false): ?Transaction
    {
        return Transaction::query()
            ->when($withTrashed, fn (Builder $query): Builder => $query->withTrashed())
            ->where('user_id', $user->id)
            ->where('merchant_key', $merchantKey)
            ->lockForUpdate()
            ->find($id);
    }
}
