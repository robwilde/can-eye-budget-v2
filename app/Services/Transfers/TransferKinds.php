<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Enums\AccountClass;
use App\Enums\BudgetTag;
use App\Enums\CategorySource;
use App\Enums\TransactionKind;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\User;
use App\Services\UserCategoryProvisioner;
use Illuminate\Database\Eloquent\Builder;

/**
 * The kind each leg of a confirmed transfer takes, and the Investment Contributions category
 * an uncategorised contribution is filed under. The category is a root of the user's own tree,
 * found by owner, no parent and name and created when missing: it must not sit under Transfer,
 * whose rows are left out of totals by name. A label only; countable() is untouched.
 */
final class TransferKinds
{
    public const string INVESTMENT_CATEGORY = 'Investment Contributions';

    public function outflowKind(AccountClass $source, AccountClass $destination): TransactionKind
    {
        return match (true) {
            $destination === AccountClass::Loan, $destination === AccountClass::Mortgage => TransactionKind::LoanPayment,
            $destination === AccountClass::CreditCard => TransactionKind::CreditCardPayment,
            $destination === AccountClass::Investment => $source === AccountClass::Investment
                ? TransactionKind::FundsMovement
                : TransactionKind::InvestmentContribution,
            default => TransactionKind::FundsMovement,
        };
    }

    /**
     * Attributes for the two legs of a confirmed transfer. An uncategorised investment
     * contribution also gets the Investment Contributions category.
     *
     * @return array{outflow: array<string, mixed>, inflow: array<string, mixed>}
     */
    public function attributes(int $userId, AccountClass $source, AccountClass $destination, ?int $outflowCategoryId): array
    {
        $kind = $this->outflowKind($source, $destination);
        $outflow = ['kind' => $kind];

        if ($kind === TransactionKind::InvestmentContribution && $outflowCategoryId === null) {
            $outflow['category_id'] = $this->investmentCategory($userId)->id;
            $outflow['category_source'] = CategorySource::Rule;
        }

        return ['outflow' => $outflow, 'inflow' => ['kind' => TransactionKind::FundsMovement]];
    }

    public function investmentCategoryId(int $userId): ?int
    {
        $id = $this->investmentCategoryQuery($userId)->value('id');

        return is_int($id) ? $id : null;
    }

    /**
     * Finds or creates the user's Investment Contributions category. Call inside the write's DB
     * transaction: the owner's row is locked first, after any account locks, so two first uses
     * cannot both find nothing and each create one. The lookup is a locking read, which sees what
     * a writer that held the owner's lock committed even when this transaction's REPEATABLE READ
     * snapshot was fixed before the wait.
     */
    public function investmentCategory(int $userId): Category
    {
        User::query()->whereKey($userId)->lockForUpdate()->value('id');

        return $this->investmentCategoryQuery($userId)->lockForUpdate()->first()
            ?? Category::query()->create([
                'user_id' => $userId,
                'name' => self::INVESTMENT_CATEGORY,
                'icon' => 'chart-bar',
                'budget_tag' => BudgetTag::Savings,
                'group_id' => CategoryGroup::query()
                    ->where('user_id', $userId)
                    ->where('name', UserCategoryProvisioner::SAVINGS_GROUP)
                    ->value('id'),
            ]);
    }

    /** @return Builder<Category> */
    private function investmentCategoryQuery(int $userId): Builder
    {
        return Category::query()
            ->where('user_id', $userId)
            ->whereNull('parent_id')
            ->where('name', self::INVESTMENT_CATEGORY)
            ->orderBy('id');
    }
}
