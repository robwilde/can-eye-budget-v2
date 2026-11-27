<?php

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Profile settings')] class extends Component {
    use ProfileValidationRules;
    use WithFileUploads;

    public string $name = '';
    public string $email = '';
    public ?TemporaryUploadedFile $photo = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($user->wasChanged('email')) {
            $this->sendVerificationNotification($user);
        }

        $this->dispatch('profile-updated', name: $user->name);
    }

    /**
     * Store a JPEG or PNG profile photo up to 10 MB, replacing and deleting the previous one.
     */
    public function savePhoto(): void
    {
        $this->validate([
            'photo' => ['required', 'file', 'mimes:jpeg,png', 'max:10240'],
        ]);

        $user = Auth::user();
        $previous = $user->avatar_path;
        $path = $this->photo->store('avatars/'.$user->id, User::AVATAR_DISK);

        if ($path === false) {
            $this->addError('photo', __('The photo could not be saved.'));

            return;
        }

        $user->update(['avatar_path' => $path]);

        if ($previous !== null) {
            Storage::disk(User::AVATAR_DISK)->delete($previous);
        }

        $this->photo = null;

        Session::flash('status', __('Photo updated.'));

        $this->redirect(route('profile.edit'), navigate: true);
    }

    /**
     * Remove the profile photo and delete its file.
     */
    public function removePhoto(): void
    {
        $user = Auth::user();
        $previous = $user->avatar_path;

        $user->update(['avatar_path' => null]);

        if ($previous !== null) {
            Storage::disk(User::AVATAR_DISK)->delete($previous);
        }

        Session::flash('status', __('Photo removed.'));

        $this->redirect(route('profile.edit'), navigate: true);
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        if (! $this->sendVerificationNotification($user)) {
            $this->addError('email', __('Too many verification emails requested. Try again in a minute.'));

            return;
        }

        Session::flash('status', 'verification-link-sent');
    }

    /**
     * Sends the verification email unless this user already asked for six in the last minute,
     * the same budget Fortify gives the verification.send route.
     */
    private function sendVerificationNotification(User $user): bool
    {
        $sent = RateLimiter::attempt('verification-resend:'.$user->id, 6, static fn () => $user->sendEmailVerificationNotification(), 60);

        return $sent !== false;
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <div class="my-6 w-full space-y-4" data-test="profile-photo">
            <div class="flex items-center gap-4">
                <flux:avatar :src="auth()->user()->avatarUrl()" :initials="auth()->user()->initials()" size="xl" />

                <div class="space-y-2">
                    <input type="file" wire:model="photo" accept="image/jpeg,image/png" data-test="profile-photo-input" />
                    <flux:text size="sm">{{ __('JPEG or PNG, up to 10 MB.') }}</flux:text>
                    @error('photo') <flux:text size="sm" class="text-red-600" data-test="profile-photo-error">{{ $message }}</flux:text> @enderror
                </div>
            </div>

            <div class="flex items-center gap-3">
                <flux:button size="sm" variant="primary" wire:click="savePhoto" data-test="save-photo-button">{{ __('Upload photo') }}</flux:button>

                @if (auth()->user()->avatar_path !== null)
                    <flux:button size="sm" variant="ghost" wire:click="removePhoto" data-test="remove-photo-button">{{ __('Remove photo') }}</flux:button>
                @endif
            </div>
        </div>

        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

                <x-action-message class="me-3" on="profile-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>

        <livewire:pages::settings.reset-data />

        <livewire:pages::settings.delete-user-form />
    </x-pages::settings.layout>
</section>
