<?php

use App\Concerns\PasswordValidationRules;
use App\Services\UserDataResetter;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete everything the signed-in user's finances created and keep the login.
     */
    public function resetData(UserDataResetter $resetter): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        try {
            $resetter->reset(Auth::user());
        } catch (Throwable $exception) {
            report($exception);

            $this->addError('password', __('Your data could not be reset. Nothing was deleted.'));

            return;
        }

        session()->flash('status', __('Your data has been reset.'));

        $this->redirect(route('dashboard'), navigate: true);
    }
}; ?>

<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <flux:heading>{{ __('Reset my data') }}</flux:heading>
        <flux:subheading>{{ __('Delete your accounts, transactions, budgets, rules and imports, and start again. Your login and preferences stay.') }}</flux:subheading>
    </div>

    <flux:modal.trigger name="confirm-data-reset">
        <flux:button variant="danger" data-test="reset-data-button">
            {{ __('Reset my data') }}
        </flux:button>
    </flux:modal.trigger>

    <flux:modal name="confirm-data-reset" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form method="POST" wire:submit="resetData" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Are you sure you want to reset your data?') }}</flux:heading>

                <flux:subheading>
                    {{ __('Everything you have imported or entered is permanently deleted and cannot be restored. Enter your password to confirm.') }}
                </flux:subheading>
            </div>

            <flux:input wire:model="password" :label="__('Password')" type="password" data-test="reset-data-password" />

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit" data-test="confirm-reset-data-button">
                    {{ __('Reset my data') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
