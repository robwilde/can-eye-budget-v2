<x-layouts::auth.simple :title="__('Setup guide')" width="max-w-2xl">
    <div class="space-y-8">
        <section class="space-y-3">
            <flux:heading size="xl" level="1">{{ __('Before you start') }}</flux:heading>
            <flux:text>
                {{ __('Can I Budget needs one thing from you and offers one more. Get them ready first and setup takes a few minutes.') }}
            </flux:text>
            <ul class="list-disc space-y-2 pl-5 text-sm text-zinc-600">
                <li>
                    <strong class="text-cib-ink">{{ __('Redbark API key (required)') }}</strong>
                    {{ __('Redbark is an Australian open-banking service. You connect your bank to Redbark; Can I Budget reads your transactions through Redbark with a key that only you hold. We never see your bank login.') }}
                </li>
                <li>
                    <strong class="text-cib-ink">{{ __('Google app password (optional)') }}</strong>
                    {{ __('Lets Can I Budget read your Gmail to find buy-now-pay-later schedules and receipts and match them to transactions. You can skip it and add it later under Settings > Providers.') }}
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <flux:heading size="lg" level="2">{{ __('1. Get a Redbark API key') }}</flux:heading>
            <flux:text>
                {{ __('Redbark has a 7-day free trial with no credit card, and the trial includes API access. After the trial, Can I Budget keeps working on the Developer plan (A$16 a month) or the Professional plan; the Saver plan has no API access.') }}
            </flux:text>
            <ol class="list-decimal space-y-2 pl-5 text-sm text-zinc-600">
                <li>
                    {{ __('Create a Redbark account and start the free trial:') }}
                    <flux:link href="https://app.redbark.com/sign-up" external rel="noopener">app.redbark.com/sign-up</flux:link>
                </li>
                <li>
                    {{ __('Connect your bank in Redbark: click Add Connection, choose AU Banks, pick your bank and approve the Consumer Data Right consent. Your bank login happens on your bank\'s own page, not on Redbark or Can I Budget.') }}
                </li>
                <li>
                    {{ __('Create an API key under') }}
                    <flux:link href="https://app.redbark.com/settings/api-mcp" external rel="noopener">{{ __('Settings > API & MCP') }}</flux:link>{{ __('. Copy it straight away; Redbark shows it only once. Keys start with rbk_live_.') }}
                </li>
                <li>
                    {{ __('Come back to Can I Budget and paste the key into the Redbark API key field on step 1.') }}
                </li>
            </ol>
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('No accounts on step 2?')">
                <flux:callout.text>
                    {{ __('Go back to Redbark and check the bank connection finished under Connections, then press Connect again.') }}
                </flux:callout.text>
            </flux:callout>
            <flux:text size="sm">
                {{ __('Redbark\'s own guide:') }}
                <flux:link href="https://redbark.com/docs" external rel="noopener">redbark.com/docs</flux:link>
            </flux:text>
        </section>

        <section class="space-y-3">
            <flux:heading size="lg" level="2">{{ __('2. Create a Google app password (optional)') }}</flux:heading>
            <flux:text>
                {{ __('An app password is a separate 16-character password Google makes for one app. Can I Budget uses it to sign in to Gmail over IMAP, read-only, and searches only for buy-now-pay-later and receipt emails. It never sends mail. It is stored encrypted and you can remove it under Settings > Providers.') }}
            </flux:text>
            <ol class="list-decimal space-y-2 pl-5 text-sm text-zinc-600">
                <li>
                    {{ __('Turn on 2-Step Verification for your Google account:') }}
                    <flux:link href="https://myaccount.google.com/security" external rel="noopener">myaccount.google.com/security</flux:link>{{ __('. App passwords only appear once it is on.') }}
                </li>
                <li>
                    {{ __('Create an app password:') }}
                    <flux:link href="https://myaccount.google.com/apppasswords" external rel="noopener">myaccount.google.com/apppasswords</flux:link>{{ __('. Name it Can I Budget and copy the 16-character password Google shows.') }}
                </li>
                <li>
                    {{ __('In the last setup step (or later under Settings > Providers), enter your Gmail address and that app password.') }}
                </li>
            </ol>
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.text>
                    {{ __('Use the app password Google generated, not your normal Google password. Your normal password is rejected.') }}
                </flux:callout.text>
            </flux:callout>
        </section>

        <section class="space-y-3">
            <flux:text size="sm" class="text-zinc-500">
                {{ __('Last checked 9 October 2026 against redbark.com and myaccount.google.com. If a step no longer matches what you see, tell us with the Report feedback button once you are signed in.') }}
            </flux:text>
            @if (Route::has('register'))
                <flux:link :href="route('register')">{{ __('Create your Can I Budget account') }}</flux:link>
            @endif
        </section>
    </div>
</x-layouts::auth.simple>
