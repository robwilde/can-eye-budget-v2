<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-cib-teal-400 antialiased">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-5">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2" wire:navigate>
                    <span class="flex size-12 items-center justify-center rounded-xl border-2 border-cib-black bg-white shadow-pop-sm">
                        <x-app-logo-icon class="size-7 fill-current text-cib-teal-600" />
                    </span>
                    <span class="font-display text-lg font-black text-white">{{ config('app.name', 'Can I Budget') }}</span>
                </a>

                <div class="flex flex-col gap-6 rounded-2xl border-2 border-cib-black bg-white p-6 text-cib-ink shadow-pop sm:p-8">
                    {{ $slot }}
                </div>
            </div>
        </div>
        @fluxScripts
    </body>
</html>
