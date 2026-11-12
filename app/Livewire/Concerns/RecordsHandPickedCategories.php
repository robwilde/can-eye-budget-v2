<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Services\RecentCategoryPicks;
use Livewire\Attributes\Locked;

/**
 * Tells a category a user chose in a picker from one the screen filled in for
 * them. Livewire runs an updated hook only for changes that come from the
 * client, so a component calls markHandPicked() from the hook of the property
 * the picker binds to and server-side pre-fills never count (ADR 0002).
 */
trait RecordsHandPickedCategories
{
    /** @var array<string, true> */
    #[Locked]
    public array $handPickedCategories = [];

    protected function markHandPicked(int|string $key): void
    {
        $this->handPickedCategories[(string) $key] = true;
    }

    protected function forgetHandPicked(int|string|null $key = null): void
    {
        if ($key === null) {
            $this->handPickedCategories = [];

            return;
        }

        unset($this->handPickedCategories[(string) $key]);
    }

    protected function recordHandPicked(User $user, int|string $key, ?int $categoryId): void
    {
        if ($categoryId !== null && ($this->handPickedCategories[(string) $key] ?? false)) {
            app(RecentCategoryPicks::class)->record($user, $categoryId);
        }
    }
}
