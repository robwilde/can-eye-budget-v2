<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description"
          content="Can Eye Budget — self-hosted personal budgeting for Australians. Real bank data via open banking, real visibility, real control.">

    <title>{{ config('app.name', 'Can Eye Budget') }} — Self-hosted budgeting for Australians</title>

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,500,600,700|instrument-sans:400,500,600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css'])

    <style>
        .ce-landing {
            --ce-teal: #0E7C8A;
            --ce-teal-deep: #0A5A66;
            --ce-teal-soft: #D6EDEF;
            --ce-mustard: #E8B53D;
            --ce-mustard-soft: #F6E3A7;
            --ce-cream: #FAF6EC;
            --ce-ink: #1A1816;
            --font-display: 'Fraunces', ui-serif, Georgia, serif;
            background: var(--ce-cream);
            color: var(--ce-ink);
            font-feature-settings: "ss01", "ss02";
            -webkit-font-smoothing: antialiased;
        }

        .ce-landing .display {
            font-family: var(--font-display);
            font-variation-settings: "SOFT" 100, "WONK" 0, "opsz" 144;
            letter-spacing: -0.02em;
            line-height: 0.95;
        }

        .ce-landing .display-wonk {
            font-family: var(--font-display);
            font-variation-settings: "SOFT" 100, "WONK" 1, "opsz" 144;
        }

        .ce-landing .text-teal {
            color: var(--ce-teal);
        }

        .ce-landing .text-teal-deep {
            color: var(--ce-teal-deep);
        }

        .ce-landing .text-cream {
            color: var(--ce-cream);
        }

        .ce-landing .text-mustard {
            color: var(--ce-mustard);
        }

        .ce-landing .bg-teal {
            background-color: var(--ce-teal);
        }

        .ce-landing .bg-teal-deep {
            background-color: var(--ce-teal-deep);
        }

        .ce-landing .bg-teal-soft {
            background-color: var(--ce-teal-soft);
        }

        .ce-landing .bg-mustard {
            background-color: var(--ce-mustard);
        }

        .ce-landing .bg-cream {
            background-color: var(--ce-cream);
        }

        .ce-landing .btn-primary {
            background-color: var(--ce-mustard);
            color: var(--ce-ink);
            border: 2px solid var(--ce-ink);
            box-shadow: 4px 4px 0 var(--ce-ink);
            transition: transform 120ms ease, box-shadow 120ms ease;
        }

        .ce-landing .btn-primary:hover {
            transform: translate(-2px, -2px);
            box-shadow: 6px 6px 0 var(--ce-ink);
        }

        .ce-landing .btn-primary:active {
            transform: translate(2px, 2px);
            box-shadow: 1px 1px 0 var(--ce-ink);
        }

        .ce-landing .eyebrow {
            font-family: var(--font-display);
            font-variation-settings: "SOFT" 100, "WONK" 0, "opsz" 14;
            font-weight: 500;
            letter-spacing: 0.22em;
            text-transform: uppercase;
            font-size: 0.78rem;
        }

        .ce-landing .focus-ring:focus-visible {
            outline: 3px solid var(--ce-teal);
            outline-offset: 3px;
            border-radius: 2px;
        }

        .ce-landing ::selection {
            background: var(--ce-mustard);
            color: var(--ce-ink);
        }

        @media (prefers-reduced-motion: reduce) {
            .ce-landing * {
                transition: none !important;
                animation: none !important;
            }
        }
    </style>
</head>
<body class="ce-landing antialiased">

<nav class="max-w-6xl mx-auto px-6 lg:px-10 pt-8 flex items-center justify-between">
    <a href="{{ route('home') }}" class="flex items-center gap-3 focus-ring">
        <img
                src="{{ asset('images/can-eye-bugget-logo.png') }}"
                alt="Can Eye Budget"
                width="40"
                height="40"
                loading="eager"
                decoding="async"
                class="h-10 w-10 object-contain"
        >
        <span class="display text-xl font-semibold">Can Eye Budget</span>
    </a>

    <div class="flex items-center gap-3 sm:gap-5 text-sm">
        @auth
            <a
                    href="{{ route('dashboard') }}"
                    class="focus-ring inline-flex items-center gap-2 px-4 py-2 rounded-full bg-teal-deep text-cream font-medium hover:opacity-90"
            >
                Dashboard
                <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                     stroke-linejoin="round">
                    <path d="M5 12h14M13 5l7 7-7 7"/>
                </svg>
            </a>
        @else
            <a href="{{ route('login') }}" class="focus-ring hidden sm:inline font-medium hover:text-teal-deep">
                Log in
            </a>
            <a
                    href="{{ route('register') }}"
                    class="focus-ring inline-flex items-center px-4 py-2 rounded-full border-2 font-medium hover:bg-teal-soft"
                    style="border-color: var(--ce-ink);"
            >
                Register
            </a>
        @endauth
    </div>
