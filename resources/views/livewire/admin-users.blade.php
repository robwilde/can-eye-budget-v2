<div class="mx-auto max-w-6xl space-y-6" data-test="admin-users">
    <div class="space-y-2">
        <flux:heading size="xl">{{ __('Users') }}</flux:heading>
        <flux:text>{{ __('Everyone on this instance. Removing a user deletes their account and all of their data.') }}</flux:text>
    </div>

    <flux:input wire:model.live.debounce.300ms="search" type="search" :placeholder="__('Search by name or email')" data-test="admin-user-search" />

    @error('admin') <flux:callout variant="danger" icon="exclamation-triangle" data-test="admin-error"><flux:callout.text>{{ $message }}</flux:callout.text></flux:callout> @enderror

    <div class="overflow-x-auto rounded-lg border-2 border-cib-black bg-white">
        <table class="w-full text-start text-sm">
            <thead class="border-b-2 border-cib-black bg-zinc-50 text-xs uppercase text-zinc-500">
                <tr>
                    <th class="px-4 py-2 text-start">{{ __('Name') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Email') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Admin') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Joined') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->users as $user)
                    <tr class="border-b border-zinc-200" wire:key="admin-user-{{ $user->id }}" data-test="admin-user-row">
                        <td class="px-4 py-2 font-bold">{{ $user->name }}</td>
                        <td class="px-4 py-2">{{ $user->email }}</td>
                        <td class="px-4 py-2" data-test="admin-flag">{{ $user->is_admin ? __('Admin') : '' }}</td>
                        <td class="px-4 py-2 text-zinc-500">{{ $user->created_at?->format('j M Y') }}</td>
                        <td class="px-4 py-2 text-end">
                            <flux:button size="sm" variant="ghost" wire:click="toggleAdmin({{ $user->id }})" wire:confirm="{{ $user->is_admin ? __('Remove admin rights from this user?') : __('Make this user an admin?') }}" data-test="toggle-admin-button">{{ $user->is_admin ? __('Demote') : __('Make admin') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="sendResetLink({{ $user->id }})" data-test="send-reset-button">{{ __('Send reset link') }}</flux:button>
                            @if ($user->id !== auth()->id())
                                <flux:button size="sm" variant="ghost" wire:click="startRemoval({{ $user->id }})" data-test="remove-user-button">{{ __('Remove') }}</flux:button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-6 text-zinc-500" colspan="5">{{ __('No users found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $this->users->links() }}

    @if ($this->removing !== null)
        <flux:callout variant="danger" icon="trash" data-test="remove-confirmation">
            <flux:callout.heading>{{ __('Remove :name?', ['name' => $this->removing->name]) }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('This permanently deletes the account and all of its data. Type :email to confirm.', ['email' => $this->removing->email]) }}
            </flux:callout.text>
            <form wire:submit="removeUser" class="mt-3 flex items-start gap-3">
                <div class="flex-1">
                    <flux:input wire:model="confirmEmail" type="email" autocomplete="off" data-test="confirm-email-input" />
                    @error('confirmEmail') <flux:text size="sm" class="text-red-600" data-test="confirm-email-error">{{ $message }}</flux:text> @enderror
                </div>
                <flux:button type="submit" variant="danger" data-test="confirm-remove-button">{{ __('Remove user') }}</flux:button>
                <flux:button type="button" variant="ghost" wire:click="cancelRemoval" data-test="cancel-remove-button">{{ __('Cancel') }}</flux:button>
            </form>
        </flux:callout>
    @endif
</div>
