---
name: can-i-budget-design
description: Use this skill to generate well-branded interfaces and assets for Can-I-Budget — a playful, no-nonsense Australian personal-budgeting app whose answer to every question is delivered in three numbers (Owed / Available / Needed). Use for production Livewire/Blade work or for throwaway prototypes, mocks, landing pages, slides and marketing artifacts.
user-invocable: true
---

Read `README.md` in this skill for the full brand + product context, then explore the other files as needed:

- `colors_and_type.css` — raw palette, semantic tokens, radii, shadows, spacing, motion, type scale. Import directly in standalone HTML artifacts.
- `assets/` — favicons + the hi-res brand master `logo.png` (1024×1536). The app serves small optimised web copies from `public/images/` (~8 KB shell PNG, ~52 KB welcome WebP — see #296); use `assets/logo.png` here as the source. Never redraw the mark.
- `preview/` — design-system cards. Read these as living references for colours, type, components.
- `ui_kits/app/` — React + CSS recreation of the web app shell (sidebar, topbar, dashboard, transactions, connect-bank). Reference-only — copy patterns out and adapt; never commit React into the app.
- `README.md` — the brand bible.

## Current implementation status (live app)

This skill lives inside the real repo (Laravel 12 + Livewire 4 + Flux UI Free v2 + Tailwind v4). The brand is **already implemented** — don't re-derive it, extend it:

- **Tokens are live** in `resources/css/app.css` (`@theme` block) as Tailwind `--color-*` / `--radius-*` / `--shadow-*` variables. `colors_and_type.css` here is the canonical reference; the app re-expresses the same values for Tailwind. Change tokens in `app.css`, then keep this file in sync.
- **The shell is branded**: teal sidebar, yellow active states, black 2px borders, mobile bottom tab bar + FAB, global `<livewire:transaction-modal>`. Component CSS (`.mcard`, `.tx-row`, pay-cycle calendar, quick-check pills, type toggle, …) lives in `app.css` `@layer components`.
- **Light-only.** The app deliberately forces `flux.appearance='light'` in `resources/views/partials/head.blade.php`. **Do not ship dark-mode surfaces** — the `.dark` block in `colors_and_type.css` and the README's dark-mode notes are aspirational and currently unused. (Legacy auth layouts `card`/`simple`/`split.blade.php` still carry `class="dark"` with generic stone colours; that's tech debt, not the target.)
- **Production code is Blade + Flux, never React.** Lift patterns from `ui_kits/` and `preview/` into Livewire/Blade using the live tokens.

## When to use what

- **Visual artifacts** (slides, throwaway prototypes, landing pages, marketing mocks) — import `colors_and_type.css`, copy `fonts/` + the brand master `assets/logo.png` alongside, and compose from the patterns in `ui_kits/app/` and `preview/`. Output static HTML files the user can open.
- **Production code** — edit Blade/Livewire views and reuse the tokens/components already in `resources/css/app.css`. Stay on-brand using the rules in `README.md`. Never output React into the repo.

## Non-negotiables

- The **Owed / Available / Needed** triad is the product's design primitive. Red / green / neutral, in that order, always. *Available includes remaining credit-card room.*
- **Mobile first.** The verdict sentence ("Yes — you're clear.") leads every home surface; triad stacks as rows on mobile; bottom tab bar, 44px+ targets. See `preview/mobile-home.html`.
- **Australian market.** AUD via `en-AU`, fortnightly pay cycle default, AU spelling, AU merchants/billers in sample data (Coles, AGL, Optus, BPAY, rego). Consent/CDR screens drop the playfulness entirely.
- **Sentence case** everywhere. No emoji in product UI. No corporate-bank clichés ("take control", "financial wellness").
- Money is **always tabular-nums** and **signed** when the sign matters (`+$420.00`, `−$120.00`).
- The brand signature is the **hard 3px black drop-shadow** on yellow CTAs and the logo. Use it for *brand* moments only — never on every product card.
- **One type family: Lato.** Hero / display / money = **Black 900**; body = **Regular 400**; labels, money lines, badges, buttons = **Bold 700**; italic for one-or-two-word emphasis only. (Lato is the resolved single brand family — see README "Substitutions flagged". The logo wordmark is an image, not type.)

## If invoked without guidance

Ask the user what they want to build (a slide, an email, a marketing page, a new product screen, a pitch deck, a logo variant, a motion piece…), ask a few clarifying questions (audience, length, fidelity, variations), then act as an expert designer. Output HTML artifacts or production code depending on the need.