</nav>

{{-- HERO --}}
<header class="max-w-6xl mx-auto px-6 lg:px-10 pt-16 lg:pt-24 pb-24 lg:pb-32">
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-8 items-center">

        <div class="lg:col-span-7 order-2 lg:order-1">
            <p class="eyebrow text-teal-deep mb-6">Self-hosted · Australian · Open banking</p>

            <h1 class="display text-[3.5rem] sm:text-[5rem] lg:text-[7rem] font-semibold">
                Yes, you <span class="display-wonk relative inline-block">can.
                        <svg
                                aria-hidden="true"
                                class="absolute left-0 -bottom-3 lg:-bottom-4 w-full"
                                viewBox="0 0 300 20"
                                preserveAspectRatio="none"
                                style="height: 0.35em; color: var(--ce-mustard);"
                        >
                            <path
                                    d="M2 14 C 50 2, 100 18, 150 10 S 250 2, 298 12"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="5"
                                    stroke-linecap="round"
                            />
                        </svg>
                    </span>
            </h1>

            <p class="mt-10 text-lg sm:text-xl max-w-xl leading-relaxed">
                Real Australian bank data. Real visibility. Real control.
                Personal budgeting software that lives on your hardware,
                talks to your bank, and never gets between you and your money.
            </p>

            <div class="mt-10 flex flex-wrap items-center gap-5">
                <a
                        href="{{ route('register') }}"
                        class="btn-primary focus-ring display-wonk inline-flex items-center gap-2 px-7 py-4 rounded-full font-semibold text-lg"
                >
                    Get started — it's
                    <span class="relative">
                            free
                            <svg aria-hidden="true" class="absolute -inset-x-2 -inset-y-1 w-[calc(100%+1rem)] h-[calc(100%+0.5rem)]" viewBox="0 0 60 30"
                                 preserveAspectRatio="none">
                                <ellipse cx="30" cy="15" rx="27" ry="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                         style="color: var(--ce-ink);"/>
                            </svg>
                        </span>
                </a>

                <a href="#how-it-works" class="focus-ring font-medium text-teal-deep inline-flex items-center gap-2 hover:gap-3 transition-all">
                    See how it works
                    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14M13 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>
        </div>

        <div class="lg:col-span-5 order-1 lg:order-2 relative">
            <div class="relative mx-auto max-w-sm lg:max-w-none">

                <svg
                        aria-hidden="true"
                        class="hidden lg:block absolute -left-24 top-1/2 -translate-y-1/2 text-teal-deep"
                        width="100" height="60" viewBox="0 0 100 60" fill="none"
                >
                    <path d="M5 30 C 30 10, 60 50, 88 30" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" fill="none"/>
                    <path d="M78 22 L 90 30 L 80 40" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                </svg>

                <div class="rounded-3xl overflow-hidden bg-teal-soft" style="box-shadow: 12px 12px 0 var(--ce-ink);">
                    <img
                            src="{{ asset('images/can-eye-bugget-logo.png') }}"
                            alt="Can Eye Budget — a playful tin can, eyeball, and dollar sign mascot"
                            class="w-full h-auto"
                            loading="eager"
                            fetchpriority="high"
                    >
                </div>
            </div>
        </div>

    </div>
</header>

