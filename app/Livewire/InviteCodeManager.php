<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\RegistrationMode;
use App\Models\InviteCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Instance admins generate and revoke single-use invite codes. Every method re-checks the admin
 * gate, because Livewire actions can be called without going through the page route.
 */
final class InviteCodeManager extends Component
{
    public int $validDays = InviteCode::DEFAULT_VALID_DAYS;

    #[Locked]
    public ?string $newCode = null;

    public function mount(): void
    {
        Gate::authorize('admin');
    }

    /** @return Collection<int, InviteCode> */
    #[Computed]
    public function codes(): Collection
    {
        Gate::authorize('admin');

        return InviteCode::query()->latest('id')->limit(100)->get();
    }

    public function generate(): void
    {
        Gate::authorize('admin');

        $this->validate(['validDays' => ['required', 'integer', 'min:1', 'max:'.InviteCode::MAX_VALID_DAYS]]);

        /** @var User $user */
        $user = Auth::user();

        $this->newCode = InviteCode::issue($user, $this->validDays)['code'];

        unset($this->codes);
    }

    public function revoke(int $inviteCodeId): void
    {
        Gate::authorize('admin');

        InviteCode::query()
            ->whereKey($inviteCodeId)
            ->whereNull('claimed_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $this->newCode = null;

        unset($this->codes);
    }

    public function render(): View
    {
        return view('livewire.invite-code-manager', ['mode' => RegistrationMode::current()]);
    }
}
