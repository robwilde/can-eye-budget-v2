<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\MerchantBrandStatus;
use App\Jobs\ResolveMerchantBrandJob;
use App\Models\Merchant;
use App\Models\MerchantBrand;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Merchants\MerchantMerger;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Search, create, edit, merge and delete the user's merchants, with the recently unlinked list. */
final class MerchantManager extends Component
{
    public const int RECENTLY_UNLINKED_DAYS = 30;

    #[Url(except: '')]
    public string $search = '';

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $colour = '';

    public string $website = '';

    /** @var list<int|string> */
    public array $mergeSources = [];

    public string $mergeTarget = '';

    public function save(): void
    {
        $user = $this->authenticatedUser();

        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('merchants', 'name')->where('user_id', $user->id)->ignore($this->editingId),
            ],
            'colour' => ['nullable', 'string', 'max:7'],
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        $merchant = $this->editingId === null
            ? new Merchant(['user_id' => $user->id])
            : Merchant::query()->where('user_id', $user->id)->findOrFail($this->editingId);

        $merchant->fill([
            'name' => $validated['name'],
            'colour' => $validated['colour'] ?? null,
            'website' => filled($validated['website'] ?? null) ? $validated['website'] : null,
        ])->save();

        $this->queueBrandLookup($user, $merchant);
        $this->cancelEdit();

        Flux::toast(text: 'Merchant saved', variant: 'success');
    }

    public function edit(int $merchantId): void
    {
        $merchant = Merchant::query()
            ->where('user_id', $this->authenticatedUser()->id)
            ->findOrFail($merchantId);

        $this->resetErrorBag();
        $this->editingId = $merchant->id;
        $this->name = $merchant->name;
        $this->colour = $merchant->colour;
        $this->website = $merchant->website ?? '';
    }

    public function cancelEdit(): void
    {
        $this->resetErrorBag();
        $this->editingId = null;
        $this->name = '';
        $this->colour = '';
        $this->website = '';
    }

    public function merge(MerchantMerger $merger): void
    {
        $user = $this->authenticatedUser();

        $this->validate([
            'mergeSources' => ['required', 'array', 'min:1'],
            'mergeSources.*' => ['integer'],
            'mergeTarget' => ['required', 'integer'],
        ]);

        $sourceIds = array_values(array_diff(array_map('intval', $this->mergeSources), [(int) $this->mergeTarget]));

        $sources = Merchant::query()->where('user_id', $user->id)->whereIn('id', $sourceIds)->get();
        $target = Merchant::query()->where('user_id', $user->id)->find((int) $this->mergeTarget);

        if ($target === null || $sources->isEmpty() || $sources->count() !== count($sourceIds)) {
            $this->addError('mergeTarget', 'Choose merchants of your own to merge.');

            return;
        }

        try {
            $merger->merge($user, $sources, $target);
        } catch (InvalidArgumentException) {
            $this->addError('mergeTarget', 'Choose merchants of your own to merge.');

            return;
        }

        $this->mergeSources = [];
        $this->mergeTarget = '';
        $this->dispatch('merchants-changed');

        Flux::toast(text: 'Merchants merged', variant: 'success');
    }

    public function delete(int $merchantId, MerchantMerger $merger): void
    {
        $user = $this->authenticatedUser();

        $merger->delete($user, Merchant::query()->where('user_id', $user->id)->findOrFail($merchantId));

        $this->mergeSources = array_values(array_diff($this->mergeSources, [$merchantId]));
        $this->dispatch('merchants-changed');

        Flux::toast(text: 'Merchant deleted', variant: 'success');
    }

    public function unlink(int $merchantId, MerchantMerger $merger): void
    {
        $user = $this->authenticatedUser();

        $merger->unlink($user, Merchant::query()->where('user_id', $user->id)->findOrFail($merchantId));

        $this->dispatch('merchants-changed');

        Flux::toast(text: 'Merchant unlinked from its transactions', variant: 'success');
    }

    public function render(): View
    {
        $user = $this->authenticatedUser();

        $merchants = Merchant::query()
            ->where('user_id', $user->id)
            ->withCount(['transactions' => fn ($query) => $query->where('user_id', $user->id)->current()])
            ->when(mb_trim($this->search) !== '', fn (Builder $query): Builder => $query
                ->where('name', 'like', '%'.addcslashes(mb_trim($this->search), '%_\\').'%'))
            ->orderBy('name')
            ->get();

        $recentlyUnlinked = Merchant::query()
            ->where('user_id', $user->id)
            ->where('unlinked_at', '>=', now()->subDays(self::RECENTLY_UNLINKED_DAYS))
            ->whereNotIn('id', Transaction::query()
                ->where('user_id', $user->id)
                ->whereNotNull('merchant_id')
                ->current()
                ->select('merchant_id'))
            ->orderByDesc('unlinked_at')
            ->get();

        return view('livewire.merchant-manager', [
            'merchants' => $merchants,
            'recentlyUnlinked' => $recentlyUnlinked,
            'allMerchants' => Merchant::query()->where('user_id', $user->id)->orderBy('name')->get(['id', 'name']),
            'logos' => $this->logos($user, $merchants),
        ]);
    }

    /**
     * @param  Collection<int, Merchant>  $merchants
     * @return array<string, string>
     */
    private function logos(User $user, Collection $merchants): array
    {
        return MerchantBrand::query()
            ->where('user_id', $user->id)
            ->whereIn('merchant_key', $merchants->pluck('merchant_key')->all())
            ->where('status', MerchantBrandStatus::Resolved)
            ->whereNotNull('logo_url')
            ->pluck('logo_url', 'merchant_key')
            ->all();
    }

    private function queueBrandLookup(User $user, Merchant $merchant): void
    {
        if (! config('services.context_dev.enrichment_enabled')) {
            return;
        }

        $known = MerchantBrand::query()
            ->where('user_id', $user->id)
            ->where('merchant_key', $merchant->merchant_key)
            ->exists();

        if (! $known) {
            ResolveMerchantBrandJob::dispatch($user, $merchant->merchant_key);
        }
    }

    private function authenticatedUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
