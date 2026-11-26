<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Instance admins list the users, switch the admin flag, send a password reset link and remove a user. Every method
 * re-checks the admin gate because Livewire actions can be called without going through the page route.
 */
final class AdminUsers extends Component
{
    use WithPagination;

    public const int PER_PAGE = 25;

    public string $search = '';

    #[Locked]
    public ?int $removingId = null;

    public string $confirmEmail = '';

    public function mount(): void
    {
        Gate::authorize('admin');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, User> */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        Gate::authorize('admin');

        $term = mb_trim($this->search);
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        return User::query()
            ->select(['id', 'name', 'email', 'is_admin', 'email_verified_at', 'created_at'])
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->whereRaw("name LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("email LIKE ? ESCAPE '!'", [$like])))
            ->orderBy('id')
            ->paginate(self::PER_PAGE);
    }

    /** @return User|null the user a removal is waiting on */
    #[Computed]
    public function removing(): ?User
    {
        Gate::authorize('admin');

        return $this->removingId === null ? null : User::query()->select(['id', 'name', 'email'])->find($this->removingId);
    }

    public function toggleAdmin(int $userId): void
    {
        Gate::authorize('admin');

        $this->resetErrorBag();

        $refused = DB::transaction(function () use ($userId): ?string {
            $admins = User::query()->where('is_admin', true)->orderBy('id')->lockForUpdate()->pluck('id')->all();
            $target = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            if ($target->is_admin && count($admins) <= 1) {
                return __('The last instance admin cannot be demoted.');
            }

            $target->forceFill(['is_admin' => ! $target->is_admin])->save();

            return null;
        });

        if ($refused !== null) {
            $this->addError('admin', $refused);
        }

        unset($this->users);
    }

    public function sendResetLink(int $userId): void
    {
        Gate::authorize('admin');

        $this->resetErrorBag();

        $user = User::query()->select(['id', 'email'])->findOrFail($userId);

        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_LINK_SENT) {
            Flux::toast(text: __('Password reset link sent.'), variant: 'success');

            return;
        }

        $this->addError('admin', __('The reset link could not be sent.'));
    }

    public function startRemoval(int $userId): void
    {
        Gate::authorize('admin');

        $this->resetErrorBag();
        $this->confirmEmail = '';

        if ($userId === Auth::id()) {
            $this->removingId = null;
            $this->addError('admin', __('You cannot remove your own user.'));

            return;
        }

        $this->removingId = User::query()->whereKey($userId)->firstOrFail()->id;
        unset($this->removing);
    }

    public function cancelRemoval(): void
    {
        Gate::authorize('admin');

        $this->resetErrorBag();
        $this->removingId = null;
        $this->confirmEmail = '';
        unset($this->removing);
    }

    public function removeUser(): void
    {
        Gate::authorize('admin');

        $this->resetErrorBag();

        if ($this->removingId === null) {
            return;
        }

        if ($this->removingId === Auth::id()) {
            $this->addError('admin', __('You cannot remove your own user.'));

            return;
        }

        $removingId = $this->removingId;
        $typed = mb_strtolower(mb_trim($this->confirmEmail));

        $mismatch = DB::transaction(function () use ($removingId, $typed): bool {
            $admins = User::query()->where('is_admin', true)->orderBy('id')->lockForUpdate()->pluck('id')->all();

            abort_unless(in_array(Auth::id(), $admins, true), 403);

            $target = User::query()->whereKey($removingId)->lockForUpdate()->firstOrFail();

            if ($typed !== mb_strtolower($target->email)) {
                return true;
            }

            $target->delete();

            return false;
        });

        if ($mismatch) {
            $this->addError('confirmEmail', __('Type the user\'s email exactly to remove them.'));

            return;
        }

        $this->removingId = null;
        $this->confirmEmail = '';

        unset($this->users, $this->removing);

        Flux::toast(text: __('User removed.'), variant: 'success');
    }

    public function render(): View
    {
        return view('livewire.admin-users');
    }
}
