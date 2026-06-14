# Can-I-Budget — App UI kit

Pixel-approximate recreation of the Can-I-Budget web app (Laravel 12 + Livewire + Flux UI), stripped down to pure React + CSS.

## Files

- `index.html` — entry; renders the app shell with state and routing
- `primitives.jsx` — `Icon`, `Button`, `Badge`, `Card`, `MoneyCard`, `fmt`
- `shell.jsx` — `Sidebar`, `Topbar`
- `screens.jsx` — `Dashboard`, `Transactions`, `ConnectBank`, `EmptyCategories`
- `kit.css` — layout + component CSS (layers on top of `../../colors_and_type.css`)

## Screens included

1. **Connect Bank** — onboarding screen; primary Basiq entry point (`app/Livewire/ConnectBank.php` in the codebase)
2. **Dashboard** — the "can I spend?" answer + Owed/Available/Needed triad + budgets + recent activity (`resources/views/livewire/dashboard.blade.php`)
3. **Transactions** — filtered table (`resources/views/livewire/transaction-list.blade.php`)
4. **Categories empty state** — mirrors `spending-by-category.blade.php`'s zero-data branch

## What's faked
- Basiq auth / polling / refresh counter — stubbed to increment locally
- Categories screen is empty-state only; the real app renders an ApexCharts pie
- Calendar & Trends routes aren't built (noted as gaps in DEVLOG.md)
