<?php

declare(strict_types=1);

use App\Models\AnalysisSuggestion;
use App\Models\BnplOrder;
use App\Models\BnplOrderEvent;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Payee;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\UserCategoryBudgetTag;
use App\Models\UserRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Moves an existing category tree onto the 14 budget-tagged roots.
 *
 * Old leaves are re-parented, never recreated, so their ids survive and every
 * transaction, split, budget, planned transaction, BNPL order, rule and
 * suggestion that points at them stays valid. A row is only merged (its
 * references rewritten onto another row and then deleted) where two old rows
 * collapse into one: Food (root) and Bills / Food into Groceries.
 *
 * Old roots Office, Personal and Bills are deleted only when nothing is left
 * under or pointing at them. Re-running is a no-op.
 *
 * Irreversible by design: the old shape is not recoverable once leaves are
 * re-parented, so down() does nothing.
 */
return new class extends Migration
{
    private const array ROOTS = [
        'Housing & Utilities' => ['needs', 'home'],
        'Groceries' => ['needs', 'shopping-cart'],
        'Transport' => ['needs', 'home'],
        'Health' => ['needs', 'activity'],
        'Insurance' => ['needs', 'shield-check'],
        'Software & Online Services' => ['needs', 'bolt'],
        'Work Equipment' => ['wants', 'wrench-screwdriver'],
        'Bank Fees & Finance Services' => ['needs', 'building-library'],
        'Loans & Debt Repayment' => ['savings', 'building-library'],
        'Eating Out' => ['wants', 'coffee'],
        'Learning & Reading' => ['wants', 'book-open-text'],
        'Entertainment' => ['wants', 'sparkles'],
        'Pets' => ['wants', 'house-heart'],
        'Personal & Shopping' => ['wants', 'sparkles'],
    ];

    /** @var array<string, string> "old root|old child" => promoted root name */
    private const array PROMOTIONS = [
        'Personal|Health' => 'Health',
        'Personal|Pet' => 'Pets',
        'Personal|Finance' => 'Bank Fees & Finance Services',
        'Food|Groceries' => 'Groceries',
    ];

    /** @var array<string, list<string>> old root => children moving to the named new root */
    private const array REPARENTS = [
        'Housing & Utilities' => ['Bills|Rent', 'Bills|Electricity', 'Bills|Hotwater', 'Bills|Internet', 'Bills|Mobile', 'Bills|Cleaning'],
        'Eating Out' => ['Food|Restaurant', 'Food|Quick Foods'],
        'Software & Online Services' => ['Office|Online Service', 'Office|Software', 'Office|AI Apps', 'Office|Mobile App'],
        'Work Equipment' => ['Office|Hardware', 'Office|3D Printing', 'Office|IoT', 'Office|Laptop', 'Office|Tools'],
        'Learning & Reading' => ['Office|Newsletter', 'Office|Training'],
        'Personal & Shopping' => [
            'Personal|Subscription', 'Personal|Hunter', 'Personal|Kitchen', 'Personal|Clothes', 'Personal|Gifts',
            'Personal|Grooming', 'Personal|Beddings', 'Personal|Bathroom', 'Personal|Holiday', 'Personal|Plants',
            'Personal|Fines', 'Personal|Charity', 'Personal|Job Hunting',
        ],
    ];

    private const array OLD_ROOTS = ['Office', 'Personal', 'Bills', 'Food'];

    private const array CATEGORY_REFERENCING_MODELS = [Transaction::class, Budget::class, PlannedTransaction::class, TransactionSplit::class, BnplOrder::class];

    private const array PAYLOAD_MODELS = [AnalysisSuggestion::class, BnplOrderEvent::class];

    public function up(): void
    {
        if (! $this->table(Category::class)->exists()) {
            return;
        }

        DB::transaction(function (): void {
            $this->renameLoanRoot();

            foreach (self::PROMOTIONS as $key => $newName) {
                [$oldRoot, $oldChild] = explode('|', $key);
                $this->promote($oldRoot, $oldChild, $newName);
            }

            foreach (self::ROOTS as $name => [$tag, $icon]) {
                $this->ensureRoot($name, $tag, $icon);
            }

            foreach (self::REPARENTS as $newRoot => $children) {
                $rootId = $this->rootId($newRoot);

                foreach ($children as $key) {
                    [$oldRoot, $oldChild] = explode('|', $key);
                    $this->reparent($oldRoot, $oldChild, $rootId);
                }
            }

            $groceriesId = $this->rootId('Groceries');
            $billsFood = $this->child('Bills', 'Food');

            if ($billsFood !== null) {
                $this->merge($billsFood, $groceriesId);
            }

            $food = $this->root('Food');

            if ($food !== null) {
                $this->merge($food, $groceriesId);
            }

            $this->deleteOrphanedOldRoots();
        });
    }

    public function down(): void {}

    /**
     * @param  class-string<Illuminate\Database\Eloquent\Model>  $model
     */
    private function table(string $model): Builder
    {
        return $model::query()->withoutGlobalScopes()->toBase();
    }

    private function root(string $name): ?int
    {
        $id = $this->table(Category::class)->whereNull('parent_id')->where('name', $name)->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function rootId(string $name): int
    {
        return $this->root($name) ?? throw new RuntimeException("Root category {$name} missing after ensureRoot.");
    }

    private function child(string $rootName, string $childName): ?int
    {
        $rootId = $this->root($rootName);

        if ($rootId === null) {
            return null;
        }

        $id = $this->table(Category::class)->where('parent_id', $rootId)->where('name', $childName)->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function renameLoanRoot(): void
    {
        $loan = $this->root('Loan');

        if ($loan === null) {
            return;
        }

        $target = $this->root('Loans & Debt Repayment');

        if ($target === null) {
            $this->table(Category::class)->where('id', $loan)->update(['name' => 'Loans & Debt Repayment', 'updated_at' => now()]);

            return;
        }

        $this->merge($loan, $target);
    }

    private function promote(string $oldRoot, string $oldChild, string $newName): void
    {
        $child = $this->child($oldRoot, $oldChild);

        if ($child === null) {
            return;
        }

        $existing = $this->root($newName);

        if ($existing !== null) {
            $this->merge($child, $existing);

            return;
        }

        $this->table(Category::class)->where('id', $child)->update(['parent_id' => null, 'name' => $newName, 'updated_at' => now()]);
    }

    private function ensureRoot(string $name, string $tag, string $icon): void
    {
        $id = $this->root($name);

        if ($id === null) {
            $this->table(Category::class)->insert([
                'name' => $name,
                'parent_id' => null,
                'budget_tag' => $tag,
                'icon' => $icon,
                'is_hidden' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        $this->table(Category::class)->where('id', $id)->update(['budget_tag' => $tag, 'updated_at' => now()]);
        $this->table(Category::class)->where('id', $id)->whereNull('icon')->update(['icon' => $icon]);
    }

    private function reparent(string $oldRoot, string $oldChild, int $newRootId): void
    {
        $child = $this->child($oldRoot, $oldChild);

        if ($child === null) {
            return;
        }

        $sameName = $this->table(Category::class)
            ->where('parent_id', $newRootId)
            ->where('name', $oldChild)
            ->where('id', '!=', $child)
            ->value('id');

        if ($sameName !== null) {
            $this->merge($child, (int) $sameName);

            return;
        }

        $this->table(Category::class)->where('id', $child)->update(['parent_id' => $newRootId, 'updated_at' => now()]);
    }

    private function merge(int $from, int $to): void
    {
        if ($from === $to) {
            return;
        }

        foreach (self::CATEGORY_REFERENCING_MODELS as $model) {
            $this->table($model)->where('category_id', $from)->update(['category_id' => $to]);
        }

        $this->table(Payee::class)->where('suggested_category_id', $from)->update(['suggested_category_id' => $to]);
        $this->table(Payee::class)->where('confirmed_category_id', $from)->update(['confirmed_category_id' => $to]);

        $ownersOfTarget = $this->table(UserCategoryBudgetTag::class)->where('category_id', $to)->pluck('user_id');
        $this->table(UserCategoryBudgetTag::class)->where('category_id', $from)->whereIn('user_id', $ownersOfTarget)->delete();
        $this->table(UserCategoryBudgetTag::class)->where('category_id', $from)->update(['category_id' => $to]);

        $this->table(Category::class)->where('parent_id', $from)->update(['parent_id' => $to]);

        $this->rewriteJsonReferences($from, $to);

        $this->table(Category::class)->where('id', $from)->delete();
    }

    private function rewriteJsonReferences(int $from, int $to): void
    {
        $this->table(UserRule::class)->orderBy('id')->chunkById(200, function ($rules) use ($from, $to): void {
            foreach ($rules as $rule) {
                $actions = json_decode((string) $rule->actions, true) ?? [];
                $triggers = json_decode((string) $rule->triggers, true) ?? [];

                $newActions = array_map(fn (array $action): array => ($action['type'] ?? null) === 'set_category' && (string) ($action['value'] ?? '') === (string) $from
                    ? [...$action, 'value' => $this->sameShape($action['value'], $to)]
                    : $action, $actions);

                $newTriggers = array_map(fn (array $trigger): array => ($trigger['field'] ?? null) === 'category_id' && (string) ($trigger['value'] ?? '') === (string) $from
                    ? [...$trigger, 'value' => $this->sameShape($trigger['value'], $to)]
                    : $trigger, $triggers);

                if ($newActions !== $actions || $newTriggers !== $triggers) {
                    $this->table(UserRule::class)->where('id', $rule->id)->update([
                        'actions' => json_encode($newActions),
                        'triggers' => json_encode($newTriggers),
                    ]);
                }
            }
        });

        foreach (self::PAYLOAD_MODELS as $model) {
            $this->table($model)->whereNotNull('payload')->orderBy('id')->chunkById(200, function ($rows) use ($model, $from, $to): void {
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row->payload, true);

                    if (! is_array($payload)) {
                        continue;
                    }

                    $changed = false;

                    foreach (['category_id', 'previous_category_id'] as $key) {
                        if (isset($payload[$key]) && (string) $payload[$key] === (string) $from) {
                            $payload[$key] = $this->sameShape($payload[$key], $to);
                            $changed = true;
                        }
                    }

                    if ($changed) {
                        $this->table($model)->where('id', $row->id)->update(['payload' => json_encode($payload)]);
                    }
                }
            });
        }
    }

    private function sameShape(mixed $original, int $id): int|string
    {
        return is_string($original) ? (string) $id : $id;
    }

    private function deleteOrphanedOldRoots(): void
    {
        $referenced = $this->referencedInJson();

        foreach (self::OLD_ROOTS as $name) {
            $id = $this->root($name);

            if ($id === null || isset($referenced[$id])) {
                continue;
            }

            $inUse = $this->table(Category::class)->where('parent_id', $id)->exists()
                || $this->table(Transaction::class)->where('category_id', $id)->exists()
                || $this->table(Budget::class)->where('category_id', $id)->exists()
                || $this->table(PlannedTransaction::class)->where('category_id', $id)->exists()
                || $this->table(TransactionSplit::class)->where('category_id', $id)->exists()
                || $this->table(BnplOrder::class)->where('category_id', $id)->exists()
                || $this->table(UserCategoryBudgetTag::class)->where('category_id', $id)->exists()
                || $this->table(Payee::class)->where('suggested_category_id', $id)->orWhere('confirmed_category_id', $id)->exists();

            if (! $inUse) {
                $this->table(Category::class)->where('id', $id)->delete();
            }
        }
    }

    /** @return array<int, true> */
    private function referencedInJson(): array
    {
        $ids = [];

        $this->table(UserRule::class)->orderBy('id')->chunkById(200, function ($rules) use (&$ids): void {
            foreach ($rules as $rule) {
                foreach (json_decode((string) $rule->actions, true) ?? [] as $action) {
                    if (($action['type'] ?? null) === 'set_category' && is_numeric($action['value'] ?? null)) {
                        $ids[(int) $action['value']] = true;
                    }
                }

                foreach (json_decode((string) $rule->triggers, true) ?? [] as $trigger) {
                    if (($trigger['field'] ?? null) === 'category_id' && is_numeric($trigger['value'] ?? null)) {
                        $ids[(int) $trigger['value']] = true;
                    }
                }
            }
        });

        foreach (self::PAYLOAD_MODELS as $model) {
            $this->table($model)->whereNotNull('payload')->orderBy('id')->chunkById(200, function ($rows) use (&$ids): void {
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row->payload, true);

                    if (! is_array($payload)) {
                        continue;
                    }

                    foreach (['category_id', 'previous_category_id'] as $key) {
                        if (is_numeric($payload[$key] ?? null)) {
                            $ids[(int) $payload[$key]] = true;
                        }
                    }
                }
            });
        }

        return $ids;
    }
};
