# Can-I-Budget Design System

A playful, no-nonsense budgeting system for Australians who want one answer, fast: **can I spend this, or not?**

## The product

Can-I-Budget (domain: can-eye-budget) is a self-hosted personal budgeting app. It pulls real bank data via Basiq (CDR-accredited open banking in AU) and renders three headline numbers for each pay cycle:

- **Owed** — liabilities across credit cards / loans
- **Available** — what's actually free to spend right now
- **Needed** — projected spend between now and the next payday

Everything else (transaction list, calendar, categories, rules, bank connection) exists to keep those three numbers honest. The north-star metric is **time-to-first-answer**: a new user should know where they stand in **under 5 minutes** (goal: 3).

## Brand voice, one line

> Fun, easy to remember, not too serious — like the logo. The app answers plain-language money questions in plain language.

> **Implementation status (this repo).** This document is the brand bible; the system is already built. Tokens live in `resources/css/app.css` (`@theme`); the app shell, mobile tab bar and component CSS are in place. The app is **light-only** (`resources/views/partials/head.blade.php` forces `flux.appearance='light'`), so the dark-mode notes below are aspirational and currently unused. The React `ui_kits/` are reference-only — production is Blade + Flux. See `SKILL.md`.

## Source materials

- **Codebase** — `can-eye-budget-v2/` (mounted, read-only). Laravel 12 + Livewire 4 + Flux UI Free v2 + Tailwind v4 + ApexCharts + Alpine.
  - Product mission: `agent-os/product/mission.md`
  - Roadmap: `agent-os/product/roadmap.md`
  - Tech stack: `agent-os/product/tech-stack.md`
  - Core Livewire views: `resources/views/livewire/*.blade.php`
    (dashboard, transaction-list, spending-by-category, calendar-view, connect-bank, account-manager, …)
  - Layouts: `resources/views/layouts/app/{sidebar,header}.blade.php`
  - Tailwind theme: `resources/css/app.css`
