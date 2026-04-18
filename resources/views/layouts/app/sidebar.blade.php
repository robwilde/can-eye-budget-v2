<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen bg-bg-page text-fg-1">
@php
    $navItem = 'text-white! hover:text-white! hover:bg-white/10! data-current:bg-cib-yellow-400! data-current:text-cib-black! data-current:border-2! data-current:border-cib-black! data-current:shadow-pop-sm!';
@endphp

<flux:sidebar sticky collapsible="mobile" class="border-e-2! border-cib-black! bg-cib-teal-400! text-white!">
    <flux:sidebar.header>
        <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate/>
        <flux:sidebar.collapse class="lg:hidden"/>
    </flux:sidebar.header>

    <flux:sidebar.nav>
        <flux:sidebar.group :heading="__('Platform')" class="grid">
            <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate :class="$navItem">
                {{ __('Dashboard') }}
            </flux:sidebar.item>
            <flux:sidebar.item icon="credit-card" :href="route('accounts')" :current="request()->routeIs('accounts')" wire:navigate :class="$navItem">
                {{ __('Accounts') }}
            </flux:sidebar.item>
            <flux:sidebar.item icon="arrows-right-left" :href="route('transactions')" :current="request()->routeIs('transactions')" wire:navigate
                               :class="$navItem">
                {{ __('Transactions') }}
            </flux:sidebar.item>
            <flux:sidebar.item icon="calendar-days" :href="route('calendar')" :current="request()->routeIs('calendar')" wire:navigate :class="$navItem">
                {{ __('Calendar') }}
            </flux:sidebar.item>
            <flux:sidebar.item icon="building-library" :href="route('connect-bank')" :current="request()->routeIs('connect-bank')" wire:navigate
                               :class="$navItem">
                {{ __('Connect Bank') }}
            </flux:sidebar.item>
            <flux:sidebar.item icon="funnel" :href="route('rules')" :current="request()->routeIs('rules')" wire:navigate :class="$navItem">
                {{ __('Rules') }}
            </flux:sidebar.item>
        </flux:sidebar.group>
    </flux:sidebar.nav>

    <flux:spacer/>

    @if($shellUser)
        <div class="mt-auto flex items-center gap-2 border-t-[1.5px] border-white/18 px-2 pt-3">
            <flux:avatar
                    :initials="$shellUser->initials()"
                    class="size-8 border-[1.5px] border-cib-black bg-cib-yellow-400! text-cib-black!"
            />
            <div class="text-sm truncate in-data-flux-sidebar-collapsed-desktop:hidden">
                <div class="font-bold leading-tight truncate">{{ $shellUser->name }}</div>
                <div class="text-xs text-white/70 truncate">
                    {{ $shellAccountCount }} {{ Str::plural('account', $shellAccountCount) }} · synced {{ $shellSyncedHuman }}
                </div>
            </div>
        </div>
    @endif
</flux:sidebar>

@include('layouts.app.partials.topbar')

@include('layouts.app.partials.mobile-tabbar')

<main data-flux-main style="grid-area: main" class="min-w-0 pb-28 lg:pb-8">
    {{ $slot }}
</main>

<livewire:feedback-widget/>

@fluxScripts
</body>
</html>
