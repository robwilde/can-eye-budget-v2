@props([
    'user',
    'accountCount' => 0,
    'syncedHuman' => '—',
])

<flux:dropdown position="top" align="start" class="mt-auto w-full">
    <button
        type="button"
        data-test="sidebar-menu-button"
        aria-haspopup="menu"
        aria-label="{{ $user->name }}"
        class="flex w-full items-center gap-2 rounded-lg border-2 border-cib-black bg-cib-teal-400 px-2 py-2 text-start text-white shadow-pop-sm hover:bg-cib-teal-500"
    >
        <flux:avatar
            :initials="$user->initials()"
            class="size-8 border-[1.5px] border-cib-black bg-cib-yellow-400! text-cib-black!"
        />
        <div class="min-w-0 text-sm truncate in-data-flux-sidebar-collapsed-desktop:hidden">
            <div class="font-bold leading-tight truncate">{{ $user->name }}</div>
            <div class="text-xs text-white/70 truncate">
                {{ $accountCount }} {{ Str::plural('account', $accountCount) }} · synced {{ $syncedHuman }}
            </div>
        </div>
    </button>

    <flux:menu class="border-2 border-cib-black bg-white text-cib-black shadow-pop rounded-lg">
        <flux:menu.radio.group>
            <flux:menu.item
                :href="route('profile.edit')"
                icon="cog"
                wire:navigate
                class="data-active:bg-cib-yellow-400 data-active:text-cib-black data-active:font-black"
            >
                {{ __('Settings') }}
            </flux:menu.item>

            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:menu.item
                    as="button"
                    type="submit"
                    icon="arrow-right-start-on-rectangle"
                    class="w-full cursor-pointer data-active:bg-cib-yellow-400 data-active:text-cib-black data-active:font-black"
                    data-test="sidebar-logout-button"
                >
                    {{ __('Log out') }}
                </flux:menu.item>
            </form>
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
