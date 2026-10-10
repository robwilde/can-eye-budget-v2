<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\PayeeReviewItem;
use App\Enums\BudgetTag;
use App\Enums\PayeeStatus;
use App\Models\Category;
use App\Models\Payee;
use App\Models\User;
use App\Services\Payees\PayeeConfirmer;
use App\Services\Payees\PayeeReviewQueue;
use App\Support\Budget\BudgetTagResolver;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Throwable;

/**
 * Lets the user confirm Jev's category for the payees that matter most.
 *
 * Never blocks: every row can be left alone and the whole step can be skipped.
 */
final class PayeeReview extends Component
{
    public const array WORK_ROOTS = ['Software & Online Services', 'Work Equipment'];

    public const array PERSONAL_ROOTS = ['Personal & Shopping', 'Entertainment', 'Learning & Reading'];

    public bool $onboarding = false;

    /** @var array<int, int|string|null> */
    public array $categoryChoices = [];

    /** @var array<int, string|null> */
    public array $tagChoices = [];

    /** @var array<int, string|null> */
    public array $intents = [];

    public function mount(PayeeReviewQueue $queue): void
    {
        foreach ($queue->pending($this->authenticatedUser()) as $item) {
            $this->categoryChoices[$item->payeeId] = $item->suggestedCategoryId;
        }
    }

    public function updatedIntents(mixed $value, string $key): void
    {
        $payeeId = (int) $key;
        $allowed = $this->rootsFor(is_string($value) ? $value : null);

        if ($allowed === null) {
            return;
        }

        $current = $this->categoryChoices[$payeeId] ?? null;
        $options = $this->categoriesWithinRoots($allowed);

        if ($current !== null && $current !== '' && $options->contains('id', (int) $current)) {
            return;
        }

        $this->categoryChoices[$payeeId] = $options->first()?->id;
    }

    /**
     * @throws Throwable
     */
    public function accept(int $payeeId, PayeeConfirmer $confirmer): void
    {
        $user = $this->authenticatedUser();
        $payee = $this->pendingPayee($user, $payeeId);

        if ($payee === null) {
            return;
        }

        $rawCategory = $this->categoryChoices[$payeeId] ?? $payee->suggested_category_id;
        $categoryId = $rawCategory !== null && $rawCategory !== '' ? (int) $rawCategory : null;

        if ($categoryId === null || ! Category::visible()->whereKey($categoryId)->exists()) {
            Flux::toast(text: 'Pick a category first', variant: 'warning');

            return;
        }

        $confirmer->confirm($user, $payee, $categoryId, BudgetTag::tryFrom((string) ($this->tagChoices[$payeeId] ?? '')));

        $this->forget($payeeId);

        Flux::toast(text: 'Payee categorised', variant: 'success');
    }

    public function dismiss(int $payeeId, PayeeConfirmer $confirmer): void
    {
        $user = $this->authenticatedUser();
        $payee = $this->pendingPayee($user, $payeeId);

        if ($payee === null) {
            return;
        }

        $confirmer->dismiss($user, $payee);

        $this->forget($payeeId);
    }

    public function acceptAll(PayeeReviewQueue $queue, PayeeConfirmer $confirmer): void
    {
        $user = $this->authenticatedUser();

        $payeeIds = $queue->pending($user, null)
            ->filter(fn (PayeeReviewItem $item): bool => $item->suggestedCategoryId !== null)
            ->map(fn (PayeeReviewItem $item): int => $item->payeeId)
            ->values()
            ->all();

        $confirmed = $confirmer->confirmMany($user, $payeeIds);

        Flux::toast(
            text: $confirmed === 1 ? 'Categorised 1 payee' : sprintf('Categorised %d payees', $confirmed),
            variant: 'success',
        );
    }

    public function finish(): void
    {
        $this->dispatch('payee-review-finished');
    }

    public function render(PayeeReviewQueue $queue, BudgetTagResolver $resolver): View
    {
        $user = $this->authenticatedUser();
        $items = $queue->pending($user);
        $categories = Category::visibleSortedByFullPath();
        $byId = $categories->keyBy('id');

        $rows = $items->map(function (PayeeReviewItem $item) use ($categories, $byId, $resolver, $user): array {
            $intent = $this->intents[$item->payeeId] ?? null;
            $roots = $item->isAmbiguous ? $this->rootsFor($intent) : null;
            $chosen = $this->categoryChoices[$item->payeeId] ?? $item->suggestedCategoryId;
            $chosenId = $chosen !== null && $chosen !== '' ? (int) $chosen : null;
            $category = $chosenId !== null ? $byId->get($chosenId) : null;
            $override = BudgetTag::tryFrom((string) ($this->tagChoices[$item->payeeId] ?? ''));

            return [
                'item' => $item,
                'categories' => $roots === null ? $categories : $this->categoriesWithinRoots($roots, $categories),
                'chosenId' => $chosenId,
                'tag' => $override ?? $resolver->forCategory($user, $category),
            ];
        });

        return view('livewire.payee-review', [
            'rows' => $rows,
            'tags' => BudgetTag::cases(),
        ]);
    }

    private function authenticatedUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function pendingPayee(User $user, int $payeeId): ?Payee
    {
        return Payee::query()
            ->where('user_id', $user->id)
            ->where('status', PayeeStatus::Pending)
            ->whereKey($payeeId)
            ->first();
    }

    private function forget(int $payeeId): void
    {
        unset($this->categoryChoices[$payeeId], $this->tagChoices[$payeeId], $this->intents[$payeeId]);
    }

    /**
     * @return list<string>|null
     */
    private function rootsFor(?string $intent): ?array
    {
        return match ($intent) {
            'work' => self::WORK_ROOTS,
            'personal' => self::PERSONAL_ROOTS,
            default => null,
        };
    }

    /**
     * @param  list<string>  $roots
     * @param  Collection<int, Category>|null  $categories
     * @return Collection<int, Category>
     */
    private function categoriesWithinRoots(array $roots, ?Collection $categories = null): Collection
    {
        return ($categories ?? Category::visibleSortedByFullPath())
            ->filter(fn (Category $category): bool => in_array(explode(' / ', $category->fullPath())[0], $roots, true))
            ->values();
    }
}