- **Logo** — hi-res master `assets/logo.png` (1024×1536); the app serves optimised web copies from `public/images/` (see #296).
- **GitHub repo** — `robwilde/can-eye-budget-v2` (not pulled; codebase was mounted locally).

## Index — what's in this folder

```
README.md                   ← you are here
SKILL.md                    ← Claude Code–compatible skill manifest
colors_and_type.css         ← CSS custom properties: palette, semantic, type
assets/                     ← logo (master) + favicons
fonts/                      ← Lato family (TTF: Thin–Black + italics)
preview/                    ← Design System tab cards (colors, type, components, mobile)
ui_kits/app/                ← Can-I-Budget web app UI kit (React JSX + index.html)
```

## The three headline numbers (design primitive)

Every surface has to respect the **Owed / Available / Needed** triad. They are visually colour-coded across the entire app:

| Slot | Meaning | Colour token | Hex |
|---|---|---|---|
| **Owed** | Debt, outgoing | `--money-owed` | `#E5484D` |
| **Available** | Free to spend **right now — cash plus remaining credit-card room** | `--money-available` | `#2AAF46` |
| **Needed** | Bills + planned spend between now and the next payday | `--money-needed` | `#262B2A` (neutral by design — it is a fact, not a verdict) |

The "am I OK?" answer is computed as `spare = available − needed`. Positive = green, negative = red, zero = neutral. The app shows this as a single sentence, e.g. *"+$420 above what you need"* or *"$120 below what you need"*. **The verdict sentence — "Yes — you're clear." / "Careful — you're short." — is the first thing on screen, before any number.** Everything else is supporting evidence.

---

## Mobile first

The primary surface is a phone held one-handed in a shop aisle. Design for that moment, in this order:

1. **The verdict** ("Yes — you're clear.") — readable at arm's length, Lato Black ≥ 36px, above the fold, no interaction needed. The app must answer before it is asked.
2. **The triad** — stacked compact rows on mobile (never the 3-up grid; numbers get too small). Label left, money right, 56px row height.
3. **Bills before payday** — the evidence for "Needed". Date chip + name + amount.
4. **Quick check** — type a price, get yes/no. This is the product's signature interaction; treat the input like a calculator key-pad moment (Lato Black 26px, `inputmode="decimal"`).

Mobile rules:

- **Bottom tab bar** (Home / Activity / Calendar / Settings), not a sidebar. 4 tabs max. ≥52px tall + safe-area inset.
- **Hit targets ≥ 44px**, primary actions ≥ 56px.
- **Inputs ≥ 16px font** (prevents iOS auto-zoom).
- The teal verdict panel + white rounded sheet (24px top radius) is the mobile shell pattern — brand on top, content card below.
- Hero money on mobile: 22px in rows, 40px in the verdict. Never show more than one 40px number per screen.
- See `preview/mobile-home.html`, `preview/components-quick-check.html`, `preview/mobile-payday.html`.

## Australian market

- **Currency**: AUD only. Format with `toLocaleString('en-AU')`. `$` not `A$` (the audience is domestic). Cents shown on rows; whole dollars allowed on hero/verdict numbers (`+$420`).
- **Pay cycle**: **fortnightly is the default** — most Australians are paid fortnightly. Weekly and monthly are options, but every example, every empty state and the payday countdown assume a 14-day cycle.
- **Spelling**: Australian English everywhere (*organise, colour, centre*). Dates `Thu 25 Jun`, day-first, no ordinal suffixes.
- **Bills vocabulary**: *direct debit*, *BPAY*, *rego*, *rates*, *super* are first-class words. Sample data uses real AU merchants and billers: Coles, Woolies, AGL/Origin, Optus/Telstra, Medicare, BP, myki/Opal.
- **Tone**: plain Aussie directness — *"Yes — go for it."*, *"Not this time."*, *"You're clear."* Light vernacular is welcome in marketing ("you're sweet") but **never** in numbers, warnings or consent flows.
- **Trust & CDR**: open-banking consent is regulated (Consumer Data Right). Consent screens are the one place the playful brand steps back: plain language, *read-only*, *powered by Basiq (CDR-accredited)*, *disconnect any time*. No pop shadows, no yellow.
- **Superannuation/HECS**: out of scope for the triad — they're not "between now and payday" money. Don't surface them.

---

## Content fundamentals

**Voice:** second-person, direct, calm. Australian English spelling. Says *you*, not *the user*. Avoids jargon (no "liquidity", no "disposable income" — say **available** and **needed**).

**Casing:** Sentence case for every heading, button, label and toast. Title Case only in the logo mark itself. No ALL CAPS except the small-caps `.label` utility (12px tracked, for table headers like `DESCRIPTION / DATE / AMOUNT`).

**Sentence shapes that recur:**

- Empty states: one-liner problem, one-liner next step, one primary button.
  > *"No linked accounts."* / *"Connect your bank to see your accounts and what you have available."* / **Connect Bank**
- Status sentences: short, comparative, always include a sign.
  > *"+$420 above what you need"*, *"$120 below what you need"*, *"$0 — right on target"*
- Time phrasing is human: *"Last synced 3 minutes ago"*, *"5 days until payday"*, *"Pay cycle"*, not *"7d"* in user-facing copy (`7d` is fine for chart chips).
- Actions are verbs: **Connect Bank**, **Refresh Now**, **Set up pay cycle →**, **Reconcile**.

**Emoji / decoration:** **no emoji in the app UI.** The brand personality lives in the logo, the chunky display face, the hard drop-shadow, and the teal/yellow colour block. Icons come from a Heroicons-style outline set (see Iconography).

**Examples that set the tone (pulled from the codebase):**

- `"Connect your bank to see your accounts and what you have available."`
- `"Spending trends will appear here once you have transactions."`
- `"{N} of 20 refreshes used today"`
- `"Set up pay cycle →"` — right-arrow suffix means *configure in settings*.
- Numbers always carry the currency symbol and a leading sign when signed (`+$420.00`, `-$120.00`).

**Never:**

- never say "track your finances", "take control", "financial wellness" — corporate-bank speak
- never stack adjectives ("simple, easy, beautiful budgeting")
- never use passive voice for status ("has been synced" → **"synced 3 minutes ago"**)

---

## Visual foundations

**Mood:** friendly cartoon-sticker meets Aussie pragmatism. Hard black outlines + flat saturated fills + chunky display type on the marketing/brand layer. The product itself is quieter — a modern card-based dashboard that *borrows the brand palette* but doesn't try to be the logo.

### Palette

- **Primary:** Teal `--cib-teal-400 #1E8F85` — the logo background. Used for the brand mark, primary buttons, active states, progress fills.
- **Accent:** Yellow `--cib-yellow-400 #F8C93A` — sparingly; highlights, celebratory toasts, "nice one" moments.
- **Money semantic:** red `#E5484D` (owed), green `#2AAF46` (available), neutral dark (needed), amber `#F1A51C` (planned/suggested).
- **Neutrals:** warm greys with a slight green undertone so teal and neutrals sit in the same family.
- **Dark mode** — *aspirational, not shipped.* Tokens exist (`.dark` block in `colors_and_type.css`) but the live app is deliberately **light-only** (`partials/head.blade.php` forces `flux.appearance='light'`). If dark mode is revived, backgrounds are deep teal-tinted near-black (`#0B1513`) — never pure `#000`, never cool blue-black.

### Typography

- **One family, all of it → Lato** (shipped in `fonts/`, weights 100/300/400/700/900 + italics). Humanist sans, open counters, warm but unfussy — the brand's text voice is the same whether it's a $42 Coles line item or a marketing headline.
- **Display / headlines / hero money** → Lato **Black 900**, tight tracking. Never Bold 700 for hero money — the 900 cut is part of the brand personality and pairs visually with the heavy outlined wordmark in the logo.
- **Body / UI** → Lato **Regular 400** at 15/1.55 for default, 17/1.55 for reading surfaces.
- **Labels, money lines, badges, buttons** → Lato **Bold 700**. For small tabular money (row-level amounts) 700 is mandatory, not 600 — the `tnum` figures look thin otherwise.
- **Italic** is used for *emphasis only* (one or two words at a time). Never italicise whole paragraphs.
- **Mono / tabular** → system mono via `ui-monospace`; money always uses `font-variant-numeric: tabular-nums` in the UI font (no font switch, just the OpenType feature).
- **Scale** is defined as CSS custom properties in `colors_and_type.css` (`--t-display-lg` … `--t-caption`, plus `--t-money-hero` / `--t-money` for numbers).

### Backgrounds

- Mostly flat. No photography.
- Marketing / auth: big **solid teal** fields, sometimes with the logo or the eye/dollar motif placed off-grid as a hero illustration.
- Product: **flat `--bg-page` + white/cream surface cards**. No gradients, no noise, no patterns inside the product.
- **No full-bleed illustrations inside the product.** Illustration lives in empty states and marketing only.

### Animation

- **Subtle bounce** on primary actions — the `--ease-snap` curve (`cubic-bezier(.2,.9,.2,1.2)`) gives buttons a tiny overshoot, matching the bouncy-sticker logo vibe.
- Most transitions are fades + 4-8px translate (starting-state utility classes in the Laravel welcome page already do this: `starting:translate-y-4 starting:opacity-0 duration-750`).
- Disclosure rows (`Account breakdown` in the dashboard) use height collapse. No spring physics, no parallax.

### Hover / press states

- **Hover** on cards: border darkens one step (`--border-1 → --border-2`), optional ring in teal on interactive tiles. No shadow elevation change on dashboard tiles.
- **Hover** on rows (transactions, calendar days): `bg-zinc-50` / `dark:bg-zinc-800` — the Flux default.
- **Press** on primary buttons: **shrink 2px + shadow-pop collapses to zero** (the hard black drop-shadow offset snaps from `3px 3px 0` to `0 0 0`). This is the signature brand interaction.
- **Focus** is always visible: 2px teal ring with 2px surface offset (inherits from Flux `ring-accent`).

### Borders

- `1px solid var(--border-1)` on product cards.
- `2px solid var(--border-strong)` (#111) on brand/marketing cards and cartoon-style CTAs — matches the logo's heavy outline.
- Dividers: 1px `--border-1`; never use 2px dividers.

### Shadows

- `--shadow-xs / sm / md / lg` — soft teal-tinted shadows for product surfaces.
- `--shadow-pop` — **3px offset hard black drop-shadow** (no blur). This is the brand signature, used on logo-adjacent CTAs, toasts, and decorative price tags. Never use a pop shadow on a regular product card — it reads too loud.
- Inner ring used for outline-only buttons: `inset 0 0 0 1px rgba(26,26,0,0.16)` (from Flux).

### Protection gradients vs capsules

- No protection gradients anywhere.
- Chips/capsules use flat `--money-*-soft` backgrounds with matching `--money-*` text (e.g. red-soft bg + red text for "owed" badges). Always 1px same-hue border-free — readability comes from the soft tint.

### Layout rules

- Sidebar app shell: **240px collapsible sidebar** (Flux default, `w-[220px] md:w-[220px]`), sticky, collapsible on mobile.
- Max content width: `max-w-4xl` / `56rem` on single-column reading, full-width cards on the dashboard grid.
- Dashboard grid: **3 columns @ `md`** for the Owed/Available/Needed triad, 1 column on mobile. Gap: `--s-4` (16px).
- Cards: `rounded-xl` (`--r-md` 12px) → sits between the brand's bouncy `--r-xl` (28px) and system `rounded-lg`.

### Transparency & blur

- Used only for **loading overlays** on async panels: `bg-white/60` with a spinner centered. No frosted glass, no translucent sidebars, no translucent modals.

### Imagery colour vibe

- Warm. Slightly saturated. No grain. No B&W. If photography is ever added, it should be bright daylight, Aussie suburbia, not stock-finance-skyscrapers.

### Corner radii

| Token | Px | Used for |
|---|---|---|
| `--r-xs` | 4 | Badges, tag chips |
| `--r-sm` | 8 | Inputs, small buttons |
| `--r-md` | 12 | **Default card radius** (matches `rounded-xl` in app) |
| `--r-lg` | 18 | Primary CTA, hero callouts |
| `--r-xl` | 28 | Brand panels / logo-style rounded-rects |
| `--r-pill` | 999 | Pills, avatar, filter chips |

### Cards — anatomy

- White surface, 1px `--border-1`, `--r-md` radius, no shadow by default.
- 16px outer padding (`--s-4`), 12px gap between title + body.
- Three-slot pattern on dashboard: `title` (label-cased) + `icon badge` (16px icon inside a soft square, matching semantic colour) + `money-hero` number + `caption` line with a tiny state icon.

---

## Iconography

- **System: Heroicons** (outline + mini solid) — this is what Flux UI ships with and what every product view uses today (`flux:icon.credit-card`, `flux:icon.banknotes`, `flux:icon.chart-pie`, `flux:icon.calendar-days`, etc.). We keep Heroicons as the canonical product icon set.
- **Previews:** the HTML preview cards use [lucide](https://lucide.dev) for incidental glyphs; the `ui_kits/app/` reference uses an inline `Icon` component in `primitives.jsx`. In the **product**, icons come from Flux's bundled Heroicons (`flux:icon.*`) — that is the canonical set; the preview/kit choice is reference-only.
- **Weight:** 1.5px stroke, 24×24 canvas. Use the 20×20 `mini` solid variant inside coloured badge squares; outline 24×24 in lists and buttons.
- **Brand mark** (the can + eye + dollar) is **not an icon** — it lives in `assets/logo.png` and is only used whole. Do not extract the eye or the can as standalone pictograms in-app.
- **Emoji:** never in product UI. Acceptable in marketing copy and social (sparingly).
- **Unicode chars as icons:** the `→` arrow is used at the end of secondary actions ("Set up pay cycle →"). No other unicode iconography.
- **PNG icons / illustration sprites:** none in the codebase today. Empty-state visuals are drawn from Heroicons at `size-12 text-zinc-400` — a large outline icon above the headline. We preserve this pattern.

### Substitutions flagged

- **Display font** — **none, resolved.** Lato is now the single brand family. The logo wordmark still reads as a heavier, chunkier cut than Lato Black; if it turns out to be a different custom face used *only* for the mark, that's fine — the mark is an image, not type.
- **Icon set** — Heroicons kept (matches codebase). No substitution.

---

## Open questions / caveats

1. **Dollar-sign green** — picked from the logo eyeballed as `#3DC85A`. Happy to resample from a high-res source.
2. **Mascot use** — the cartoon can + eye + dollar is unforgettable but currently only used as a whole logo. Worth a call on whether we want standalone "reaction" stickers (scared can, celebrating eye) for empty/success states down the line.
3. **Dark mode** — tokens exist (`.dark` block in `colors_and_type.css`) but the app is **light-only** today (`flux.appearance` forced to `light`). No dashboard dark pass; revisit only if the light-only decision is reversed.