{{-- THREE-UP FEATURES --}}
<section id="how-it-works" class="max-w-6xl mx-auto px-6 lg:px-10 py-20 lg:py-28">
    <p class="eyebrow text-teal-deep mb-4">How it works</p>
    <h2 class="display text-4xl sm:text-5xl lg:text-6xl font-semibold max-w-3xl leading-[0.95]">
        Three steps. Nothing clever. Nothing hidden.
    </h2>

    <div class="mt-16 grid grid-cols-1 md:grid-cols-3 gap-10 md:gap-8 lg:gap-12">

        <article class="border-t-2 pt-8" style="border-color: var(--ce-ink);">
            <div class="display text-6xl font-semibold leading-none" style="color: var(--ce-teal);">01</div>
            <h3 class="display mt-6 text-2xl lg:text-3xl font-semibold leading-tight">
                Connect your bank in 60 seconds.
            </h3>
            <p class="mt-4 text-base leading-relaxed opacity-80">
                We use Basiq, Australia's open banking provider, to securely sync
                your transactions through the CDR. You authorise once. We never
                see your bank password.
            </p>
        </article>

        <article class="border-t-2 pt-8" style="border-color: var(--ce-ink);">
            <div class="display text-6xl font-semibold leading-none" style="color: var(--ce-teal);">02</div>
            <h3 class="display mt-6 text-2xl lg:text-3xl font-semibold leading-tight">
                See what's actually happening to your money.
            </h3>
            <p class="mt-4 text-base leading-relaxed opacity-80">
                Auto-categorised transactions, spending broken down by category,
                time-series views, and a calendar of where every dollar went.
                No tedious manual entry.
            </p>
        </article>

        <article class="border-t-2 pt-8" style="border-color: var(--ce-ink);">
            <div class="display text-6xl font-semibold leading-none" style="color: var(--ce-teal);">03</div>
            <h3 class="display mt-6 text-2xl lg:text-3xl font-semibold leading-tight">
                Plan around payday, not panic.
            </h3>
            <p class="mt-4 text-base leading-relaxed opacity-80">
                We learn your pay cycle, project your spending, and tell you
                exactly how much buffer you have before next pay. Recurring
                bills get spotted and tracked automatically.
            </p>
        </article>

    </div>
</section>

{{-- MANIFESTO --}}
<section class="bg-teal-deep text-cream py-24 lg:py-32">
    <div class="max-w-5xl mx-auto px-6 lg:px-10">
        <p class="eyebrow text-mustard mb-8">The manifesto</p>

        <blockquote class="display text-4xl sm:text-5xl lg:text-7xl font-semibold leading-[1.02]">
            Other budget apps want
            <span class="display-wonk italic" style="color: var(--ce-mustard);">your</span>
            data. We want
            <span class="display-wonk italic" style="color: var(--ce-mustard);">you</span>
            to have your data.
        </blockquote>

        <ul class="mt-16 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 lg:gap-8 text-base">
            @foreach ([
                ['Self-hosted', 'Your data, your hardware.'],
                ['CDR-compliant', 'Australian open banking via Basiq.'],
                ['No upsells', 'No premium tier. No ads. Ever.'],
                ['Open source', 'Read the code. Fork the code.'],
            ] as [$title, $body])
                <li class="flex gap-3">
                    <svg aria-hidden="true" class="shrink-0 mt-1" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="color: var(--ce-mustard);">
                        <path d="M4 12l5 5L20 6"/>
                    </svg>
                    <div>
                        <div class="display font-semibold text-lg">{{ $title }}</div>
                        <div class="opacity-80 mt-1">{{ $body }}</div>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</section>

{{-- FOOTER CTA --}}
<section class="max-w-4xl mx-auto px-6 lg:px-10 py-24 lg:py-32 text-center">
    <h2 class="display text-4xl sm:text-5xl lg:text-6xl font-semibold leading-[1.05]">
        Either you're budgeting, or budgeting is happening
        <span class="display-wonk italic">to</span> you.
        <br>
        Pick one.
    </h2>

    <div class="mt-12">
        <a
                href="{{ route('register') }}"
                class="btn-primary focus-ring display-wonk inline-flex items-center gap-2 px-8 py-4 rounded-full font-semibold text-lg"
        >
            Get started — free forever
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                 stroke-linejoin="round">
                <path d="M5 12h14M13 5l7 7-7 7"/>
            </svg>
        </a>
    </div>
</section>

{{-- FOOTER --}}
<footer class="border-t" style="border-color: rgba(26,24,22,0.12);">
    <div class="max-w-6xl mx-auto px-6 lg:px-10 py-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-sm opacity-70">
        <div>© {{ date('Y') }} Can Eye Budget</div>
        <div class="flex items-center gap-5">
            <a href="https://github.com/robwilde/can-eye-budget-v2" class="focus-ring hover:opacity-100 hover:text-teal-deep" target="_blank" rel="noopener noreferrer">GitHub</a>
            <span class="opacity-100 hover:text-teal-deep">Privacy</span>
            <span class="opacity-100 hover:text-teal-deep">Terms</span>
        </div>
    </div>
</footer>

</body>
</html>
