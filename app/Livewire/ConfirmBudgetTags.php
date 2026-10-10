<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\BudgetTag;
use App\Models\Category;
use App\Models\User;
use App\Models\UserCategoryBudgetTag;
use App\Support\Budget\BudgetTagResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Optional onboarding step: review which of Needs, Wants or Savings each tagged root
 * category counts as. Moving a category writes a per-user override; moving it back to its
 * default removes the override. Skipping keeps the defaults.
 *
 * @property-read Collection<string, Collection<int, array{category: Category, tag: BudgetTag}>> $groups
 */
final class ConfirmBudgetTags extends Component
{
    /** @return Collection<string, Collection<int, array{category: Category, tag: BudgetTag}>> */
    #[Computed]
    public function groups(): Collection
    {
        $user = $this->user();
        $resolver = app(BudgetTagResolver::class);

        $categories = Category::query()
            ->visible()
            ->whereNull('parent_id')
            ->whereNotNull('budget_tag')
            ->orderBy('name')
            ->get();

        $resolved = $categories->mapWithKeys(fn (Category $category): array => [
            $category->id => $resolver->forCategory($user, $category),
        ]);

        return collect(BudgetTag::cases())
            ->mapWithKeys(fn (BudgetTag $tag): array => [
                $tag->value => $categories
                    ->filter(fn (Category $category): bool => $resolved[$category->id] === $tag)
                    ->map(fn (Category $category): array => ['category' => $category, 'tag' => $tag])
                    ->values(),
            ]);
    }

    public function setTag(int $categoryId, string $tag): void
    {
        $budgetTag = BudgetTag::tryFrom($tag);

        $category = Category::query()
            ->visible()
            ->whereNull('parent_id')
            ->whereNotNull('budget_tag')
            ->find($categoryId);

        if ($budgetTag === null || $category === null) {
            return;
        }

        $user = $this->user();

        if ($category->budget_tag === $budgetTag) {
            UserCategoryBudgetTag::query()
                ->where('user_id', $user->id)
                ->where('category_id', $category->id)
                ->delete();
        } else {
            UserCategoryBudgetTag::query()->updateOrCreate(
                ['user_id' => $user->id, 'category_id' => $category->id],
                ['budget_tag' => $budgetTag],
            );
        }

        unset($this->groups);
    }

    public function confirm(): void
    {
        $this->dispatch('budget-tags-confirmed');
    }

    public function skip(): void
    {
        $this->dispatch('budget-tags-confirmed');
    }

    public function render(): View
    {
        return view('livewire.confirm-budget-tags');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
